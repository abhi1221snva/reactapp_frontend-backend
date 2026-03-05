<?php


namespace App\Model\Master;

use App\Exceptions\RenderableException;
use App\Jobs\SendIpWhitelistNotification;
use App\Jobs\UpdateIpLocation;
use App\Model\Client\ExtensionLive;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use phpseclib\Crypt\RSA;
use phpseclib\Net\SSH2;

class AsteriskServer extends Model
{
    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    protected $connection = 'master';

    protected $table = "asterisk_server";

    public static function list()
    {
        $asteriskServerList = [];
        $servers = self::all();
        foreach ( $servers as $server ) {
            array_push($asteriskServerList, $server->toArray());
        }
        return $asteriskServerList;
    }

    public function whiteListIp(string $ip, int $userId, int $clientId, int $addedBy=null)
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) return false;
        if ($this->isIpWhitelisted($ip, $userId)) return true;

        if (app()->environment()==="local"){
            $output = "ACCEPT";
        } else {
            $ssh = new SSH2($this->host, $this->ssh_port);
            // Load your private key
            $key = new RSA();
            $key->loadKey(env("TEL_PRIVATE_KEY"));
            if (!$ssh->login('root', $key)) {
                throw new RenderableException("Failed to whitelist client IP $ip on server " . $this->host . " Unable to login.");
            }
            $output = $ssh->exec("csf -a " . escapeshellarg($ip));
        }

        if (preg_match("/ACCEPT/", $output) || preg_match("/already in the allow file/", $output)) {
            #save in ip_whitelists
            $ipWhiteList = new IpWhiteList();
            $ipWhiteList->server_ip = $this->host;
            $ipWhiteList->whitelist_ip = $ip;
            $ipWhiteList->client_id = $clientId;
            $ipWhiteList->approval_status = 1;
            if ($userId) {
                $ipWhiteList->last_login_user = $userId;
                $ipWhiteList->last_login_at = Carbon::now();
            }
            if ($addedBy) {
                $ipWhiteList->from_web = 1;
                $ipWhiteList->updated_by = $addedBy;
            }
            $ipWhiteList->saveOrFail();

            $updateJob = new UpdateIpLocation($clientId, $this->host, $ip);
            $updateJob->chain([
                new SendIpWhitelistNotification($clientId, $this->host, $ip, "IP address whitelisted on {$this->host}")
            ]);
            dispatch($updateJob)->onConnection("database");

            return true;
        } else {
            Log::info("AsteriskServer.whiteListIp", [
                "ip" => $ip,
                "userId" => $userId,
                "clientId" => $clientId,
                "output" => $output
            ]);
        }

        return false;
    }

    private function isIpWhitelisted(string $ip, int $userId):bool
    {
        #First check if IP exists in ip_whitelists
        $ipWhiteList = IpWhiteList::find([
            "server_ip" => $this->host,
            "whitelist_ip" => $ip
        ]);
        if ($ipWhiteList) {
            if ($userId) {
                $ipWhiteList->last_login_user = $userId;
                $ipWhiteList->last_login_at = Carbon::now();
                $ipWhiteList->save();
            }
            return true;
        }
        return false;
    }

    public function requestIpWhitelist(string $ip, int $userId, int $clientId)
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) return false;
        if ($this->isIpWhitelisted($ip, $userId)) return true;

        #save in ip_whitelists
        $ipWhiteList = new IpWhiteList();
        $ipWhiteList->server_ip = $this->host;
        $ipWhiteList->whitelist_ip = $ip;
        $ipWhiteList->from_web = 1;
        $ipWhiteList->client_id = $clientId;
        $ipWhiteList->last_login_user = $userId;
        $ipWhiteList->last_login_at = Carbon::now();
        $ipWhiteList->saveOrFail();

        $updateJob = new UpdateIpLocation($clientId, $this->host, $ip);
        $updateJob->chain([
            new SendIpWhitelistNotification($clientId, $this->host, $ip, "IP whitelisting pending for approval")
        ]);
        dispatch($updateJob)->onConnection("database");

        return true;
    }


    public function hangupConferences(int $clientId, string $extension)
    {
        // Validate: extensions are numeric (4-6 digits)
        if (!preg_match('/^\d{1,10}$/', $extension)) {
            throw new RenderableException("Invalid extension format: $extension");
        }

        if (app()->environment()==="local")
        {
            $output = "Hangup on channel";
        }
        else
        {
            $ssh = new SSH2($this->host, $this->ssh_port);
            // Load your private key
            $key = new RSA();
            $key->loadKey(env("TEL_PRIVATE_KEY"));
            if (!$ssh->login('root', $key)) {
                throw new RenderableException("Failed to clean conferences for $extension on server " . $this->host . " Unable to login.");
            }
            //sh /tmp/reset_conf.sh 89999
            $output = $ssh->exec("sh /tmp/reset_conf.sh " . escapeshellarg($extension));
        }
        if (preg_match("/Hangup on channel/", $output))
        {
            $deleteExtension = ExtensionLive::on("mysql_$clientId")->where('extension',$extension)->delete();
            return true;
        }
        return false;
    }
}
