<?php

namespace App\Http\Controllers;

use App\Model\Lists;
use App\Model\Client\UploadHistoryDid;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Log;


class ListsController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    private $request;
    public function __construct(Request $request, Lists $lists)
    {
        $this->request = $request;
        $this->model = $lists;
    }

    /*
     * Fetch Lists details
     * @return json
     */

    /**
     * @OA\Get(
     *     path="/count-lists",
     *     summary="Count Lists",
     *     tags={"Reports"},
     *     security={{"Bearer": {}}},
     *     @OA\Response(
     *         response=200,
     *         description="count lists",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="count-lists."),
     
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
    public function countList(Request $request)
    {
        $lists = Lists::on("mysql_" . $request->auth->parent_id)->where('is_active', '=', 1)->get();
        $listCount = $lists->count();
        return $this->successResponse("Count Lists", [$listCount]);
    }

    /**
     * @OA\Post(
     *     path="/list",
     *     summary="Get List Data by Campaign and List ID",
     *     description="Fetches list data for the given campaign and list ID",
     *     tags={"Lists"},
     *     security={{"Bearer": {}}},
     *      @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"campaign_id", "list_id"},
     *             @OA\Property(property="campaign_id", type="integer", example=34),
     *             @OA\Property(property="list_id", type="integer", example=126),
     *             @OA\Property(property="start",type="integer",default=0,description="Start index for pagination"),
     *             @OA\Property(property="limit", type="integer",default=10,description="Limit number of records returned"
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="List fetched successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation failed",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Invalid input"),
     *             @OA\Property(
     *                 property="errors",
     *                 type="object",
     *                 additionalProperties=@OA\Property(type="array", @OA\Items(type="string"))
     *             )
     *         )
     *     )
     * )
     */

    public function getList()
    {
        $this->validate($this->request, [
            'campaign_id' => 'numeric',
            'list_id'    => 'numeric'
        ]);
        $response = $this->model->getList($this->request);
        return response()->json($response);
    }


    /**
     * @OA\Post(
     *     path="/list-header",
     *     summary="Get List Header",
     *     description="Fetches the list header using list_data array and id",
     *     tags={"Lists"},
     *     security={{"Bearer": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"id", "list_data"},
     *             @OA\Property(
     *                 property="id",
     *                 type="integer",
     *                 example=918
     *             ),
     *             @OA\Property(
     *                 property="list_data",
     *                 type="array",
     *                 @OA\Items(type="integer"),
     *                 example={0}
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Header data returned",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="List header detail."),
     *             @OA\Property(property="data", type="array", @OA\Items(
     *                 @OA\Property(property="column_name", type="string", example="first_name"),
     *                 @OA\Property(property="title", type="string", example="Name")
     *             ))
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation failed",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="The given data was invalid."),
     *             @OA\Property(
     *                 property="errors",
     *                 type="object",
     *                 additionalProperties=@OA\Property(type="array", @OA\Items(type="string"))
     *             )
     *         )
     *     )
     * )
     */
    public function getListHeader()
    {
        $this->validate($this->request, [
            'list_id'    => 'numeric',
            'id'         => 'required|numeric'
        ]);
        $response = $this->model->getListHeader($this->request);
        return response()->json($response);
    }
    /*
     *Edit List detail
     *@return json
     */
    /**
     * @OA\Post(
     *     path="/edit-list",
     *     summary="Edit list details, update fields or delete list",
     *     tags={"Lists"},
     *     operationId="editList",
     *     security={{"Bearer": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"list_id", "campaign_id", "id"},
     *             @OA\Property(property="title", type="string", maxLength=255, example="New List Title"),
     *             @OA\Property(property="status", type="integer", example=1),
     *             @OA\Property(property="is_deleted", type="integer", example=0),
     *             @OA\Property(property="list_id", type="integer", example=57),
     *             @OA\Property(property="campaign_id", type="integer", example=20),
     *             @OA\Property(property="new_campaign_id", type="integer", example=12),
     *             @OA\Property(
     *                 property="list_header",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=613),
     *                     @OA\Property(property="is_search", type="integer", example=1),
     *                     @OA\Property(property="is_dialing", type="integer", example=1),
     *                     @OA\Property(property="is_visible", type="integer", example=1),
     *                     @OA\Property(property="is_editable", type="integer", example=1),
     *                     @OA\Property(property="label_id", type="integer", example=3),
     *                     @OA\Property(property="column_name", type="string", example="email")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="List updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Lists updated successfully.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation failed",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Invalid input")
     *         )
     *     )
     * )
     */


    public function editList()
    {
        $this->validate($this->request, [
            'title'          => 'string|max:255',
            'status'         => 'numeric',
            'is_deleted'     => 'numeric',
            'list_id'        => 'required|numeric',
            'campaign_id'    => 'required|numeric',
            'new_campaign_id' => 'numeric',
            'list_header'    => 'array',
            //  'id'             => 'required|numeric'
        ]);
        $response = $this->model->editList($this->request);
        $status = ($response['success'] === 'true') ? 200 : 400; // or 422 if you prefer

return response()->json($response, $status);
    }

    /**
     * @OA\Post(
     *     path="/add-list",
     *     summary="Upload and add a contact list via Excel file",
     *     tags={"Lists"},
     *      security={{"Bearer": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"title", "file", "campaign", "id"},
     *                 @OA\Property(
     *                     property="title",
     *                     type="string",
     *                     description="Name of the list",
     *                     example="June Leads"
     *                 ),
     *                 @OA\Property(
     *                     property="duplicate_check",
     *                     type="string",
     *                     enum={"yes", "no"},
     *                     description="Whether to check for duplicates",
     *                     example="yes"
     *                 ),
     *                 @OA\Property(
     *                     property="file",
     *                     type="string",
     *                     description="Excel file to upload (.xls or .xlsx)"
     *                 ),
     *                 @OA\Property(
     *                     property="campaign",
     *                     type="integer",
     *                     description="Campaign ID",
     *                     example=101
     *                 ),
     *                 @OA\Property(
     *                     property="id",
     *                     type="integer",
     *                     description="User or session ID",
     *                     example=1
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="List upload successful",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="List added successfully."),
     *             @OA\Property(property="list_id", type="integer", example=33),
     *             @OA\Property(property="campaign_id", type="integer", example=101)
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Upload failed",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Unable to upload file.")
     *         )
     *     )
     * )
     */


    public function addList()
    {
        $this->validate($this->request, [
            'title'          => 'required|string|max:255',
            'file'           => 'required|string', //|mimes:xls,xlsx', //commented  not able to upload file directory
            'campaign'       => 'required|numeric',
            'id'             => 'required|numeric'
        ]);
        if ($this->request->has('file')) {
            //commented  not able to upload file directory
            //$path = ".." . DIRECTORY_SEPARATOR . "upload" . DIRECTORY_SEPARATOR;
            //$this->request->file('file')->move($path, $this->request->file('file')->getClientOriginalName());
            // $filePath = env('LIST_FILE_UPLOAD_PATH') . $this->request->input('file');
             $filePath = base_path() . "/upload/" . $this->request->input('file');
        }
        if (!empty($filePath) && file_exists($filePath)) {
            $response = $this->model->addList($this->request, $filePath);
            return response()->json($response);
        } else {
            return response()->json(array(
                'success' => 'false',
                'message' => 'Unable to upload file.'
            ));
        }
    }


       /**
 * @OA\Post(
 *     path="/add-list-api",
 *     summary="Upload and add a contact list via Excel or CSV file",
 *     tags={"Lists"},
 *     security={{"Bearer": {}}},
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\MediaType(
 *             mediaType="multipart/form-data",
 *             @OA\Schema(
 *                 required={"title", "file", "campaign"},
 *                 @OA\Property(
 *                     property="title",
 *                     type="string",
 *                     description="Name of the list",
 *                     example="June Leads"
 *                 ),
 *                 @OA\Property(
 *                     property="file",
 *                     type="string",
 *                     format="binary",
 *                     description="File to upload (.xls, .xlsx, or .csv)"
 *                 ),
 *                 @OA\Property(
 *                     property="campaign",
 *                     type="integer",
 *                     description="Campaign ID",
 *                     example=101
 *                 )
 *             )
 *         )
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="List upload successful",
 *         @OA\JsonContent(
 *             @OA\Property(property="success", type="string", example="true"),
 *             @OA\Property(property="message", type="string", example="List added successfully."),
 *             @OA\Property(property="list_id", type="integer", example=33),
 *             @OA\Property(property="campaign_id", type="integer", example=101)
 *         )
 *     ),
 *     @OA\Response(
 *         response=400,
 *         description="Upload failed",
 *         @OA\JsonContent(
 *             @OA\Property(property="success", type="string", example="false"),
 *             @OA\Property(property="message", type="string", example="Unable to upload file.")
 *         )
 *     )
 * )
 */


        public function addListUsingApi()
        {
            $this->validate($this->request, [
                'title'    => 'required|string|max:255',
                'file'     => 'required|file', // |mimes:xls,xlsxAccept actual file
                'campaign' => 'required|numeric',
            ]);


            if ($this->request->hasFile('file')) {
        // Create upload path if not exists
        $uploadPath = base_path('upload');
        if (!file_exists($uploadPath)) {
            mkdir($uploadPath, 0777, true);
        }

        // Move uploaded file
        $filename = time() . '_' . $this->request->file('file')->getClientOriginalName();
        $this->request->file('file')->move($uploadPath, $filename);

         $filePath = $uploadPath . DIRECTORY_SEPARATOR . $filename;

        if (file_exists($filePath)) {
            $response = $this->model->addList($this->request, $filePath);
            return response()->json($response);
        }
    }

    return response()->json([
        'success' => 'false',
        'message' => 'Unable to upload file.'
    ]);
}


    /**
     * @OA\Post(
     *     path="/lead-count",
     *     summary="Get Lead Count",
     *     description="Returns the total number of leads in the list_data table for the authenticated client's database.",
     *     tags={"Lead"},
     *     security={{"Bearer": {}}},
     *     @OA\RequestBody(
     *         required=false,
     *         description="No request body is required."
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successful response with lead count",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Lead count"),
     *             @OA\Property(property="data", type="integer", example=41470)
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error"
     *     )
     * )
     */


    public function getLeadCount()
    {
        $response = $this->model->getLeadCount($this->request);
        return response()->json($response);
    }

    /**
     * @OA\Post(
     *     path="/search-leads",
     *     summary="Search Leads",
     *     description="Search leads based on list data, header column, and value",
     *     tags={"Lists"},
     *     security={{"Bearer": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(
     *                 property="list_data",
     *                 type="array",
     *                 @OA\Items(type="integer"),
     *                 example={0}
     *             ),
     *             @OA\Property(
     *                 property="header_column",
     *                 type="string",
     *                 example="option_5"
     *             ),
     *             @OA\Property(
     *                 property="header_value",
     *                 type="string",
     *                 example="2012334277"
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Leads fetched successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Lead detail."),
     *             @OA\Property(property="data", type="array", @OA\Items(type="object"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="No leads found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="No Leads Found."),
     *             @OA\Property(property="data", type="array", @OA\Items(type="object"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Validation error"),
     *             @OA\Property(
     *                 property="errors",
     *                 type="object",
     *                 additionalProperties=@OA\Property(type="array", @OA\Items(type="string"))
     *             )
     *         )
     *     )
     * )
     */
    public function searchLeads()
    {

        $this->validate($this->request, [

            'list_data'    => 'array',

        ]);
        $response = $this->model->searchLeads($this->request);
        return response()->json($response);
    }

    public function updateListStatus()
    {
        $response = $this->model->updateListStatus($this->request);
        return response()->json($response);
    }


    /**
     * @OA\Post(
     *     path="/status-update-list",
     *     summary="Update status of a  list",
     *     tags={"Lists"},
     *     operationId="updateCampaignListStatus",
     *     security={{"Bearer": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"listId", "campaign_id", "status"},
     *             @OA\Property(property="listId", type="integer", example=12),
     *             @OA\Property(property="campaign_id", type="integer", example=101),
     *             @OA\Property(property="status", type="integer", example=1)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Campaign list status update response",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Campaign List status updated successfully")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid input",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Campaign List update failed")
     *         )
     *     )
     * )
     */

    public function updateCampaignListStatus()
    {
        $response = $this->model->updateCampaignListStatus($this->request);
        return response()->json($response);
    }

    /**
     * Get data for edit lead page
     */
    /**
     * @OA\Post(
     *     path="/get-data-for-edit-lead-page_copy",
     *     summary="Get Lead Data for Edit Page",
     *     description="Fetch lead data for editing, including dynamic labels and header fields",
     *     tags={"Lists"},
     *     security={{"Bearer": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"lead_id"},
     *             @OA\Property(property="lead_id", type="integer", example=123)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Lead data retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Edit Lead Data"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(
     *                     property="leadData",
     *                     type="array",
     *                     @OA\Items(
     *                         type="object",
     *                         @OA\Property(property="id", type="integer", example=5),
     *                         @OA\Property(property="title", type="string", example="First Name"),
     *                         @OA\Property(property="column_name", type="string", example="option_1"),
     *                         @OA\Property(property="is_dialing", type="integer", example=0),
     *                         @OA\Property(property="value", type="string", example="John")
     *                     )
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Missing or invalid parameters",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Validation error"),
     *             @OA\Property(
     *                 property="errors",
     *                 type="object",
     *                 additionalProperties=@OA\Property(type="array", @OA\Items(type="string"))
     *             )
     *         )
     *     )
     * )
     */

    public function getDataForEditLeadPage_copy()
    {
        $response = $this->model->getLeadDataForEditPage_copy($this->request->input('lead_id'), $this->request->auth->parent_id);
        return $this->successResponse("Edit Lead Data", $response);
    }
    public function getDataForEditLeadPage()
    {
        $response = $this->model->getLeadDataForEditPage($this->request->input('lead_id'), $this->request->auth->parent_id);
        return $this->successResponse("Edit Lead Data", $response);
    }

    /**
     * Update / Create list/lead data
     */

    /**
     * @OA\Post(
     *     path="/update-lead-data_copy",
     *     summary="Update or Insert Lead Data",
     *     description="Updates lead fields or creates a new lead if lead_id is 0. Also links CDR data by number.",
     *     tags={"Lists"},
     *     security={{"Bearer": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"lead_id", "number", "label_id", "label_value"},
     *             @OA\Property(property="lead_id", type="integer", example=0, description="Set to 0 for new lead"),
     *             @OA\Property(property="number", type="string", example="9876543210"),
     *             @OA\Property(
     *                 property="label_id",
     *                 type="array",
     *                 @OA\Items(type="integer", example=2)
     *             ),
     *             @OA\Property(
     *                 property="label_value",
     *                 type="array",
     *                 @OA\Items(type="string", example="John Doe")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Lead updated or inserted successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Lead has been updated successfully")
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Invalid input"),
     *             @OA\Property(
     *                 property="errors",
     *                 type="object",
     *                 additionalProperties=@OA\Property(type="array", @OA\Items(type="string"))
     *             )
     *         )
     *     )
     * )
     */

    public function updateLeadData_copy()
    {
        $response = $this->model->updateLeadData_copy($this->request, $this->request->auth->parent_id);
        return response()->json($response);
    }

    public function updateLeadData()
    {
        $response = $this->model->updateLeadData($this->request, $this->request->auth->parent_id);
        return response()->json($response);
    }


    /**
     * @OA\Post(
     *     path="/change-disposition",
     *     summary="Change Disposition",
     *     description="Update the disposition ID for a given CDR record in both live and archive tables.",
     *     tags={"Lists"},
     *     security={{"Bearer": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"cdr_id", "disposition_id"},
     *             @OA\Property(property="cdr_id", type="integer", example=123, description="ID of the CDR record"),
     *             @OA\Property(property="disposition_id", type="integer", example=5, description="New disposition ID to be assigned")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Disposition updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="disposition has been updated successfully")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid request or missing parameters"
     *     )
     * )
     */

    public function changeDisposition()
    {
        $response = $this->model->changeDisposition($this->request, $this->request->auth->parent_id);
        return response()->json($response);
    }



    /**
     * @OA\Get(
     *     path="/list/{id}/content",
     *     summary="Get list content including headers and data",
     *     tags={"Lists"},
     *     operationId="getListContent",
     *     security={{"Bearer": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID of the list to fetch",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     * *     @OA\Response(
     *         response=200,
     *         description="List content fetched successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="All list data"),
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="List not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="List data does not exist")
     *         )
     *     )
     * )
     */

    public function getListContent(Request $request)
    {

        $intListId = $request->route('id');
        $arrList = Lists::on("mysql_" . $request->auth->parent_id)->find($intListId)->toArray();

        //validate list id
        if (empty($arrList)) {
            return $this->failResponse("List data does not exist", []);
        }

        //get list headers
        $strListHeaderSql = "SELECT GROUP_CONCAT(header ORDER BY column_name + 0 ASC SEPARATOR ',') as list_headers
                from list_header
                WHERE list_id =:list_id";
        $arrListHeaders = DB::connection('mysql_' . $request->auth->parent_id)->select($strListHeaderSql, ['list_id' => $intListId]);
        $arrFinalListHeaders = array_reverse(explode(",", $arrListHeaders[0]->list_headers));

        //get list data
        $strListDataSql = "SELECT
                        option_1, option_2, option_3, option_4, option_5, option_6, option_7, option_8, option_9, option_10, option_11, option_12, option_13, option_14, option_15, option_16, option_17, option_18, option_19, option_20, option_21, option_22, option_23, option_24, option_25, option_26, option_27, option_28, option_29, option_30
                from list_data
                    WHERE list_id =:list_id";
        $arrFinalListData = DB::connection('mysql_' . $request->auth->parent_id)->select($strListDataSql, ['list_id' => $intListId]);
        $filename = $arrList["title"] . "_" . date("Y-m-d") . ".csv";
        $this->storeDownloadHistory($request->auth->id, $filename, $request);

        return $this->successResponse("All list data", ["list_name" => $arrList["title"], "list_header" => $arrFinalListHeaders, "list_data" => $arrFinalListData]);
    }
    //     public function getListContent(Request $request)
    // {
    //     $intListId = $request->route('id');
    //     $arrList = Lists::on("mysql_" . $request->auth->parent_id)->find($intListId)->toArray();

    //     // validate list id
    //     if (empty($arrList)) {
    //         return $this->failResponse("List data does not exist", []);
    //     }

    //     // get list headers
    //     $strListHeaderSql = "SELECT GROUP_CONCAT(header ORDER BY column_name + 0 ASC SEPARATOR ',') as list_headers
    //         from list_header
    //         WHERE list_id = :list_id";
    //     $arrListHeaders = DB::connection('mysql_' . $request->auth->parent_id)->select($strListHeaderSql, ['list_id' => $intListId]);
    //     $arrFinalListHeaders = array_reverse(explode(",", $arrListHeaders[0]->list_headers));

    //     // get list data
    //     $strListDataSql = "SELECT
    //         option_1, option_2, option_3, option_4, option_5, option_6, option_7, option_8, option_9, option_10, option_11, option_12, option_13, option_14, option_15, option_16, option_17, option_18, option_19, option_20, option_21, option_22, option_23, option_24, option_25, option_26, option_27, option_28, option_29, option_30
    //         from list_data
    //         WHERE list_id = :list_id";
    //     $arrFinalListData = DB::connection('mysql_' . $request->auth->parent_id)->select($strListDataSql, ['list_id' => $intListId]);

    //     // Prepare the file name
    //     $filename = $arrList["title"] . "_" . date("Y-m-d") . ".csv";

    //     // Generate the CSV content
    //     $csvContent = $this->arrayToCsv($arrFinalListHeaders, $arrFinalListData);

    //     // Store the download history
    //     $this->storeDownloadHistory($request->auth->user_id, $filename);

    //     return response()->streamDownload(function () use ($csvContent) {
    //         echo $csvContent;
    //     }, $filename);
    // }



    // public function getListContentViewold(Request $request){

    //         $intListId = $request->route('id');
    //         $arrList = Lists::on("mysql_" . $request->auth->parent_id)->find($intListId)->toArray();

    //         //validate list id
    //         if(empty($arrList)){
    //             return $this->failResponse("List data does not exist", []);
    //         }

    //         //get list headers
    //         $strListHeaderSql = "SELECT GROUP_CONCAT(header ORDER BY column_name + 0 ASC SEPARATOR ',') as list_headers
    //                 from list_header
    //                 WHERE list_id =:list_id";
    //         $arrListHeaders = DB::connection('mysql_' . $request->auth->parent_id)->select($strListHeaderSql, ['list_id' => $intListId]);
    //         $arrFinalListHeaders = array_reverse(explode(",", $arrListHeaders[0]->list_headers));

    //         //get list data
    //         $strListDataSql = "SELECT id,
    //                         option_1, option_2, option_3, option_4, option_5, option_6, option_7, option_8, option_9, option_10, option_11, option_12, option_13, option_14, option_15, option_16, option_17, option_18, option_19, option_20, option_21, option_22, option_23, option_24, option_25, option_26, option_27, option_28, option_29, option_30
    //                 from list_data
    //                     WHERE list_id =:list_id";
    //         $arrFinalListData = DB::connection('mysql_' . $request->auth->parent_id)->select($strListDataSql, ['list_id' => $intListId]);
    //         $filename = $arrList["title"] . "_" . date("Y-m-d") . ".csv";
    //         $this->storeDownloadHistory($request->auth->id, $filename,$request);

    //         return $this->successResponse("All list data", ["list_name" =>$arrList["title"], "list_header" => $arrFinalListHeaders, "list_data" => $arrFinalListData]);
    //     }

    /**
     * @OA\Get(
     *     path="/list-data/{id}/content",
     *     summary="Get paginated or full list data with headers",
     *     description="Returns list data with headers. Supports search, pagination, and Excel download.",
     *     tags={"Lists"},
     *     security={{"Bearer": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="List ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="search",
     *         in="query",
     *         required=false,
     *         description="Search term to filter list data",
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="start",
     *         in="query",
     *         required=false,
     *         description="Pagination lower limit (offset)",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="limit",
     *         in="query",
     *         required=false,
     *         description="Pagination upper limit (number of records)",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="excel",
     *         in="query",
     *         required=false,
     *         description="If passed, returns all data for Excel export",
     *         @OA\Schema(type="boolean")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="List content retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Paginated list data"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="list_name", type="string", example="My List"),
     *                 @OA\Property(property="list_header", type="array", @OA\Items(type="string")),
     *                 @OA\Property(property="list_data", type="array", @OA\Items(type="object")),
     *                 @OA\Property(property="list_data_count", type="integer", example=25),
     *                 @OA\Property(property="search_term", type="string", example="John"),
     *                 @OA\Property(property="total_records", type="integer", example=100)
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="List not found"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server error"
     *     )
     * )
     */




     public function getListContentView(Request $request)
     {
         try {
             $intListId = $request->route('id');
             $excel = $request->input('excel');
             $search = $request->input('search');
     
             $arrList = Lists::on("mysql_" . $request->auth->parent_id)->find($intListId)?->toArray();
     
             if (empty($arrList)) {
                 return $this->failResponse("List data does not exist", []);
             }
     
             // Fetch list headers with label titles
             $arrListHeaders = DB::connection('mysql_' . $request->auth->parent_id)
                 ->table('list_header as l')
                 ->leftJoin('label as lb', 'l.label_id', '=', 'lb.id')
                 ->where('l.list_id', $intListId)
                 ->where('l.is_deleted', 0)
                 ->where('lb.is_deleted', 0)
                 ->orderByRaw('l.column_name + 0 ASC')
                 ->select('l.column_name', 'lb.title', 'l.label_id')
                 ->get();
     
             if ($arrListHeaders->isEmpty()) {
                 return $this->failResponse("No headers found for this list", []);
             }
     
             $allLabelsNull = $arrListHeaders->every(function ($item) {
                 return empty($item->label_id) || is_null($item->title);
             });
     
             if ($allLabelsNull) {
                 return $this->failResponse("List headers found but labels are missing or not assigned", []);
             }
     
             // Map column_name => label title
             $columnToLabelMap = [];
             foreach ($arrListHeaders as $header) {
                 $columnToLabelMap[$header->column_name] = $header->title;
             }
     
             $optionColumns = array_keys($columnToLabelMap);
     
             // Add id column so we can use it as lead_id
             if (!in_array('id', $optionColumns)) {
                 $optionColumns[] = 'id';
             }
     
             // Build query
             $listDataQuery = DB::connection('mysql_' . $request->auth->parent_id)
                 ->table('list_data')
                 ->where('list_id', $intListId)
                 ->select($optionColumns);
     
             // Apply search if exists
             if (!empty($search)) {
                 $listDataQuery->where(function($q) use ($search, $optionColumns) {
                     foreach ($optionColumns as $column) {
                         $q->orWhere($column, 'LIKE', "%$search%");
                     }
                 });
             }
     
             $totalRecords = (clone $listDataQuery)->count();
     
             // Pagination
             if (!$excel) {
                 $start = (int)$request->input('start', 0);
                 $limit = (int)$request->input('limit', 10);
                 $listDataQuery->offset($start)->limit($limit);
             }
     
             $arrListData = $listDataQuery->get();
     
             // Prepare list data
             $arrFinalListData = [];
             foreach ($arrListData as $row) {
                 $rowData = ['lead_id' => $row->id]; // ✅ Add lead_id instead of list_id
                 foreach ($columnToLabelMap as $column => $labelTitle) {
                     if (isset($row->$column) && $row->$column !== null && $row->$column !== '') {
                         $rowData[$labelTitle] = $row->$column;
                     }
                 }
                 if (!empty($rowData)) {
                     $arrFinalListData[] = $rowData;
                 }
             }
     
             $arrFinalListHeaders = array_values($columnToLabelMap);
     
             // ✅ Add list_id in top-level response
             return $this->successResponse($excel ? "All list data for download" : "Paginated list data", [
                 "list_name" => $arrList["title"],
                 "list_id" => $intListId, // ✅ Added here
                 "list_header" => $arrFinalListHeaders,
                 "list_data" => $arrFinalListData,
                 "list_data_count" => count($arrFinalListData),
                 "total_records" => $totalRecords,
                 "search_term" => $search,
                 "start" => $start ?? 0,
                 "limit" => $limit ?? 0,
             ]);
     
         } catch (Exception $e) {
             Log::error($e->getMessage());
             return $this->failResponse("Error occurred", []);
         }
     }
     

