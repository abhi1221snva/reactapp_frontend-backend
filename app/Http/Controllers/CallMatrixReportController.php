<?php

namespace App\Http\Controllers;

use App\Model\Client\CallAnalysisSummary;
use App\Model\Client\LeadScorecard;
use App\Model\Client\AgentPerformanceMetric;
use App\Model\Client\CallAnalysisLog;
use App\Model\Master\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * @OA\Post(
 *   path="/call-matrix-report",
 *   summary="Save CallChex call analysis result",
 *   operationId="callMatrixStore",
 *   tags={"Call Matrix"},
 *   security={{"Bearer":{}}},
 *   @OA\RequestBody(@OA\JsonContent(
 *     @OA\Property(property="reference_id", type="string"),
 *     @OA\Property(property="response_data", type="object")
 *   )),
 *   @OA\Response(response=200, description="Analysis saved"),
 *   @OA\Response(response=401, description="Unauthenticated")
 * )
 *
 * @OA\Post(
 *   path="/call-matrix/process",
 *   summary="Submit call recording to CallChex for analysis",
 *   operationId="callMatrixProcess",
 *   tags={"Call Matrix"},
 *   security={{"Bearer":{}}},
 *   @OA\RequestBody(@OA\JsonContent(
 *     @OA\Property(property="lead_id", type="integer"),
 *     @OA\Property(property="audio_url", type="string"),
 *     @OA\Property(property="agent_id", type="string"),
 *     @OA\Property(property="campaign_id", type="integer"),
 *     @OA\Property(property="recording_url", type="string")
 *   )),
 *   @OA\Response(response=200, description="Analysis initiated with reference_id"),
 *   @OA\Response(response=500, description="CallChex request failed")
 * )
 *
 * @OA\Get(
 *   path="/call-matrix-report/{reference_id}",
 *   summary="View call analysis result by reference ID",
 *   operationId="callMatrixView",
 *   tags={"Call Matrix"},
 *   security={{"Bearer":{}}},
 *   @OA\Parameter(name="reference_id", in="path", required=true, @OA\Schema(type="string", format="uuid")),
 *   @OA\Response(response=200, description="Call analysis result"),
 *   @OA\Response(response=404, description="Not found")
 * )
 */
