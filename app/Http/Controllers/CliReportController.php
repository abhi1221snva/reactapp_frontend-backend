<?php

namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Model\Client\CliReport;
use App\Model\Master\Did;
use App\Model\Master\CnamCliReport;
use App\Model\Master\AsteriskServer;
use Illuminate\Pagination\Paginator;





class CliReportController extends Controller
{
    /**
     * @OA\Post(
     *     path="/cli-report",
     *     summary="Get CLI Report List",
     *     description="Fetch CLI Report list based on optional search term and pagination limits.",
     *     tags={"Reports"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="search",
     *         in="query",
     *         description="Search term for `cli` or `cnam` (prefix match).",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="lower_limit",
     *         in="query",
     *         description="Offset for pagination.",
     *         required=false,
     *         @OA\Schema(type="integer", minimum=0)
     *     ),
     *     @OA\Parameter(
     *         name="upper_limit",
     *         in="query",
     *         description="Number of records to fetch for pagination.",
     *         required=false,
     *         @OA\Schema(type="integer", minimum=1)
     *     ),
     *     @OA\Response(
     *         response="200",
     *         description="CLI Report List Response",
     *         @OA\JsonContent(
     *             type="object",
     *             properties={
     *                 @OA\Property(property="success", type="boolean", example=true),
     *                 @OA\Property(property="message", type="string", example="CLI Report List."),
     *                 @OA\Property(property="record_count", type="integer", example=100),
     *                 @OA\Property(
     *                     property="data",
     *                     type="array",
     *                     @OA\Items(
     *                         type="object",
     *                         properties={
     *                             @OA\Property(property="id", type="integer"),
     *                             @OA\Property(property="cli", type="string"),
     *                             @OA\Property(property="cnam", type="string"),
     *                             @OA\Property(property="created_at", type="string", format="date-time")
     *                         }
     *                     )
     *                 )
     *             }
     *         )
     *     ),
     *     @OA\Response(
     *         response="500",
     *         description="Internal Server Error"
     *     )
     * )
     */
    public function index(Request $request)
{
    try {
        $searchTerm = $request->input('search');
        $limitString = '';
        $parameters = [];

        $query = "SELECT SQL_CALC_FOUND_ROWS * FROM cli_report";

        if (!empty($searchTerm)) {
            $query .= " WHERE (cli LIKE CONCAT(?, '%') OR cnam LIKE CONCAT(?, '%'))";
            $parameters[] = $searchTerm;
            $parameters[] = $searchTerm;
        }

        if ($request->has('lower_limit') && $request->has('upper_limit') && is_numeric($request->input('lower_limit')) && is_numeric($request->input('upper_limit'))) {
            $query .= " LIMIT ?, ?";
            $parameters[] = $request->input('lower_limit');
            $parameters[] = $request->input('upper_limit');
        }

        $record = DB::connection('mysql_' . $request->auth->parent_id)->select($query, $parameters);

        $recordCount = DB::connection('mysql_' . $request->auth->parent_id)->selectOne("SELECT FOUND_ROWS() as count");
        $recordCount = (array)$recordCount;

        $cli_report = (array)$record;

        if (!empty($cli_report)) {
            return [
                'success' => true,
                'message' => 'CLI Report List.',
                'data' => $cli_report,
                'record_count' => $recordCount['count'],
            ];
        }

        return [
            'success' => false,
            'message' => 'CLI Report List not found.',
            'data' => [],
            'record_count' => 0,
        ];
    } catch (Exception $e) {
        Log::error($e->getMessage());
    } catch (InvalidArgumentException $e) {
        Log::error($e->getMessage());
    }
}

  
/**
     * @OA\Get(
     *     path="/find-cli-report/{number}",
     *     summary="Find CLI Report by Number",
     *     description="Fetches the CLI report for a specific number based on the CLI value and returns the data.",
     *     tags={"Reports"},
     *      security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="number",
     *         in="query",
     *         description="The CLI number to find the report for.",
     *         required=true,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response="200",
     *         description="Successfully fetched the CLI report data",
     *         @OA\JsonContent(
     *             type="object",
     *             properties={
     *                 @OA\Property(property="success", type="boolean", example=true),
     *                 @OA\Property(property="message", type="string", example="Manually Call data"),
     *                 @OA\Property(property="data", type="array", items=@OA\Items(type="object"))
     *             }
     *         )
     *     ),
     *     @OA\Response(
     *         response="400",
     *         description="Bad request due to missing or incorrect parameters"
     *     ),
     *     @OA\Response(
     *         response="404",
     *         description="CLI report not found"
     *     ),
     *     @OA\Response(
     *         response="500",
     *         description="Internal Server Error"
     *     )
     * )
     */
    public function findCliReport(Request $request) {
        $cli = $request->number;
        $CnamCliReport = CnamCliReport::where('cli',$cli)->orderBy('id','DESC')->get()->first();
            return $this->successResponse("Manully Call data", [$CnamCliReport]);



    }
/**
     * @OA\Post(
     *     path="/run-manually-call-for-cname",
     *     summary="Create a Manual Call",
     *     description="Create a manual call request by writing a `.call` file and transferring it to an Asterisk server.",
     *     tags={"Reports"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="number",
     *         in="query",
     *         description="The CLI number for the manual call.",
     *         required=true,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response="200",
     *         description="Successfully created the manual call",
     *         @OA\JsonContent(
     *             type="object",
     *             properties={
     *                 @OA\Property(property="success", type="boolean", example=true),
     *                 @OA\Property(property="message", type="string", example="Manually Call created"),
     *                 @OA\Property(property="data", type="array", items=@OA\Items(type="object"))
     *             }
     *         )
     *     ),
     *     @OA\Response(
     *         response="400",
     *         description="Bad request due to missing or incorrect parameters"
     *     ),
     *     @OA\Response(
     *         response="500",
     *         description="Internal Server Error"
     *     )
     * )
     */
    public function callManually(Request $request)
    {
        /*$did = Did::where('cli',$request->number)->get()->first();

        if($did) {

        }*/
            $cli = $request->number;
        $CnamCliReport = new CnamCliReport();
                    $CnamCliReport->setConnection("master");
                    $CnamCliReport->cli = $cli;
                    /*$CnamCliReport->cnam = $cnam;
                    $CnamCliReport->created_date = $created_date;*/
                    $CnamCliReport->parent_id = $request->auth->parent_id;
                    $CnamCliReport->saveOrFail();


            $content = "Channel: SIP/Airespring1/#135196219859805718\nCallerId: $cli\nContext: callfile-detect\nExtension: s\nPriority: 1\n";
            $file_name = $cli;
            $file = fopen($file_name.".call", 'w');
            fwrite($file, $content);
            $rootPath = '/var/www/html/branch/backend/public/';
            $convertedFilename = $rootPath . $file_name . ".call";

            //new
            $AsteriskServer = AsteriskServer::list();
            if($AsteriskServer)
            {
                foreach($AsteriskServer as $server)
                {
                    $strAsteriskPath = "root@" . $server['domain'] .":/var/spool/asterisk/outgoing/";
                    shell_exec("scp -P 10347 $convertedFilename $strAsteriskPath");
                }
            }

            $path=$rootPath.$file_name . ".call";
            if(unlink($path)){
            } 
            //close


            /*$strAsteriskPath = "root@sip1.domain.com:/var/spool/asterisk/outgoing/";
            shell_exec("scp -P 10347 $convertedFilename $strAsteriskPath");*/

            return $this->successResponse("Manully Call created", []);
        
    }
 /**
     * @OA\Post(
     *     path="/run-manually-call-for-did",
     *     summary="Create a Manual Call for DID",
     *     description="Create a manual call request for DID by writing a `.call` file and transferring it to an Asterisk server to play a message.",
     *     tags={"Reports"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="did_value",
     *         in="query",
     *         description="The CLI value for the DID (Direct Inward Dialing).",
     *         required=true,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="number",
     *         in="query",
     *         description="The phone number to be used for the call.",
     *         required=true,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response="200",
     *         description="Successfully created the manual call for DID",
     *         @OA\JsonContent(
     *             type="object",
     *             properties={
     *                 @OA\Property(property="success", type="boolean", example=true),
     *                 @OA\Property(property="message", type="string", example="Manually DID Call created"),
     *                 @OA\Property(property="data", type="array", items=@OA\Items(type="object"))
     *             }
     *         )
     *     ),
     *     @OA\Response(
     *         response="400",
     *         description="Bad request due to missing or incorrect parameters"
     *     ),
     *     @OA\Response(
     *         response="500",
     *         description="Internal Server Error"
     *     )
     * )
     */
    public function callManuallyDID(Request $request)
    {
        $cli = $request->did_value;
        $phone_number = $request->number;

        $content = "Channel: SIP/voxox1/1$phone_number\nCallerId: $cli\nContext: callfile-detect-play-message\nExtension: s\nPriority: 1\n";
        $file_name = $cli;

        $file = fopen($file_name.".call", 'w');
        fwrite($file, $content);
        $rootPath = '/var/www/html/branch/backend/public/';
        $convertedFilename = $rootPath . $file_name . ".call";

        $AsteriskServer = AsteriskServer::list();
        if($AsteriskServer)
        {
            foreach($AsteriskServer as $server)
            {
                $strAsteriskPath = "root@" . $server['domain'] .":/var/spool/asterisk/outgoing/";
                shell_exec("scp -P 10347 $convertedFilename $strAsteriskPath");
            }
        }

        $path=$rootPath.$file_name . ".call";
        if(unlink($path)){
        } 
    } 
    // function fetch_data(Request $request)
    // {
    //  if($request->ajax())
    //  {
    //   $sort_by = $request->get('sortby');
    //   $sort_type = $request->get('sorttype');
    //         $query = $request->get('query');
    //         $query = str_replace(" ", "%", $query);
    //   $data = CliReport::
    //                 where('id', 'like', '%'.$query.'%')
    //                 ->orWhere('cli', 'like', '%'.$query.'%')
    //                 ->orWhere('cnam', 'like', '%'.$query.'%')
    //                 ->orderBy($sort_by, $sort_type)
    //                 ->paginate(5);
    //   return view('cli-report.list', compact('data'))->render();
    //  }
    // }
}
