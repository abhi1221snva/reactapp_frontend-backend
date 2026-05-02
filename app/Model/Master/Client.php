<?php

namespace App\Model\Master;

use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    /**
     * The connection name for the model.
     */
    protected $connection = 'master';

    protected $fillable = [
        'id', 'company_name', 'address_1', 'address_2', 'logo', 'trunk',
        'sms', 'fax', 'chat', 'webphone', 'sms_plateform', 'enable_2fa',
        'api_key', 'mca_crm', 'ringless', 'callchex', 'predictive_dial',
        'sendgrid_key', 'call_matrix_api_key', 'call_matrix_api_url', 'call_matrix_status',
        // Subscription fields
        'subscription_plan_id', 'billing_cycle', 'subscription_started_at',
        'subscription_ends_at', 'subscription_status',
        'custom_max_agents', 'custom_max_calls_monthly', 'custom_max_sms_monthly',
        // Stripe billing fields
        'stripe_customer_id', 'stripe_subscription_id', 'stripe_price_id',
        // Wallet config
        'wallet_balance_cents', 'wallet_low_threshold_cents', 'wallet_low_notified',
        // Grace period
        'grace_period_ends_at',
    ];

    const RECORD_SAVED          = 1;
    const ADMIN_ASSIGNED        = 2;
    const SAVE_CONNECTION       = 3;
    const MIGRATE_SEED          = 4;
    const ASSIGN_ASTERISK_SERVER = 5;
    const FULLY_PROVISIONED     = 6;  // storage + settings + admin user created

    public function getAsteriskServers()
    {
        $asteriskServerList = [];
        $servers = $this->servers;
        foreach ( $servers as $server ) {
            $asteriskServerList[$server->server_id] = $server->toArray();
        }
        return $asteriskServerList;
    }

    public function servers()
    {
        return $this->hasMany("App\Model\Master\ClientServers");
    }

    public function subscriptionPlan()
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }
}
