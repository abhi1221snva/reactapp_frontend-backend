<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

use Session;
use App\Helper\Helper;
use Illuminate\Http\Request;
use App\Events\IncomingLead;
use Google\Cloud\TextToSpeech\V1\AudioConfig;
use Google\Cloud\TextToSpeech\V1\AudioEncoding;
use Google\Cloud\TextToSpeech\V1\SsmlVoiceGender;
use Google\Cloud\TextToSpeech\V1\SynthesisInput;
use Google\Cloud\TextToSpeech\V1\TextToSpeechClient;
use Google\Cloud\TextToSpeech\V1\VoiceSelectionParams;
use App\Model\VoiceTemplate;
use Illuminate\Support\Facades\DB;
use App\Model\Client\Campaign;
use App\Model\Client\CampaignList;
use App\Model\Client\ListData;
use App\Model\Client\LeadTemp;
use App\Model\Dialer;
use App\Model\Client\CustomFieldLabelsValues;
use App\Model\Client\Label;
use App\Model\Client\Did;

use App\Model\Client\ListHeader;
use App\Model\Cron;

class CnamReportCron extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cnam:cron';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

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
        $clients = \App\Model\Master\Client::all();
        //echo "<pre>";print_r($clients);die;
        
        foreach ( $clients as $client )
        {
            $clientId = $client['id'];
            $i=0;
            $did = Did::on("mysql_" . $clientId)->where('is_deleted','0')->get()->all();
            
            foreach($did as $did_call)
            {
                $cli = $did_call['cli'];
                $content = "Channel: PJSIP/Airespring1/#135196219859805718\nCallerId: $cli\nContext: callfile-detect\nExtension: s\nPriority: 1\n";
                $file_name = $cli;
                $file = fopen($file_name.".call", 'w');
                
                fwrite($file, $content);
                $rootPath = '/var/www/html/branch/backend/';
                $convertedFilename = $rootPath . $file_name . ".call";
                $strAsteriskPath = "root@sip1.voiptella.com:/var/spool/asterisk/outgoing/";
               // $strAsteriskPath = "root@sip1.domain.com:/var/spool/asterisk/audio/audio_message/";

                shell_exec("scp -P 10347 " . escapeshellarg($convertedFilename) . " " . escapeshellarg($strAsteriskPath));

                $i++;

                if($i == 10 || $i == 20 || $i == 30 || $i == 40 || $i ==50 || $i == 60 || $i ==70 || $i ==80)
                {
                    sleep(15);
                }

                echo $file_name . ".call".'<br>';

                $path=$rootPath.$file_name . ".call";

                if(unlink($path)){
                } 
            }
        }    
    }
}
