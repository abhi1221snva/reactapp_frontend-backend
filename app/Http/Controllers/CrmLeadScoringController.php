<?php

namespace App\Http\Controllers;

use App\Services\LeadScoringService;
use Illuminate\Http\Request;

/**
 * Lead scoring endpoints.
 *
 * GET  /crm/lead/{id}/score          — Get current score + breakdown
 * POST /crm/lead/{id}/score/recalc   — Recalculate and persist score
 * POST /crm/leads/score/recalc-batch — Recalculate all leads (manager+)
 */
/**
 * @OA\Get(
 *   path="/crm/lead/{id}/score",
 *   tags={"CRM"},
 *   summary="Get current lead score and grade",
 *   security={{"bearerAuth":{}}},
 *   @OA\Parameter(name="id", in="path", required=true, description="Lead ID", @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Lead score and grade (A/B/C/D/F)"),
 *   @OA\Response(response=401, description="Unauthenticated")
 * )
 *
 * @OA\Post(
 *   path="/crm/lead/{id}/score/recalc",
 *   tags={"CRM"},
 *   summary="Recalculate and persist lead score",
 *   security={{"bearerAuth":{}}},
 *   @OA\Parameter(name="id", in="path", required=true, description="Lead ID", @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Updated score and grade"),
 *   @OA\Response(response=401, description="Unauthenticated")
 * )
 *
 * @OA\Post(
 *   path="/crm/leads/score/recalc-batch",
 *   tags={"CRM"},
 *   summary="Recalculate scores for all leads (manager+ only)",
 *   security={{"bearerAuth":{}}},
 *   @OA\Response(response=200, description="Batch recalculation complete"),
 *   @OA\Response(response=403, description="Insufficient permissions"),
 *   @OA\Response(response=401, description="Unauthenticated")
 * )
 */
class CrmLeadScoringController extends Controller
{
    private function service(Request $request): LeadScoringService
    {
        return LeadScoringService::forClient($request->auth->parent_id);
    }

    public function show(Request $request, int $id)
    {
        try {
            $score = $this->service($request)->score($id);
            return $this->successResponse("Lead Score", [
                'lead_id' => $id,
                'score'   => $score,
                'grade'   => $this->grade($score),
            ]);
        } catch (\Throwable $e) {
            return $this->failResponse("Failed to get score", [$e->getMessage()], $e, 500);
        }
    }

    public function recalculate(Request $request, int $id)
    {
        try {
            $score = $this->service($request)->recalculate($id);
            return $this->successResponse("Score Recalculated", [
                'lead_id' => $id,
                'score'   => $score,
                'grade'   => $this->grade($score),
            ]);
        } catch (\Throwable $e) {
            return $this->failResponse("Failed to recalculate score", [$e->getMessage()], $e, 500);
        }
    }

    public function recalculateBatch(Request $request)
    {
        if (($request->auth->user_level ?? 0) < 5) {
            return $this->failResponse("Insufficient permissions", [], null, 403);
        }

        try {
            $updated = $this->service($request)->recalculateBatch();
            return $this->successResponse("Batch Score Recalculation Complete", ['updated' => $updated]);
        } catch (\Throwable $e) {
            return $this->failResponse("Failed to recalculate batch", [$e->getMessage()], $e, 500);
        }
    }

    private function grade(int $score): string
    {
        return match (true) {
            $score >= 80 => 'A',
            $score >= 60 => 'B',
            $score >= 40 => 'C',
            $score >= 20 => 'D',
            default      => 'F',
        };
    }
}
