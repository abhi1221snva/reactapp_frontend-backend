<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Model\User;
use Illuminate\Support\Str;

class BackfillPusherUuid extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'user:backfill-pusher-uuid';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backfill pusher UUID for existing users';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->info('Starting backfill of pusher_uuid...');

        // Use chunk to handle large datasets effectively
        User::whereNull('pusher_uuid')
            ->orWhere('pusher_uuid', '')
            ->chunkById(100, function ($users) {
                foreach ($users as $user) {
                    $user->pusher_uuid = (string) Str::uuid();
                    $user->save();
                }
                $this->info("Processed batch of users...");
            });

        $this->info("Backfill completed.");
    }
}
