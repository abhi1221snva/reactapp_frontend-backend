<?php

namespace App\Models\Client;

use Illuminate\Database\Eloquent\Model;

class EmailParsedAttachment extends Model
{
    protected $table = 'email_parsed_attachments';

    protected $fillable = [
        'gmail_message_id',
        'gmail_attachment_id',
        'gmail_oauth_token_id',
        'user_id',
        'thread_id',
        'email_from',
        'email_subject',
        'email_date',
        'filename',
        'mime_type',
        'file_size',
        'local_path',
        'doc_type',
        'classification_confidence',
        'classification_method',
        'parse_status',
        'parser_response',
        'error_message',
        'linked_lead_id',
        'linked_application_id',
    ];

    protected $casts = [
        'parser_response' => 'array',
        'email_date'      => 'datetime',
        'user_id'         => 'integer',
        'file_size'       => 'integer',
        'linked_lead_id'  => 'integer',
    ];

    public function application()
    {
        return $this->hasOne(EmailParsedApplication::class, 'attachment_id');
    }
}
