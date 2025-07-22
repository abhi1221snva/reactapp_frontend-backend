<?php

use Illuminate\Database\Seeder;

class AsteriskServerUpdater extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $asteriskServer = \App\Model\Master\AsteriskServer::find(1);
        $asteriskServer->location = "Montreal";
        $asteriskServer->save();

        $asteriskServer = \App\Model\Master\AsteriskServer::find(2);
        $asteriskServer->location = "New York";
        $asteriskServer->save();

        $asteriskServer = \App\Model\Master\AsteriskServer::find(3);
        $asteriskServer->location = "New York";
        $asteriskServer->save();

        $asteriskServer = \App\Model\Master\AsteriskServer::find(4);
        $asteriskServer->location = "New York";
        $asteriskServer->save();
    }
}
