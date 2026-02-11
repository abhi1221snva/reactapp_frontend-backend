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

    /**
     * Trigger a test Pusher event via PusherService
     */
    public function triggerTest() {
        try {
            // Validate basic requirements if needed, or rely on Service
            $parentId = $this->request->input('parent_id') ?? 1;
            $pusherUuid = $this->request->input('pusher_uuid');

            // Set up request object properties that PusherService expects
            $this->request->merge(['parent_id' => $parentId]);
            
            // Allow mimicking auth object for test purpose
            $this->request->auth = (object) [
                'parent_id' => $parentId,
                'pusher_uuid' => $pusherUuid
            ];
            
            // Call the service which now contains the cURL logic
            $response = \App\Services\PusherService::notify($this->request, [
                'module' => 'controller-test',
                'message' => 'Test message from PusherController',
                'timestamp' => date('Y-m-d H:i:s')
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Pusher event triggered successfully via Controller -> Service',
                'channel' => "dashboard-{$parentId}",
                'event' => $pusherUuid ? "dashboard-notification{$pusherUuid}" : "dashboard-notification",
                'pusher_response' => $response
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

}
