<?php


namespace App\Model\Master;

use Illuminate\Database\Eloquent\Model;

class LeadSourceApi extends Model
{
    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = true;

    protected $connection = 'master';

    protected $table = "lead_source_api";

    protected $fillable = [
        "id",
        "api_key",
        "client_id"
    ];
}
