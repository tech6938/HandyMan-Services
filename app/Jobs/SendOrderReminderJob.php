<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\BookingModule\Entities\Booking;

class SendOrderReminderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;
    public $tries = 3;
    public $backoff = [60, 300, 600];

    protected $orderId;
    protected $reminderCount;
    protected $isImmediate;

    public function __construct($orderId, $reminderCount, $isImmediate = false)
    {
        $this->orderId = $orderId;
        $this->reminderCount = $reminderCount;
        $this->isImmediate = $isImmediate;
        $this->onQueue('order-reminders');
    }

    public function handle()
    {
        DB::transaction(function () {
            $order = Booking::with([
                'provider.owner:id,fcm_token,first_name,last_name',
                'serviceman.user:id,fcm_token,first_name,last_name'
            ])->lockForUpdate()->find($this->orderId);

            if (!$order) {
                Log::warning('Order not found', ['order_id' => $this->orderId]);
                return;
            }

            // Check if order is still pending
            if (!$this->isOrderStillPending($order)) {
                Log::info('Order no longer pending, skipping reminder', [
                    'order_id' => $order->id,
                    'status' => $order->booking_status
                ]);
                return;
            }

            // Check if reminder already sent
            if ($this->isReminderAlreadySent($order)) {
                return;
            }

            // Send notification
            $sentCount = $this->sendNotification($order);

            if ($sentCount > 0) {
                $this->trackReminder($order);

                $type = $this->isImmediate ? 'Immediate' : 'Reminder';
                Log::info("Order {$type} sent", [
                    'order_id' => $order->id,
                    'reminder_count' => $this->reminderCount
                ]);
            }
        });
    }

    private function isOrderStillPending($order): bool
    {
        return $order->booking_status === config('order-reminders.order_status', 'pending');
    }

    private function isReminderAlreadySent($order): bool
    {
        $level = $this->isImmediate ? 'ORDER_IMMEDIATE' : 'ORDER_REMINDER_' . $this->reminderCount;

        return DB::table('booking_reminders')
            ->where('booking_id', $order->id)
            ->where('level', $level)
            ->exists();
    }

    private function sendNotification($order): int
    {
        $sentCount = 0;

        $title = $this->getNotificationTitle();
        $description = $this->getNotificationDescription($order);

        // Send to Provider
        if ($token = $order->provider?->owner?->fcm_token) {
            try {
                device_notification(
                    $token,
                    $title,
                    $description,
                    null,
                    $order->parent_booking_id ?? $order->id,
                    'order_reminder',
                    null,
                    $order->provider?->id,
                    null,
                    null,
                    null
                );
                $this->logNotification($order, 'provider', $token, true);
                $sentCount++;
            } catch (\Exception $e) {
                $this->logNotification($order, 'provider', $token, false, $e->getMessage());
                Log::error('Provider notification failed', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        // Send to Serviceman (if exists)
        if ($token = $order->serviceman?->user?->fcm_token) {
            try {
                device_notification(
                    $token,
                    $title,
                    $description,
                    null,
                    $order->parent_booking_id ?? $order->id,
                    'order_reminder',
                    null,
                    $order->provider?->id,
                    null,
                    null,
                    null
                );
                $this->logNotification($order, 'serviceman', $token, true);
                $sentCount++;
            } catch (\Exception $e) {
                $this->logNotification($order, 'serviceman', $token, false, $e->getMessage());
                Log::error('Serviceman notification failed', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $sentCount;
    }

    private function getNotificationTitle(): string
    {
        $titles = config('order-reminders.titles', []);

        if ($this->isImmediate) {
            return $titles[1] ?? '📋 New Order #: Please take action';
        }

        return $titles[$this->reminderCount] ?? $titles['default'] ?? '📢 Reminder: Order # still pending';
    }

    private function getNotificationDescription($order): string
    {
        $orderId = $order->readable_id ?? $order->id;
        $hours = round(($this->reminderCount * 30) / 60, 1);

        if ($this->isImmediate) {
            return "New order #{$orderId} is waiting for your action. Please accept it.";
        }

        if ($this->reminderCount == 1) {
            return "Order #{$orderId} is still pending for 30 minutes. Please take action.";
        } elseif ($this->reminderCount == 2) {
            return "Order #{$orderId} is still pending for 1 hour. Please take action.";
        } else {
            return "Order #{$orderId} is still pending for {$hours} hours. Please take action.";
        }
    }

    private function logNotification($order, $recipientType, $token, $success, $error = null)
    {
        DB::table('notification_logs')->insert([
            'booking_id' => $order->id,
            'recipient_type' => $recipientType . '_order',
            'level' => $this->isImmediate ? 'ORDER_IMMEDIATE' : 'ORDER_REMINDER_' . $this->reminderCount,
            'fcm_token' => $token,
            'success' => $success ? 1 : 0,
            'error_message' => $error,
            'sent_at' => now(),
            'created_at' => now(),
            'updated_at' => now()
        ]);
    }

    private function trackReminder($order)
    {
        $level = $this->isImmediate ? 'ORDER_IMMEDIATE' : 'ORDER_REMINDER_' . $this->reminderCount;

        DB::table('booking_reminders')->insert([
            'booking_id' => $order->id,
            'level' => $level,
            'sent_at' => now(),
            'created_at' => now(),
            'updated_at' => now()
        ]);
    }

    // Add this method to the SendOrderReminderJob class
    private function canSendNotification($token): bool
    {
        $key = "rate_limit:order_notification:" . md5($token);
        $count = Cache::get($key, 0);
        $maxPerMinute = config('order-reminders.rate_limit.notifications_per_minute', 30);

        if ($count >= $maxPerMinute) {
            Log::info('Rate limit reached for token', [
                'token' => substr($token, 0, 20) . '...',
                'count' => $count
            ]);
            return false;
        }

        Cache::increment($key);
        Cache::expire($key, 60); // Reset after 1 minute
        return true;
    }
}
