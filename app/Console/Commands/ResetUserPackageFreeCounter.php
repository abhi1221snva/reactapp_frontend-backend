<?php

namespace App\Console\Commands;

use App\Jobs\ResetUserPackageFreeCounterJob;
use Illuminate\Console\Command;

class ResetUserPackageFreeCounter extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:reset-user-package  {--clientId= : Command will only work for this client}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reset user_packages free counter & renew subscription of expired packages for all clients';

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
        $clientId = $this->option('clientId');

        if ($clientId) {
            $this->info("ResetUserPackageFreeCounterJob($clientId)");
            dispatch(new ResetUserPackageFreeCounterJob($clientId))->onConnection("reset_user_package");
        } else {
            $clients = \App\Model\Master\Client::all();
            foreach ( $clients as $client ) {
                $this->info("ResetUserPackageFreeCounterJob({$client->id})");
                dispatch(new ResetUserPackageFreeCounterJob($client->id))->onConnection("reset_user_package");
            }
        }
    }
}
