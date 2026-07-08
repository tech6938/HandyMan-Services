<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\BookingModule\Entities\Booking;

class SendBookingReminderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;
    public $tries = 3;
    public $backoff = [60, 300, 600];

    protected $bookingId;
    protected $level;
    protected $targetMinutes;

    public function __construct($bookingId, $level, $targetMinutes)
    {
        $this->bookingId = $bookingId;
        $this->level = $level;
        $this->targetMinutes = $targetMinutes;
    }

    public function handle()
    {
        try {
            $booking = Booking::with([
                'provider.owner:id,fcm_token,first_name,last_name',
                'serviceman.user:id,fcm_token,first_name,last_name'
            ])->find($this->bookingId);

            if (!$booking) {
                Log::warning('Booking not found for reminder', ['booking_id' => $this->bookingId]);
                return;
            }

            if (!$this->shouldSendReminder($booking)) {
                return;
            }

            $sentCount = $this->sendNotifications($booking);

            if ($sentCount > 0) {
                $this->trackReminder($booking);

                Log::info('Booking reminder sent via queue', [
                    'booking_id' => $booking->id,
                    'level' => $this->level,
                    'sent_to' => $sentCount
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Failed to send booking reminder', [
                'booking_id' => $this->bookingId,
                'level' => $this->level,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }

    private function shouldSendReminder($booking): bool
    {
        // Check if already sent
        $alreadySent = DB::table('booking_reminders')
            ->where('booking_id', $booking->id)
            ->where('level', $this->level)
            ->exists();

        if ($alreadySent) {
            Log::info('Reminder already sent', [
                'booking_id' => $booking->id,
                'level' => $this->level
            ]);
            return false;
        }

        // Check if booking still pending
        if ($booking->booking_status !== 'accepted') {
            Log::info('Booking no longer pending', [
                'booking_id' => $booking->id,
                'status' => $booking->booking_status
            ]);
            return false;
        }

        // Check minimum time threshold
        $minutes = $booking->created_at->diffInMinutes(now());
        if ($minutes < $this->targetMinutes) {
            Log::info('Booking not old enough for reminder', [
                'booking_id' => $booking->id,
                'minutes' => $minutes,
                'required' => $this->targetMinutes
            ]);
            return false;
        }

        return true;
    }

    private function sendNotifications($booking): int
    {
        $sentCount = 0;

        $title = match ($this->level) {
            '5_HOURS' => "⏰ Reminder: Booking pending 5 hours",
            '30_HOURS' => "📋 Reminder: Booking pending 30 hours",
            '60_HOURS' => "⚠️ Reminder: Booking pending 60 hours",
            default => "📢 Reminder: Booking pending"
        };

        $description = "Booking #{$booking->readable_id} is still pending. Please take action.";

        // Send to provider
        if ($providerFcm = $booking->provider?->owner?->fcm_token) {
            try {
                $this->sendNotification(
                    $providerFcm,
                    $title,
                    $description,
                    $booking,
                    'provider'
                );
                $sentCount++;
            } catch (\Exception $e) {
                $this->logNotificationError($booking, 'provider', $e->getMessage());
            }
        }

        // Send to serviceman
        if ($servicemanFcm = $booking->serviceman?->user?->fcm_token) {
            try {
                $this->sendNotification(
                    $servicemanFcm,
                    $title,
                    $description,
                    $booking,
                    'serviceman'
                );
                $sentCount++;
            } catch (\Exception $e) {
                $this->logNotificationError($booking, 'serviceman', $e->getMessage());
            }
        }

        return $sentCount;
    }

    private function sendNotification($fcmToken, $title, $description, $booking, $recipientType)
    {
        device_notification(
            $fcmToken,
            $title,
            $description,
            null,
            $booking->parent_booking_id,
            'booking_reminder',
            null,
            $booking->provider?->id,
            null,
            null,
            null
        );

        // Log successful notification
        DB::table('notification_logs')->insert([
            'booking_id' => $booking->id,
            'recipient_type' => $recipientType,
            'level' => $this->level,
            'fcm_token' => $fcmToken,
            'success' => true,
            'sent_at' => now(),
            'created_at' => now(),
            'updated_at' => now()
        ]);
    }

    private function logNotificationError($booking, $recipientType, $errorMessage)
    {
        DB::table('notification_logs')->insert([
            'booking_id' => $booking->id,
            'recipient_type' => $recipientType,
            'level' => $this->level,
            'success' => false,
            'error_message' => $errorMessage,
            'sent_at' => now(),
            'created_at' => now(),
            'updated_at' => now()
        ]);
    }

    private function trackReminder($booking)
    {
        DB::table('booking_reminders')->insert([
            'booking_id' => $booking->id,
            'level' => $this->level,
            'sent_at' => now(),
            'created_at' => now(),
            'updated_at' => now()
        ]);
    }
}
