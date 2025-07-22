<?php


namespace App\Model;


use Illuminate\Database\Eloquent\Model;

class UserSettings extends Model
{
    protected $table = "user_setting";

    protected $casts = [
        "sender_list" => "array"
    ];

    public function __construct($connection, array $attributes = [])
    {
        parent::__construct($attributes);
        $this->connection = $connection;
    }
}
