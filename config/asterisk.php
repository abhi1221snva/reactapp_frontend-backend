<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Asterisk AMI Configuration
    |--------------------------------------------------------------------------
    |
    | Default fallback values for AMI connection. In production, credentials
    | are resolved dynamically from the master DB asterisk_server table via
    | AsteriskAmiService::connectForClient($clientId).
    |
    | These are only used if no asterisk_server record is found.
    |
    */
    'ami_host'     => env('ASTERISK_AMI_HOST', '127.0.0.1'),
    'ami_port'     => (int) env('ASTERISK_AMI_PORT', 5038),
    'ami_username' => env('ASTERISK_AMI_USERNAME', 'admin'),
    'ami_secret'   => env('ASTERISK_AMI_SECRET', ''),

    /*
    |--------------------------------------------------------------------------
    | Outbound Caller ID
    |--------------------------------------------------------------------------
    | The CallerID sent when dialing customers through the campaign.
    | Overridable per-campaign in future iterations.
    |
    */
    'outbound_caller_id' => env('ASTERISK_OUTBOUND_CID', '0000000000'),

    /*
    |--------------------------------------------------------------------------
    | WebRTC / WSS
    |--------------------------------------------------------------------------
    | The WebSocket port Asterisk listens on (configured in pjsip.conf transport-wss).
    | Exposed to the frontend via .env so agents can SIP-register via browser.
    |
    */
    'wss_port' => env('ASTERISK_WSS_PORT', 8089),
];
