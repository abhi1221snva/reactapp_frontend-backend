<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\SmsInboxService;

class CrmSmsInboxController extends Controller
{
    private SmsInboxService $svc;

    public function __construct()
    {
        $this->svc = new SmsInboxService();
    }

    /**
     * GET /crm/sms/conversations
     * Query params: status, page, per_page
     */
    public function getConversations(Request $request)
    {
        $clientId = (int) $request->auth->parent_id;

        $filters = [
            'status'   => $request->query('status'),
            'page'     => $request->query('page', 1),
            'per_page' => $request->query('per_page', 30),
        ];

        try {
            $result = $this->svc->getConversations($clientId, $filters);
            return $this->successResponse('Conversations retrieved.', $result);
        } catch (\Exception $e) {
            return $this->failResponse('Failed to retrieve conversations.', [], $e, 500);
        }
    }

    /**
     * GET /crm/sms/conversations/{id}/messages
     */
    public function getMessages(Request $request, $id)
    {
        $clientId       = (int) $request->auth->parent_id;
        $conversationId = (int) $id;

        try {
            $messages = $this->svc->getMessages($clientId, $conversationId);
            return $this->successResponse('Messages retrieved.', ['messages' => $messages]);
        } catch (\Exception $e) {
            return $this->failResponse('Failed to retrieve messages.', [], $e, 500);
        }
    }

    /**
     * POST /crm/sms/conversations/{id}/send
     * Body: { body: string }
     */
    public function sendMessage(Request $request, $id)
    {
        $this->validate($request, [
            'body' => 'required|string|max:1600',
        ]);

        $clientId       = (int) $request->auth->parent_id;
        $userId         = (int) $request->auth->id;
        $conversationId = (int) $id;
        $body           = $request->input('body');

        try {
            $message = $this->svc->sendMessage($clientId, $conversationId, $body, $userId);
            return $this->successResponse('Message sent.', ['message' => $message->toArray()]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->failResponse('Conversation not found.', [], $e, 404);
        } catch (\Exception $e) {
            return $this->failResponse('Failed to send message.', [], $e, 500);
        }
    }

    /**
     * POST /crm/sms/conversations/{id}/read
     */
    public function markRead(Request $request, $id)
    {
        $clientId       = (int) $request->auth->parent_id;
        $conversationId = (int) $id;

        try {
            $this->svc->markRead($clientId, $conversationId);
            return $this->successResponse('Conversation marked as read.', []);
        } catch (\Exception $e) {
            return $this->failResponse('Failed to mark conversation as read.', [], $e, 500);
        }
    }
}
