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
                 'resource' => $this->formatToE164($resource),
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
                'resource' => $this->formatToE164($resource),
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
    private function formatToE164($number)
{
    // Remove all non-digit characters
    $clean = preg_replace('/\D/', '', $number);

    // If number length is 10 → assume US and add country code 1
    if (strlen($clean) === 10) {
        $clean = '1' . $clean;
    }

    // If already has country code (11+ digits), use as it is

    return '+' . $clean;
}

}



