<?php

namespace App\Http\Controllers;

use App\Model\User;
use App\Model\UserFcmToken;
use App\Services\ApnsVoipService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class VoipPushController extends Controller
{
    /**
     * @OA\Post(
     *     path="/voip-push/trigger",
     *     summary="Trigger a VoIP push notification to a user's iOS device",
     *     description="Sends an APNs VoIP push that invokes CallKit on the user's device, displaying an incoming-call screen.",
     *     tags={"Notification"},
     *     security={{"Bearer": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"user_id", "caller_number", "caller_name"},
     *             @OA\Property(property="user_id", type="integer", example=73, description="Target user ID"),
     *             @OA\Property(property="caller_number", type="string", example="+15555550100", description="Caller phone number"),
     *             @OA\Property(property="caller_name", type="string", example="John Doe", description="Caller display name"),
     *             @OA\Property(property="call_uuid", type="string", example="550e8400-e29b-41d4-a716-446655440000", description="Optional call UUID")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="VoIP push sent successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="VoIP push sent"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(response=404, description="No VoIP token found for user"),
     *     @OA\Response(response=422, description="Validation error"),
     *     @OA\Response(response=502, description="APNs delivery failed")
     * )
     */
    public function trigger(Request $request)
    {
        $this->validate($request, [
            'user_id'       => 'required|integer|exists:master.users,id',
            'caller_number' => 'required|string|max:30',
            'caller_name'   => 'nullable|string|max:100',
            'call_uuid'     => 'nullable|string|max:64',
        ]);

        $userId       = (int) $request->input('user_id');
        $callerNumber = $request->input('caller_number');
        $callerName   = $request->input('caller_name', $callerNumber);
        $callUuid     = $request->input('call_uuid');

        // Look up the user's VoIP device token
        $tokenRow = UserFcmToken::where('user_id', $userId)
            ->where('device_type', 'ios-voip')
            ->first();

        if (!$tokenRow) {
            return $this->failResponse(
                'No VoIP device token registered for this user',
                ['user_id' => $userId],
                null,
                404
            );
        }

        $deviceToken = $tokenRow->device_token;

        // Build the CallKit payload (resolves caller name from CRM if needed)
        $payload = ApnsVoipService::buildCallPayload($callerNumber, $callerName, $callUuid, $userId);

        Log::info('VoIP push trigger', [
            'user_id'      => $userId,
            'caller'       => $callerNumber,
            'device_token' => substr($deviceToken, 0, 12) . '...',
        ]);

        // Send via APNs
        $result = ApnsVoipService::send($deviceToken, $payload);

        if ($result['success']) {
            return $this->successResponse('VoIP push sent', [
                'user_id'  => $userId,
                'uuid'     => $payload['uuid'],
                'apns'     => $result,
            ]);
        }

        // If APNs returned 410 Gone or 400 BadDeviceToken, clean up the stale token
        if (in_array($result['status_code'], [400, 410])) {
            $tokenRow->delete();
            Log::info('Deleted stale VoIP token', ['user_id' => $userId]);
        }

        return $this->failResponse(
            'APNs delivery failed',
            ['apns' => $result],
            null,
            502
        );
    }

    /**
     * Dialplan-friendly endpoint — no JWT, uses PREDICTIVE_CALL_TOKEN.
     * Looks up the user by extension number.
     *
     * GET /voip-push/dial?extension=5001&caller_number=9025551234&caller_name=John&token=...
     */
    public function dial(Request $request)
    {
        $token = $request->input('token');
        if ($token !== env('PREDICTIVE_CALL_TOKEN')) {
            return response()->json(['success' => false, 'message' => 'Invalid token'], 403);
        }

        $ext          = $request->input('extension');
        $callerNumber = $request->input('caller_number', 'Unknown');
        $callerName   = $request->input('caller_name', $callerNumber);

        if (!$ext) {
            return response()->json(['success' => false, 'message' => 'extension is required'], 422);
        }

        // Find user by extension, alt_extension, or app_extension
        $user = User::where('extension', $ext)
            ->orWhere('alt_extension', $ext)
            ->orWhere('app_extension', $ext)
            ->first();

        if (!$user) {
            return response()->json(['success' => false, 'message' => 'No user found for extension ' . $ext], 404);
        }

        $tokenRow = UserFcmToken::where('user_id', $user->id)
            ->where('device_type', 'ios-voip')
            ->first();

        if (!$tokenRow) {
            return response()->json([
                'success' => false,
                'message' => 'User has no VoIP token (not logged in on mobile app)',
                'user_id' => $user->id,
            ], 404);
        }

        $payload = ApnsVoipService::buildCallPayload($callerNumber, $callerName, null, $user->id);
        $result  = ApnsVoipService::send($tokenRow->device_token, $payload);

        if ($result['success']) {
            Log::info('VoIP push sent via dialplan', [
                'extension' => $ext, 'user_id' => $user->id, 'caller' => $callerNumber,
            ]);
            return response()->json([
                'success' => true,
                'message' => 'VoIP push sent',
                'user_id' => $user->id,
                'uuid'    => $payload['uuid'],
            ]);
        }

        if (in_array($result['status_code'], [400, 410])) {
            $tokenRow->delete();
        }

        return response()->json(['success' => false, 'message' => 'APNs failed', 'apns' => $result], 502);
    }
}