class CallMatrixReportController extends Controller
{
    public function process1(Request $request)
    {
        Log::info('request->all()');
        $leadId =  41360;
        $data = $request->json()->all(); // or use $request->input()

        $postData = [
            'industry_id'       => $data['industry_id'] ?? null,
            'lead_phone_number' => $data['lead_phone_number'] ?? null,
            'agent_name'        => $data['agent_name'] ?? null,
            'audio_url'         => $data['audio_url'] ?? null,
            'agent_id'          => $data['agent_id'] ?? null,
            'from_number'       => $data['from_number'] ?? null,
            'start_timestamp'   => $data['start_timestamp'] ?? null,
            'end_timestamp'     => $data['end_timestamp'] ?? null,
            'campaign_id'       => $data['campaign_id'] ?? null,
            'campaign_name'     => $data['campaign_name'] ?? null,
            'call_status'       => $data['call_status'] ?? null,
            'recording_url'     => $data['recording_url'] ?? null,
        ];

        $jsonPayload = json_encode($postData);

        /*
        $ch = curl_init('https://callchex.com/api/webhook');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer tfstu4lCfmG02SpQx2xn6bwtFibnbSmonS7nNuLe',
            'Content-Type: application/json',
        ]);

        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);

        $postResponse = curl_exec($ch);
        $postStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($postStatus !== 200) {
            return response()->json(['error' => 'CallChex POST failed', 'response' => $postResponse], 500);
        }

        $postData = json_decode($postResponse, true);
        $referenceId = $postData['reference_id'] ?? null;
        */
        $referenceId = '985350c2-3e2d-449f-bab7-7ca024510b8c';

        if (!$referenceId) {
            return response()->json(['error' => 'No reference_id returned from CallChex'], 500);
        }
        else
        {
            $leadId = $leadId;
            $query = "UPDATE cdr SET call_matrix_reference_id = :ref WHERE id = :id";
            DB::connection('mysql_' . $request->auth->parent_id)->update($query, [
                'ref' => $referenceId,
                'id'  => $leadId
            ]);

            $query = "UPDATE cdr_archive SET call_matrix_reference_id = :ref WHERE id = :id";
            DB::connection('mysql_' . $request->auth->parent_id)->update($query, [
                'ref' => $referenceId,
                'id'  => $leadId
            ]);

            return response()->json(['success' => $referenceId], 200);
        }
    }

public function process(Request $request)
{
    try {
        
Log::info('CallChex process initiated.', ['request_data' => $request->all()]);

        $leadId = $request->input('lead_id');
        Log::info('Lead ID received: ' . $leadId);
        $data = $request->all();
        Log::info('Full request data:', $data);

        $postData = [
            'industry_id'       => $data['industry_id'] ?? null,
            'lead_phone_number' => $data['lead_phone_number'] ?? null,
            'agent_name'        => $data['agent_name'] ?? null,
            'audio_url'         => $data['audio_url'] ?? null,
            'agent_id'          => $data['agent_id'] ?? null,
            'from_number'       => $data['from_number'] ?? null,
            'start_timestamp'   => $data['start_timestamp'] ?? null,
            'end_timestamp'     => $data['end_timestamp'] ?? null,
            'campaign_id'       => $data['campaign_id'] ?? null,
            'campaign_name'     => $data['campaign_name'] ?? null,
            'call_status'       => $data['call_status'] ?? null,
            'recording_url'     => $data['recording_url'] ?? null,
        ];

        Log::info('Prepared payload for CallChex:', $postData);

        $jsonPayload = json_encode($postData);
        $clientDb = 'mysql_' . $request->auth->parent_id;
        $client = Client::where('id',$request->auth->parent_id)->first();
        $api_key= $client->call_matrix_api_key;
       
        $ch = curl_init('https://callchex.com/api/webhook');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $api_key,
            'Content-Type: application/json',
        ]);

        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);

        $postResponse = curl_exec($ch);
        $postStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($postStatus !== 200) {
            return response()->json(['error' => 'CallChex POST failed', 'response' => $postResponse], 500);
        }

        $postData = json_decode($postResponse, true);
        $referenceId = $postData['reference_id'] ?? null;
        

        // Simulated reference ID for development
        // $referenceId = '985350c2-3e2d-449f-bab7-7ca024510b8c';
        
        Log::info('Reference ID: ' . $referenceId);

        if (!$referenceId) {
            Log::error('No reference_id returned from CallChex');
            return response()->json(['error' => 'No reference_id returned from CallChex'], 500);
        }


        DB::connection($clientDb)->update("UPDATE cdr SET call_matrix_reference_id = :ref WHERE id = :id", [
            'ref' => $referenceId,
            'id'  => $leadId
        ]);
        Log::info("Updated cdr table for lead ID: $leadId");

        DB::connection($clientDb)->update("UPDATE cdr_archive SET call_matrix_reference_id = :ref WHERE id = :id", [
            'ref' => $referenceId,
            'id'  => $leadId
        ]);
        Log::info("Updated cdr_archive table for lead ID: $leadId");

        return response()->json(['success' => $referenceId,'message' => 'Analysis created successfully.'], 200);
    } catch (\Exception $e) {
        Log::error('Exception in CallChex process:', ['error' => $e->getMessage()]);
        return response()->json([
            'error' => 'An error occurred during processing.',
            'message' => $e->getMessage()
        ], 500);
    }
}

    public function view(Request $request, string $reference_id)
    {

        $clientDb = 'mysql_' . $request->auth->parent_id;
    Log::info('clientDb: ' . $clientDb);

        $model = (new CallAnalysisSummary())->setConnection($clientDb);

        $existingAnalysis = $model->newQuery()->where('reference_id', $reference_id)->first();
    Log::info('existingAnalysis: ' . $existingAnalysis);

        if ($existingAnalysis) {
            return $this->show($request, $reference_id);
        }

        $referenceId = $reference_id; //'985350c2-3e2d-449f-bab7-7ca024510b8c'
        $getUrl = "https://callchex.com/api/webhook/results/{$referenceId}";
        $ch = curl_init($getUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer tfstu4lCfmG02SpQx2xn6bwtFibnbSmonS7nNuLe',
        ]);

        $getResponse = curl_exec($ch);
        $getStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($getStatus !== 200) {
            return response()->json(['error' => 'CallChex GET failed', 'response' => $getResponse], 500);
        }

        $result = json_decode($getResponse, true);
        $request->merge(['reference_id' => $referenceId]);
        return $this->store($request, $result);
    }

    public function store(Request $request, $response = null)
    {
        $response = $response ?? $request->all();

        if (!isset($response['reference_id']) 
            || !isset($response['response_data']) 
            || !isset($request->auth->parent_id)
        ) {
            return response()->json(['error' => 'Invalid request payload'], 422);
        }

        $clientDb = 'mysql_' . $request->auth->parent_id;

        DB::connection($clientDb)->transaction(function () use ($response, $clientDb, $request) {

            // 1. Save call_analysis_logs
            $this->storeAnalysisLog($response, $clientDb, $request);

            // 2. Summary
            $summary = new CallAnalysisSummary();
            $summary->setConnection($clientDb);
            $summary->reference_id           = $response['reference_id'];
            $summary->agent_id               = $request->agent_id ?? null;
            $summary->campaign_id            = $request->campaign_id ?? null;
            $summary->total_score            = $response['response_data']['lead_scorecard_summary']['total_score'] ?? null;
            $summary->max_score              = $response['response_data']['lead_scorecard_summary']['max_score'] ?? null;
            $summary->percentage             = $response['response_data']['lead_scorecard_summary']['percentage'] ?? null;
            $summary->agent_total_score      = $response['response_data']['agent_performance_summary']['total_score'] ?? null;
            $summary->agent_max_score        = $response['response_data']['agent_performance_summary']['max_score'] ?? null;
            $summary->agent_average_score    = $response['response_data']['agent_performance_summary']['average_score'] ?? null;
            $summary->lead_category_emoji    = $response['response_data']['summary']['lead_category']['emoji'] ?? null;
            $summary->lead_category_desc     = $response['response_data']['summary']['lead_category']['description'] ?? null;
            $summary->coaching_recommendation= $response['response_data']['summary']['coaching_recommendation']['description'] ?? null;
            $summary->save();

            // 3. Lead Scorecards
            foreach ($response['response_data']['lead_scorecard'] ?? [] as $item) {
                $score = new LeadScorecard();
                $score->setConnection($clientDb);
                $score->analysis_id    = $summary->id;
                $score->category       = $item['category'];
                $score->score          = $item['score'];
                $score->score_display  = $item['score_display'];
                $score->notes          = $item['notes'];
                $score->save();
            }

            // 4. Agent Performance Metrics
            foreach ($response['response_data']['agent_performance_metrics'] ?? [] as $item) {
                $metric = new AgentPerformanceMetric();
                $metric->setConnection($clientDb);
                $metric->analysis_id   = $summary->id;
                $metric->category      = $item['category'];
                $metric->score         = $item['score'];
                $metric->score_display = $item['score_display'];
                $metric->notes         = $item['notes'];
                $metric->save();
            }
        });

        $model = (new CallAnalysisSummary())->setConnection($clientDb);

        $existingAnalysis = $model->newQuery()->where('reference_id', $response['reference_id'])->first();

        if ($existingAnalysis) {
            return $this->show($request, $response['reference_id']);
        }

        return response()->json(['message' => 'Call analysis saved successfully']);
    }

    public function storeAnalysisLog($response, $clientDb, $request)
    {
        $log = new CallAnalysisLog();
        $log->setConnection($clientDb);
        $log->reference_id = $response['reference_id'];
        $log->agent_id     = $request->agent_id ?? null;
        $log->campaign_id  = $request->campaign_id ?? null;
        $log->response_json = json_encode($response, JSON_PRETTY_PRINT);
        $log->save();
    }

    public function show(Request $request, $reference_id)
    {
        $clientDb = 'mysql_' . $request->auth->parent_id;
        $model = (new CallAnalysisSummary())->setConnection($clientDb);
        $analysis = $model->newQuery()->with(['leadScorecards', 'agentMetrics'])->Where('reference_id', $reference_id)->firstOrFail();
        return $this->successResponse("Call Matrix Result", [$analysis]);
        
    }


}
