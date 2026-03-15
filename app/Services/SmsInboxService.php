<?php

namespace App\Services;

use App\Model\Client\CrmSmsConversation;
use App\Model\Client\CrmSmsMessage;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SmsInboxService
{
    /**
     * Return paginated conversations ordered by last_message_at desc.
     * Optionally filter by status (default: exclude archived).
     * Joins crm_leads to include lead display name.
     */
    public function getConversations(int $clientId, array $filters = []): array
    {
        $db      = DB::connection("mysql_{$clientId}");
        $page    = max(1, (int) ($filters['page']    ?? 1));
        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 30)));
        $status  = $filters['status'] ?? null;

        // crm_leads uses EAV — names are stored in crm_lead_values, not crm_leads columns
        $query = $db->table('crm_sms_conversations as c')
            ->leftJoin('crm_lead_values as vfn', function ($j) {
                $j->on('vfn.lead_id', '=', 'c.lead_id')->where('vfn.field_key', 'first_name');
            })
            ->leftJoin('crm_lead_values as vln', function ($j) {
                $j->on('vln.lead_id', '=', 'c.lead_id')->where('vln.field_key', 'last_name');
            })
            ->leftJoin('crm_lead_values as vcn', function ($j) {
                $j->on('vcn.lead_id', '=', 'c.lead_id')->where('vcn.field_key', 'company_name');
            })
            ->select([
                'c.id',
                'c.lead_id',
                'c.lead_phone',
                'c.agent_id',
                'c.last_message_at',
                'c.unread_count',
                'c.status',
                'c.created_at',
                'c.updated_at',
                $db->raw("COALESCE(vfn.field_value, '') as first_name"),
                $db->raw("COALESCE(vln.field_value, '') as last_name"),
                $db->raw("COALESCE(vcn.field_value, '') as company_name"),
            ])
            ->orderByDesc('c.last_message_at');

        if ($status && in_array($status, CrmSmsConversation::STATUSES, true)) {
            $query->where('c.status', $status);
        } else {
            // Default: exclude archived
            $query->where('c.status', '!=', 'archived');
        }

        $total   = $query->count();
        $records = $query->offset(($page - 1) * $perPage)->limit($perPage)->get()->toArray();

        return [
            'data'         => array_map(fn ($r) => (array) $r, $records),
            'total'        => $total,
            'page'         => $page,
            'per_page'     => $perPage,
            'total_pages'  => (int) ceil($total / $perPage),
        ];
    }

    /**
     * Find an existing open conversation for the given lead_id,
     * or create a new one on the client's database connection.
     */
    public function getOrCreateConversation(int $clientId, int $leadId, string $phone): CrmSmsConversation
    {
        $conn = "mysql_{$clientId}";

        $existing = CrmSmsConversation::on($conn)
            ->where('lead_id', $leadId)
            ->where('status', 'open')
            ->latest('last_message_at')
            ->first();

        if ($existing) {
            return $existing;
        }

        return CrmSmsConversation::on($conn)->create([
            'lead_id'         => $leadId,
            'lead_phone'      => $phone,
            'agent_id'        => null,
            'last_message_at' => Carbon::now(),
            'unread_count'    => 0,
            'status'          => 'open',
        ]);
    }

    /**
     * Return all messages for a conversation ordered by created_at asc.
     */
    public function getMessages(int $clientId, int $conversationId): array
    {
        $rows = CrmSmsMessage::on("mysql_{$clientId}")
            ->where('conversation_id', $conversationId)
            ->orderBy('created_at', 'asc')
            ->get()
            ->toArray();

        return $rows;
    }

    /**
     * Send an outbound SMS for a conversation.
     * Resolves a "from" number from campaign_numbers, then delegates to TwilioService.
     * Saves the message record regardless of Twilio success/failure.
     */
    public function sendMessage(int $clientId, int $conversationId, string $body, int $userId): CrmSmsMessage
    {
        $conn         = "mysql_{$clientId}";
        $conversation = CrmSmsConversation::on($conn)->findOrFail($conversationId);

        // Resolve a from-number: prefer a campaign number for this client
        $fromNumber = DB::connection($conn)
            ->table('campaign_numbers')
            ->whereNotNull('phone_number')
            ->value('phone_number');

        if (!$fromNumber) {
            $fromNumber = config('services.twilio.from_number', '+10000000000');
        }

        $sid    = null;
        $status = 'failed';

        try {
            $twilio = \App\Services\TwilioService::forClient($clientId);
            $result = $twilio->sendSms($conversation->lead_phone, $fromNumber, $body);
            $sid    = $result['sms_sid'] ?? null;
            $status = 'sent';
        } catch (\Exception $e) {
            Log::warning("SmsInboxService::sendMessage Twilio error for client {$clientId}: " . $e->getMessage());
        }

        /** @var CrmSmsMessage $message */
        $message = CrmSmsMessage::on($conn)->create([
            'conversation_id' => $conversationId,
            'direction'       => 'outbound',
            'body'            => $body,
            'from_number'     => $fromNumber,
            'to_number'       => $conversation->lead_phone,
            'status'          => $status,
            'twilio_sid'      => $sid,
            'sent_by'         => $userId,
        ]);

        $conversation->setConnection($conn);
        $conversation->update(['last_message_at' => Carbon::now()]);

        return $message;
    }

    /**
     * Handle an inbound SMS webhook: find the lead by phone, get/create
     * the conversation, persist the message, and increment unread_count.
     */
    public function receiveMessage(
        int    $clientId,
        string $from,
        string $to,
        string $body,
        string $twilioSid
    ): CrmSmsMessage {
        $conn = "mysql_{$clientId}";

        // Normalise the inbound number for lookup
        $normalised = preg_replace('/\D/', '', $from);

        // Find lead by phone_number — try exact match then suffix match
        $lead = DB::connection($conn)
            ->table('crm_leads')
            ->where('phone_number', $from)
            ->orWhere('phone_number', $normalised)
            ->first();

        if (!$lead) {
            // Fallback: ends-with last 10 digits
            $last10 = substr($normalised, -10);
            $lead   = DB::connection($conn)
                ->table('crm_leads')
                ->whereRaw("RIGHT(REGEXP_REPLACE(phone_number, '[^0-9]', ''), 10) = ?", [$last10])
                ->first();
        }

        $leadId = $lead ? $lead->id : 0;
        $phone  = $from;

        $conversation = $this->getOrCreateConversation($clientId, $leadId, $phone);

        /** @var CrmSmsMessage $message */
        $message = CrmSmsMessage::on($conn)->create([
            'conversation_id' => $conversation->id,
            'direction'       => 'inbound',
            'body'            => $body,
            'from_number'     => $from,
            'to_number'       => $to,
            'status'          => 'received',
            'twilio_sid'      => $twilioSid,
            'sent_by'         => null,
        ]);

        // Update conversation timestamps and unread counter
        $conversation->setConnection($conn);
        $conversation->increment('unread_count');
        $conversation->update(['last_message_at' => Carbon::now()]);

        return $message;
    }

    /**
     * Zero out the unread counter for a conversation.
     */
    public function markRead(int $clientId, int $conversationId): void
    {
        CrmSmsConversation::on("mysql_{$clientId}")
            ->where('id', $conversationId)
            ->update(['unread_count' => 0]);
    }
}
