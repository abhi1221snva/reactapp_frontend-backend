<?php

namespace App\Http\Controllers;

use App\Model\Master\meeting;
use Illuminate\Http\Request;

//TODO: created this controller to authenticate the meeting code, if in future we are not really authenticating meeting code then remove this along with DB table
class MeetingsController extends Controller
{
    function verify(Request $request){
        try{
            $arrMeeting = Meeting::where('key', '=', $request->get('key'));
            if(empty($arrMeeting)){
                return $this->successResponse("Meeting not found", []);
            }
            return $this->successResponse("Meeting found", []);
        } catch (\Throwable $exception) {
            return $this->failResponse("Meeting not found", [$exception->getMessage()], $exception);
        }
    }

    function store(Request $request){
        try {
            $this->newMeeting([
                'key' => $request->get('key'),
                'from_id' => $request->get('from_id'),
                'message_id' =>$request->get('message_id')
            ]);
            return $this->successResponse("Meeting Stored", []);
        } catch (\Throwable $exception) {
            return $this->failResponse("Meeting fail to store", [$exception->getMessage()], $exception);
        }
    }

    public function newMeeting($data){
        $objMeeting = new Meeting();
        $objMeeting->key = $data['key'];
        $objMeeting->from_id = $data['from_id'];
        $objMeeting->message_id = $data['message_id'];
        $objMeeting->save();
    }
}
