<?php
namespace App\Http\Controllers;

use App\Model\Client\ConferenceRecording;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
class ConferenceRecordingController extends Controller
{
 
    /**
 * @OA\Get(
 *     path="/recording-conference",
 *     summary="Get list of conference recordings",
 *     tags={"Conferencing"},
 *     security={{"Bearer": {}}},
 *     @OA\Response(
 *         response=200,
 *         description="List of conference recordings",
 *         @OA\JsonContent(
 *             @OA\Property(property="success", type="string", example="true"),
 *             @OA\Property(property="message", type="string", example="ConferenceRecording List"),
 *             @OA\Property(property="data", type="array", @OA\Items(
 *                 @OA\Property(property="id", type="integer", example=1),
 *                 @OA\Property(property="conference_id", type="string", example="conf12345"),
 *                 @OA\Property(property="recording_url", type="string", example="https://example.com/recording/12345"),
 *                 @OA\Property(property="duration", type="integer", example=3600),
 *                 @OA\Property(property="start_time", type="string", format="date-time", example="2025-04-20T10:00:00Z"),
 *                 @OA\Property(property="end_time", type="string", format="date-time", example="2025-04-20T11:00:00Z"),
 *                 @OA\Property(property="status", type="string", example="completed"),
 *                 @OA\Property(property="created_at", type="string", format="date-time", example="2025-04-20T09:00:00Z"),
 *                 @OA\Property(property="updated_at", type="string", format="date-time", example="2025-04-20T09:30:00Z")
 *             ))
 *         )
 *     ),
 *     @OA\Response(
 *         response=500,
 *         description="Internal server error"
 *     )
 * )
 */

	public function index(Request $request)
    {
        $ConferenceRecording = ConferenceRecording::on("mysql_" . $request->auth->parent_id)->get()->all();
        return $this->successResponse("ConferenceRecording List", $ConferenceRecording);
    }
   
}
