<?php

namespace App\Http\Controllers\Rvm;

use App\Http\Controllers\Controller;
use App\Model\Master\Rvm\Drop;
use App\Model\Master\Rvm\Event;
use App\Model\VoiceTemplate;
use App\Services\Rvm\DTO\CallbackResult;
use App\Services\Rvm\Providers\PlivoProvider;
use App\Services\Rvm\Providers\RvmProviderInterface;
use App\Services\Rvm\Providers\SlybroadcastProvider;
use App\Services\Rvm\Providers\TwilioProvider;
use App\Services\Rvm\RvmWalletService;
use App\Services\Rvm\RvmWebhookService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Plivo\XML\Response as PlivoResponse;
use Throwable;
use Twilio\TwiML\VoiceResponse;

/**
 * Public RVM callback endpoints for the phase-5a drivers.
 *
 * These routes are OUTSIDE any authenticated middleware because the
 * calling parties (Twilio, Plivo, Slybroadcast) cannot present a JWT.
 * Authentication is provided by an HMAC signature embedded in the URL:
 *
 *     /rvm/{provider}/{method}/{dropId}?sig=<hmac_sha256>
 *
 * Each driver constructs this URL in its deliver() path using
 * hash_hmac('sha256', $path, RVM_CALLBACK_SECRET). The controller
 * recomputes the signature over the request path and rejects mismatches
 * before touching any state.
 *
 * Endpoints:
 *
 *   POST /rvm/twilio/twiml/{dropId}      → TwiML <Play> for the voicemail audio
 *   POST /rvm/twilio/status/{dropId}     → Twilio statusCallback receiver
 *   POST /rvm/plivo/answer/{dropId}      → Plivo XML <Play> or <Hangup>
 *   POST /rvm/plivo/status/{dropId}      → Plivo hangup_url receiver
 *   GET  /rvm/slybroadcast/audio/{dropId}→ Audio file (or 302 to metadata.audio_url)
 *   POST /rvm/slybroadcast/status/{dropId}→ Slybroadcast disposition receiver
 *
 * Responses are provider-native:
 *   - Twilio receives XML (text/xml) for TwiML, empty 204 for status
 *   - Plivo receives XML for answer, empty 200 for status
 *   - Slybroadcast receives audio bytes for audio, plain "OK" for status
 */
class CallbackController extends Controller
{
    public function __construct(
        private TwilioProvider $twilio,
        private PlivoProvider $plivo,
        private SlybroadcastProvider $slybroadcast,
        private RvmWalletService $wallet,
        private RvmWebhookService $webhook,
    ) {}

    // ── Twilio ─────────────────────────────────────────────────────────────

    /**
     * POST /rvm/twilio/twiml/{dropId}
     *
     * Twilio fetches this URL once the call is connected and
     * machineDetection has flagged the answer as a machine. We return
     * TwiML that plays the voicemail audio, then hangs up.
     */
    public function twilioTwiml(Request $request, string $dropId): Response
    {
        $this->verifySignature($request, "rvm/twilio/twiml/{$dropId}");

        $drop = $this->loadDrop($dropId);
        $audioUrl = $this->resolveAudioUrl($drop);

        $vr = new VoiceResponse();
        if ($audioUrl) {
            $vr->play($audioUrl);
        } else {
            // No pre-recorded audio — fall back to TTS via the voice template
            // description. This matches the legacy pipeline's behaviour when
            // Asterisk has no sound file configured.
            $vr->say($this->resolveTtsText($drop), ['voice' => 'Polly.Joanna']);
        }
        $vr->hangup();

        return new Response((string) $vr, 200, ['Content-Type' => 'text/xml']);
    }

    /**
     * POST /rvm/twilio/status/{dropId}
     *
     * Twilio statusCallback receiver. Parses the payload, hands it to
     * TwilioProvider::handleCallback, and applies the resulting
     * CallbackResult to the drop.
     */
    public function twilioStatus(Request $request, string $dropId): Response
    {
        $this->verifySignature($request, "rvm/twilio/status/{$dropId}");

        $result = $this->twilio->handleCallback(
            $request->all(),
            $request->headers->all(),
        );

        $this->applyCallbackResult($this->twilio, $result);

        // Twilio does not care about the body — a 204 keeps their retry
        // machinery quiet. If we returned 500 they would keep retrying.
        return new Response('', 204);
    }

    // ── Plivo ──────────────────────────────────────────────────────────────

