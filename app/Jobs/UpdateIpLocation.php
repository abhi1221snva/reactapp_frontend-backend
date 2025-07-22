<?php

namespace App\Jobs;

use App\Model\Master\IpWhiteList;

class UpdateIpLocation extends Job
{
    private $clientId;

    private $serverIp;

    private $whitelistIp;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(int $clientId, string $serverIp, string $whitelistIp)
    {
        $this->clientId = $clientId;
        $this->serverIp = $serverIp;
        $this->whitelistIp = $whitelistIp;
        $this->connection = "database";
        $this->chainConnection = "database";
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $ipInfo = geoip($this->whitelistIp);
        if ($ipInfo) {
            $ipWhitelist = IpWhiteList::find(["server_ip" => $this->serverIp, "whitelist_ip"=>$this->whitelistIp]);
            $ipWhitelist->ip_location = $ipInfo["city"]. ", " .$ipInfo["country"];
            $ipWhitelist->save();
        }
    }
}