//     public function getListContentView(Request $request)
// {
//     try {
//         $intListId = $request->route('id');
//         $excel = $request->input('excel');
//         $search = $request->input('search');

//         $arrList = Lists::on("mysql_" . $request->auth->parent_id)->find($intListId)?->toArray();

//         if (empty($arrList)) {
//             return $this->failResponse("List data does not exist", []);
//         }

//         // Fetch list headers with label titles
//         $arrListHeaders = DB::connection('mysql_' . $request->auth->parent_id)
//             ->table('list_header as l')
//             ->leftJoin('label as lb', 'l.label_id', '=', 'lb.id')
//             ->where('l.list_id', $intListId)
//             ->where('l.is_deleted', 0)
//             ->where('lb.is_deleted', 0)
//             ->orderByRaw('l.column_name + 0 ASC')
//             ->select('l.column_name', 'lb.title', 'l.label_id')
//             ->get();

//         if ($arrListHeaders->isEmpty()) {
//             return $this->failResponse("No headers found for this list", []);
//         }
//         // ✅ Case 2: Headers exist, but all have NULL label_id or missing label
//         $allLabelsNull = $arrListHeaders->every(function ($item) {
//             return empty($item->label_id) || is_null($item->title);
//         });

//         if ($allLabelsNull) {
//             return $this->failResponse("List headers found but labels are missing or not assigned", []);
//         }
//         // Map column_name => label title (use exact column name, no extra 'option_')
//         $columnToLabelMap = [];
//         foreach ($arrListHeaders as $header) {
//             $columnToLabelMap[$header->column_name] = $header->title;
//         }

