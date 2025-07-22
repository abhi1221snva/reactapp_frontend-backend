<?php

namespace App\Model\Master;

use App\Model\Traits\HasCompositePrimaryKey;
use Illuminate\Database\Eloquent\Model;

class ProspectPackage extends Model
{
    use HasCompositePrimaryKey;

    protected $primaryKey = ['prospect_id', 'package_key'];

    protected $connection = 'master';

    protected $table = 'prospect_packages';

    protected $fillable = [
        "prospect_id",
        "package_key",
        "start_time",
        "end_time",
        "expiry_time",
        "billed",
        "payment_cent_amount",
        "payment_time",
        "psp_reference"
    ];
}
