<?php

namespace App\Http\Controllers;

use App\Model\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PusherController extends Controller {

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    private $request;

    public function __construct(Request $request, User $user) {
        $this->request = $request;
        $this->model = $user;
    }
    
    /**
    * Get user id
    * Used in ivr mesu add / edit page
    * @return type
    */
    public function checkAndGetUserIdForPusher() {
        $platform = $to = $event = '';
        if ($this->request->has('to') && is_numeric($this->request->input('to'))) {
            $to = $this->request->input('to');
        }

        if ($this->request->has('platform')) {
            $platform = $this->request->input('platform');
        }
        if ($this->request->has('event')) {
            $event = $this->request->input('event');
        }

        if($platform == '' || $to == '') {
            return array(
                'success' => 'false',
                'message' => 'Both Platform and Did/extension are required fields',
                'data' => []
            );
        }
        $response = $this->model->checkAndGetUserIdForPusher($platform, $to, $event);
        return response()->json($response);
    }

}
