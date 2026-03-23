<?php

namespace App\Services;

use App\Model\Client\ExtensionLive;
use App\Model\Client\LineDetail;
use App\Model\Dialer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * CallTransferService
 *
 * Encapsulates the three-step warm-transfer pipeline:
 *   1. validateCall()      — confirm the agent has an active call for the given lead
 *   2. validateTarget()    — verify the extension is online/free, or phone number is valid
 *   3. initiateTransfer()  — fire the Asterisk AMI command and return a session ID
 *
 * This service is intentionally decoupled from HTTP concerns so it can be
 * tested in isolation and reused from queues or other callers.
 */
class CallTransferService
{
    /** @var Dialer */
    private $dialer;

    public function __construct(Dialer $dialer)
    {
        $this->dialer = $dialer;
    }

    // =========================================================================
    // Step 1 — Validate the active call
    // =========================================================================

    /**
     * Confirm that the requesting agent has an active call for the given lead.
     *
     * Queries `line_detail` (scoped to the tenant DB) matching:
     *   - lead_id
     *   - campaign_id  (derived from domain or explicit field)
     *   - customer phone number
     *   - agent's alt_extension (the WebRTC/SIP leg)
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int                       $parentId  Tenant ID from JWT
     * @return array                     Hydrated line_detail row
     *
     * @throws \RuntimeException  When no active call row is found
     */
    public function validateCall($request, int $parentId): array
    {
        $campaignId = $this->resolveCampaignId($request);

        $lineDetail = LineDetail::on("mysql_{$parentId}")
            ->where('lead_id', $request->lead_id)
            ->where('campaign_id', $campaignId)
            ->where('number', $request->customer_phone_number)
            ->where('extension', $request->auth->alt_extension)
            ->first();

        if (!$lineDetail) {
            throw new \RuntimeException(
                'No active call found. The call may have already ended or the extension does not match.'
            );
        }

        Log::debug('CallTransferService.validateCall.ok', [
            'lead_id'    => $request->lead_id,
            'campaign_id' => $campaignId,
            'extension'  => $request->auth->alt_extension,
        ]);

        return $lineDetail->toArray();
    }

    // =========================================================================
    // Step 2 — Validate the transfer target
    // =========================================================================

    /**
     * Verify the transfer destination is reachable before firing the AMI command.
     *
     * Extension transfer:
     *   - target extension must have a live row in `extension_live`
     *   - transfer_status must not be 2 (busy / mid-transfer)
     *
     * External (DID/phone) transfer:
     *   - target must be a valid E.164-compatible phone number (7–15 digits)
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int                       $parentId
     * @return array   Validated target metadata
     *
     * @throws \RuntimeException  On validation failure
     */
    public function validateTarget($request, int $parentId): array
    {
        $transferType = $request->transfer_type;
        $target       = $request->target;
        $campaignId   = $this->resolveCampaignId($request);

        if ($transferType === 'extension') {
            return $this->validateExtensionTarget($parentId, $target, $campaignId);
        }

        if ($transferType === 'external') {
            return $this->validateExternalTarget($target);
        }

        throw new \RuntimeException(
            "Invalid transfer_type '{$transferType}'. Allowed values: extension, external."
        );
    }

    // =========================================================================
    // Step 3 — Initiate the transfer via Asterisk AMI
    // =========================================================================

