<?php

namespace App\Http\Controllers;
use App\Model\Conferencing;
use Illuminate\Http\Request;
class ConferencingController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    private $request;
    public function __construct(Request $request, Conferencing $conferencing)
    {
        $this->request = $request;
        $this->model = $conferencing;
    }

    /*
     * Fetch Dnc details
     * @return json
     */

     /**
 * @OA\Post(
 *     path="/conferencing",
 *     summary="Get conferencing details",
 *     tags={"Conferencing"},
 *     security={{"Bearer": {}}},
 *     @OA\Parameter(
 *         name="auto_id",
 *         in="query",
 *         required=false,
 *         description="Auto ID of the conferencing record",
 *         @OA\Schema(type="integer", example=1)
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="Conferencing data detail",
 *         @OA\JsonContent(
 *             @OA\Property(property="success", type="string", example="true"),
 *             @OA\Property(property="message", type="string", example="Conferencing Data detail."),
 *             @OA\Property(
 *                 property="data",
 *                 type="array",
 *                 @OA\Items(
 *                     type="object",
 *                     @OA\Property(property="id", type="integer", example=1),
 *                     @OA\Property(property="title", type="string", example="Team Sync Meeting"),
 *                     @OA\Property(property="description", type="string", example="Weekly team sync-up call."),
 *                     @OA\Property(property="start_time", type="string", format="date-time", example="2025-06-01T10:00:00Z"),
 *                     @OA\Property(property="end_time", type="string", format="date-time", example="2025-06-01T11:00:00Z")
 *                 )
 *             )
 *         )
 *     ),
 *     @OA\Response(
 *         response=404,
 *         description="Conferencing data not found",
 *         @OA\JsonContent(
 *             @OA\Property(property="success", type="string", example="false"),
 *             @OA\Property(property="message", type="string", example="Conferencing Data not created."),
 *             @OA\Property(property="data", type="array", @OA\Items(type="object"))
 *         )
 *     ),
 *     @OA\Response(
 *         response=500,
 *         description="Internal server error"
 *     )
 * )
 */

    public function getConferencing()
    {


        $response = $this->model->getConferencing($this->request);
        return response()->json($response);
    }

/**
 * @OA\Post(
 *     path="/add-conferencing",
 *     summary="Add a new conference",
 *     tags={"Conferencing"},
 *     security={{"Bearer": {}}},
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\JsonContent(
 *             required={"title", "conference_id"},
 *             @OA\Property(property="title", type="string", example="Daily Standup Meeting"),
 *             @OA\Property(property="conference_id", type="string", example="conf123"),
 *             @OA\Property(property="host_pin", type="string", example="1234"),
 *             @OA\Property(property="part_pin", type="string", example="5678"),
 *             @OA\Property(property="max_part", type="integer", example="50"),
 *             @OA\Property(property="locked", type="boolean", example=0),
 *             @OA\Property(property="mute", type="boolean", example=1),
 *             @OA\Property(property="prompt", type="string", example="welcome_prompt.mp3"),
 *             @OA\Property(property="speech_text", type="string", example="Welcome to the conference"),
 *             @OA\Property(property="prompt_option", type="string", example="text_to_speech"),
 *             @OA\Property(property="language", type="string", example="en-US"),
 *             @OA\Property(property="voice_name", type="string", example="Joanna")
 *         )
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="Conference added successfully",
 *         @OA\JsonContent(
 *             @OA\Property(property="success", type="string", example="true"),
 *             @OA\Property(property="message", type="string", example="Conference added successfully.")
 *         )
 *     ),
 *     @OA\Response(
 *         response=400,
 *         description="Bad request or missing required fields",
 *         @OA\JsonContent(
 *             @OA\Property(property="success", type="string", example="false"),
 *             @OA\Property(property="message", type="string", example="Conference are not added successfully.")
 *         )
 *     ),
 *     @OA\Response(
 *         response=500,
 *         description="Internal server error"
 *     )
 * )
 */

    public function addConferencing()
    {
        $this->validate($this->request, [
            'title'=> 'string',
            'conference_id' => 'string',
            'host_pin'   => 'string',
            'part_pin'   => 'string',
            'max_part'   => 'string',
            'locked'   => 'numeric',
            'mute'   => 'numeric',
        ]);


        $response = $this->model->addConferencing($this->request);
        return response()->json($response);
    }

/**
 * @OA\Post(
 *     path="/edit-conferencing",
 *     summary="Edit existing conferencing data",
 *     tags={"Conferencing"},
 *     security={{"Bearer": {}}},
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\JsonContent(
 *             required={"auto_id"},
 *             @OA\Property(property="auto_id", type="integer", example=1, description="ID of the conference to update"),
 *             @OA\Property(property="title", type="string", example="Weekly Sync Meeting"),
 *             @OA\Property(property="conference_id", type="string", example="conf456"),
 *             @OA\Property(property="host_pin", type="string", example="1111"),
 *             @OA\Property(property="part_pin", type="string", example="2222"),
 *             @OA\Property(property="max_part", type="integer", example=100),
 *             @OA\Property(property="locked", type="integer", example=1),
 *             @OA\Property(property="mute", type="integer", example=0),
 *             @OA\Property(property="prompt", type="string", example="new_prompt.mp3"),
 *             @OA\Property(property="speech_text", type="string", example="Welcome back!"),
 *             @OA\Property(property="prompt_option", type="string", example="audio_file"),
 *             @OA\Property(property="language", type="string", example="en-GB"),
 *             @OA\Property(property="voice_name", type="string", example="Brian")
 *         )
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="Conferencing updated successfully",
 *         @OA\JsonContent(
 *             @OA\Property(property="success", type="string", example="true"),
 *             @OA\Property(property="message", type="string", example="Conferencing updated successfully.")
 *         )
 *     ),
 *     @OA\Response(
 *         response=400,
 *         description="Bad request or conference not found",
 *         @OA\JsonContent(
 *             @OA\Property(property="success", type="string", example="false"),
 *             @OA\Property(property="message", type="string", example="Conferencing doesn't exist.")
 *         )
 *     ),
 *     @OA\Response(
 *         response=500,
 *         description="Internal server error"
 *     )
 * )
 */

     public function editConferencing()
    {

        $response = $this->model->editConferencing($this->request);
        return response()->json($response);
    }

 /**
 * @OA\Post(
 *     path="/delete-conferencing",
 *     summary="Delete a conferencing record",
 *     tags={"Conferencing"},
 *     security={{"Bearer": {}}},
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\JsonContent(
 *             required={"auto_id"},
 *             @OA\Property(property="auto_id", type="integer", example=1, description="ID of the conference to delete")
 *         )
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="Conference deleted successfully",
 *         @OA\JsonContent(
 *             @OA\Property(property="success", type="string", example="true"),
 *             @OA\Property(property="message", type="string", example="Conferencing Id deleted successfully.")
 *         )
 *     ),
 *     @OA\Response(
 *         response=400,
 *         description="Conference not found or invalid request",
 *         @OA\JsonContent(
 *             @OA\Property(property="success", type="string", example="false"),
 *             @OA\Property(property="message", type="string", example="Conferencing doesn't exist.")
 *         )
 *     ),
 *     @OA\Response(
 *         response=500,
 *         description="Internal server error"
 *     )
 * )
 */
     public function deleteConferencing()
    {
        $this->validate($this->request, [

            'auto_id'        => 'required|numeric'
        ]);
        $response = $this->model->deleteConferencing($this->request);
        return response()->json($response);
    }
}
