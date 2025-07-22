<?php

namespace App\Http\Controllers;

use App\Model\Mailbox;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MailboxController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    private $request;
    public function __construct(Request $request, Mailbox $mailbox)
    {
        $this->request = $request;
        $this->model = $mailbox;
    }

    /*
     * Fetch call data report
     * @return json
     */

    /**
 * @OA\Post(
 *     path="/mailbox",
 *     summary="Get Mailbox Report",
 *     description="Fetches the mailbox records based on the provided start and end dates. Extension and pagination limits are optional.",
 *     tags={"Mailbox"},
 *     security={{"Bearer":{}}},
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\JsonContent(
 *             required={"start_date", "end_date"},
 *             @OA\Property(property="start_date", type="string", format="date", example="2024-01-01", description="Start date for filtering records (YYYY-MM-DD)"),
 *             @OA\Property(property="end_date", type="string", format="date", example="2024-01-31", description="End date for filtering records (YYYY-MM-DD)"),
 *             @OA\Property(property="extesnion", type="string", example="38080", description="(Optional) Extension number to filter mailbox records"),
 *             @OA\Property(property="lower_limit", type="integer", example=0, description="(Optional) Lower limit for pagination"),
 *             @OA\Property(property="upper_limit", type="integer", example=10, description="(Optional) Upper limit for pagination"),
 *             @OA\Property(property="id", type="integer", example=358, description="(Optional) Upper limit for pagination"),
 *         )
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="Successful Mailbox Report Retrieval",
 *         @OA\JsonContent(
 *             @OA\Property(property="success", type="string", example="true"),
 *             @OA\Property(property="message", type="string", example="MailBox Report."),
 *             @OA\Property(property="record_count", type="integer", example=50),
 *             @OA\Property(
 *                 property="data",
 *                 type="array",
 *                 @OA\Items(
 *                     @OA\Property(property="id", type="integer", example=1),
 *                     @OA\Property(property="ani", type="string", example="9876543210"),
 *                     @OA\Property(property="vm_file_location", type="string", example="/path/to/file.wav"),
 *                     @OA\Property(property="status", type="string", example="read"),
 *                     @OA\Property(property="extension", type="string", example="1001"),
 *                     @OA\Property(property="date", type="string", format="date", example="2024-04-01")
 *                 )
 *             )
 *         )
 *     ),
 *     @OA\Response(
 *         response=400,
 *         description="Invalid input or No MailBox Report found",
 *         @OA\JsonContent(
 *             @OA\Property(property="success", type="string", example="false"),
 *             @OA\Property(property="message", type="string", example="MailBox Report doesn't exist.")
 *         )
 *     ),
 *     @OA\Response(
 *         response=500,
 *         description="Internal Server Error"
 *     )
 * )
 */

    public function getMailbox(Request $request)
    {
        Log::info('mailbox reached',[$request->all()]);
        $this->validate($this->request, [
            //'number'        => 'numeric',
            //'campaign'      => 'numeric',
            //'disposition'   => 'numeric',
            'type'          => 'string',
            'start_date'    => 'date',
            'end_date'      => 'date',
            'lower_limit'   => 'numeric',
            'upper_limit'   => 'numeric'
        ]);
        $response = $this->model->getMailbox($this->request);
        return response()->json($response);
    }


    /**
     * @OA\Post(
     *     path="/edit-mailbox",
     *     tags={"Mailbox"},
     *     summary="Update mailbox status",
     *     description="Updates the status of a mailbox by its ID",
     *     operationId="updateMailbox",
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"mailbox_id", "status"},
     *             @OA\Property(property="mailbox_id", type="integer", example=5, description="ID of the mailbox"),
     *             @OA\Property(property="status", type="integer", example=1, description="Status value to update")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Mailbox updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="mailbox updated successfully.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid input or update failed",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="mailbox are not updated successfully.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Mailbox not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="mailbox doesn't exist.")
     *         )
     *     )
     * )
     */
    public function editMailBox()
    {

        $this->validate($this->request, [

            'status'   => 'required|numeric',
            'mailbox_id'        => 'required|numeric'
        ]);
        $response = $this->model->updateMailBox($this->request);
        return response()->json($response);
    }

    /*
     * Fetch live calls
     * @return json
     */

    public function getUnreadMailBox()
    {
        $response = $this->model->getUnreadMailBox($this->request);
        return response()->json($response);
    }

    /**
     * @OA\post(
     *     path="/delete-mailbox",
     *     summary="Delete a mailbox",
     *     description="Deletes a mailbox by ID for the authenticated user's parent client database.",
     *     tags={"Mailbox"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"mailbox_id"},
     *             @OA\Property(property="mailbox_id", type="integer", example=123)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Mailbox deletion status",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Mailbox deleted successfully.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Mailbox ID missing or deletion failed",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Mailbox doesn't exist.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="An unexpected error occurred.")
     *         )
     *     )
     * )
     */


    public function deleteMailbox()
    {

        $response = $this->model->deleteMailbox($this->request);
        return response()->json($response);
    }
}
