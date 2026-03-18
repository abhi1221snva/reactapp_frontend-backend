<?php

namespace App\Http\Controllers;

use App\Model\Client\EmailSetting;
use App\Services\EmailService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * CRM Email Settings — CRUD + test email (Lumen-compatible)
 *
 * Routes (all under jwt.auth middleware):
 *   GET    /crm/email-settings            → index
 *   POST   /crm/email-settings            → store
 *   POST   /crm/email-settings/test       → testEmail
 *   GET    /crm/email-settings/{id}       → show
 *   PUT    /crm/email-settings/{id}       → update
 *   DELETE /crm/email-settings/{id}       → destroy
 *   POST   /crm/email-settings/{id}/toggle → toggle
 *
 * Legacy routes kept for backward compatibility:
 *   GET    /crm-email-setting             → index
 *   POST   /crm-email-setting             → store
 *   POST   /update-crm-email-setting/{id} → update
 */
class CrmEmailSettingController extends Controller
{
    private const DRIVER_PRESETS = [
        'Sendgrid'  => ['mail_host' => 'smtp.sendgrid.net',                      'mail_port' => 587, 'mail_encryption' => 'TLS'],
        'Zoho'      => ['mail_host' => 'smtp.zoho.com',                          'mail_port' => 587, 'mail_encryption' => 'TLS'],
        'Google'    => ['mail_host' => 'smtp.gmail.com',                         'mail_port' => 587, 'mail_encryption' => 'TLS'],
        'Mailgun'   => ['mail_host' => 'smtp.mailgun.org',                       'mail_port' => 587, 'mail_encryption' => 'TLS'],
        'SES'       => ['mail_host' => 'email-smtp.us-east-1.amazonaws.com',     'mail_port' => 587, 'mail_encryption' => 'TLS'],
        'Sendpulse' => ['mail_host' => 'smtp-pulse.com',                         'mail_port' => 587, 'mail_encryption' => 'TLS'],
    ];

    private const VALID_TYPES = ['online application', 'notification', 'submission', 'marketing_campaigns'];
    private const VALID_VIA   = ['user_email', 'custom'];

    // ── List ──────────────────────────────────────────────────────────────────
    public function index(Request $request)
    {
        try {
            $clientId = $request->auth->parent_id;
            $rows = EmailSetting::on("mysql_{$clientId}")->orderBy('id')->get();

            $grouped = ['online' => null, 'notification' => null, 'submission' => null, 'marketing_campaigns' => null];
            foreach ($rows as $r) {
                $key = $r->mail_type === 'online application' ? 'online' : $r->mail_type;
                if (array_key_exists($key, $grouped) && !$grouped[$key]) {
                    $grouped[$key] = $r;
                }
            }

            return $this->successResponse('Email Settings', ['list' => $rows, 'grouped' => $grouped]);
        } catch (\Throwable $e) {
            return $this->failResponse('Failed to list email settings', [$e->getMessage()], $e, 500);
        }
    }

    // Legacy alias
    public function list(Request $request) { return $this->index($request); }

    // ── Show ──────────────────────────────────────────────────────────────────
    public function show(Request $request, int $id)
    {
        try {
            $setting = EmailSetting::on("mysql_{$request->auth->parent_id}")->findOrFail($id);
            return $this->successResponse('Email Setting', $setting);
        } catch (\Throwable $e) {
            return $this->failResponse('Setting not found', [$e->getMessage()], $e, 404);
        }
    }

    // ── Create ────────────────────────────────────────────────────────────────
    public function store(Request $request)
    {
        try {
            $clientId = $request->auth->parent_id;
            $input    = $request->all();

            $v = Validator::make($input, [
                'mail_type'      => 'required|in:' . implode(',', self::VALID_TYPES),
                'mail_driver'    => 'required|string|max:50',
                'mail_username'  => 'required|string|max:255',
                'mail_password'  => 'required|string',
                'sender_email'   => 'required|email',
                'sender_name'    => 'nullable|string|max:100',
                'send_email_via' => 'nullable|in:' . implode(',', self::VALID_VIA),
                'mail_host'      => 'nullable|string|max:255',
                'mail_port'      => 'nullable|integer',
                'mail_encryption'=> 'nullable|string|max:10',
                'meta_json'      => 'nullable|string',
            ]);
            if ($v->fails()) {
                return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $v->errors()], 422);
            }

