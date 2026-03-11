<?php
namespace App\Model\Client;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CrmLabel extends Model
{
    use SoftDeletes;
    public $timestamps = true;
    protected $table = "crm_label";
    protected $fillable = ['title','is_deleted','label_title_url','view_on_lead','data_type','required','display_order','column_name','status','values','placeholder','heading_type','storage_type','edit_mode','conditions'];

    protected $casts = ['conditions' => 'array'];
    protected $dates = ['deleted_at'];
    protected static function boot()
    {
        parent::boot();

        static::deleting(function ($label) {
            // Soft delete logic
            $label->is_deleted = 1;
            $label->status = '0';
            $label->save();
        });

      
    }
    }

