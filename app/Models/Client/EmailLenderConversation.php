<?php

namespace App\Models\Client;

use Illuminate\Database\Eloquent\Model;

/**
 * Logged lender email conversation matched to a CRM lead.
 *
 * @property int         $id
 * @property int         $user_id
 * @property int         $lead_id
 * @property int         $lender_id
 * @property string      $gmail_message_id
 * @property string|null $gmail_thread_id
 * @property string      $direction          inbound|outbound
 * @property string      $from_email
 * @property string|null $to_email
 * @property string|null $subject
 * @property string|null $body_preview
 * @property bool        $has_attachments
 * @property int         $attachment_count
 * @property array|null  $attachment_filenames
 * @property string|null $detected_merchant_name
 * @property string|null $detection_source    subject|body|both
 * @property bool        $offer_detected
 * @property array|null  $offer_details
 * @property \Carbon\Carbon|null $conversation_date
 * @property int|null    $activity_id
 * @property int|null    $note_id
 */
class EmailLenderConversation extends Model
{
    protected $table = 'email_lender_conversations';

    protected $fillable = [
        'user_id',
        'lead_id',
        'lender_id',
        'gmail_message_id',
        'gmail_thread_id',
        'direction',
        'from_email',
        'to_email',
        'subject',
        'body_preview',
        'has_attachments',
        'attachment_count',
        'attachment_filenames',
        'detected_merchant_name',
        'detection_source',
        'offer_detected',
        'offer_details',
        'conversation_date',
        'activity_id',
        'note_id',
    ];

    protected $casts = [
        'user_id'              => 'integer',
        'lead_id'              => 'integer',
        'lender_id'            => 'integer',
        'has_attachments'      => 'boolean',
        'attachment_count'     => 'integer',
        'attachment_filenames' => 'array',
        'offer_detected'       => 'boolean',
        'offer_details'        => 'array',
        'conversation_date'    => 'datetime',
        'activity_id'          => 'integer',
        'note_id'              => 'integer',
    ];

    public function lead()
    {
        return $this->belongsTo(CrmLeadRecord::class, 'lead_id');
    }

    public function lender()
    {
        return $this->belongsTo(\App\Model\Client\Lender::class, 'lender_id');
    }
}
