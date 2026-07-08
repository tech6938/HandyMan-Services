<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\BookingModule\Entities\Booking;
use App\Jobs\SendOrderReminderJob;

class SendOrderReminders extends Command
{
    protected $signature = 'orders:send-reminders
                            {--batch-size=100}
                            {--dry-run : Only show what would be processed}';

    protected $description = 'Send pending order reminders to providers';

    public function handle()
    {
        $startTime = microtime(true);
        $now = now();
        $dryRun = $this->option('dry-run');
        $batchSize = (int) $this->option('batch-size');
        $dispatchedCount = 0;

        $this->info('🚀 Starting order reminder dispatch...');

        try {
            // Get orders that need reminders
            $ordersToProcess = $this->getOrdersNeedingReminders($now);

            if (empty($ordersToProcess)) {
                $this->info('✅ No orders need reminders.');
                return 0;
            }

            $total = count($ordersToProcess);
            $this->info("📊 Found {$total} orders needing reminders");

            // Process in batches
            $batches = array_chunk($ordersToProcess, $batchSize);
            $progressBar = $this->output->createProgressBar(count($batches));

            foreach ($batches as $batch) {
                foreach ($batch as $data) {
                    if ($dryRun) {
                        $this->line("  Would dispatch: Order {$data['readable_id']} - Reminder #{$data['reminder_count']}");
                        continue;
                    }

                    try {
                        SendOrderReminderJob::dispatch(
                            $data['order_id'],
                            $data['reminder_count'],
                            false // Not immediate
                        )->onQueue('order-reminders');

                        $dispatchedCount++;
                    } catch (\Exception $e) {
                        Log::error('Failed to dispatch order reminder job', [
                            'order_id' => $data['order_id'],
                            'error' => $e->getMessage()
                        ]);
                    }
                }
                $progressBar->advance();
                gc_collect_cycles();
            }

            $progressBar->finish();
            $this->newLine();

            $executionTime = round(microtime(true) - $startTime, 2);
            $this->info("✅ Dispatched {$dispatchedCount} jobs in {$executionTime}s");

        } catch (\Exception $e) {
            Log::error('Order reminder dispatch failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->error('❌ Failed: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }

    private function getOrdersNeedingReminders($now): array
    {
        $intervalMinutes = config('order-reminders.reminder_interval_minutes', 30);
        $maxReminders = config('order-reminders.max_reminders', 10);
        $acceptedStatuses = config('order-reminders.accepted_status', ['accepted', 'ongoing', 'completed']);

        // Get pending orders older than 30 minutes
        $cutoffDate = $now->copy()->subMinutes($intervalMinutes);

        $orders = Booking::select(
            'bookings.id',
            'bookings.created_at',
            'bookings.readable_id',
            'bookings.booking_status',
            DB::raw('COUNT(booking_reminders.id) as reminder_count')
        )
        ->leftJoin('booking_reminders', function($join) {
            $join->on('bookings.id', '=', 'booking_reminders.booking_id')
                 ->where('booking_reminders.level', 'LIKE', 'ORDER_REMINDER_%');
        })
        ->where('bookings.booking_status', 'pending')
        ->where('bookings.created_at', '<=', $cutoffDate)
        ->whereNotIn('bookings.booking_status', $acceptedStatuses)
        ->groupBy('bookings.id', 'bookings.created_at', 'bookings.readable_id', 'bookings.booking_status')
        ->having('reminder_count', '<', $maxReminders)
        ->get();

        $result = [];
        foreach ($orders as $order) {
            $minutesSince = $order->created_at->diffInMinutes($now);
            $expectedReminders = floor($minutesSince / $intervalMinutes);
            $sentReminders = (int) $order->reminder_count;

            // Only send if next reminder is due
            if ($expectedReminders > $sentReminders) {
                $nextReminder = $sentReminders + 1;

                // Safety: Don't exceed max reminders
                if ($nextReminder <= $maxReminders) {
                    $result[] = [
                        'order_id' => $order->id,
                        'readable_id' => $order->readable_id,
                        'reminder_count' => $nextReminder
                    ];
                }
            }
        }

        return $result;
    }
}
