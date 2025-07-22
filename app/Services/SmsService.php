<?php


namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;

class SmsService
{
    private $service_url;
    private $service_key;
    private $service_token;

    const STATUS = [
        100 => "Response is successful",
        101 => "Authentication failure",
        102 => "Invalid parameters",
        107 => "Action not allowed through API",
        108 => "Credit balance is low",
        111 => "Failure",
        113 => "SMS is not active on number",
        114 => "SMS is already active on number"
    ];

    public function __construct($url, $key, $token)
    {
        $this->service_url = $url;
        $this->service_key = $key;
        $this->service_token = $token;
    }

    public function sendMessage(string $from, string $to, string $message)
    {
        $client = new Client();
        $response = $client->post($this->service_url, [
            RequestOptions::JSON => [
                "from" => $from,
                "to" => $to,
                "text" => $message
            ],
            RequestOptions::HEADERS => [
                "Content-Type" => "application/json",
                "Authorization" => "Basic " . base64_encode($this->service_key.":".$this->service_token)
            ]
        ]);
        return $response->getBody()->getContents();
    }
}