    /**
     * POST /rvm/plivo/answer/{dropId}
     *
     * Plivo calls this URL after machine_detection has completed. The
     * `Machine` form field tells us whether the answer was a machine or
     * a human. We return <Play> for a machine, <Hangup> for a human.
     */
    public function plivoAnswer(Request $request, string $dropId): Response
    {
        $this->verifySignature($request, "rvm/plivo/answer/{$dropId}");

        $drop   = $this->loadDrop($dropId);
        $isMach = strtolower((string) $request->input('Machine', 'false')) === 'true';

        $xml = new PlivoResponse();
        if ($isMach) {
            $audioUrl = $this->resolveAudioUrl($drop);
            if ($audioUrl) {
                $xml->addPlay($audioUrl);
            } else {
                $xml->addSpeak($this->resolveTtsText($drop), [
                    'voice'    => 'WOMAN',
                    'language' => 'en-US',
                ]);
            }
        }
        // Plivo hangs up automatically when the XML document ends — no
        // explicit <Hangup> needed for either branch.

        return new Response($xml->toXML(), 200, ['Content-Type' => 'text/xml']);
    }

    /**
     * POST /rvm/plivo/status/{dropId}
     *
     * Plivo hangup_url receiver. We also opportunistically upgrade the
     * drop's provider_message_id from request_uuid → call_uuid so
     * subsequent lookups are more stable.
     */
    public function plivoStatus(Request $request, string $dropId): Response
    {
        $this->verifySignature($request, "rvm/plivo/status/{$dropId}");

        $result = $this->plivo->handleCallback(
            $request->all(),
            $request->headers->all(),
        );

        $this->applyCallbackResult($this->plivo, $result);

        return new Response('OK', 200);
    }

    // ── Slybroadcast ───────────────────────────────────────────────────────

    /**
     * GET /rvm/slybroadcast/audio/{dropId}
     *
     * Slybroadcast downloads the audio file from this URL before it
     * begins dropping voicemails. We either stream back the file that
     * the drop metadata pointed at, or 302 to it if it's already
     * publicly reachable.
     *
     * If there's no audio configured we return 404 — Slybroadcast will
     * log a fetch error and reject the campaign. That is a correct
     * failure mode: we should not be dropping empty voicemails.
     */
    public function slybroadcastAudio(Request $request, string $dropId): Response
    {
        $this->verifySignature($request, "rvm/slybroadcast/audio/{$dropId}");

        $drop = $this->loadDrop($dropId);
        $url  = $this->resolveAudioUrl($drop);

        if (!$url) {
            return new Response('audio_not_configured', 404, ['Content-Type' => 'text/plain']);
        }

        // Simplest & most robust: redirect the provider to the canonical
        // audio file. This avoids proxying large audio through the app
        // server and preserves caching headers from the origin.
        return new Response('', 302, ['Location' => $url]);
    }

    /**
     * POST /rvm/slybroadcast/status/{dropId}
     *
     * Slybroadcast disposition callback. Per their docs the provider
     * expects a plain "OK" response body to acknowledge receipt.
     */
    public function slybroadcastStatus(Request $request, string $dropId): Response
    {
        $this->verifySignature($request, "rvm/slybroadcast/status/{$dropId}");

        $result = $this->slybroadcast->handleCallback(
            $request->all(),
            $request->headers->all(),
        );

        $this->applyCallbackResult($this->slybroadcast, $result);

        return new Response('OK', 200, ['Content-Type' => 'text/plain']);
    }

    // ── Shared internals ───────────────────────────────────────────────────

    /**
     * Recompute the signature over the URL path and compare against the
     * ?sig= query parameter using hash_equals (timing-safe).
     *
     * Aborts the request with 401 on any mismatch.
     */
    private function verifySignature(Request $request, string $canonicalPath): void
    {
        $secret = (string) (env('RVM_CALLBACK_SECRET') ?: env('APP_KEY'));
        if ($secret === '') {
            Log::error('RvmCallbackController: no signing secret configured');
            abort(500, 'Callback signing secret not configured');
        }

        $provided = (string) $request->query('sig', '');
        if ($provided === '') {
            Log::warning('RvmCallbackController: missing signature', [
                'path' => $canonicalPath,
                'ip'   => $request->ip(),
            ]);
            abort(401, 'Missing signature');
        }

        $expected = hash_hmac('sha256', $canonicalPath, $secret);

        if (!hash_equals($expected, $provided)) {
            Log::warning('RvmCallbackController: bad signature', [
                'path' => $canonicalPath,
                'ip'   => $request->ip(),
            ]);
            abort(401, 'Invalid signature');
        }
    }

    /**
     * Load a drop by id or abort 404.
     */
    private function loadDrop(string $dropId): Drop
    {
        $drop = Drop::on('master')->find($dropId);
        if (!$drop) {
            Log::warning('RvmCallbackController: drop not found', ['drop_id' => $dropId]);
            abort(404, 'Drop not found');
        }
        return $drop;
    }

