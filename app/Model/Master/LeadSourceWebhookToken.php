<?php
namespace App\Model\Master;

use Illuminate\Database\Eloquent\Model;

class LeadSourceWebhookToken extends Model
{
    protected $connection = 'master';
    protected $table = 'lead_source_webhook_tokens';
    protected $fillable = ['client_id', 'source_id', 'token'];
}
