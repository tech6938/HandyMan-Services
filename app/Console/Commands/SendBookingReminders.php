<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\BookingModule\Entities\Booking;
use App\Jobs\SendBookingReminderJob;

class SendBookingReminders extends Command
{
    protected $signature = 'bookings:send-reminders
                            {--batch-size=100 : Number of bookings to process per batch}
                            {--dry-run : Only show what would be processed}
                            {--force : Force run even if already running}';

    protected $description = 'Dispatch booking reminder jobs to queue';

    private const REMINDER_LEVELS = [
        300  => '5_HOURS',
        1800 => '30_HOURS',
        3600 => '60_HOURS',
    ];

    public function handle()
    {
        $startTime = microtime(true);
        $dispatchedCount = 0;
        $now = now();
        $batchSize = (int) $this->option('batch-size');
        $dryRun = $this->option('dry-run');
        $force = $this->option('force');

        $this->info('🚀 Starting booking reminder dispatch...');

        try {
            // Check if already running
            if (!$force && $this->isRunning()) {
                $this->warn('⚠️  Command is already running. Use --force to override.');
                return 0;
            }

            // Get bookings that need reminders
            $bookingsData = $this->getBookingsNeedingReminders($now);

            if (empty($bookingsData)) {
                $this->info('✅ No bookings need reminders.');
                return 0;
            }

            $this->info("📊 Found " . count($bookingsData) . " reminders to process");

            // Process in batches
            $batches = array_chunk($bookingsData, $batchSize);
            $totalBatches = count($batches);

            $bar = $this->output->createProgressBar($totalBatches);
            $bar->start();

            foreach ($batches as $index => $batchData) {
                if ($dryRun) {
                    $this->line("\n  ℹ️  Would dispatch " . count($batchData) . " jobs");
                    continue;
                }

                $dispatched = $this->dispatchBatchJobs($batchData);
                $dispatchedCount += $dispatched;

                $bar->advance();
                gc_collect_cycles();
            }

            $bar->finish();
            $this->newLine();

            $executionTime = round(microtime(true) - $startTime, 2);

            $this->info("✅ Dispatched {$dispatchedCount} jobs in {$executionTime}s");

            if ($dryRun) {
                $this->line("ℹ️  This was a dry run. No jobs were dispatched.");
            }

            // Log summary
            $this->logSummary($dispatchedCount, $executionTime);
        } catch (\Exception $e) {
            Log::error('Booking reminder dispatch failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->error('❌ Failed: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }

    private function getBookingsNeedingReminders($now): array
    {
        // Get bookings that are old enough for reminders
        $minMinutes = min(array_keys(self::REMINDER_LEVELS));
        $cutoffDate = $now->copy()->subMinutes($minMinutes);

        // Get bookings with their last sent reminders
        $bookings = Booking::select(
            'bookings.id',
            'bookings.created_at',
            DB::raw('GROUP_CONCAT(booking_reminders.level) as sent_levels')
        )
            ->leftJoin('booking_reminders', function ($join) {
                $join->on('bookings.id', '=', 'booking_reminders.booking_id')
                    ->where('booking_reminders.sent_at', '>=', now()->subDays(3));
            })
            ->where('bookings.booking_status', 'accepted')
            ->where('bookings.created_at', '>=', $now->copy()->subDays(3))
            ->where('bookings.created_at', '<=', $cutoffDate)
            ->groupBy('bookings.id', 'bookings.created_at')
            ->having(DB::raw('COUNT(booking_reminders.id)'), '<', count(self::REMINDER_LEVELS))
            ->get();

        $result = [];
        foreach ($bookings as $booking) {
            $minutes = $booking->created_at->diffInMinutes($now);
            $sentLevels = explode(',', $booking->sent_levels ?? '');

            foreach (self::REMINDER_LEVELS as $targetMinutes => $level) {
                if ($minutes >= $targetMinutes && !in_array($level, $sentLevels)) {
                    $result[] = [
                        'id' => $booking->id,
                        'level' => $level,
                        'target_minutes' => $targetMinutes
                    ];
                }
            }
        }

        return $result;
    }

    private function dispatchBatchJobs(array $batchData): int
    {
        $dispatched = 0;

        foreach ($batchData as $data) {
            try {
                SendBookingReminderJob::dispatch(
                    $data['id'],
                    $data['level'],
                    $data['target_minutes']
                )->onQueue('reminders');

                $dispatched++;
            } catch (\Exception $e) {
                Log::error('Failed to dispatch job', [
                    'booking_id' => $data['id'],
                    'level' => $data['level'],
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $dispatched;
    }

    private function isRunning(): bool
    {
        $lockFile = storage_path('framework/booking_reminders.lock');

        if (!file_exists($lockFile)) {
            return false;
        }

        $lockTime = filemtime($lockFile);
        if (time() - $lockTime > 3600) { // 1 hour timeout
            @unlink($lockFile);
            return false;
        }

        return true;
    }

    private function logSummary($dispatchedCount, $executionTime)
    {
        DB::table('command_metrics')->insert([
            'command' => 'bookings:send-reminders',
            'jobs_dispatched' => $dispatchedCount,
            'execution_time' => $executionTime,
            'memory_usage' => memory_get_peak_usage(true),
            'created_at' => now()
        ]);
    }

    // public function handle()
    // {
    //     $now = now();

    //     // Get all accepted bookings where provider or serviceman can receive reminders
    //     $bookings = Booking::where('booking_status', 'accepted')
    //         ->where(function ($query) {
    //             $query->whereHas('provider.owner', function ($query) {
    //                 $query->whereNotNull('fcm_token');
    //             })
    //                 ->orWhereHas('serviceman.user', function ($query) {
    //                     $query->whereNotNull('fcm_token');
    //                 });
    //         })
    //         ->with(['provider.owner', 'serviceman.user'])
    //         ->get();

    //     $this->info("Found " . $bookings->count() . " notifiable bookings");
    //     Log::info("Booking reminder checking " . $bookings->count() . " bookings with provider/serviceman FCM");

    //     $sentCount = 0;

    //     foreach ($bookings as $booking) {
    //         try {
    //             $minutesAgo = $booking->created_at->diffInMinutes($now);
    //             $hoursAgo = floor($minutesAgo / 60);
    //             $daysAgo = floor($minutesAgo / 1440);

    //             if ($daysAgo >= 30) {
    //                 $level = 'CRITICAL';
    //                 $title = "⚠️ CRITICAL: Booking pending for {$daysAgo} days";
    //                 $description = "Booking #{$booking->readable_id} has been pending since " .
    //                     $booking->created_at->format('M d, Y') .
    //                     ". Immediate action required!";
    //                 $cacheTTL = 86400 * 7;
    //             } elseif ($daysAgo >= 7) {
    //                 $level = 'URGENT';
    //                 $title = "🔴 URGENT: Booking pending for {$daysAgo} days";
    //                 $description = "Booking #{$booking->readable_id} has been pending since " .
    //                     $booking->created_at->format('M d, Y');
    //                 $cacheTTL = 86400 * 3;
    //             } elseif ($hoursAgo >= 24) {
    //                 $level = 'DAILY';
    //                 $title = "📋 Daily Reminder: Booking #{$booking->readable_id}";
    //                 $description = "This booking has been pending for {$daysAgo} day(s). Created: " .
    //                     $booking->created_at->format('M d, H:i');
    //                 $cacheTTL = 86400;
    //             } else {
    //                 $level = 'HOURLY';
    //                 $title = "⏰ Reminder: Booking pending for {$hoursAgo} hours";
    //                 $description = "Booking #{$booking->readable_id} is still pending. Please take action.";
    //                 $cacheTTL = 3600;
    //             }

    //             $cacheKey = "reminder:{$booking->id}:{$level}";
    //             $added = Cache::add($cacheKey, true, now()->addSeconds($cacheTTL));

    //             if (!$added) {
    //                 continue;
    //             }

    //             $sentThisBooking = 0;

    //             $providerFcm = $booking->provider?->owner?->fcm_token;
    //             if ($providerFcm) {
    //                 $cacheKey = "reminder:{$booking->id}:{$level}:provider";
    //                 $added = Cache::add($cacheKey, true, now()->addSeconds($cacheTTL));
    //                 if ($added) {
    //                     device_notification(
    //                         $providerFcm,
    //                         $title,
    //                         $description,
    //                         null,
    //                         $booking->parent_booking_id,
    //                         'booking_reminder',
    //                         null,
    //                         $booking->provider?->id,
    //                         null,
    //                         null,
    //                         null
    //                     );
    //                     $sentThisBooking++;
    //                     Log::info('Sent booking reminder to provider', [
    //                         'booking_id' => $booking->id,
    //                         'readable_id' => $booking->readable_id,
    //                         'level' => $level,
    //                         'minutes_old' => $minutesAgo,
    //                         'provider_id' => $booking->provider_id
    //                     ]);
    //                 }
    //             }

    //             $servicemanFcm = $booking->serviceman?->user?->fcm_token;
    //             if ($servicemanFcm) {
    //                 $cacheKey = "reminder:{$booking->id}:{$level}:serviceman";
    //                 $added = Cache::add($cacheKey, true, now()->addSeconds($cacheTTL));
    //                 if ($added) {
    //                     device_notification(
    //                         $servicemanFcm,
    //                         $title,
    //                         $description,
    //                         null,
    //                         $booking->parent_booking_id,
    //                         'booking_reminder',
    //                         null,
    //                         $booking->provider?->id,
    //                         null,
    //                         null,
    //                         null
    //                     );
    //                     $sentThisBooking++;
    //                     Log::info('Sent booking reminder to serviceman', [
    //                         'booking_id' => $booking->id,
    //                         'readable_id' => $booking->readable_id,
    //                         'level' => $level,
    //                         'minutes_old' => $minutesAgo,
    //                         'serviceman_id' => $booking->serviceman_id
    //                     ]);
    //                 }
    //             }

    //             if ($sentThisBooking === 0) {
    //                 Log::warning('No FCM token available for booking recipients', [
    //                     'booking_id' => $booking->id,
    //                     'provider_id' => $booking->provider_id,
    //                     'serviceman_id' => $booking->serviceman_id
    //                 ]);
    //                 continue;
    //             }

    //             $sentCount += $sentThisBooking;

    //             $this->info("Sent {$level} reminder for {$booking->readable_id} ({$minutesAgo} minutes old)");

    //             // Rate limit to avoid overwhelming
    //             if ($sentCount % 10 == 0) {
    //                 sleep(1);
    //             }
    //         } catch (\Exception $e) {
    //             Log::error('Failed to send booking reminder', [
    //                 'booking_id' => $booking->id,
    //                 'error' => $e->getMessage()
    //             ]);
    //         }
    //     }

    //     $this->info("Sent {$sentCount} reminders out of {$bookings->count()} notifiable bookings");
    //     Log::info("Booking reminder summary", ['sent' => $sentCount, 'total' => $bookings->count()]);

    //     return 0;
    // }
}
