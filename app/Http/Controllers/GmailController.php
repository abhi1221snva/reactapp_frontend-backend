<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use App\Model\User;
use Illuminate\Support\Facades\Http;
use App\Http\Helper\JwtToken;
use Carbon\Carbon;
class GmailController extends Controller
{
    // Redirect to Google OAuth
    public function redirectToGoogle()
    {
        Log::info('Reached Google redirect');
    
        return Socialite::driver('google')
            ->stateless()
            ->with([
                'access_type' => 'offline',
                'prompt' => 'consent',
            ])
            ->scopes([
                'https://www.googleapis.com/auth/gmail.readonly', 'https://www.googleapis.com/auth/gmail.send','https://www.googleapis.com/auth/gmail.modify','https://mail.google.com/',
            ])
            ->redirect();
    }
    public function handleGoogleCallback()
    {
        try {
            $googleUser = Socialite::driver('google')
                ->stateless()
                ->user();
    
            if (!$googleUser->token) {
                throw new \Exception('Google did not return access token');
            }
    
            $user = User::where('email', $googleUser->getEmail())->first();
    
            if (!$user) {
                $user = User::create([
                    'first_name' => $googleUser->getName(),
                    'email' => $googleUser->getEmail(),
                    'google_id' => $googleUser->getId(),
                ]);
            }
    
            // ✅ SAVE GOOGLE TOKENS
            $user->google_access_token = $googleUser->token;
            $user->google_refresh_token = $googleUser->refreshToken; // may be null on re-login
            $user->google_token_expires_at = Carbon::now()->addSeconds($googleUser->expiresIn);
            $user->save();
    
            $jwt = JwtToken::createToken($user->id)[0];
    
            return response()->json([
                'token' => $jwt
            ]);
    
        } catch (\Exception $e) {
            Log::error('Google Login Exception', [
                'message' => $e->getMessage()
            ]);
    
            return response()->json([
                'error' => 'Login failed'
            ], 500);
        }
    }
        
    

    private function getGmailService($accessToken)
{
    if (!$accessToken || !is_string($accessToken)) {
        abort(401, 'Invalid Google access token');
    }

    return new class($accessToken) {
        private $accessToken;

        public function __construct($token)
        {
            $this->accessToken = $token;
        }

        public function listMessages()
        {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->accessToken,
            ])->get('https://gmail.googleapis.com/gmail/v1/users/me/messages');

            return [
                'messages' => $response->json()['messages'] ?? [],
                'nextPageToken' => $response->json()['nextPageToken'] ?? null,
                'error' => $response->json()['error'] ?? null,
            ];
        }

