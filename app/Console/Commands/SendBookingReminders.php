<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\BookingModule\Entities\Booking;

class SendBookingReminders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bookings:send-reminders';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send scheduled reminders for pending bookings at 30, 60 and 5 hours';

    public function handle()
    {
        $now = now();
        $sentCount = 0;

        // Only fetch relevant bookings (up to 3 days old max)
        Booking::where('booking_status', 'accepted')
            ->where('created_at', '>=', $now->copy()->subDays(3))
            ->with(['provider.owner', 'serviceman.user'])
            ->chunk(200, function ($bookings) use ($now, &$sentCount) {

                foreach ($bookings as $booking) {

                    try {
                        $minutes = $booking->created_at->diffInMinutes($now);

                        // Define exact trigger points
                        $levels = [
                            300  => '5_HOURS',
                            1800 => '30_HOURS',
                            3600 => '60_HOURS',
                        ];

                        foreach ($levels as $targetMinutes => $level) {

                            // Only trigger when crossing threshold
                            if ($minutes < $targetMinutes) {
                                continue;
                            }

                            // Prevent duplicate sends
                            $cacheKey = "booking:reminder:{$booking->id}:{$level}";

                            if (!Cache::add($cacheKey, true, now()->addDays(3))) {
                                continue;
                            }

                            // Message
                            $title = match ($level) {
                                '5_HOURS' => "⏰ Reminder: Booking pending 5 hours",
                                '30_HOURS' => "📋 Reminder: Booking pending 30 hours",
                                '60_HOURS' => "⚠️ Reminder: Booking pending 60 hours",
                            };

                            $description = "Booking #{$booking->readable_id} is still pending. Please take action.";

                            $sentThisBooking = 0;

                            // Provider
                            $providerFcm = $booking->provider?->owner?->fcm_token;

                            if ($providerFcm) {
                                $providerKey = $cacheKey . ":provider";

                                if (Cache::add($providerKey, true, now()->addDays(3))) {
                                    device_notification(
                                        $providerFcm,
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

                                    $sentThisBooking++;
                                }
                            }

                            // Serviceman
                            $servicemanFcm = $booking->serviceman?->user?->fcm_token;

                            if ($servicemanFcm) {
                                $servicemanKey = $cacheKey . ":serviceman";

                                if (Cache::add($servicemanKey, true, now()->addDays(3))) {
                                    device_notification(
                                        $servicemanFcm,
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

                                    $sentThisBooking++;
                                }
                            }

                            if ($sentThisBooking > 0) {
                                $sentCount += $sentThisBooking;

                                Log::info('Booking reminder sent', [
                                    'booking_id' => $booking->id,
                                    'level' => $level,
                                    'minutes' => $minutes
                                ]);
                            }
                        }
                    } catch (\Exception $e) {
                        Log::error('Booking reminder failed', [
                            'booking_id' => $booking->id ?? null,
                            'error' => $e->getMessage()
                        ]);
                    }
                }
            });

        $this->info("Total reminders sent: {$sentCount}");

        return 0;
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
