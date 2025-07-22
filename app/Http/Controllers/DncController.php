<?php

namespace App\Http\Controllers;

use App\Model\Dnc;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DncController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    private $request;
    public function __construct(Request $request, Dnc $dnc)
    {
        $this->request = $request;
        $this->model = $dnc;
    }

    /*
     * Fetch Dnc details
     * @return json
     */

    /**
     * @OA\Post(
     *     path="/dnc",
     *     summary="Get DNC (Do Not Call) list",
     *     description="Retrieves DNC records based on given search or filters. Accepts POST request.",
     *     tags={"DNC"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=false,
     *         @OA\JsonContent(
     *             @OA\Property(property="search", type="string", example="123"),
     *             @OA\Property(property="lower_limit", type="integer", example=0),
     *             @OA\Property(property="upper_limit", type="integer", example=10)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="DNC records retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="DNC Detail."),
     *             @OA\Property(property="data", type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="number", type="string", example="9876543210"),
     *                     @OA\Property(property="extension", type="string", example="100")
     *                 )
     *             ),
     *             @OA\Property(property="record_count", type="integer", example=20),
     *             @OA\Property(property="searchTerm", type="string", example="123")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="No DNC records found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="DNC not found."),
     *             @OA\Property(property="data", type="array", @OA\Items(type="object")),
     *             @OA\Property(property="record_count", type="integer", example=0),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string")),
     *             @OA\Property(property="searchTerm", type="string", example="123")
     *         )
     *     )
     * )
     */
    public function getDnc()
    {
        $this->validate($this->request, [

            'lower_limit' => 'numeric',
            'upper_limit' => 'numeric',
        ]);
        $response = $this->model->dncDetail($this->request);
        return response()->json($response);
    }

    /*
     * Update Dnc detail
     * @return json
     */
    /**
     * @OA\Post(
     *     path="/edit-dnc",
     *     summary="Update an existing DNC (Do Not Call) record",
     *     description="Updates the extension and/or comment for a phone number in the DNC list.",
     *     tags={"DNC"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"number"},
     *             @OA\Property(property="number", type="string", example="9876543210", description="Required. Number to update in DNC list."),
     *             @OA\Property(property="extension", type="string", example="101", description="Optional. Defaults to auth user's extension."),
     *             @OA\Property(property="comment", type="string", example="Updated comment", description="Optional comment for the DNC entry.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="DNC updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Dnc updated successfully.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="DNC entry does not exist",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Dnc doesn't exist.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Failed to update DNC entry",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Dnc are not updated successfully.")
     *         )
     *     )
     * )
     */

    public function editDnc()
    {
        $this->validate($this->request, [
            'number'    => 'required|numeric|regex:/^([0-9\s\-\+\(\)]*)$/|min:10',
            'extension' => 'numeric',
            'comment'   => 'string',
            // 'id'        => 'required|numeric'
        ]);
        $response = $this->model->dncUpdate($this->request);
        return response()->json($response);
    }
    /*
     *Add Dnc details
     *@return json
     */
    /**
     * @OA\Post(
     *     path="/add-dnc",
     *     summary="Add a new number to the DNC (Do Not Call) list",
     *     description="Adds a phone number to the DNC registry if it does not already exist.",
     *     tags={"DNC"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"number"},
     *             @OA\Property(property="number", type="string", example="9876543210"),
     *             @OA\Property(property="extension", type="string", example="101", description="Optional. Defaults to auth user's extension"),
     *             @OA\Property(property="comment", type="string", example="Customer requested to block calls")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="DNC entry status",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Dnc added successfully.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=409,
     *         description="Number already exists",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Number is already there in our DO NOT CALL registry.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Missing number or failed insertion",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Dnc are not added successfully.")
     *         )
     *     )
     * )
     */
    public function addDnc()
    {
        $this->validate($this->request, [
            'number'    => 'required|numeric|regex:/^([0-9\s\-\+\(\)]*)$/|min:10',
            'extension' => 'numeric',
            'comment'   => 'string',
            // 'id'        => 'required|numeric'
        ]);
        $response = $this->model->addDnc($this->request);
        return response()->json($response);
    }
    /*
     *Delete Dnc
     *@return json
     */
    /**
     * @OA\Post(
     *     path="/delete-dnc",
     *     summary="Delete a DNC (Do Not Call) record",
     *     description="Deletes a phone number from the DNC list by ID and number.",
     *     tags={"DNC"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"number"},
     *             @OA\Property(property="number", type="string", example="9876543210", description="Phone number to remove from DNC list.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="DNC record deleted successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Dnc deleted successfully.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="DNC record not deleted or validation error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Dnc not deleted successfully.")
     *         )
     *     )
     * )
     */

    public function deleteDnc()
    {
        $this->validate($this->request, [
            'number'    => 'required|numeric|regex:/^([0-9\s\-\+\(\)]*)$/|min:10',
            // 'id'        => 'required|numeric'
        ]);
        $response = $this->model->dncDelete($this->request);
        return response()->json($response);
    }

    /**
     * @OA\Get(
     *     path="/dnc/fetch_data",
     *     summary="Process a previously uploaded DNC file",
     *     description="Process a DNC file that has already been uploaded to the server. This does not upload a file; it processes it.",
     *     tags={"DNC"},
     *     security={{"Bearer":{}}},
     *
     *     @OA\Parameter(
     *         name="file",
     *         in="query",
     *         required=true,
     *         description="The name of the file to be processed (e.g., dnc_data.csv)",
     *         @OA\Schema(
     *             type="string",
     *             example="dnc_data.csv"
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="File processed successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="DNC file processed successfully.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="File not found or upload failed",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Unable to find the file.")
     *         )
     *     )
     * )
     */



    public function uploadDnc()
    {
        $this->validate($this->request, [
            'file'           => 'required'
        ]);


        if ($this->request->has('file')) {
            //commented  not able to upload file directory
            //$path = ".." . DIRECTORY_SEPARATOR . "upload" . DIRECTORY_SEPARATOR;
            //$this->request->file('file')->move($path, $this->request->file('file')->getClientOriginalName());
            // Construct the full file path
            $filename = $this->request->input('file');
            $filePath = ('/var/www/html/api/upload/' . $filename);

            Log::info('reached filepath', ['filePath' => $filePath]);
        }
        if (!empty($filePath) && file_exists($filePath)) {
            Log::info('reached file exists', ['filePath' => $filePath]);
            $response = $this->model->uploadDnc($this->request, $filePath);
            return response()->json($response);
        } else {
            return response()->json(array(
                'success' => 'false',
                'message' => 'Unable to upload file.'
            ));
        }
    }
}
