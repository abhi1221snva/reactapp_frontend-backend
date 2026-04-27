<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;

/**
 * Tracks real-time state of each SIP extension.
 * Table: extension_live (PK: extension)
 *
 * Used by the campaign dialer to associate a live call with a lead_id.
 * Frontend polls /dialer/agent/{ext}/current-lead which reads this table.
 */
class ExtensionLive extends Model
{
    public $timestamps = false;
    protected $primaryKey = 'extension';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $table = 'extension_live';

    protected $fillable = [
        'extension',
        'status',
        'channel',
        'campaign_id',
        'lead_id',
        'call_status',
        'transfer_status',
        'conf_room',
        'customer_channel',
        'call_started_at',
    ];

    protected $casts = [
        'status' => 'boolean',
    ];

    const CALL_STATUS_RINGING   = 'ringing';
    const CALL_STATUS_CONNECTED = 'connected';
    const CALL_STATUS_BRIDGED   = 'bridged';

    /**
     * Mark extension as on an active campaign call.
     */
    public static function markLive(
        int $extension,
        int $campaignId,
        int $leadId,
        string $channel = null,
        string $callStatus = self::CALL_STATUS_RINGING,
        string $confRoom = null,
        string $dbConnection = null
    ): self {
        $query = $dbConnection ? static::on($dbConnection) : static::query();

        return $query->updateOrCreate(
            ['extension' => $extension],
            [
                'status'          => 1,
                'channel'         => $channel,
                'campaign_id'     => $campaignId,
                'lead_id'         => $leadId,
                'call_status'     => $callStatus,
                'conf_room'       => $confRoom,
                'call_started_at' => null,  // set later when call is actually bridged
            ]
        );
    }

    /**
     * Reset extension to idle after call ends.
     */
    public static function markIdle(int $extension, string $dbConnection = null): void
    {
        $query = $dbConnection ? static::on($dbConnection) : static::query();

        $query->where('extension', $extension)->update([
            'status'           => 0,
            'channel'          => null,
            'campaign_id'      => null,
            'lead_id'          => null,
            'call_status'      => null,
            'transfer_status'  => null,
            'conf_room'        => null,
            'customer_channel' => null,
            'call_started_at'  => null,
        ]);
    }
}
