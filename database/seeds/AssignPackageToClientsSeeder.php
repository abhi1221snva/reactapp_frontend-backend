<?php

use App\Model\Master\Client;
use Illuminate\Database\Seeder;

class AssignPackageToClientsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $package = \App\Model\Master\Package::where("name", "Premium")->first();
        if (!empty($package)) {
            $clients = Client::all();
            foreach ( $clients as $client ) {
                echo "Running for client {$client->id}: {$client->company_name}\n";
                \App\Services\PackageService::assignPackageToClientUsers($client, $package, 100, \Illuminate\Support\Carbon::now()->addYears(10));
            }
        } else {
            echo "No trial package found in the system\n";
        }
    }
}
