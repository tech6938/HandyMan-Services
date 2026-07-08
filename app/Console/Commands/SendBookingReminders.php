<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\BookingModule\Entities\Booking;
use App\Jobs\SendBookingReminderJob;

class SendBookingReminders extends Command
{
    protected $signature = 'bookings:send-reminders
                            {--batch-size=100}
                            {--dry-run : Only show what would be processed}';

    protected $description = 'Dispatch booking reminder jobs to queue with smart intervals';

    public function handle()
    {
        $startTime = microtime(true);
        $now = now();
        $dryRun = $this->option('dry-run');
        $batchSize = (int) $this->option('batch-size');
        $dispatchedCount = 0;

        $this->info('🚀 Starting booking reminder dispatch...');

        try {
            // Get all intervals from config
            $intervals = config('reminders.intervals', []);
            $dailyInterval = config('reminders.daily_interval_minutes', 1440);

            // Get bookings that need reminders
            $bookingsToProcess = $this->getBookingsNeedingReminders($now, $intervals);

            if (empty($bookingsToProcess)) {
                $this->info('✅ No bookings need reminders.');
                return 0;
            }

            $total = count($bookingsToProcess);
            $this->info("📊 Found {$total} reminders to process");

            // Process in batches
            $batches = array_chunk($bookingsToProcess, $batchSize);
            $progressBar = $this->output->createProgressBar(count($batches));

            foreach ($batches as $batch) {
                foreach ($batch as $data) {
                    if ($dryRun) {
                        $this->line("  Would dispatch: Booking {$data['readable_id']} - {$data['level']}");
                        continue;
                    }

                    try {
                        // Dispatch job to queue
                        SendBookingReminderJob::dispatch(
                            $data['booking_id'],
                            $data['level'],
                            $data['target_minutes']
                        )->onQueue('reminders');

                        $dispatchedCount++;
                    } catch (\Exception $e) {
                        Log::error('Failed to dispatch job', [
                            'booking_id' => $data['booking_id'],
                            'level' => $data['level'],
                            'error' => $e->getMessage()
                        ]);
                    }
                }
                $progressBar->advance();
                gc_collect_cycles(); // Memory cleanup
            }

            $progressBar->finish();
            $this->newLine();

            $executionTime = round(microtime(true) - $startTime, 2);
            $this->info("✅ Dispatched {$dispatchedCount} jobs in {$executionTime}s");
        } catch (\Exception $e) {
            Log::error('Reminder dispatch failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->error('❌ Failed: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }

    private function getBookingsNeedingReminders($now, $intervals): array
    {
        $minMinutes = min(array_keys($intervals));
        $cutoffDate = $now->copy()->subMinutes($minMinutes);

        // Get bookings with their sent reminders
        $bookings = Booking::select(
            'bookings.id',
            'bookings.created_at',
            'bookings.parent_booking_id',
            'bookings.readable_id',
            'bookings.booking_status',
            DB::raw('GROUP_CONCAT(booking_reminders.level) as sent_levels')
        )
            ->leftJoin('booking_reminders', function ($join) {
                $join->on('bookings.id', '=', 'booking_reminders.booking_id')
                    ->where('booking_reminders.sent_at', '>=', now()->subDays(30));
            })
            ->where('bookings.booking_status', 'accepted')
            ->where('bookings.created_at', '>=', $now->copy()->subDays(30))
            ->where('bookings.created_at', '<=', $cutoffDate)
            ->groupBy('bookings.id', 'bookings.created_at', 'bookings.parent_booking_id', 'bookings.readable_id', 'bookings.booking_status')
            ->get();

        $result = [];
        foreach ($bookings as $booking) {
            $minutes = $booking->created_at->diffInMinutes($now);
            $sentLevels = explode(',', $booking->sent_levels ?? '');

            foreach ($intervals as $targetMinutes => $level) {
                if ($minutes >= $targetMinutes && !in_array($level, $sentLevels)) {
                    $result[] = [
                        'booking_id' => $booking->id,
                        'level' => $level,
                        'target_minutes' => $targetMinutes,
                        'readable_id' => $booking->readable_id
                    ];
                }
            }
        }

        return $result;
    }
}
