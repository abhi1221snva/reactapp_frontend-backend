<?php
namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class Lead extends Model
{
    public $timestamps = true;
    protected $table   = "crm_lead_data";

    // option_N columns removed — dynamic field values now stored in crm_lead_field_values (EAV)
    protected $fillable = [
        "first_name", "last_name", "email", "phone_number", "gender", "dob",
        "city", "state", "country", "address", "lead_status", "assigned_to",
        "lead_source_id", "lead_type", "company_name", "unique_url",
        "owner_2_signtaure_image", "owner_2_signtaure_date", "unique_token",
        "lead_parent_id", "is_copied", "copy_lead_id", "opener_id", "closer_id",
        "group_id", "is_deleted", "created_by", "updated_by",
    ];

    protected $dates = ['deleted_at'];

    /**
     * Global scope: always exclude soft-deleted leads.
     * Use ->withoutGlobalScope('active') when you intentionally need deleted records.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::addGlobalScope('active', function (Builder $q) {
            $q->where('is_deleted', 0);
        });
    }
}
