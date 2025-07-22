<?php

namespace App\Http\Controllers;

use App\Model\VoiceTemplate;
use App\Model\SmsTemplete;

use Illuminate\Http\Request;

class VoiceTempleteController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    private $request;
    public function __construct(Request $request, VoiceTemplate $voiceTemplate)
    {
        $this->request = $request;
        $this->model = $voiceTemplate;
    }

    /**
     * @OA\Get(
     *     path="/voice-templete",
     *     tags={"VoiceTemplate"},
     *     summary="Get all voice templates",
     *     description="Fetch the list of all voice templates for the authenticated client.",
     *     operationId="getVoiceTemplates",
     *     security={{"Bearer":{}}},
     *      @OA\Parameter(
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
     *         description="List of voice templates",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Voice Template List"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(type="object")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Failed to fetch templates")
     *         )
     *     )
     * )
     */

    public function index(Request $request)
    {
        $templates = VoiceTemplate::on("mysql_" . $request->auth->parent_id)->get()->all();

        if ($request->has('start') && $request->has('limit')) {
            $total_row = count($templates);

            $start = (int) $request->input('start');  // Start index (0-based)
            $limit = (int) $request->input('limit');  // Number of records to fetch

            $templates = array_slice($templates, $start, $limit, false);

            return $this->successResponse("Voice Template List", [
                'start' => $start,
                'limit' => $limit,
                'total' => $total_row,
                'data' => $templates
            ]);
        }
        return $this->successResponse("Voice Template List", $templates);
    }

    public function index_old_code(Request $request)
    {
        $templates = VoiceTemplate::on("mysql_" . $request->auth->parent_id)->get()->all();
        return $this->successResponse("Voice Template List", $templates);
    }
    /*
     *Fetch extension details
     *@return json
     */

    /**
     * @OA\Post(
     *     path="/voice-templete",
     *     tags={"VoiceTemplate"},
     *     summary="Fetch Voice Template Details",
     *     description="Returns the details of voice templates. Optionally filter by `templete_id`.",
     *     operationId="getVoiceTemplete",
     *     security={{"Bearer":{}}},
     *
     *     @OA\RequestBody(
     *         required=false,
     *         @OA\JsonContent(
     *             @OA\Property(
     *                 property="templete_id",
     *                 type="integer",
     *                 example=5,
     *                 description="Filter by specific template ID"
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Voice Template detail response",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Voice Template detail."),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="templete_id", type="integer", example=5),
     *                     @OA\Property(property="title", type="string", example="Welcome Template"),
     *                     @OA\Property(property="content", type="string", example="Hello, welcome to our service."),
     *                     @OA\Property(property="status", type="string", example="active"),
     *                 )
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=404,
     *         description="Template not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Voice Template not created."),
     *             @OA\Property(property="data", type="array", @OA\Items(type="object"))
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Something went wrong")
     *         )
     *     )
     * )
     */


    public function getVoiceTemplete()
    {
        $this->validate($this->request, [
            'templete_name' => 'string',
            'templete_id'          => 'required|numeric',

        ]);
        $response = $this->model->voiceTempleteDetail($this->request);
        return response()->json($response);
    }
    /*
     *Add extension
     *@return json
     */

    /**
     * @OA\Post(
     *     path="/add-voice-templete",
     *     tags={"VoiceTemplate"},
     *     summary="Add a new Voice Template",
     *     description="Creates a new voice template.",
     *     operationId="addVoiceTemplete",
     *     security={{"Bearer":{}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"templete_name", "templete_desc"},
     *             @OA\Property(property="templete_name", type="string", example="Welcome Template"),
     *             @OA\Property(property="templete_desc", type="string", example="This is a welcome message for new users."),
     *             @OA\Property(property="language", type="string", example="en-US"),
     *             @OA\Property(property="voice_name", type="string", example="Joanna"),
     *             @OA\Property(property="pitch", type="string", example="2"),
     *             @OA\Property(property="speed", type="string", example="1")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Voice Template added successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Voice Templete added successfully.")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=400,
     *         description="Missing required fields",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Voice templete not created. Required Details are missing"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=500,
     *         description="Internal Server Error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Internal error occurred.")
     *         )
     *     )
     * )
     */

    public function addVoiceTemplete()
    {

        $response = $this->model->addVoiceTemplete($this->request);
        return response()->json($response);
    }
    /*
     *Edit Extension
     *@return json
     */
    /**
     * @OA\Post(
     *     path="/edit-voice-templete",
     *     summary="Edit Voice Template",
     *     description="Update the voice template fields using the template ID.",
     *     tags={"VoiceTemplate"},
     *     security={{"Bearer": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"templete_id"},
     *             @OA\Property(property="templete_id", type="integer", example=12, description="ID of the template to update"),
     *             @OA\Property(property="templete_name", type="string", example="Customer Greeting", description="Name of the template"),
     *             @OA\Property(property="templete_desc", type="string", example="Greeting for new customers", description="Description of the template"),
     *             @OA\Property(property="language", type="string", example="en-US", description="Language code"),
     *             @OA\Property(property="voice_name", type="string", example="Matthew", description="Voice name"),
     *             @OA\Property(property="pitch", type="string", example="high", description="0"),
     *             @OA\Property(property="speed", type="string", example="medium", description="1")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Voice Template updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Voice Template updated successfully.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Bad Request",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Nothing to update.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal Server Error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Internal server error.")
     *         )
     *     )
     * )
     */

    public function editVoiceTemplete()
    {

        $response = $this->model->editVoiceTemplete($this->request);
        return response()->json($response);
    }

    /**
     * @OA\Post(
     *     path="/delete-voice-templete",
     *     summary="Delete Voice Template",
     *     description="Marks a voice template as deleted using its ID.",
     *     tags={"VoiceTemplate"},
     *     security={{"Bearer": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"templete_id", "is_deleted"},
     *             @OA\Property(property="templete_id", type="integer", example=5, description="ID of the voice template to delete"),
     *             @OA\Property(property="is_deleted", type="integer", example=1, description="Flag to mark as deleted (1 = deleted)")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Voice Template deleted successfully.",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Voice Templete deleted successfully.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Bad Request",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Voice Templete are not deleted successfully.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server Error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Internal server error.")
     *         )
     *     )
     * )
     */

    public function deleteVoiceTemplete()
    {
        $response = $this->model->deleteVoiceTemplete($this->request);
        return response()->json($response);
    }

    public function getEmailSmsList()
    {
        $response = $this->model->getEmailSmsList($this->request);
        return response()->json($response);
    }

    public function getSmsPreview()
    {
        $response = $this->model->getSmsPreview($this->request);
        return response()->json($response);
    }


    /**
     * @OA\Delete(
     *     path="/voice-template/{id}",
     *     summary="Delete Voice Template",
     *     description="Deletes a voice template by its ID for the authenticated user's parent account.",
     *     tags={"VoiceTemplate"},
     *     security={{"Bearer": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID of the voice template to delete",
     *         @OA\Schema(type="integer", example=5)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Voice template deleted successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Template List"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Template not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Template Not Found"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string", example="Invalid template id 5"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server Error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Failed to fetch the template"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string", example="SQL error or other exception message"))
     *         )
     *     )
     * )
     */

    public function delete(Request $request, int $id)
    {
        try {
            $template = VoiceTemplate::on("mysql_" . $request->auth->parent_id)->findOrFail($id);
            $deleted = $template->delete();
            if ($deleted) {
                return $this->successResponse("Template List", $template->toArray());
            } else {
                return $this->failResponse("Failed to delete the template ", [
                    "Unkown"
                ]);
            }
        } catch (ModelNotFoundException $exception) {
            return $this->failResponse("Template Not Found", [
                "Invalid template id $id"
            ], $exception, 404);
        } catch (\Exception $exception) {
            return $this->failResponse("Failed to fetch the template ", [
                $exception->getMessage()
            ], $exception, 500);
        }
    }
}
