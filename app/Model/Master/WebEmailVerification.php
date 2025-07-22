<?php


namespace App\Model\Master;

use Illuminate\Database\Eloquent\Model;

class WebEmailVerification extends Model
{
    /**
     * The connection name for the model.
     */
    protected $connection = 'master';

    protected $keyType = 'string';

    public $incrementing = false;

    public $statusCode = [
        1 => "Requested",
        2 => "Processing",
        3 => "Sent",
        4 => "Verified",
        5 => "Failed",
        6 => "Invalid"
    ];

    public function toArray()
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'mobile_uuid' => $this->uuid,
            'expiry' => $this->expiry,
            'status' => $this->statusCode[$this->status],
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at
        ];
    }
}
