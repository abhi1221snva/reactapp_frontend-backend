<?php

namespace App\Http\Controllers;
use App\Model\ExcludeNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class ExcludeNumberController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    private $request;
    public function __construct(Request $request, ExcludeNumber $excludeNumber)
    {
        $this->request = $request;
        $this->model = $excludeNumber;
    }

    /*
     * Fetch excludeNumber details
     * @return json
     */


      /**
 * @OA\Post(
 *     path="/exclude-number",
 *     summary="Get Excluded Numbers",
 *     description="Retrieves excluded number records based on search and pagination filters.",
 *     tags={"Exclude Number"},
 *     security={{"Bearer":{}}},
 *     @OA\RequestBody(
 *         required=false,
 *         @OA\JsonContent(
 *             @OA\Property(property="search", type="string", example="John"),
 *             @OA\Property(property="lower_limit", type="integer", example=0),
 *             @OA\Property(property="upper_limit", type="integer", example=10)
 *         )
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="Exclude number records retrieved successfully",
 *         @OA\JsonContent(
 *             @OA\Property(property="success", type="boolean", example=true),
 *             @OA\Property(property="message", type="string", example="Exclude Number."),
 *             @OA\Property(property="data", type="array",
 *                 @OA\Items(
 *                     @OA\Property(property="id", type="integer", example=1),
 *                     @OA\Property(property="first_name", type="string", example="John"),
 *                     @OA\Property(property="last_name", type="string", example="Doe"),
 *                     @OA\Property(property="company_name", type="string", example="Example Corp"),
 *                     @OA\Property(property="number", type="string", example="+1234567890")
 *                 )
 *             ),
 *             @OA\Property(property="record_count", type="integer", example=25),
 *             @OA\Property(property="searchTerm", type="string", example="John")
 *         )
 *     ),
 *     @OA\Response(
 *         response=404,
 *         description="No exclude number records found",
 *         @OA\JsonContent(
 *             @OA\Property(property="success", type="boolean", example=false),
 *             @OA\Property(property="message", type="string", example="Exclude Number not found."),
 *             @OA\Property(property="data", type="array", @OA\Items(type="object")),
 *             @OA\Property(property="record_count", type="integer", example=0),
 *             @OA\Property(property="errors", type="array", @OA\Items(type="string")),
 *             @OA\Property(property="searchTerm", type="string", example="John")
 *         )
 *     )
 * )
 */

    public function getExcludeNumber()
    {
        $this->validate($this->request, [                     
            'lower_limit' => 'numeric',
            'upper_limit' => 'numeric'
        ]);
        $response = $this->model->excludeNumberDetail($this->request);
        return response()->json($response);
    }
    /*
     * Update Exclude Number detail
     * @return json
     */

     /**
 * @OA\Post(
 *     path="/edit-exclude-number",
 *     summary="Update an Exclude Number record",
 *     description="Updates an existing exclude number record by number and campaign_id. Optional fields like new_campaign_id, first_name, last_name, and company_name can be updated.",
 *     tags={"Exclude Number"},
 *     security={{"Bearer":{}}},
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\JsonContent(
 *             @OA\Property(property="number", type="string", example="9876543210"),
 *             @OA\Property(property="campaign_id", type="integer", example=12),
 *             @OA\Property(property="new_campaign_id", type="integer", example=15),
 *             @OA\Property(property="first_name", type="string", example="John"),
 *             @OA\Property(property="last_name", type="string", example="Doe"),
 *             @OA\Property(property="company_name", type="string", example="ABC Corp")
 *         )
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="Successfully updated the exclude number record",
 *         @OA\JsonContent(
 *             @OA\Property(property="success", type="string", example="true"),
 *             @OA\Property(property="message", type="string", example="Exclude Number updated successfully.")
 *         )
 *     ),
 *     @OA\Response(
 *         response=400,
 *         description="Failed to update the exclude number record",
 *         @OA\JsonContent(
 *             @OA\Property(property="success", type="string", example="false"),
 *             @OA\Property(property="message", type="string", example="Exclude Number are not updated successfully.")
 *         )
 *     ),
 *     @OA\Response(
 *         response=404,
 *         description="Exclude number record not found",
 *         @OA\JsonContent(
 *             @OA\Property(property="success", type="string", example="false"),
 *             @OA\Property(property="message", type="string", example="Exclude Number doesn't exist.")
 *         )
 *     )
 * )
 */

    public function editExcludeNumber()
    {
        $this->validate($this->request, [
            'number'        => 'required|numeric|regex:/^([0-9\s\-\+\(\)]*)$/|min:10',
            'campaign_id'   => 'required|numeric',
            'first_name'    => 'string',
            'last_name'     => 'string',
            'company_name'  => 'string',
            // 'id'            => 'required|numeric'
        ]);
        $response = $this->model->excludeNumberUpdate($this->request);
        //return response()->json($response);
         return response()->json(
        [
            'success' => $response['success'],
            'message' => $response['message']
        ],
        $response['code'] ?? 200
    );
    }
    /*
     *Add Exclude Number details
     *@return json
     */
    /**
 * @OA\Post(
 *     path="/add-exclude-number",
 *     summary="Add a number to the Exclude Number list",
 *     description="Adds a new number to the Exclude Number list based on the campaign and optional personal/company details.",
 *     tags={"Exclude Number"},
 *     security={{"Bearer":{}}},
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\JsonContent(
 *             @OA\Property(property="number", type="string", example="9876543210"),
 *             @OA\Property(property="campaign_id", type="integer", example=12),
 *             @OA\Property(property="first_name", type="string", example="John"),
 *             @OA\Property(property="last_name", type="string", example="Doe"),
 *             @OA\Property(property="company_name", type="string", example="ABC Corp")
 *         )
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="Successfully added to exclude number list",
 *         @OA\JsonContent(
 *             @OA\Property(property="success", type="string", example="true"),
 *             @OA\Property(property="message", type="string", example="Exclude Number added successfully.")
 *         )
 *     ),
 *     @OA\Response(
 *         response=400,
 *         description="Failed to add to exclude number list",
 *         @OA\JsonContent(
 *             @OA\Property(property="success", type="string", example="false"),
 *             @OA\Property(property="message", type="string", example="Exclude Number are not added successfully.")
 *         )
 *     )
 * )
 */

    public function addExcludeNumber()
    {
        $this->validate($this->request, [
            'number'        => 'required|numeric|regex:/^([0-9\s\-\+\(\)]*)$/|min:10',
            // 'campaign_id'   => 'required|numeric',
            'first_name'    => 'string',
            'last_name'     => 'string',
            'company_name'  => 'string',
            // 'id'            => 'required|numeric'
        ]);
        $response = $this->model->addExcludeNumber($this->request);
        return response()->json($response);
    }
    /*
     *Delete excludeNumber
     *@return json
     */
    /**
 * @OA\Post(
 *     path="/delete-exclude-number",
 *     summary="Delete an Exclude Number record",
 *     description="Deletes an exclude number record based on number and campaign_id.",
 *     tags={"Exclude Number"},
 *     security={{"Bearer":{}}},
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\JsonContent(
 *             @OA\Property(property="number", type="string", example="9876543210"),
 *             @OA\Property(property="campaign_id", type="integer", example=12)
 *         )
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="Successfully deleted the exclude number record",
 *         @OA\JsonContent(
 *             @OA\Property(property="success", type="string", example="true"),
 *             @OA\Property(property="message", type="string", example="Exclude Number deleted successfully.")
 *         )
 *     ),
 *     @OA\Response(
 *         response=400,
 *         description="Failed to delete the exclude number record",
 *         @OA\JsonContent(
 *             @OA\Property(property="success", type="string", example="false"),
 *             @OA\Property(property="message", type="string", example="Exclude Number are not deleted successfully.")
 *         )
 *     ),
 *     @OA\Response(
 *         response=404,
 *         description="Exclude number record not found",
 *         @OA\JsonContent(
 *             @OA\Property(property="success", type="string", example="false"),
 *             @OA\Property(property="message", type="string", example="Exclude Number doesn't exist.")
 *         )
 *     )
 * )
 */

    public function excludeNumberDelete()
    {
        $this->validate($this->request, [
            'number'        => 'required|numeric|regex:/^([0-9\s\-\+\(\)]*)$/|min:10',
            'campaign_id'   => 'required|numeric',
            // 'id'            => 'required|numeric'
        ]);
        $response = $this->model->excludeNumberDelete($this->request);
        return response()->json($response);
    }


    public function downloadExcludeNumber()
    {
        try {
            $db = DB::connection('mysql_' . $this->request->auth->parent_id);
            $records = $db->select("SELECT number, first_name, last_name, company_name, campaign_id, updated_at FROM exclude_number ORDER BY updated_at DESC");

            $callback = function () use ($records) {
                $handle = fopen('php://output', 'w');
                fputcsv($handle, ['Phone Number', 'First Name', 'Last Name', 'Company', 'Campaign ID', 'Last Updated']);
                foreach ($records as $row) {
                    $row = (array) $row;
                    fputcsv($handle, [
                        $row['number'] ?? '',
                        $row['first_name'] ?? '',
                        $row['last_name'] ?? '',
                        $row['company_name'] ?? '',
                        $row['campaign_id'] ?? '',
                        $row['updated_at'] ?? '',
                    ]);
                }
                fclose($handle);
            };

            $filename = 'exclude_list_' . date('Y-m-d_His') . '.csv';
            return response()->stream($callback, 200, [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to export Exclude list.'], 500);
        }
    }

    public function uploadExcludeNumber()
    {
        $uploadDir = '/var/www/html/api/upload/';

        // Handle actual multipart file upload from React frontend
        if ($this->request->hasFile('file')) {
            $uploadedFile = $this->request->file('file');
            $filename = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $uploadedFile->getClientOriginalName());
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            $uploadedFile->move($uploadDir, $filename);
            $filePath = $uploadDir . $filename;
        } elseif ($this->request->has('file')) {
            $filename = $this->request->input('file');
            $filePath = $uploadDir . $filename;
        } else {
            return response()->json(['success' => 'false', 'message' => 'No file provided.']);
        }

        if (!empty($filePath) && file_exists($filePath)) {
            $response = $this->model->uploadExcludeNumber($this->request, $filePath);
            return response()->json($response);
        }

        return response()->json(['success' => 'false', 'message' => 'Unable to upload file.']);
    }
}
