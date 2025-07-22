<?php
namespace App\Http\Controllers;
use App\Model\Master\RinglessVoiceMail;
use App\Model\Master\RvmDomainList;
use App\Model\Master\RvmCdrLog;
use App\Model\Master\SipGateway\SipGateways;


use App\Model\Master\Client;
use App\Model\Master\UserExtension;
use App\Model\Master\RvmCallbackConfiguration;

use App\Jobs\SendRvmJob;
use App\Model\Master\RvmQueueList;

use App\Jobs\RinglessVoicemailDropBySipName;

use DateTime;
use DateTimeZone;
use Illuminate\Http\Request;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;




class RinglessVMNotSuccessController extends Controller
{
    public function notSuccess(Request $request)
    {
        $this->validate($request, ['date' => 'required']);

    }
    
 


}
