<?php

namespace App\Http\Controllers;

use App\Model\Client\AudioMessage;

use App\Model\Client\TariffLabelValues;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AudioMessageController extends Controller
{


    /**
     * @OA\Get(
     *     path="/audio-message",
     *     summary="Get Audio Message list",
     *     tags={"AudioMessage"},
     *     security={{"Bearer": {}}},
     * *      @OA\Parameter(
     *         name="start",
     *         in="query",
     *         required=false,
     *         description="Start index for pagination",
     *         @OA\Schema(type="integer", default=0)
     *     ),
     *     @OA\Parameter(
     *         name="limit",
     *         in="query",
     *         required=false,
     *         description="Limit number of records returned",
     *         @OA\Schema(type="integer", default=10)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Success",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Audio message retrieved successfully.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Validation Error"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server Error"
     *     )
     * )
     */

    // public function list(Request $request)
    // {
    //     $audio_message = AudioMessage::on("mysql_" . $request->auth->parent_id)->get()->all();
    //     if ($request->has('start') && $request->has('limit')) {
    //         $start = (int)$request->input('start'); // Start index (0-based)
    //         $limit = (int)$request->input('limit'); // Limit number of records to fetch
    //         $audio_message = array_slice($audio_message, $start, $limit, false);
    //     }
    //     return $this->successResponse("Audio Message List", $audio_message);
    // }
    public function list(Request $request)
{
    $query = AudioMessage::on("mysql_" . $request->auth->parent_id);

    // 🔎 SEARCH (only if not empty)
    if ($request->filled('search')) {
        $search = $request->input('search');

        $query->where(function ($q) use ($search) {
            $q->where('ivr_id', 'LIKE', "%{$search}%")
              ->orWhere('ann_id', 'LIKE', "%{$search}%")
              ->orWhere('ivr_desc', 'LIKE', "%{$search}%")
              ->orWhere('speech_text', 'LIKE', "%{$search}%")
              ->orWhere('language', 'LIKE', "%{$search}%")
              ->orWhere('voice_name', 'LIKE', "%{$search}%");
        });
    }

    // 📊 TOTAL COUNT (before pagination)
    $total = $query->count();

    // 📌 PAGINATION
    if ($request->has('start') && $request->has('limit')) {

        $start = (int) $request->input('start');
        $limit = (int) $request->input('limit');

        $data = $query->offset($start)
                      ->limit($limit)
                      ->get();

        return $this->successResponse("Audio Message List", [
            'start' => $start,
            'limit' => $limit,
            'total' => $total,
            'data'  => $data
        ]);
    }

    // If no pagination
    $data = $query->get();

    return $this->successResponse("Audio Message List", [
        'total' => $total,
        'data'  => $data
    ]);
}

    public function list_old(Request $request)
    {
        $audio_message = AudioMessage::on("mysql_" . $request->auth->parent_id)->get()->all();
        return $this->successResponse("Audio Message List", $audio_message);
    }

    /**
     * @OA\Post(
     *     path="/add-audio-message",
     *     summary="Add a new audio message",
     *     tags={"AudioMessage"},
     *     security={{"Bearer": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="ann_id", type="string", example="101"),
     *             @OA\Property(property="ivr_id", type="string", example="5001"),
     *             @OA\Property(property="ivr_desc", type="string", example="Welcome IVR"),
     *             @OA\Property(property="language", type="string", example="en"),
     *             @OA\Property(property="voice_name", type="string", example="Joanna"),
     *             @OA\Property(property="speech_text", type="string", example="Thank you for calling."),
     *             @OA\Property(property="prompt_option", type="string", example="text"),
     *             @OA\Property(property="speed", type="string", example="medium"),
     *             @OA\Property(property="pitch", type="string", example="high")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Audio Message created successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Audio Message created"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="id", type="integer", example=5),
     *                 @OA\Property(property="ivr_id", type="string", example="5001"),
     *                 @OA\Property(property="ann_id", type="string", example="101"),
     *                 @OA\Property(property="ivr_desc", type="string", example="Welcome IVR"),
     *                 @OA\Property(property="language", type="string", example="en"),
     *                 @OA\Property(property="voice_name", type="string", example="Joanna"),
     *                 @OA\Property(property="speech_text", type="string", example="Thank you for calling."),
     *                 @OA\Property(property="prompt_option", type="string", example="text"),
     *                 @OA\Property(property="speed", type="string", example="medium"),
     *                 @OA\Property(property="pitch", type="string", example="high")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Validation Error"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server Error"
     *     )
     * )
     */

    public function addAudioMessage(Request $request)
    {
        $this->validate($request, ['ann_id' => 'string', 'ivr_id'   => 'string', 'ivr_desc'   => 'string']);

        $AudioMessage = new AudioMessage();
        $AudioMessage->setConnection("mysql_" . $request->auth->parent_id);
        $AudioMessage->ivr_id = $request->ivr_id;
        $AudioMessage->ann_id = $request->ann_id;
        $AudioMessage->ivr_desc = $request->ivr_desc;
        $AudioMessage->language = $request->language;
        $AudioMessage->voice_name = $request->voice_name;
        $AudioMessage->speech_text = $request->speech_text;
        $AudioMessage->prompt_option = $request->prompt_option;
        $AudioMessage->speed = $request->speed;
        $AudioMessage->pitch = $request->pitch;
        $AudioMessage->save();

        return $this->successResponse("Audio Message  created", $AudioMessage->toArray());
    }

    /**
     * @OA\Post(
     *     path="/edit-audio-message",
     *     summary="Edit Audio Message",
     *     description="Update an existing Audio Message by ID.",
     *     tags={"AudioMessage"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"auto_id"},
     *             @OA\Property(property="auto_id", type="integer", example=1),
     *             @OA\Property(property="ivr_id", type="string", example="ivr_123"),
     *             @OA\Property(property="ann_id", type="string", example="ann_456"),
     *             @OA\Property(property="ivr_desc", type="string", example="Main IVR for support"),
     *             @OA\Property(property="language", type="string", example="en"),
     *             @OA\Property(property="voice_name", type="string", example="Emma"),
     *             @OA\Property(property="speech_text", type="string", example="Welcome to our support line."),
     *             @OA\Property(property="prompt_option", type="string", example="press 1 for sales"),
     *             @OA\Property(property="speed", type="string", example="1.0"),
     *             @OA\Property(property="pitch", type="string", example="1.2")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Audio Message updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Audio Message updated"),
     *             @OA\Property(property="data", type="object", example={})
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Audio Message not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="No Audio Message with id 1"),
     *             @OA\Property(property="data", type="object", example={})
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to update Audio Message",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Failed to update Audio Message"),
     *             @OA\Property(property="data", type="object", example={})
     *         )
     *     )
     * )
     */

    public function ediAudioMessage(Request $request)
    {
        $this->validate($request, ['ann_id' => 'string', 'ivr_id'   => 'string', 'ivr_desc'   => 'string', 'auto_id'        => 'required|numeric']);

        try {
            $id = $request->auto_id;
            $input = [
                'ivr_id' => $request->ivr_id,
                'ann_id' => $request->ann_id,
                'ivr_desc' => $request->ivr_desc,
                'language' => $request->language,
                'voice_name' => $request->voice_name,
                'speech_text' => $request->speech_text,
                'prompt_option' => $request->prompt_option,
                'speed' => $request->speed,
                'pitch' => $request->pitch
            ];

            AudioMessage::on("mysql_" . $request->auth->parent_id)->where('id', $id)->update($input);
            return $this->successResponse("Audio Message updated");
        } catch (ModelNotFoundException $exception) {
            throw new NotFoundHttpException("No Audio Message with id $id");
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to update Audio Message ", [], $exception);
        }
    }
}
