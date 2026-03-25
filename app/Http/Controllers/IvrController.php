<?php

namespace App\Http\Controllers;

use App\Model\Ivr;
use Illuminate\Http\Request;

class IvrController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    private $request;
    public function __construct(Request $request, Ivr $ivr)
    {
        $this->request = $request;
        $this->model = $ivr;
    }

    /*
     * Fetch Dnc details
     * @return json
     */

    /**
     * @OA\Post(
     *     path="/ivr",
     *     summary="Get IVR details",
     *     security={{"Bearer":{}}},
     *     tags={"IVR"},
     * @OA\RequestBody(
     *         required=false,
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(
     *                 property="start",
     *                 type="integer",
     *                 default=0,
     *                 description="Start index for pagination"
     *             ),
     *             @OA\Property(
     *                 property="limit",
     *                 type="integer",
     *                 default=10,
     *                 description="Limit number of records returned"
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="IVR detail retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="IVR detail"),
     *             @OA\Property(property="data", type="integer", example=42)
     *         )
     *     )
     * )
     */
    public function getIvr()
    {
        $response = $this->model->ivrDetail($this->request);
        return response()->json($response);
    }
    /*
     * Update Dnc detail
     * @return json
     */

    /**
     * @OA\Post(
     *     path="/edit-ivr",
     *     summary="Update IVR details",
     *     description="Updates IVR configuration data by ID",
     *     tags={"IVR"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"auto_id"},
     *             @OA\Property(property="auto_id", type="integer", example=1),
     *             @OA\Property(property="ivr_id", type="string", example="ivr_101"),
     *             @OA\Property(property="ann_id", type="string", example="ann_202"),
     *             @OA\Property(property="ivr_desc", type="string", example="Main IVR"),
     *             @OA\Property(property="language", type="string", example="en-US"),
     *             @OA\Property(property="voice_name", type="string", example="Joanna"),
     *             @OA\Property(property="speech_text", type="string", example="Welcome to our service."),
     *             @OA\Property(property="prompt_option", type="string", example="1"),
     *             @OA\Property(property="speed", type="string", example="medium"),
     *             @OA\Property(property="pitch", type="string", example="high")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="IVR updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Ivr updated successfully.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Bad request or missing parameters"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error"
     *     )
     * )
     */

    public function editIvr()
    {
        $this->validate($this->request, [
            'ann_id' => 'string',
            'ivr_id'   => 'string',
            'ivr_desc'   => 'string',

            // 'id'        => 'required|numeric'
        ]);
        $response = $this->model->ivrUpdate($this->request);
        return response()->json($response);
    }
    /*
     *Add Dnc details
     *@return json
     */
    /**
     * @OA\Post(
     *     path="/add-ivr",
     *     summary="Add IVR detail",
     *     description="Adds a new IVR entry with description, announcement, speech, and prompt settings.",
     *     tags={"IVR"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"ivr_id", "ann_id", "ivr_desc", "language", "voice_name", "speech_text", "prompt_option", "speed", "pitch"},
     *             @OA\Property(property="ivr_id", type="string", example="ivr-001"),
     *             @OA\Property(property="ann_id", type="string", example="ann_123"),
     *             @OA\Property(property="ivr_desc", type="string", example="Main menu IVR"),
     *             @OA\Property(property="language", type="string", example="en"),
     *             @OA\Property(property="voice_name", type="string", example="Joanna"),
     *             @OA\Property(property="speech_text", type="string", example="Welcome to our company. Press 1 for sales, 2 for support."),
     *             @OA\Property(
     *                 property="prompt_option",
     *                 type="integer",
     *                 enum={0, 1, 2},
     *                 example=1,
     *                 description="Prompt type: 0 = Upload, 1 = Text to Speech, 2 = Record"
     *             ),
     *             @OA\Property(property="speed", type="string", example="medium"),
     *             @OA\Property(property="pitch", type="string", example="high")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="IVR added successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Ivr added successfully.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid input or missing parameters"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server error"
     *     )
     * )
     */

    // public function addIvr()
    // {
    //     $this->validate($this->request, [
    //         'ann_id' => 'string',
    //         'ivr_id'   => 'string',
    //         'ivr_desc'   => 'string',

    //         // 'id'        => 'required|numeric'
    //     ]);
    //     $response = $this->model->addIvr($this->request);
    //     return response()->json($response);
    // }
    public function addIvr()
{
    // ✅ Validation (auto 422)
    $this->validate($this->request, [
        'ivr_id'   => 'required|string',
        'ann_id'   => 'nullable|string',
        'ivr_desc' => 'required|string',
    ]);

    $response = $this->model->addIvr($this->request);

    // ❌ Model-level failure
    if ($response['success'] === false) {
        return response()->json($response, 400);
    }

    // ✅ Success
    return response()->json($response, 201);
}

    /*
     *Delete Dnc
     *@return json
     */

    /**
     * @OA\Post(
     *     path="/delete-ivr",
     *     summary="Delete IVR detail",
     *     description="Deletes the IVR entry by auto_id",
     *     tags={"IVR"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"auto_id"},
     *             @OA\Property(property="auto_id", type="integer", example=1)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="IVR detail deleted successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="IVR deleted successfully"),
     *             @OA\Property(property="data", type="integer", example=42)
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Bad request or missing auto_id"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error"
     *     )
     * )
     */
    public function deleteIvr()
    {
        $this->validate($this->request, [

            'auto_id'        => 'required|numeric'
        ]);
        $response = $this->model->ivrDelete($this->request);
        return response()->json($response);
    }
}