        // ✅ ADD THIS METHOD
        public function getMessage($messageId)
        {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->accessToken,
            ])->get(
                "https://gmail.googleapis.com/gmail/v1/users/me/messages/{$messageId}",
                ['format' => 'full']
            );

            return [
                'message' => $response->json(),
                'error' => $response->json()['error'] ?? null,
            ];
        }
    };
}

    private function getValidGoogleToken($user)
{
    if (Carbon::now()->lt($user->google_token_expires_at)) {
        return $user->google_access_token;
    }

    // 🔄 Refresh token
    $response = Http::asForm()->post(
        'https://oauth2.googleapis.com/token',
        [
            'client_id' => env('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'refresh_token' => $user->google_refresh_token,
            'grant_type' => 'refresh_token',
        ]
    );

    $data = $response->json();

    $user->google_access_token = $data['access_token'];
    $user->google_token_expires_at = Carbon::now()->addSeconds($data['expires_in']);
    $user->save();

    return $user->google_access_token;
}


    // --- Controller endpoints ---

    // List emails
    public function listEmails(Request $request)
{
    //$accessToken = $request->input('token');
    $user=$request->auth;
    $accessToken = $this->getValidGoogleToken($user);

    $service = $this->getGmailService($accessToken);

    $list = $service->listMessages();
    $emails = [];

    // foreach ($list['messages'] as $msg) {

    //     // fetch full email
    //     $message = Http::withHeaders([
    //         'Authorization' => 'Bearer ' . $accessToken,
    //     ])->get(
    //         "https://gmail.googleapis.com/gmail/v1/users/me/messages/{$msg['id']}"
    //     )->json();

    //     $headers = collect($message['payload']['headers']);

    //     $emails[] = [
    //         'id'      => $msg['id'],
    //         'from'    => $headers->firstWhere('name', 'From')['value'] ?? null,
    //         'to'      => $headers->firstWhere('name', 'To')['value'] ?? null,
    //         'subject' => $headers->firstWhere('name', 'Subject')['value'] ?? null,
    //         'snippet' => $message['snippet'] ?? null,
    //     ];
    // }
foreach ($list['messages'] as $msg) {

    $message = Http::withHeaders([
        'Authorization' => 'Bearer ' . $accessToken,
    ])->get(
        "https://gmail.googleapis.com/gmail/v1/users/me/messages/{$msg['id']}",
        ['format' => 'full']
    )->json();

    $headers = collect($message['payload']['headers']);

    // ✅ Detect SENT or INBOX
    $labels = $message['labelIds'] ?? [];
    $type = in_array('SENT', $labels) ? 'sent' : 'inbox';

    // ✅ Created at (internalDate is in milliseconds)
    $createdAt = isset($message['internalDate'])
        ? Carbon::createFromTimestampMs($message['internalDate'])->toISOString()
        : null;

    // ✅ Detect attachments
    $hasAttachments = false;
    if (!empty($message['payload']['parts'])) {
        foreach ($message['payload']['parts'] as $part) {
            if (!empty($part['filename'])) {
                $hasAttachments = true;
                break;
            }
        }
    }

    $emails[] = [
        'id'               => $msg['id'],
        'from'             => $headers->firstWhere('name', 'From')['value'] ?? null,
        'to'               => $headers->firstWhere('name', 'To')['value'] ?? null,
        'subject'          => $headers->firstWhere('name', 'Subject')['value'] ?? null,
        'snippet'          => $message['snippet'] ?? null,
        'type'             => $type,
        'created_at'       => $createdAt,
        'has_attachments'  => $hasAttachments,
    ];
}

    return response()->json([
        'data' => $emails
    ]);
}

    public function listEmailsid(Request $request)
    {
        $user = $request->auth; // using your JWT auth
    
        $accessToken = $this->getValidGoogleToken($user);
        $service = $this->getGmailService($accessToken);
    
        $response = $service->listMessages();
    
        if ($response['error']) {
            return response()->json([
                'success' => false,
                'error' => $response['error']['message']
            ], 401);
        }
    
        return response()->json([
            'success' => true,
            'data' => $response['messages'],
            'nextPageToken' => $response['nextPageToken']
        ]);
    }
    

    // Get single email
    public function getEmail(Request $request, $messageId)
{
    //$accessToken = $request->input('token');
      $user=$request->auth;
    $accessToken = $this->getValidGoogleToken($user);
    $service = $this->getGmailService($accessToken);

    $response = $service->getMessage($messageId);

    if (!empty($response['error'])) {
        Log::error('Gmail API Error', ['error' => $response['error']]);
        return response()->json([
            'success' => false,
            'error' => $response['error']['message'] ?? 'Unknown error'
        ], 500);
    }

    $message = $response['message'];
    $headers = collect($message['payload']['headers']);

    // ✅ Type (sent / inbox)
    $type = in_array('SENT', $message['labelIds'] ?? []) ? 'sent' : 'inbox';

    // ✅ Created at
    $createdAt = Carbon::createFromTimestampMs(
        $message['internalDate']
    )->toISOString();

    // ✅ Email body (HTML preferred)
    $body = null;
    if (!empty($message['payload']['parts'])) {
        foreach ($message['payload']['parts'] as $part) {
            if ($part['mimeType'] === 'text/html') {
                $body = base64_decode(
                    str_replace(['-', '_'], ['+', '/'], $part['body']['data'])
                );
                break;
            }
        }
    }

    // ✅ Attachments
    $attachments = [];
    if (!empty($message['payload']['parts'])) {
        foreach ($message['payload']['parts'] as $part) {
            if (!empty($part['filename']) && isset($part['body']['attachmentId'])) {
                $attachments[] = [
                    'name' => $part['filename'],
                    'size' => isset($part['body']['size'])
                        ? round($part['body']['size'] / 1024 / 1024, 2) . 'MB'
                        : null,
                    'url' => null // Gmail does not give public URLs
                ];
            }
        }
    }

    return response()->json([
        'success' => true,
        'data' => [
            'id' => $messageId,
            'from' => $headers->firstWhere('name', 'From')['value'] ?? null,
            'to' => isset($headers->firstWhere('name', 'To')['value'])
                ? array_map('trim', explode(',', $headers->firstWhere('name', 'To')['value']))
                : [],
            'cc' => isset($headers->firstWhere('name', 'Cc')['value'])
                ? array_map('trim', explode(',', $headers->firstWhere('name', 'Cc')['value']))
                : [],
            'bcc' => isset($headers->firstWhere('name', 'Bcc')['value'])
                ? array_map('trim', explode(',', $headers->firstWhere('name', 'Bcc')['value']))
                : [],
            'subject' => $headers->firstWhere('name', 'Subject')['value'] ?? null,
            'body' => $body,
            'type' => $type,
            'attachments' => $attachments,
            'created_at' => $createdAt
        ]
    ]);
}

    // public function getEmail(Request $request, $messageId)
    // {
    //     $accessToken = $request->input('token');
    //     $service = $this->getGmailService($accessToken);

    //     $response = $service->getMessage($messageId);

    //     if (!empty($response['error'])) {
    //         Log::error('Gmail API Error', ['error' => $response['error']]);
    //         return response()->json([
    //             'success' => false,
    //             'error' => $response['error']['message'] ?? 'Unknown error'
    //         ], 500);
    //     }

    //     // Return only message array (flattened)
    //     $message = $response['message'];
    //     return response()->json([
    //         'success' => true,
    //         'data' => $message
    //     ]);
    // }

    // Archive emails
  public function archiveEmails(Request $request)
{
    // JWT-authenticated user
    $user = $request->auth;

    if (!$user) {
        return response()->json(['error' => 'Unauthenticated'], 401);
    }

    // Validate request
 
    $emailIds = $request->email_ids;

    // Get Google access token
    $accessToken = $this->getValidGoogleToken($user);
Log::info('User tokens', ['tokens' => $accessToken]);

    $archivedCount = 0;

    foreach ($emailIds as $emailId) {
        // Gmail modify message API to archive (remove INBOX label)
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $accessToken,
            'Content-Type'  => 'application/json',
        ])->post("https://gmail.googleapis.com/gmail/v1/users/me/messages/{$emailId}/modify", [
            'removeLabelIds' => ['INBOX']
        ]);

        if ($response->successful()) {
            $archivedCount++;
        }
    }

    return response()->json([
        'message' => 'Emails archived successfully',
        'archived_count' => $archivedCount
    ]);
}


    // Unarchive emails
  public function unarchiveEmails(Request $request)
{
    // JWT-authenticated user
    $user = $request->auth;

    if (!$user) {
        return response()->json(['error' => 'Unauthenticated'], 401);
    }

    // Validate request
    $emailIds = $request->email_ids;

    if (empty($emailIds) || !is_array($emailIds)) {
        return response()->json(['error' => 'Invalid email_ids provided'], 400);
    }

    // Get Google access token
    $accessToken = $this->getValidGoogleToken($user);
    Log::info('User tokens', ['tokens' => $accessToken]);

    $unarchivedCount = 0;

    foreach ($emailIds as $emailId) {
        // Gmail modify message API to unarchive (add INBOX label)
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $accessToken,
            'Content-Type'  => 'application/json',
        ])->post("https://gmail.googleapis.com/gmail/v1/users/me/messages/{$emailId}/modify", [
            'addLabelIds' => ['INBOX']
        ]);

        if ($response->successful()) {
            $unarchivedCount++;
        } else {
            Log::error('Failed to unarchive email', [
                'emailId' => $emailId,
                'response' => $response->body()
            ]);
        }
    }

    return response()->json([
        'message' => 'Emails unarchived successfully',
        'unarchived_count' => $unarchivedCount
    ]);
}


    // Send email
    public function sendEmail(Request $request)
    {
        $accessToken = $request->input('token');
        $service = $this->getGmailService($accessToken);

        $rawMessage = $this->createRawMessage($request->to, $request->subject, $request->body);
        $sent = $service->sendMessage($rawMessage);

        return response()->json(['success' => true, 'data' => $sent]);
    }

    private function createRawMessage($to, $subject, $body)
    {
        $message = "To: $to\r\n";
        $message .= "Subject: $subject\r\n";
        $message .= "MIME-Version: 1.0\r\n";
        $message .= "Content-Type: text/plain; charset=utf-8\r\n\r\n";
        $message .= $body;

        return rtrim(strtr(base64_encode($message), '+/', '-_'), '=');
    }
