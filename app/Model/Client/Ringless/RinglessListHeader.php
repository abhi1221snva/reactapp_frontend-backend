<?php
namespace App\Model\Client\Ringless;
use Illuminate\Database\Eloquent\Model;

class RinglessListHeader extends Model
{
    public $timestamps = true;
    protected $table = "ringless_list_header";
    protected $fillable = ['id','list_id','header','column_name','label_id','is_dialling','is_deleted','alternate_phone'];
}