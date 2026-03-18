<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\SmsInboxService;
use App\Model\User;

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
            'agent_id' => $request->query('agent_id'),
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
            'body'        => 'required|string|max:1600',
            'from_number' => 'nullable|string|max:30',
        ]);

        $clientId       = (int) $request->auth->parent_id;
        $userId         = (int) $request->auth->id;
        $conversationId = (int) $id;
        $body           = $request->input('body');
        $fromNumber     = $request->input('from_number') ?: null;

        try {
            $message = $this->svc->sendMessage($clientId, $conversationId, $body, $userId, $fromNumber);
            return $this->successResponse('Message sent.', ['message' => $message->toArray()]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->failResponse('Conversation not found.', [], $e, 404);
        } catch (\Exception $e) {
            return $this->failResponse('Failed to send message.', [], $e, 500);
        }
    }

    /**
     * GET /crm/sms/sender-numbers
     * Returns active Twilio numbers that have SMS capability.
     */
    public function getSenderNumbers(Request $request)
    {
        $clientId = (int) $request->auth->parent_id;
        try {
            $numbers = \Illuminate\Support\Facades\DB::connection("mysql_{$clientId}")
                ->table('twilio_numbers')
                ->where('status', 'active')
                ->orderBy('phone_number')
                ->get(['id', 'phone_number', 'friendly_name', 'capabilities'])
                ->filter(function ($row) {
                    $caps = is_string($row->capabilities) ? json_decode($row->capabilities, true) : (array) $row->capabilities;
                    return !empty($caps['sms']);
                })
                ->values()
                ->map(fn ($r) => [
                    'id'            => $r->id,
                    'phone_number'  => $r->phone_number,
                    'friendly_name' => $r->friendly_name ?: $r->phone_number,
                ]);
            return $this->successResponse('Sender numbers retrieved.', ['numbers' => $numbers]);
        } catch (\Exception $e) {
            return $this->failResponse('Failed to retrieve sender numbers.', [], $e, 500);
        }
    }

    /**
     * POST /crm/sms/new-conversation
     * Body: { phone_number: string, body: string, from_number?: string }
     */
    public function startConversation(Request $request)
    {
        $this->validate($request, [
            'phone_number' => 'required|string|max:30',
            'body'         => 'required|string|max:1600',
            'from_number'  => 'nullable|string|max:30',
        ]);

        $clientId   = (int) $request->auth->parent_id;
        $userId     = (int) $request->auth->id;
        $phone      = trim($request->input('phone_number'));
        $body       = $request->input('body');
        $fromNumber = $request->input('from_number') ?: null;

        try {
            $result = $this->svc->startNewConversation($clientId, $phone, $body, $userId, $fromNumber);
            return $this->successResponse('Conversation started.', $result);
        } catch (\Exception $e) {
            return $this->failResponse('Failed to start conversation.', [], $e, 500);
        }
    }

    /**
     * GET /crm/sms/agents
     * Returns all non-deleted users for this client (for agent assignment).
     */
    public function getAgents(Request $request)
    {
        $clientId = (int) $request->auth->parent_id;
        try {
            $agents = User::where('parent_id', $clientId)
                ->where('is_deleted', 0)
                ->orderBy('first_name')
                ->get(['id', 'first_name', 'last_name', 'email'])
                ->map(fn ($u) => [
                    'id'    => $u->id,
                    'name'  => trim("{$u->first_name} {$u->last_name}"),
                    'email' => $u->email,
                ]);
            return $this->successResponse('Agents retrieved.', ['agents' => $agents]);
        } catch (\Exception $e) {
            return $this->failResponse('Failed to retrieve agents.', [], $e, 500);
        }
    }

    /**
     * POST /crm/sms/conversations/{id}/assign
     * Body: { agent_id: int|null }
     */
    public function assignAgent(Request $request, $id)
    {
        $this->validate($request, [
            'agent_id' => 'nullable|integer',
        ]);

        $clientId       = (int) $request->auth->parent_id;
        $conversationId = (int) $id;
        $agentId        = $request->input('agent_id') ? (int) $request->input('agent_id') : null;

        try {
            $this->svc->assignAgent($clientId, $conversationId, $agentId);
            return $this->successResponse('Agent assigned.', []);
        } catch (\Exception $e) {
            return $this->failResponse('Failed to assign agent.', [], $e, 500);
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
