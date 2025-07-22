<?php

namespace App\Model\Master;

use Illuminate\Database\Eloquent\Model;

class Prospect extends Model
{
    /**
     * The connection name for the model.
     */
    protected $connection = 'master';

    protected $keyType = 'string';

    const REGISTERED = 1;
    const PAID = 2;
    const CLIENT_CREATED = 3;
    const USER_CREATED = 4;
    const PACKED_SUBSCRIBED = 5;
    const ONBOARDED = 6;

    public $statusCode = [
        self::REGISTERED => "Registered",
        self::PAID => "Paid",
        self::CLIENT_CREATED => "ClientCreated",
        self::USER_CREATED => "UserCreated",
        self::PACKED_SUBSCRIBED => "PackedSubscribed",
        self::ONBOARDED => "Onboarded"
    ];

    public function toArray(): array
    {
        $data['id'] = (int)$this->id;
        $data['first_name'] = $this->first_name;
        $data['last_name'] = $this->last_name;
        $data['country_code'] = $this->country_code;
        $data['mobile'] = $this->mobile;
        $data['email'] = $this->email;
        $data['company_name'] = $this->company_name;
        $data['address_1'] = $this->address_1;
        $data['address_2'] = $this->address_2;
        $data['status'] = $this->statusCode[$this->status];
        $data['mobile_otp'] = $this->mobile_otp;
        $data['email_otp'] = $this->email_otp;
        $data['client_id_assigned'] = $this->client_id_assigned;
        $data['created_at'] = $this->created_at;
        return $data;
    }
}
