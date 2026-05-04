<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Artisan;

class MyCronJob extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:my-cron-job';

    protected $description = 'Run storage link command';

    public function handle()
    {
        $link = public_path('storage');

        // 1️⃣ Remove existing storage link if it exists
        if (file_exists($link)) {
            rmdir($link); // folder ya symlink remove karega
            $this->info('Old storage link removed.');
            Log::info('Old storage link removed.'); // agar symlink hai
            // ya agar directory hai to rmdir($link)
            // }
        }

        Artisan::call('storage:link');
        // 2️⃣ Create new storage link
        $this->info('New storage link created successfully.');
        Log::info('New storage link created successfully.');
    }
}
