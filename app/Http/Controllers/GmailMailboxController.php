<?php

namespace App\Http\Controllers;

use App\Services\GmailMailboxService;
use Illuminate\Http\Request;

class GmailMailboxController extends Controller
{
    protected $mailboxService;

    public function __construct()
    {
        $this->mailboxService = new GmailMailboxService();
    }

    /**
     * Get emails from a specific folder/label.
     */
    public function list(Request $request)
    {
        try {
            $userId = $request->auth->id;
            $folder = $request->input('folder', 'INBOX');
            $maxResults = min($request->input('limit', 20), 50);
            $pageToken = $request->input('page_token');
            $query = $request->input('q');

            $result = $this->mailboxService->listEmails($userId, $folder, $maxResults, $pageToken, $query);

            if ($result === null) {
                return $this->failResponse("Gmail not connected or token expired. Please reconnect Gmail from your profile.", [], null, 400);
            }

            if (isset($result['error'])) {
                return $this->failResponse($result['error'], [], null, 400);
            }

            return $this->successResponse("Emails retrieved", $result);

        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to fetch emails", [$exception->getMessage()], $exception, 500);
        }
    }

    /**
     * Get a single email's full content.
     */
    public function show(Request $request, $messageId)
    {
        try {
            $userId = $request->auth->id;

            $email = $this->mailboxService->getEmail($userId, $messageId);

            if ($email === null) {
                return $this->failResponse("Email not found or access denied", [], null, 404);
            }

            return $this->successResponse("Email retrieved", ['email' => $email]);

        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to fetch email", [$exception->getMessage()], $exception, 500);
        }
    }

    /**
     * Send an email.
     */
    public function send(Request $request)
    {
        $this->validate($request, [
            'to' => 'required|email',
            'subject' => 'required|string|max:500',
            'body' => 'required|string',
            'cc' => 'nullable|string',
            'bcc' => 'nullable|string',
        ]);

        try {
            $userId = $request->auth->id;

            $result = $this->mailboxService->sendEmail($userId, [
                'to' => $request->input('to'),
                'subject' => $request->input('subject'),
                'body' => $request->input('body'),
                'cc' => $request->input('cc'),
                'bcc' => $request->input('bcc'),
            ]);

            if (!$result) {
                return $this->failResponse("Failed to send email", [], null, 500);
            }

            return $this->successResponse("Email sent successfully", ['message_id' => $result]);

        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to send email", [$exception->getMessage()], $exception, 500);
        }
    }

    /**
     * Star an email.
     */
    public function star(Request $request, $messageId)
    {
        try {
            $userId = $request->auth->id;

            $result = $this->mailboxService->modifyLabels($userId, $messageId, ['STARRED'], []);

            if (!$result) {
                return $this->failResponse("Failed to star email", [], null, 500);
            }

            return $this->successResponse("Email starred");

        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to star email", [$exception->getMessage()], $exception, 500);
        }
    }

    /**
     * Unstar an email.
     */
    public function unstar(Request $request, $messageId)
    {
        try {
            $userId = $request->auth->id;

            $result = $this->mailboxService->modifyLabels($userId, $messageId, [], ['STARRED']);

            if (!$result) {
                return $this->failResponse("Failed to unstar email", [], null, 500);
            }

            return $this->successResponse("Email unstarred");

        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to unstar email", [$exception->getMessage()], $exception, 500);
        }
    }

    /**
     * Move email to trash.
     */
    public function trash(Request $request, $messageId)
    {
        try {
            $userId = $request->auth->id;

            $result = $this->mailboxService->trashEmail($userId, $messageId);

            if (!$result) {
                return $this->failResponse("Failed to delete email", [], null, 500);
            }

            return $this->successResponse("Email moved to trash");

        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to delete email", [$exception->getMessage()], $exception, 500);
        }
    }

    /**
     * Permanently delete email.
     */
    public function delete(Request $request, $messageId)
    {
        try {
            $userId = $request->auth->id;

            $result = $this->mailboxService->deleteEmail($userId, $messageId);

            if (!$result) {
                return $this->failResponse("Failed to delete email", [], null, 500);
            }

            return $this->successResponse("Email deleted permanently");

        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to delete email", [$exception->getMessage()], $exception, 500);
        }
    }

    /**
     * Mark email as read.
     */
    public function markAsRead(Request $request, $messageId)
    {
        try {
            $userId = $request->auth->id;

            $result = $this->mailboxService->modifyLabels($userId, $messageId, [], ['UNREAD']);

            if (!$result) {
                return $this->failResponse("Failed to mark as read", [], null, 500);
            }

            return $this->successResponse("Email marked as read");

        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to mark as read", [$exception->getMessage()], $exception, 500);
        }
    }

    /**
     * Mark email as unread.
     */
    public function markAsUnread(Request $request, $messageId)
    {
        try {
            $userId = $request->auth->id;

            $result = $this->mailboxService->modifyLabels($userId, $messageId, ['UNREAD'], []);

            if (!$result) {
                return $this->failResponse("Failed to mark as unread", [], null, 500);
            }

            return $this->successResponse("Email marked as unread");

        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to mark as unread", [$exception->getMessage()], $exception, 500);
        }
    }

    /**
     * Get available labels.
     */
    public function labels(Request $request)
    {
        try {
            $userId = $request->auth->id;

            $labels = $this->mailboxService->getLabels($userId);

            if ($labels === null) {
                return $this->failResponse("Gmail not connected", [], null, 400);
            }

            return $this->successResponse("Labels retrieved", ['labels' => $labels]);

        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to fetch labels", [$exception->getMessage()], $exception, 500);
        }
    }
}
