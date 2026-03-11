<?php

namespace App\Model\Master;

use Illuminate\Database\Eloquent\Model;

/**
 * Audit log for every step in the registration flow.
 *
 * @property int         $id
 * @property int|null    $registration_id  FK → prospect_initial_data.id
 * @property string      $step             e.g. registration_started
 * @property string|null $email
 * @property string|null $phone
 * @property array|null  $request_payload
 * @property array|null  $response_payload
 * @property string      $status           success | failure
 */
class RegistrationLog extends Model
{
    protected $connection = 'master';
    protected $table      = 'registration_logs';

    public $timestamps = false; // table has only created_at

    protected $fillable = [
        'registration_id',
        'step',
        'email',
        'phone',
        'request_payload',
        'response_payload',
        'status',
        'created_at',
    ];

    protected $casts = [
        'request_payload'  => 'array',
        'response_payload' => 'array',
    ];

    // ----------------------------------------------------------------
    // Step constants
    // ----------------------------------------------------------------
    const STEP_STARTED          = 'registration_started';
    const STEP_EMAIL_OTP_SENT   = 'email_otp_sent';
    const STEP_EMAIL_VERIFIED   = 'email_verified';
    const STEP_PHONE_OTP_SENT   = 'phone_otp_sent';
    const STEP_PHONE_VERIFIED   = 'phone_verified';
    const STEP_USER_CREATED     = 'user_created';
    const STEP_CLIENT_DB_CREATED= 'client_db_created';
    const STEP_COMPLETED        = 'registration_completed';

    const STATUS_SUCCESS = 'success';
    const STATUS_FAILURE = 'failure';

    // ----------------------------------------------------------------
    // Helper
    // ----------------------------------------------------------------

    /**
     * Quick log writer.
     *
     * @param  string      $step
     * @param  string|null $email
     * @param  string|null $phone
     * @param  array       $requestPayload
     * @param  array       $responsePayload
     * @param  string      $status
     * @param  int|null    $registrationId
     */
    public static function log(
        string  $step,
        ?string $email           = null,
        ?string $phone           = null,
        array   $requestPayload  = [],
        array   $responsePayload = [],
        string  $status          = self::STATUS_SUCCESS,
        ?int    $registrationId  = null
    ): void {
        try {
            static::create([
                'registration_id'  => $registrationId,
                'step'             => $step,
                'email'            => $email,
                'phone'            => $phone,
                'request_payload'  => $requestPayload,
                'response_payload' => $responsePayload,
                'status'           => $status,
                'created_at'       => \Carbon\Carbon::now(),
            ]);
        } catch (\Throwable $e) {
            // Never crash the caller due to a logging failure
            \Illuminate\Support\Facades\Log::error('RegistrationLog::log failed', [
                'step'  => $step,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