//         $optionColumns = array_keys($columnToLabelMap);

//         // Initialize query builder
//         $listDataQuery = DB::connection('mysql_' . $request->auth->parent_id)
//             ->table('list_data')
//             ->where('list_id', $intListId)
//             ->select($optionColumns);

//         // Apply search if exists
//         if (!empty($search)) {
//             $listDataQuery->where(function($q) use ($search, $optionColumns) {
//                 foreach ($optionColumns as $column) {
//                     $q->orWhere($column, 'LIKE', "%$search%");
//                 }
//             });
//         }

//         // Clone query for total records
//         $totalRecords = (clone $listDataQuery)->count();

//         // Pagination
//         if (!$excel) {
//             $start = (int)$request->input('start', 0);
//             $limit = (int)$request->input('limit', 10);
//             $listDataQuery->offset($start)->limit($limit);
//         }

//         // Fetch list data
//         $arrListData = $listDataQuery->get();

//         // Map options to label titles and remove empty/null values
//         $arrFinalListData = [];
//         // foreach ($arrListData as $row) {
//         //     $rowData = [];
//         //     foreach ($columnToLabelMap as $column => $labelTitle) {
//         //         if (isset($row->$column) && $row->$column !== null && $row->$column !== '') {
//         //             $rowData[$labelTitle] = $row->$column;
//         //         }
//         //     }
//         //     if (!empty($rowData)) {
//         //         $arrFinalListData[] = $rowData;
//         //     }
//         // }
//         foreach ($arrListData as $row) {
//             $rowData = ['list_id' => $intListId]; // ✅ Add list_id to each record
//             foreach ($columnToLabelMap as $column => $labelTitle) {
//                 if (isset($row->$column) && $row->$column !== null && $row->$column !== '') {
//                     $rowData[$labelTitle] = $row->$column;
//                 }
//             }
//             if (!empty($rowData)) {
//                 $arrFinalListData[] = $rowData;
//             }
//         }
        
