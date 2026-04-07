<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessGmailNotificationJob;
use App\Model\Master\GmailOAuthToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Pusher\Pusher;

class GmailPubSubWebhookController extends Controller
{
    /**
     * Handle incoming Pub/Sub push notification.
     *
     * Google Pub/Sub sends a POST request with:
     * {
     *   "message": {
     *     "data": "base64 encoded JSON",
     *     "messageId": "string",
     *     "publishTime": "timestamp"
     *   },
     *   "subscription": "projects/{project}/subscriptions/{subscription}"
     * }
     *
     * The decoded data contains:
     * {
     *   "emailAddress": "user@gmail.com",
     *   "historyId": "12345"
     * }
     */
    public function handle(Request $request)
    {
        // Verify the request (optional but recommended)
        if (!$this->verifyRequest($request)) {
            Log::warning('Gmail PubSub: Invalid webhook request');
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Get the message from the request
        $message = $request->input('message');

        if (!$message || !isset($message['data'])) {
            Log::warning('Gmail PubSub: Missing message data');
            // Return 200 to acknowledge receipt (prevent retry)
            return response()->json(['status' => 'ignored', 'reason' => 'no_data']);
        }

        try {
            // Decode the base64 data
            $data = json_decode(base64_decode($message['data']), true);

            if (!$data) {
                Log::warning('Gmail PubSub: Could not decode message data');
                return response()->json(['status' => 'ignored', 'reason' => 'invalid_data']);
            }

            $emailAddress = $data['emailAddress'] ?? null;
            $historyId = $data['historyId'] ?? null;

            if (!$emailAddress || !$historyId) {
                Log::warning('Gmail PubSub: Missing email or historyId', $data);
                return response()->json(['status' => 'ignored', 'reason' => 'missing_fields']);
            }

            Log::info('Gmail PubSub: Received notification', [
                'email' => $emailAddress,
                'historyId' => $historyId,
                'messageId' => $message['messageId'] ?? 'N/A',
            ]);

            // Find the user by Gmail email
            $token = GmailOAuthToken::where('gmail_email', $emailAddress)
                ->where('is_active', true)
                ->first();

            if (!$token) {
                Log::info('Gmail PubSub: No active token for email', ['email' => $emailAddress]);
                return response()->json(['status' => 'ignored', 'reason' => 'no_user']);
            }

            // Get the user and parent_id — verify not deleted
            $user = \App\Model\User::where('id', $token->user_id)
                ->where('is_deleted', 0)
                ->first();
            if (!$user) {
                Log::warning('Gmail PubSub: User not found', ['user_id' => $token->user_id]);
                return response()->json(['status' => 'ignored', 'reason' => 'user_not_found']);
            }

            // Send instant Pusher notification for toaster alert - INLINE
            try {
                $pusherKey = 'c76e18a6ac591c737fde';
                $pusherSecret = 'ceb1f1e5f242e8e3279c';
                $pusherId = '2097295';
                $pusherCluster = 'us2';

                $pusher = new Pusher($pusherKey, $pusherSecret, $pusherId, [
                    'cluster' => $pusherCluster,
                    'useTLS' => true
                ]);

                $channel = "private-team-user.{$user->parent_id}.{$token->user_id}";
                $pusher->trigger($channel, 'gmail.new_email', [
                    'subject' => 'New Email Received',
                    'from' => $emailAddress,
                    'preview' => 'You have a new email. Click to view.',
                ]);
            } catch (\Exception $pusherEx) {
                // Ignore pusher errors
            }

            // Dispatch job to process new emails (for team chat message, etc.)
            dispatch(new ProcessGmailNotificationJob(
                $token->user_id,
                $user->parent_id,
                $historyId
            ));

            return response()->json([
                'status' => 'queued',
                'user_id' => $token->user_id,
            ]);

        } catch (\Exception $e) {
            Log::error('Gmail PubSub: Exception handling webhook', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Return 200 to prevent infinite retries
            return response()->json(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    /**
     * Verify the incoming Pub/Sub request.
     *
     * Google Cloud Pub/Sub push subscriptions can be configured with:
     * 1. No authentication (not recommended)
     * 2. Audience claim verification (JWT token)
     * 3. Service account authentication
     *
     * For this implementation, we support:
     * - Bearer token verification (if GMAIL_WEBHOOK_SECRET is set)
     * - JWT audience verification (if configured)
     */
    protected function verifyRequest(Request $request): bool
    {
        $secret = env('GMAIL_WEBHOOK_SECRET');

        // If no secret configured, allow all requests (not recommended for production)
        if (empty($secret)) {
            return true;
        }

        // Check for Bearer token in Authorization header
        $authHeader = $request->header('Authorization');
        if ($authHeader) {
            // Format: "Bearer <token>" or just "<token>"
            $token = str_starts_with($authHeader, 'Bearer ')
                ? substr($authHeader, 7)
                : $authHeader;

            if ($token === $secret) {
                return true;
            }

            // Verify JWT token from Google (optional advanced verification)
            if ($this->verifyGoogleJwt($token)) {
                return true;
            }
        }

        // Check for token in query parameter (fallback)
        $queryToken = $request->query('token');
        if ($queryToken && $queryToken === $secret) {
            return true;
        }

        return false;
    }

    /**
     * Verify Google-issued JWT token.
     *
     * Google Pub/Sub can send authenticated push requests with a JWT
     * signed by Google. This method verifies the token.
     */
    protected function verifyGoogleJwt(string $token): bool
    {
        try {
            // For now, we'll skip JWT verification
            // Full implementation would verify:
            // 1. Token signature using Google's public keys
            // 2. Token expiration
            // 3. Audience claim matches our endpoint
            // 4. Issuer is accounts.google.com

            // This is a placeholder for advanced JWT verification
            // In production, use Firebase JWT or a similar library

            return false;

        } catch (\Exception $e) {
            Log::debug('Gmail PubSub: JWT verification failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Send instant Pusher notification for toaster alert.
     * This provides immediate feedback to the user while the job processes the full notification.
     */
    protected function sendInstantPusherNotification(int $parentId, int $userId, string $emailAddress): void
    {
        // Debug log
        file_put_contents('/tmp/gmail_pusher_debug.log', date('Y-m-d H:i:s') . " - Starting notification for user $userId\n", FILE_APPEND);

        try {
            $appKey = $_ENV['PUSHER_APP_KEY'] ?? getenv('PUSHER_APP_KEY') ?? '2097295';
            $appSecret = $_ENV['PUSHER_APP_SECRET'] ?? getenv('PUSHER_APP_SECRET');
            $appId = $_ENV['PUSHER_APP_ID'] ?? getenv('PUSHER_APP_ID');
            $cluster = $_ENV['PUSHER_APP_CLUSTER'] ?? getenv('PUSHER_APP_CLUSTER') ?? 'us2';

            file_put_contents('/tmp/gmail_pusher_debug.log', date('Y-m-d H:i:s') . " - Key: $appKey, Cluster: $cluster\n", FILE_APPEND);

            if (!$appKey || !$appSecret || !$appId) {
                Log::error('Gmail PubSub: Pusher credentials missing');
                return;
            }

            $pusher = new Pusher($appKey, $appSecret, $appId, [
                'cluster' => $cluster,
                'useTLS' => true
            ]);

            $channel = "private-team-user.{$parentId}.{$userId}";

            Log::info('Gmail PubSub: Sending instant notification', [
                'channel' => $channel,
                'email' => $emailAddress,
            ]);

            $result = $pusher->trigger($channel, 'gmail.new_email', [
                'subject' => 'New Email Received',
                'from' => $emailAddress,
                'preview' => 'You have a new email. Click to view.',
            ]);

            Log::info('Gmail PubSub: Pusher result', ['result' => json_encode($result)]);

        } catch (\Exception $e) {
            Log::error('Gmail PubSub: Failed to send instant notification', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Handle watch expiration ping.
     *
     * This endpoint can be called by a scheduled task to verify
     * the webhook is accessible.
     */
    public function ping(Request $request)
    {
        return response()->json([
            'status' => 'ok',
            'timestamp' => \Illuminate\Support\Carbon::now()->toIso8601String(),
        ]);
    }
}