public function saveDraft(Request $request)
{
    // JWT-authenticated user
    $user = $request->auth;
    Log::info('user teached',['user'=>$user]);

    if (!$user) {
        return response()->json(['error' => 'Unauthenticated'], 401);
    }

    // Google token from DB (auto-refresh)
    $accessToken = $this->getValidGoogleToken($user);

    $to = (array) $request->to;
    $cc = (array) $request->cc;
    $bcc = (array) $request->bcc;
    $subject = $request->subject;
    $body = $request->body;

    // MIME build
    $mime = "From: me\r\n";
    $mime .= "To: " . implode(',', $to) . "\r\n";
    if ($cc) $mime .= "Cc: " . implode(',', $cc) . "\r\n";
    if ($bcc) $mime .= "Bcc: " . implode(',', $bcc) . "\r\n";

    $mime .= "Subject: {$subject}\r\n";
    $mime .= "MIME-Version: 1.0\r\n";
    $mime .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
    $mime .= $body;

    $raw = rtrim(strtr(base64_encode($mime), '+/', '-_'), '=');

    $response = Http::withHeaders([
        'Authorization' => 'Bearer ' . $accessToken,
        'Content-Type' => 'application/json',
    ])->post(
        'https://gmail.googleapis.com/gmail/v1/users/me/drafts',
        ['message' => ['raw' => $raw]]
    );

    return $response->json();
}
public function updateDraft(Request $request, $draftId)
{
    // JWT-authenticated user
    $user = $request->auth;

    if (!$user) {
        return response()->json(['error' => 'Unauthenticated'], 401);
    }

    // Get Google access token from DB
    $accessToken = $this->getValidGoogleToken($user);

    $to = (array) $request->to;
    $cc = (array) $request->cc;
    $bcc = (array) $request->bcc;
    $subject = $request->subject;
    $body = $request->body;

    // Build MIME message
    $mime = "From: me\r\n";
    if ($to)  $mime .= "To: " . implode(',', $to) . "\r\n";
    if ($cc)  $mime .= "Cc: " . implode(',', $cc) . "\r\n";
    if ($bcc) $mime .= "Bcc: " . implode(',', $bcc) . "\r\n";

    $mime .= "Subject: {$subject}\r\n";
    $mime .= "MIME-Version: 1.0\r\n";
    $mime .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
    $mime .= $body;

    $raw = rtrim(strtr(base64_encode($mime), '+/', '-_'), '=');

    // Gmail update draft API
    $response = Http::withHeaders([
        'Authorization' => 'Bearer ' . $accessToken,
        'Content-Type'  => 'application/json',
    ])->put(
        "https://gmail.googleapis.com/gmail/v1/users/me/drafts/{$draftId}",
        [
            'message' => [
                'raw' => $raw
            ]
        ]
    );

    if ($response->failed()) {
        return response()->json([
            'success' => false,
            'error' => $response->json()
        ], $response->status());
    }

    return response()->json([
        'success' => true,
        'data' => $response->json()
    ]);
}
public function deleteDraft(Request $request, $draftId)
{
    // JWT-authenticated user
    $user = $request->auth;

    if (!$user) {
        return response()->json(['error' => 'Unauthenticated'], 401);
    }

    // Get Google access token from DB (auto-refresh if needed)
    $accessToken = $this->getValidGoogleToken($user);

    // Gmail delete draft API
    $response = Http::withHeaders([
        'Authorization' => 'Bearer ' . $accessToken,
        'Content-Type'  => 'application/json',
    ])->delete("https://gmail.googleapis.com/gmail/v1/users/me/drafts/{$draftId}");

    if ($response->failed()) {
        return response()->json([
            'success' => false,
            'error' => $response->json() ?: 'Failed to delete draft'
        ], $response->status());
    }

    return response()->json([
        'success' => true,
        'message' => 'Draft deleted successfully'
    ]);
}


}
