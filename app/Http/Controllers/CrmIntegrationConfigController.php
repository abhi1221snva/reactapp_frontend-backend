<?php

namespace App\Http\Controllers;

use App\Model\Client\IntegrationConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Validation\Rule;

class CrmIntegrationConfigController extends Controller
{
    /**
     * Ensure the integration_configs table exists on the tenant DB.
     */
    private function ensureTable(string $conn): void
    {
        $sb = DB::connection($conn)->getSchemaBuilder();
        if (!$sb->hasTable('integration_configs')) {
            $sb->create('integration_configs', function (Blueprint $table) {
                $table->id();
                $table->string('provider', 50)->unique();
                $table->text('api_key')->nullable();
                $table->text('api_secret')->nullable();
                $table->string('endpoint_url', 500)->nullable();
                $table->json('extra_config')->nullable();
                $table->boolean('is_enabled')->default(false);
                $table->bigInteger('configured_by')->unsigned()->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * List all 8 provider slots (configured + empty stubs).
     */
    public function index(Request $request): JsonResponse
    {
        $conn = $this->tenantDb($request);
        $this->ensureTable($conn);

        $configs = IntegrationConfig::on($conn)->get()->keyBy('provider');

        $result = [];
        foreach (IntegrationConfig::PROVIDERS as $slug) {
            if ($configs->has($slug)) {
                $result[] = $configs->get($slug)->toSafeArray();
            } else {
                $result[] = [
                    'id'             => null,
                    'provider'       => $slug,
                    'has_api_key'    => false,
                    'has_api_secret' => false,
                    'endpoint_url'   => null,
                    'extra_config'   => null,
                    'is_enabled'     => false,
                    'configured_by'  => null,
                    'created_at'     => null,
                    'updated_at'     => null,
                ];
            }
        }

        return response()->json(['success' => true, 'data' => $result]);
    }

    /**
     * Create or update a provider configuration.
     */
    public function upsert(Request $request): JsonResponse
    {
        $this->validate($request, [
            'provider'     => ['required', Rule::in(IntegrationConfig::PROVIDERS)],
            'api_key'      => 'nullable|string|max:2000',
            'api_secret'   => 'nullable|string|max:2000',
            'endpoint_url' => 'nullable|string|max:500',
            'extra_config' => 'nullable|array',
            'is_enabled'   => 'nullable|boolean',
        ]);

        $conn = $this->tenantDb($request);
        $this->ensureTable($conn);

        $provider = $request->input('provider');
        $config = IntegrationConfig::on($conn)->firstOrNew(['provider' => $provider]);
        $config->setConnection($conn);

        // Only overwrite secrets when a non-empty value is sent
        if ($request->filled('api_key')) {
            $config->api_key = $request->input('api_key');
        }
        if ($request->filled('api_secret')) {
            $config->api_secret = $request->input('api_secret');
        }

        if ($request->has('endpoint_url')) {
            $config->endpoint_url = $request->input('endpoint_url');
        }
        if ($request->has('extra_config')) {
            $config->extra_config = $request->input('extra_config');
        }
        if ($request->has('is_enabled')) {
            $config->is_enabled = (bool) $request->input('is_enabled');
        }

        $config->configured_by = $request->auth->id ?? null;
        $config->save();

        return response()->json([
            'success' => true,
            'data'    => $config->toSafeArray(),
            'message' => 'Configuration saved.',
        ]);
    }

    /**
     * Toggle is_enabled for a config.
     */
    public function toggle(Request $request, int $id): JsonResponse
    {
        $conn = $this->tenantDb($request);
        $config = IntegrationConfig::on($conn)->findOrFail($id);
        $config->is_enabled = !$config->is_enabled;
        $config->save();

        return response()->json([
            'success' => true,
            'data'    => $config->toSafeArray(),
            'message' => $config->is_enabled ? 'Enabled.' : 'Disabled.',
        ]);
    }

    /**
     * Remove a provider configuration entirely.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $conn = $this->tenantDb($request);
        $config = IntegrationConfig::on($conn)->findOrFail($id);
        $config->delete();

        return response()->json([
            'success' => true,
            'message' => 'Configuration removed.',
        ]);
    }
}
