<?php


namespace App\Services;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class EasifyCreditService
{


    public function checkCredits($userId, $easifyUserUuid, $action, $resource, $count)
    {
        $response = Http::withHeaders([
            'X-Application-Token' => config('services.phonify.app_token'),
            'X-Easify-User-Token' => $easifyUserUuid,
            'Accept' => 'application/json',
        ])->post(
            config('services.phonify.easify_url') . '/api/user/credits/check',
            [
                'action'   => $action,
                'resource' => $resource,
                'count'    => $count,
            ]
        )->json();

        // ✅ Log only useful info
        if (
            empty($response['data']) ||
            ($response['data']['has_sufficient_credits'] ?? false) === false
        ) {
            Log::warning('Easify credit check failed', [
                'user_id' => $userId,
                'action'  => $action,
                'resource'=> $resource,
                'response'=> $response
            ]);
        }

        return $response;
    }

    public function deductCredits($userId, $easifyUserUuid, $action, $resource, $count)
    {
        $response = Http::withHeaders([
            'X-Application-Token' => config('services.phonify.app_token'),
            'X-Easify-User-Token' => $easifyUserUuid,
            'Accept' => 'application/json',
        ])->post(
            config('services.phonify.easify_url') . '/api/user/credits/deduct',
            [
                'action'            => $action,
                'resource'          => $resource,
                'count'             => $count,
                'skip_credit_check' => true,
            ]
        )->json();

        // 🚨 Critical log if deduction fails
        if (($response['success'] ?? false) === false) {
            Log::error('Easify credit deduction failed', [
                'user_id' => $userId,
                'action'  => $action,
                'resource'=> $resource,
                'response'=> $response
            ]);
        }

        return $response;
    }
}



