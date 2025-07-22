<?php

namespace App\Model\Client;
use Illuminate\Database\Eloquent\Model;

class CustomFieldLabelsValues extends Model
{
    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    protected $table = "custom_fields_labels_values";

    protected $fillable = ['id','custom_id','title_match','user_id','title_links','is_deleted'];

}
