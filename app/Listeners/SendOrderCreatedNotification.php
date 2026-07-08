<?php

namespace App\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use App\Jobs\SendOrderReminderJob;

class SendOrderCreatedNotification implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle($event)
    {
        $order = $event->order;

        // Send immediate notification
        SendOrderReminderJob::dispatch(
            $order->id,
            0, // 0 indicates immediate notification
            true // isImmediate = true
        )->onQueue('order-reminders');
    }
}
