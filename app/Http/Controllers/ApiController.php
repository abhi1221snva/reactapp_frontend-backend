<?php

namespace App\Http\Controllers;

use App\Model\Api;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ApiController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    private $request;
    public function __construct(Request $request, Api $api)
    {
        $this->request = $request;
        $this->model = $api;
    }
    /*
     *Add API details
     *@return json
     */
    /**
     * @OA\Post(
     *     path="/add-api",
     *     summary="Add a new API",
     *     description="Adds a new API record along with its parameters and dispositions.",
     *     tags={"Api"},
     *     security={{"Bearer": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"title", "url", "campaign_id", "method", "parameter"},
     *             @OA\Property(property="title", type="string", example="Update API Title"),
     *             @OA\Property(property="url", type="string", example="https://example.com/api"),
     *             @OA\Property(property="method", type="string", example="POST"),
     *             @OA\Property(property="campaign_id", type="integer", example=101),
     *             @OA\Property(property="is_default", type="integer", example=0),
     *             @OA\Property(
     *                 property="parameter",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="type", type="string", example="query"),
     *                     @OA\Property(property="parameter", type="string", example="user_id"),
     *                     @OA\Property(property="value", type="string", example="123")
     *                 )
     *             ),
     *             @OA\Property(
     *                 property="disposition",
     *                 type="array",
     *                 @OA\Items(type="integer", example=5)
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="API added successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Api added successfully.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Bad request, missing required fields or invalid data",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Api are not added, Required fields are missing")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server error while trying to add the API",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Api are not added successfully.")
     *         )
     *     )
     * )
     */

    public function addApi()
    {
        $this->validate($this->request, [
            'title'       => 'required|string|max:255',
            'url'         => 'required|string|max:255',
            'campaign_id' => 'required|numeric',
            'method'      => 'required|string|max:255',
            'parameter'   => 'required|array',
            'disposition' => 'required|array',
            // 'id'          => 'required|numeric'
        ]);
        $response = $this->model->addApi($this->request);
        return response()->json($response);
    }
    /*
     * Fetch Disposition details
     * @return json
     */

    /**
     * @OA\Post(
     *     path="/api-data",
     *     summary="List of APIs",
     *     tags={"Api"},
     *     security={{"Bearer": {}}},
     *  @OA\RequestBody(
     *         required=false,
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(
     *                 property="api_id",
     *                 type="integer",
     *                 default=0,
     *                 description="api_id for API list"
     *             ),
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
     *         description="API list retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="API list retrieved successfully."),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="name", type="string", example="Welcome Prompt"),
     *                     @OA\Property(property="type", type="string", example="IVR")
     *                 )
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


    public function getApi()
    {
        $this->validate($this->request, [
            'api_id' => 'numeric'
        ]);
        $response = $this->model->apiDetail($this->request);
        return response()->json($response);
    }
    /*
     * Update Api
     * @return json
     */
    /**
     * @OA\Post(
     *     path="/edit-api",
     *     summary="Edit an existing API",
     *     tags={"Api"},
     *     security={{"Bearer": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"api_id"},
     *             @OA\Property(property="api_id", type="integer", example=1),
     *             @OA\Property(property="title", type="string", example="Update API Title"),
     *             @OA\Property(property="url", type="string", example="https://example.com/api"),
     *             @OA\Property(property="method", type="string", example="POST"),
     *             @OA\Property(property="campaign_id", type="integer", example=101),
     *             @OA\Property(property="is_default", type="integer", example=0),
     *             @OA\Property(
     *                 property="parameter",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="type", type="string", example="query"),
     *                     @OA\Property(property="parameter", type="string", example="user_id"),
     *                     @OA\Property(property="value", type="string", example="123")
     *                 )
     *             ),
     *             @OA\Property(
     *                 property="disposition",
     *                 type="array",
     *                 @OA\Items(type="integer", example=5)
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="API updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="API updated successfully.")
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

    public function editApi()
    {
        $this->validate($this->request, [
            'title'      => 'string|max:255',
            'url'        => 'string|max:255',
            'campaign_id' => 'numeric',
            'method'     => 'string|max:255',
            'parameter'  => 'array',
            'disposition' => 'array',
            'api_id'     => 'required|numeric',
            // 'id'         => 'required|numeric'
        ]);
        Log::info('Edit API Request Data:', $this->request->all());
        $response = $this->model->editApi($this->request);
        return response()->json($response);
    }

    /*
     *Delete Api
     *@return json
     */

    /**
     * @OA\Post(
     *     path="/delete-api",
     *     summary="Delete an API",
     *     description="Deletes an API .",
     *     tags={"Api"},
     *     security={{"Bearer": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"api_id"},
     *             @OA\Property(property="api_id", type="integer", example="52"),
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="API successfully deleted",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Api Deleted successfully.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Bad request, missing required fields or invalid values",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Unable to delete api, Required information is missing")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server error while trying to delete the API",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Unable to delete api.")
     *         )
     *     )
     * )
     */

    public function deleteApi()
    {
        $this->validate($this->request, [
            'api_id' => 'required|numeric',
            // 'id'    => 'required|numeric'
        ]);
        $response = $this->model->apiDelete($this->request);
        return response()->json($response);
    }


    /**
     * @OA\Post(
     *     path="/copy-api",
     *     summary="Copy an existing API configuration",
     *     description="Copies an API including its dispositions and parameters from an existing API ID.",
     *     tags={"Api"},
     *     security={{"Bearer": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"api_id"},
     *             @OA\Property(property="api_id", type="integer", example=51)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="New API added successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="New API added successfully."),
     *             @OA\Property(property="list_id", type="integer", example=105)
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Missing or invalid API ID",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Api not added. Unable to add data in API table")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="An unexpected error occurred while copying the API.")
     *         )
     *     )
     * )
     */

    public function copyApi()
    {
        $this->validate($this->request, [
            'api_id' => 'required|numeric',
            // 'id'    => 'required|numeric'
        ]);

        $response = $this->model->copyApi($this->request);
        return response()->json($response);
    }
}