    /**
     * Execute the AMI warm-transfer command.
     *
     * For extension transfers the target is first resolved through dialer_mode
     * (phone / webRTC / app) so the correct SIP leg is used.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int                       $parentId
     * @return string  Unique transfer session ID (hex string)
     *
     * @throws \RuntimeException  When the AMI command fails
     */
    public function initiateTransfer($request, int $parentId): string
    {
        $transferType = $request->transfer_type;
        $target       = $request->target;

        // Resolve the actual SIP extension for the target agent
        if ($transferType === 'extension') {
            $target = $this->resolveExtensionByDialerMode($target);
        }

        // Build transfer payload — mirrors the structure Asterisk services expect
        $callTransferData = [
            'domain'            => $request->domain ?? '',
            'lead_id'           => $request->lead_id,
            'forward_extension' => $transferType === 'extension' ? $target : null,
            'did_number'        => $transferType === 'external'   ? $target : null,
            'ring_group'        => null,
            'user_extension'    => $request->auth->alt_extension,
            'number'            => $request->customer_phone_number,
            'parent_id'         => $parentId,
        ];

        try {
            $asterisk = $this->dialer->getAsterisk(
                $request->auth->asterisk_server_id,
                $request->auth->alt_extension,
                $parentId
            );

            if ($transferType === 'extension') {
                $result = $asterisk->getWarmCallTransfer($callTransferData, $request);
            } else {
                // External / DID transfer uses the DID-specific AMI handler
                $result = $asterisk->warmCallTransferDid($callTransferData, $request);
            }

            if ($result !== true) {
                throw new \RuntimeException(
                    'Asterisk AMI rejected the transfer request. Ensure the channel is still active.'
                );
            }
        } catch (\Throwable $e) {
            Log::error('CallTransferService.initiateTransfer.error', [
                'message'       => $e->getMessage(),
                'transfer_type' => $transferType,
                'target'        => $target,
                'lead_id'       => $request->lead_id,
                'parent_id'     => $parentId,
            ]);

            throw new \RuntimeException(
                'Transfer initiation failed: ' . $e->getMessage()
            );
        }

        $sessionId = $this->generateSessionId();

        Log::info('CallTransferService.initiateTransfer.ok', [
            'transfer_type'       => $transferType,
            'target'              => $target,
            'lead_id'             => $request->lead_id,
            'transfer_session_id' => $sessionId,
        ]);

        return $sessionId;
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Check that the target extension is online and not currently in a transfer.
     *
     * extension_live.transfer_status:
     *   1 = available (ringing / just answered)
     *   2 = busy (already mid-transfer)
     */
    private function validateExtensionTarget(int $parentId, string $extension, int $campaignId): array
    {
        $live = ExtensionLive::on("mysql_{$parentId}")
            ->where('campaign_id', $campaignId)
            ->where('extension', $extension)
            ->first();

        if (!$live) {
            throw new \RuntimeException(
                "Extension {$extension} is not online. Agent may be logged out or unavailable."
            );
        }

        if ((int) $live->transfer_status === 2) {
            throw new \RuntimeException(
                "Extension {$extension} is busy (already in a transfer)."
            );
        }

        return $live->toArray();
    }

    /**
     * Validate a raw phone number for external transfer.
     *
     * Strips non-digit characters and ensures E.164-compatible length (7–15 digits).
     */
    private function validateExternalTarget(string $target): array
    {
        $digits = preg_replace('/[^0-9]/', '', $target);

        if (strlen($digits) < 7 || strlen($digits) > 15) {
            throw new \RuntimeException(
                "Invalid phone number '{$target}'. Must contain 7–15 digits (E.164 format recommended)."
            );
        }

        return ['phone_number' => $digits];
    }

    /**
     * Look up a user by any extension type and return the SIP leg
     * appropriate for their configured dialer_mode.
     *
     * dialer_mode:
     *   1 = hardware phone  → use extension
     *   2 = WebRTC/browser  → use alt_extension
     *   3 = mobile app      → use app_extension
     */
    private function resolveExtensionByDialerMode(string $extensionInput): string
    {
        $user = DB::selectOne(
            "SELECT extension, alt_extension, app_extension, dialer_mode
               FROM users
              WHERE (extension = :ext OR alt_extension = :alt OR app_extension = :app)
                AND is_deleted = 0
              LIMIT 1",
            [
                'ext' => $extensionInput,
                'alt' => $extensionInput,
                'app' => $extensionInput,
            ]
        );

        if (!$user) {
            throw new \RuntimeException(
                "Extension '{$extensionInput}' not found. Ensure the agent is registered."
            );
        }

        $mode = (int) $user->dialer_mode;

        if ($mode === 3) {
            return $user->app_extension;
        }

        if ($mode === 2) {
            return $user->alt_extension;
        }

        return $user->extension;
    }

    /**
     * Domain 'crm' always targets campaign 45 (legacy behaviour preserved for
     * backward compatibility with CRM-originated calls).
     */
    private function resolveCampaignId($request): int
    {
        if (!empty($request->domain) && $request->domain === 'crm') {
            return 45;
        }

        return (int) ($request->campaign_id ?? 45);
    }

    /**
     * Generate a cryptographically random hex session ID (16 chars / 8 bytes).
     */
    private function generateSessionId(): string
    {
        return bin2hex(random_bytes(8));
    }
}
