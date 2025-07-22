<?php
namespace App\Http\Controllers;

use App\Model\Client\LiveConference;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
class LiveConferencingController extends Controller
{

    /**
 * @OA\Get(
 *     path="/live-conference",
 *     summary="Get list of live conferences",
 *     tags={"Conferencing"},
 *     security={{"Bearer": {}}},
 *     @OA\Response(
 *         response=200,
 *         description="List of live conferences",
 *         @OA\JsonContent(
 *             @OA\Property(property="success", type="string", example="true"),
 *             @OA\Property(property="message", type="string", example="LiveConference List"),
 *             @OA\Property(property="data", type="array", @OA\Items(
 *                 @OA\Property(property="id", type="integer", example=1),
 *                 @OA\Property(property="title", type="string", example="Live Conference 1"),
 *                 @OA\Property(property="conference_id", type="string", example="conf12345"),
 *                 @OA\Property(property="host_pin", type="string", example="123456"),
 *                 @OA\Property(property="part_pin", type="string", example="654321"),
 *                 @OA\Property(property="max_part", type="integer", example=100),
 *                 @OA\Property(property="locked", type="boolean", example=false),
 *                 @OA\Property(property="mute", type="boolean", example=true),
 *                 @OA\Property(property="prompt_file", type="string", example="path/to/prompt/file"),
 *                 @OA\Property(property="speech_text", type="string", example="Welcome to the conference"),
 *                 @OA\Property(property="prompt_option", type="string", example="Option 1"),
 *                 @OA\Property(property="language", type="string", example="en"),
 *                 @OA\Property(property="voice_name", type="string", example="Voice 1")
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
        $liveConference = LiveConference::on("mysql_" . $request->auth->parent_id)->get()->all();
        return $this->successResponse("LiveConference List", $liveConference);
    }
   
}
