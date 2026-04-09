<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use OpenApi\Analysers\DocBlockAnnotationFactory;
use OpenApi\Analysers\TokenAnalyser;
use OpenApi\Generator;

/**
 * Serves the OpenAPI specification for the Rocket Dialer API.
 * All routes in this controller are protected by jwt.auth + auth.sysadmin
 * (level 11 — system_administrator only).
 */
class SwaggerController extends Controller
{
    private const SPEC_PATH = 'api-docs/api-docs.json';

    // ── GET /system/swagger-spec ──────────────────────────────────────────────
    // Returns the OpenAPI JSON spec.
    // Uses a cached file if available; otherwise generates from annotations.

    /**
     * @OA\Get(
     *     path="/system/swagger-spec",
     *     summary="Return the OpenAPI 3.0 specification JSON",
     *     description="Generates and returns the full Rocket Dialer OpenAPI spec. Restricted to system_administrator (level 11).",
     *     tags={"System"},
     *     security={{"Bearer":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="OpenAPI 3.0 JSON specification",
     *         @OA\MediaType(
     *             mediaType="application/json",
     *             @OA\Schema(type="object")
     *         )
     *     ),
     *     @OA\Response(response=403, description="Forbidden — system_administrator only"),
     *     @OA\Response(response=500, description="Failed to generate spec")
     * )
     */
    public function spec(Request $request): JsonResponse
    {
        try {
            $specPath = storage_path(self::SPEC_PATH);
            $forceRegen = (bool) $request->query('regenerate', false);

            if (!$forceRegen && file_exists($specPath)) {
                $json = file_get_contents($specPath);
                return response()->json(json_decode($json, true))
                    ->header('X-Swagger-Source', 'cache')
                    ->header('Access-Control-Allow-Origin', '*');
            }

            return $this->generateAndServe($specPath);
        } catch (\Throwable $e) {
            return $this->failResponse('Failed to generate OpenAPI spec', [$e->getMessage()], $e, 500);
        }
    }

    // ── POST /system/swagger-regenerate ──────────────────────────────────────
    // Forces regeneration of the OpenAPI spec and returns the fresh version.

    /**
     * @OA\Post(
     *     path="/system/swagger-regenerate",
     *     summary="Force-regenerate the OpenAPI spec from controller annotations",
     *     tags={"System"},
     *     security={{"Bearer":{}}},
     *     @OA\Response(response=200, description="Spec regenerated successfully"),
     *     @OA\Response(response=403, description="Forbidden — system_administrator only"),
     *     @OA\Response(response=500, description="Failed to regenerate spec")
     * )
     */
    public function regenerate(): JsonResponse
    {
        try {
            $specPath = storage_path(self::SPEC_PATH);
            return $this->generateAndServe($specPath);
        } catch (\Throwable $e) {
            return $this->failResponse('Failed to regenerate OpenAPI spec', [$e->getMessage()], $e, 500);
        }
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function generateAndServe(string $specPath): JsonResponse
    {
        $analyser = new TokenAnalyser([new DocBlockAnnotationFactory()]);
        $openapi = Generator::scan([base_path('app')], [
            'analyser' => $analyser,
            'logger'   => new \Psr\Log\NullLogger(),
        ]);

        if (!$openapi) {
            return $this->failResponse('No OpenAPI annotations found in app/', [], null, 500);
        }

        $json = $openapi->toJson();

        // Ensure the storage directory exists
        $dir = dirname($specPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($specPath, $json);

        return response()->json(json_decode($json, true))
            ->header('X-Swagger-Source', 'generated')
            ->header('Access-Control-Allow-Origin', '*');
    }
}