            $data = $this->applyDriverPreset($input);
            $data['send_email_via'] = ($data['mail_type'] === 'notification') ? 'custom' : ($data['send_email_via'] ?? 'custom');

            $setting = new EmailSetting($data);
            $setting->setConnection("mysql_{$clientId}");
            $setting->status = 1;
            $setting->saveOrFail();

            return $this->successResponse('Email setting created', $setting->toArray());
        } catch (\Throwable $e) {
            return $this->failResponse('Failed to create email setting', [$e->getMessage()], $e, 500);
        }
    }

    // Legacy alias
    public function create(Request $request) { return $this->store($request); }

    // ── Update ────────────────────────────────────────────────────────────────
    public function update(Request $request, int $id)
    {
        try {
            $clientId = $request->auth->parent_id;
            $setting  = EmailSetting::on("mysql_{$clientId}")->findOrFail($id);
            $input    = $request->all();

            $v = Validator::make($input, [
                'mail_type'      => 'sometimes|in:' . implode(',', self::VALID_TYPES),
                'mail_driver'    => 'sometimes|string|max:50',
                'mail_username'  => 'sometimes|string|max:255',
                'mail_password'  => 'sometimes|string',
                'sender_email'   => 'sometimes|email',
                'sender_name'    => 'nullable|string|max:100',
                'send_email_via' => 'nullable|in:' . implode(',', self::VALID_VIA),
                'mail_host'      => 'nullable|string|max:255',
                'mail_port'      => 'nullable|integer',
                'mail_encryption'=> 'nullable|string|max:10',
                'meta_json'      => 'nullable|string',
            ]);
            if ($v->fails()) {
                return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $v->errors()], 422);
            }

            if (isset($input['mail_driver'])) {
                $input = $this->applyDriverPreset($input);
            }
            if (($input['send_email_via'] ?? null) === 'user_email') {
                $input['sender_email'] = '';
                $input['sender_name']  = '';
            }

            $setting->fill($input);
            $setting->saveOrFail();

            return $this->successResponse('Email setting updated', $setting->toArray());
        } catch (\Throwable $e) {
            return $this->failResponse('Failed to update email setting', [$e->getMessage()], $e, 500);
        }
    }

    // ── Delete ────────────────────────────────────────────────────────────────
    public function destroy(Request $request, int $id)
    {
        try {
            EmailSetting::on("mysql_{$request->auth->parent_id}")->findOrFail($id)->delete();
            return $this->successResponse('Email setting deleted', []);
        } catch (\Throwable $e) {
            return $this->failResponse('Failed to delete email setting', [$e->getMessage()], $e, 500);
        }
    }

    // ── Toggle active / inactive ──────────────────────────────────────────────
    public function toggle(Request $request, int $id)
    {
        try {
            $setting = EmailSetting::on("mysql_{$request->auth->parent_id}")->findOrFail($id);
            $setting->status = $setting->status ? 0 : 1;
            $setting->saveOrFail();
            return $this->successResponse(
                $setting->status ? 'Setting activated' : 'Setting deactivated',
                ['id' => $id, 'status' => $setting->status]
            );
        } catch (\Throwable $e) {
            return $this->failResponse('Failed to toggle email setting', [$e->getMessage()], $e, 500);
        }
    }

    // ── Test email (pre-save) ─────────────────────────────────────────────────
    public function testEmail(Request $request)
    {
        $input = $request->all();

        $v = Validator::make($input, [
            'config.mail_host'       => 'required|string',
            'config.mail_port'       => 'required|integer',
            'config.mail_username'   => 'required|string',
            'config.mail_password'   => 'required|string',
            'config.mail_encryption' => 'required|string',
            'config.sender_email'    => 'required|email',
            'config.sender_name'     => 'nullable|string',
            'test_to'                => 'required|email',
        ]);
        if ($v->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $v->errors()], 422);
        }

        $result = EmailService::test($input['config'], $input['test_to']);
        return response()->json($result, $result['success'] ? 200 : 422);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────
    private function applyDriverPreset(array $data): array
    {
        $driver = $data['mail_driver'] ?? null;
        if ($driver && isset(self::DRIVER_PRESETS[$driver])) {
            $preset = self::DRIVER_PRESETS[$driver];
            $data['mail_host']       = $data['mail_host']       ?? $preset['mail_host'];
            $data['mail_port']       = $data['mail_port']       ?? $preset['mail_port'];
            $data['mail_encryption'] = $data['mail_encryption'] ?? $preset['mail_encryption'];
        }
        return $data;
    }
}
