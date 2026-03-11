<?php

namespace App\Http\Controllers;

use App\Model\Client\PlivoTrunk;
use App\Services\PlivoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PlivoTrunkController extends Controller
{
    // -- List trunks (Plivo Applications) -----------------------------------------

    public function list(Request $request)
    {
        $clientId = $request->auth->parent_id ?: $request->auth->id;
        $conn     = "mysql_{$clientId}";

        // Sync live applications from Plivo so externally-created apps appear here
        try {
            $service = PlivoService::forClient($clientId);
            $apps    = $service->listApplications();

            foreach ($apps as $a) {
                PlivoTrunk::on($conn)->updateOrCreate(
                    ['app_id' => $a['app_id']],
                    [
                        'app_name'   => $a['app_name'],
                        'answer_url' => $a['answer_url'] ?? null,
                        'hangup_url' => $a['hangup_url'] ?? null,
                        'status'     => 'active',
                    ]
                );
            }
        } catch (\Exception $e) {
            // Serve from local cache on API failure
            Log::warning('Plivo trunk sync skipped', ['client' => $clientId, 'err' => $e->getMessage()]);
        }

        $trunks = PlivoTrunk::on($conn)->where('status', 'active')->get();

        return $this->successResponse('OK', ['trunks' => $trunks]);
    }

    // -- Create trunk (Plivo Application) -----------------------------------------

    public function create(Request $request)
    {
        $clientId = $request->auth->parent_id ?: $request->auth->id;
        $conn     = "mysql_{$clientId}";
        $appName  = $request->input('app_name');

        if (!$appName) {
            return $this->failResponse('app_name is required.', [], null, 422);
        }

        $urls = array_filter([
            'answer_url'    => $request->input('answer_url'),
            'answer_method' => $request->input('answer_method'),
            'hangup_url'    => $request->input('hangup_url'),
            'hangup_method' => $request->input('hangup_method'),
        ]);

        try {
            $service = PlivoService::forClient($clientId);
            $data    = $service->createApplication($appName, $urls);

            $trunk = PlivoTrunk::on($conn)->create([
                'app_id'     => $data['app_id'],
                'app_name'   => $data['app_name'],
                'answer_url' => $data['answer_url'],
                'hangup_url' => $data['hangup_url'] ?? null,
                'status'     => 'active',
            ]);

            return $this->successResponse('Trunk created.', ['trunk' => $trunk]);

        } catch (\Exception $e) {
            Log::error('Plivo create trunk', ['client' => $clientId, 'err' => $e->getMessage()]);
            return $this->failResponse('Failed to create trunk.', [$e->getMessage()], $e, 500);
        }
    }

    // -- Update trunk (Plivo Application) -----------------------------------------

    public function update(Request $request, string $appId)
    {
        $clientId = $request->auth->parent_id ?: $request->auth->id;
        $conn     = "mysql_{$clientId}";

        $trunk = PlivoTrunk::on($conn)->where('app_id', $appId)->first();
        if (!$trunk) {
            return $this->failResponse('Trunk not found.', [], null, 404);
        }

        $params = array_filter($request->only([
            'app_name',
            'answer_url',
            'answer_method',
            'hangup_url',
            'hangup_method',
            'message_url',
            'message_method',
        ]));

        try {
            $service = PlivoService::forClient($clientId);
            $service->updateApplication($appId, $params);

            // Sync local record
            $allowed = ['app_name', 'answer_url', 'hangup_url'];
            foreach ($allowed as $field) {
                if (isset($params[$field])) {
                    $trunk->{$field} = $params[$field];
                }
            }
            $trunk->save();

            return $this->successResponse('Trunk updated.', ['trunk' => $trunk]);

        } catch (\Exception $e) {
            Log::error('Plivo update trunk', ['client' => $clientId, 'err' => $e->getMessage()]);
            return $this->failResponse('Failed to update trunk.', [$e->getMessage()], $e, 500);
        }
    }

    // -- Delete trunk (Plivo Application) -----------------------------------------

    public function delete(Request $request, string $appId)
    {
        $clientId = $request->auth->parent_id ?: $request->auth->id;
        $conn     = "mysql_{$clientId}";

        $trunk = PlivoTrunk::on($conn)->where('app_id', $appId)->first();
        if (!$trunk) {
            return $this->failResponse('Trunk not found.', [], null, 404);
        }

        try {
            $service = PlivoService::forClient($clientId);
            $service->deleteApplication($appId);

            $trunk->status = 'deleted';
            $trunk->save();

            return $this->successResponse('Trunk deleted.');

        } catch (\Exception $e) {
            Log::error('Plivo delete trunk', ['client' => $clientId, 'err' => $e->getMessage()]);
            return $this->failResponse('Failed to delete trunk.', [$e->getMessage()], $e, 500);
        }
    }
}