    /**
     * Resolve the playable audio URL for a drop, if any.
     *
     * Precedence:
     *   1. drop.metadata.audio_url           — per-drop override
     *   2. voice_template.audio_file_url     — template default (if column exists)
     *
     * Returns null when no audio is configured. Callers should fall
     * back to TTS via resolveTtsText() when this is null.
     */
    private function resolveAudioUrl(Drop $drop): ?string
    {
        $meta = is_array($drop->metadata) ? $drop->metadata : [];
        if (!empty($meta['audio_url']) && is_string($meta['audio_url'])) {
            return $meta['audio_url'];
        }

        // Per-tenant voice_templete lives in mysql_{client_id} — not the
        // master DB. We read defensively since the template columns vary
        // between historical schemas.
        if ($drop->voice_template_id && $drop->client_id) {
            try {
                $conn = 'mysql_' . (int) $drop->client_id;
                $row = DB::connection($conn)
                    ->table('voice_templete')
                    ->where('templete_id', $drop->voice_template_id)
                    ->first();

                if ($row && isset($row->audio_file_url) && $row->audio_file_url) {
                    return (string) $row->audio_file_url;
                }
                if ($row && isset($row->audio_url) && $row->audio_url) {
                    return (string) $row->audio_url;
                }
            } catch (Throwable $e) {
                Log::warning('RvmCallbackController: could not load voice template', [
                    'drop_id' => $drop->id,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        return null;
    }

    /**
     * Resolve the TTS script text for a drop. Used as a fallback when
     * no pre-recorded audio is configured.
     */
    private function resolveTtsText(Drop $drop): string
    {
        $meta = is_array($drop->metadata) ? $drop->metadata : [];
        if (!empty($meta['tts_text']) && is_string($meta['tts_text'])) {
            return $meta['tts_text'];
        }

        if ($drop->voice_template_id && $drop->client_id) {
            try {
                $conn = 'mysql_' . (int) $drop->client_id;
                $row = DB::connection($conn)
                    ->table('voice_templete')
                    ->where('templete_id', $drop->voice_template_id)
                    ->first();
                if ($row && isset($row->templete_desc) && $row->templete_desc) {
                    return (string) $row->templete_desc;
                }
            } catch (Throwable $e) {
                // fall through to generic
            }
        }

        return 'You have a new message. Please check your notifications.';
    }

    /**
     * Apply a provider callback result to the drop:
     *   - update status + timestamps + provider_cost_cents
     *   - write an rvm_events row
     *   - commit or refund the wallet reservation
     *   - enqueue the tenant webhook
     *
     * No-op when the result is empty (CallbackResult::ignored()) or
     * when the drop is already in a terminal state.
     */
    private function applyCallbackResult(RvmProviderInterface $provider, CallbackResult $result): void
    {
        if (!$result->dropId || !$result->newStatus) {
            return;
        }

        $drop = Drop::on('master')->find($result->dropId);
        if (!$drop) {
            Log::warning('RvmCallbackController: callback for deleted drop', [
                'drop_id' => $result->dropId,
            ]);
            return;
        }
        if ($drop->isTerminal()) {
            // Already finalized — ignore the duplicate. Providers retry
            // status callbacks and we don't want to charge twice.
            return;
        }

        $now = Carbon::now();
        $updates = [
            'status'   => $result->newStatus,
            'provider' => $provider->name(),
        ];
        if ($result->newStatus === 'delivered') {
            $updates['delivered_at'] = $now;
        } elseif ($result->newStatus === 'failed') {
            $updates['failed_at']  = $now;
            $updates['last_error'] = substr((string) $result->errorMessage, 0, 500);
        }
        if ($result->providerCostCents !== null) {
            $updates['provider_cost_cents'] = $result->providerCostCents;
        }

        try {
            DB::connection('master')->transaction(function () use ($drop, $updates, $result, $provider) {
                Drop::on('master')->where('id', $drop->id)->update($updates);

                Event::create([
                    'drop_id'    => $drop->id,
                    'client_id'  => $drop->client_id,
                    'type'       => $result->newStatus,
                    'provider'   => $provider->name(),
                    'payload'    => [
                        'error_code'    => $result->errorCode,
                        'error_message' => $result->errorMessage,
                        'cost_cents'    => $result->providerCostCents,
                    ],
                    'occurred_at' => Carbon::now(),
                ]);
            });

            if ($drop->reservation_id) {
                if ($result->newStatus === 'delivered') {
                    $this->wallet->commit((int) $drop->client_id, $drop->reservation_id);
                } else {
                    $this->wallet->refund((int) $drop->client_id, $drop->reservation_id);
                }
            }

            $this->webhook->enqueue($drop->refresh(), 'rvm.drop.' . $result->newStatus);
        } catch (Throwable $e) {
            Log::error('RvmCallbackController: applyCallbackResult failed', [
                'drop_id'  => $drop->id,
                'provider' => $provider->name(),
                'error'    => $e->getMessage(),
            ]);
            // Don't rethrow — the provider will retry and we don't want
            // to send a 5xx that triggers their alerting.
        }
    }
}
