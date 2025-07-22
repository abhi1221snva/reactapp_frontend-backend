<?php

namespace App\Model\Merchant;

use Illuminate\Database\Eloquent\Model;

class Merchants extends Model {
    protected $table = 'merchants';
    public $timestamps = true;
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'lead_id', 'client_id', 'email', 'password', 'status', 'updated_at', 'created_at'
    ];

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = [
        'password'
    ];
}
