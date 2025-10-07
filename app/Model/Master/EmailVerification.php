<?php

namespace App\Model\Master;

use Illuminate\Database\Eloquent\Model;

class EmailVerification extends Model
{
    /**
     * The connection name for the model.
     */
    protected $connection = 'master';

    // If "id" is auto-increment integer, remove below two lines
    // Otherwise, keep if you're using UUIDs or custom ids
    protected $keyType = 'string';
    public $incrementing = false;

    // Allow mass assignment
    protected $fillable = [
        'id',
        'email',
        'code',
        'expiry',
        'status',
    ];

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
            'code' => $this->code, // include this so you can debug if needed
            'expiry' => $this->expiry,
            'status' => $this->statusCode[$this->status] ?? $this->status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at
        ];
    }
}