//         // Prepare list headers for response
//         $arrFinalListHeaders = array_values($columnToLabelMap);

//         return $this->successResponse($excel ? "All list data for download" : "Paginated list data", [
//             "list_name" => $arrList["title"],
//             "list_header" => $arrFinalListHeaders,
//             "list_data" => $arrFinalListData,
//             "list_data_count" => count($arrFinalListData),
//             "total_records" => $totalRecords,
//             "search_term" => $search,
//             "start" => $start ?? 0,
//             "limit" => $limit ?? 0,
//         ]);

//     } catch (Exception $e) {
//         Log::error($e->getMessage());
//         return $this->failResponse("Error occurred", []);
//     }
// }

    public function getListContentView_old_copy(Request $request)
    {
        Log::info('reached', $request->all());

        try {
            $intListId = $request->route('id');
            $show = $request->input('show');
            $arrList = Lists::on("mysql_" . $request->auth->parent_id)->find($intListId)->toArray();

            if (empty($arrList)) {
                return $this->failResponse("List data does not exist", []);
            }

            // Fetch list headers
            $strListHeaderSql = "SELECT GROUP_CONCAT(header ORDER BY column_name + 0 ASC SEPARATOR ',') as list_headers
                FROM list_header
                WHERE list_id = :list_id";
            $arrListHeaders = DB::connection('mysql_' . $request->auth->parent_id)->select($strListHeaderSql, ['list_id' => $intListId]);
            $arrFinalListHeaders = array_reverse(explode(",", $arrListHeaders[0]->list_headers));

            $search = [];
            $limitString = '';

            // Handle search results differently
            if ($request->has('search') && !empty($request->input('search'))) {
                $search['search_term'] = '%' . $request->input('search') . '%';
            }

            // Initialize parameters array
            $parameters = ['list_id' => $intListId];

            // Prepare search query and parameters
            if (!empty($search)) {
                $searchQuery = 'AND (';
                $options = [];
                for ($i = 1; $i <= 30; $i++) {
                    $options[] = "option_$i LIKE :search_term_$i";
                    $parameters["search_term_$i"] = $search['search_term'];
                }
                $searchQuery .= implode(' OR ', $options);
                $searchQuery .= ')';
            } else {
                $searchQuery = '';
            }

            // Fetch total records count
            $strTotalRecordsSql = "SELECT COUNT(*) as total_records
                FROM list_data
                WHERE list_id = :list_id $searchQuery";
            $totalRecordsResult = DB::connection('mysql_' . $request->auth->parent_id)->select($strTotalRecordsSql, $parameters);
            $totalRecords = $totalRecordsResult[0]->total_records;
            $excel = $request->input('excel');
            Log::info('reached', ['excel' => $excel]);
            // Check if it's a download request
            if ($excel) {
                // Fetch all list data without pagination
                $strListDataSql = "SELECT  option_1, option_2, option_3, option_4, option_5, option_6, option_7, option_8, option_9,
                option_10, option_11, option_12, option_13, option_14, option_15, option_16, option_17,
                option_18, option_19, option_20, option_21, option_22, option_23, option_24, option_25,
                option_26, option_27, option_28, option_29, option_30
                    FROM list_data
                    WHERE list_id = :list_id $searchQuery";
                $arrFinalListData = DB::connection('mysql_' . $request->auth->parent_id)->select($strListDataSql, $parameters);
                // Return success response with all list data
                return $this->successResponse("All list data for download", [
                    "list_name" => $arrList["title"],
                    "list_header" => $arrFinalListHeaders,
                    "list_data" => $arrFinalListData,
                    "total_records" => $totalRecords,
                ]);
            }

            // Apply pagination for regular requests
            //              // Get lower and upper limits from the request
            $lowerLimit = $request->input('lower_limit');
            $upperLimit = $request->input('upper_limit');


            $strTotalRecordsSql = "SELECT COUNT(*) as total_records
        FROM list_data
        WHERE list_id = :list_id $searchQuery";
            $totalRecordsResult = DB::connection('mysql_' . $request->auth->parent_id)->select($strTotalRecordsSql, $parameters);
            $totalRecords = $totalRecordsResult[0]->total_records;



            // Fetch paginated data using the calculated offset and items per page
            // Fetch data using lower and upper limits
            $strListDataSql = "SELECT  option_1, option_2, option_3, option_4, option_5, option_6, option_7, option_8, option_9,
   option_10, option_11, option_12, option_13, option_14, option_15, option_16, option_17,
    option_18, option_19, option_20, option_21, option_22, option_23, option_24, option_25,
    option_26, option_27, option_28, option_29, option_30
   FROM list_data
   WHERE list_id = :list_id $searchQuery
   LIMIT :lower_limit, :upper_limit";
            $arrFinalListData = DB::connection('mysql_' . $request->auth->parent_id)
                ->select($strListDataSql, array_merge($parameters, ['lower_limit' => $lowerLimit, 'upper_limit' => $upperLimit]));
            if (empty($arrFinalListData)) {
                return $this->failResponse("No data found for your search", []);
            }
            $filename = $arrList["title"] . "_" . date("Y-m-d") . ".csv";
            $this->storeDownloadHistory($request->auth->id, $filename, $request);

            // Return success response with paginated list data and total records
            return $this->successResponse("Paginated list data", [
                "list_name" => $arrList["title"],
                "list_header" => $arrFinalListHeaders,
                "list_data" => $arrFinalListData,
                "list_data_count" => count($arrFinalListData),
                "search_term" => $request->input('search'),
                "total_records" => $totalRecords,
            ]);
        } catch (Exception $e) {
            Log::error($e->getMessage());
            return $this->failResponse("Error occurred", []);
        }
    }

    public function storeDownloadHistory($userId, $filename, $request)
    {
        $connection = DB::connection('mysql_' . $request->auth->parent_id);
        $currentUrl = URL::current();
        $history = new UploadHistoryDid();
        $history->setConnection($connection->getName());
        $history->user_id = $userId;
        $history->file_name = $filename;
        $history->upload_url = $currentUrl;
        $history->url_title = "List";
        $history->save();
    }
}
