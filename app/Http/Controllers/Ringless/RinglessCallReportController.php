<?php

namespace App\Http\Controllers\Ringless;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Model\Client\Ringless\RinglessCdr;
use App\Model\Client\Ringless\RinglessCdrArchive;
use Illuminate\Support\Facades\Log;
use App\Services\TimezoneService;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class RinglessCallReportController extends Controller
{

    /**
     * @OA\Get(
     *     path="/ringless/reports/call-data",
     *     summary="ringless call-data report",
     *     tags={"RinglessCallReport"},
     *     security={{"Bearer": {}}},
     *     @OA\Response(
     *         response=200,
     *         description="Successful response with ringless call-data ",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Ringless Lists data."),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="name", type="string", example="Basic Package"),
     *                     @OA\Property(property="price", type="number", format="float", example=19.99),
     *                     @OA\Property(property="duration", type="string", example="30 days")
     *                 )
     *             )
     *         )
     *     )
     * )
     */
    public function getDefaultReport(Request $request)
    {
        try {
            $query = RinglessCdr::on("mysql_" . $request->auth->parent_id);
            $queryArchive = RinglessCdrArchive::on("mysql_" . $request->auth->parent_id);

            $records = $query->union($queryArchive)->orderBy('start_time', 'DESC')->get();

            if ($records->isNotEmpty()) {
                $data = $records->toArray();

                return [
                    'success' => 'true',
                    'message' => 'Ringless Call Data Report.',
                    'data' => $data
                ];
            } else {
                return [
                    'success' => 'true',
                    'message' => 'No Ringless Call Data Report found.',
                    'data' => []
                ];
            }
        } catch (Exception $e) {
            Log::error($e->getMessage());
        } catch (InvalidArgumentException $e) {
            Log::error($e->getMessage());
        }

        return [
            'success' => 'false',
            'message' => 'Ringless Call Data Report does not exist.'
        ];
    }


    /**
     * @OA\Post(
     *     path="/ringless/reports/call-data",
     *     summary="Get Ringless Call Data Report",
     *     description="Generates a report of Ringless Call Data from both active and archived tables. Filters by number, campaign ID, and date range.",
     *     operationId="postRinglessCdrReport",
     *     tags={"RinglessCallReport"},
     *     security={{"Bearer": {}}},
     *     @OA\RequestBody(
     *         required=false,
     *         @OA\JsonContent(
     *             @OA\Property(property="number", type="integer", example=1234567890, description="Phone number to search for"),
     *             @OA\Property(property="campaign", type="integer", example=42, description="Campaign ID to filter by"),
     *             @OA\Property(property="start_date", type="string", format="date", example="2024-01-01", description="Start date (YYYY-MM-DD)"),
     *             @OA\Property(property="end_date", type="string", format="date", example="2024-01-31", description="End date (YYYY-MM-DD)")
     *         )
     *     ),
     * 
     *     @OA\Response(
     *         response=200,
     *         description="Report fetched successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Ringless Call Data Report."),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(type="object")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation failed",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Validation failed"),
     *             @OA\Property(property="errors", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Ringless Call Data Report doesnot exist.")
     *         )
     *     )
     * )
     */
    public function getReport(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'number' => 'numeric',
                'start_date' => 'date',
                'end_date' => 'date',
                'campaign' => 'numeric',
            ]);

            if ($validator->fails()) {
                return [
                    'success' => 'false',
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ];
            }

            $query = RinglessCdr::on("mysql_" . $request->auth->parent_id);
            $queryArchive = RinglessCdrArchive::on("mysql_" . $request->auth->parent_id);

            if ($request->has('number') && !empty($request->input('number'))) {
                $number = $request->input('number');
                $query->where('number', 'like', '%' . $number . '%'); // Match anywhere in the string
                $queryArchive->where('number', 'like', '%' . $number . '%'); // Match anywhere in the string
            }

            if ($request->has('campaign') && !empty($request->input('campaign'))) {
                $campaign = $request->input('campaign');
                $query->where('campaign_id', 'like', $campaign . '%');
                $queryArchive->where('campaign_id', 'like', $campaign . '%');
            }

            if ($request->has('start_date') && $request->has('end_date') && !empty($request->input('start_date')) && !empty($request->input('end_date'))) {
                $start = date('Y-m-d', strtotime($request->input('start_date'))) . " 00:00:00";
                $end = date('Y-m-d', strtotime($request->input('end_date'))) . " 23:59:59";
                $query->whereBetween('start_time', [$start, $end]);
                $queryArchive->whereBetween('start_time', [$start, $end]);
            }

            if (!empty($request->auth->timezone)) {
                $timezoneValue = (new TimezoneService())->findTimezoneValue($request->auth->timezone);
                $query->whereRaw('CONVERT_TZ(start_time, "+00:00", "' . $timezoneValue . '") BETWEEN ? AND ?', [$start, $end]);
                $queryArchive->whereRaw('CONVERT_TZ(start_time, "+00:00", "' . $timezoneValue . '") BETWEEN ? AND ?', [$start, $end]);
            }

            $records = $query->union($queryArchive)->orderBy('start_time', 'DESC')->get();

            if ($records->isNotEmpty()) {
                $data = $records->toArray();

                return [
                    'success' => 'true',
                    'message' => 'Ringless Call Data Report.',
                    'data' => $data
                ];
            } else {
                return [
                    'success' => 'true',
                    'message' => 'No Ringless Call Data Report found.',
                    'data' => []
                ];
            }
        } catch (Exception $e) {
            Log::error($e->getMessage());
        } catch (InvalidArgumentException $e) {
            Log::error($e->getMessage());
        }

        return [
            'success' => 'false',
            'message' => 'Ringless Call Data Report doesnot exist.'
        ];
    }
}
