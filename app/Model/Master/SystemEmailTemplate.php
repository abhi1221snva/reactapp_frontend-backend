<?php

namespace App\Model\Master;

use Illuminate\Database\Eloquent\Model;

/**
 * System-level email template stored in master DB.
 * Editable via admin UI; rendered with placeholder substitution.
 *
 * @property int         $id
 * @property string      $template_key
 * @property string      $template_name
 * @property string      $subject
 * @property string      $body_html
 * @property array|null  $placeholders
 * @property bool        $is_active
 * @property int|null    $updated_by
 * @property string      $created_at
 * @property string      $updated_at
 */
class SystemEmailTemplate extends Model
{
    protected $connection = 'master';
    protected $table      = 'system_email_templates';

    protected $fillable = [
        'template_key',
        'template_name',
        'subject',
        'body_html',
        'placeholders',
        'is_active',
        'updated_by',
    ];

    protected $casts = [
        'placeholders' => 'array',
        'is_active'    => 'boolean',
        'updated_by'   => 'integer',
    ];

    // ----------------------------------------------------------------
    // Scopes
    // ----------------------------------------------------------------

    public function scopeActive($query)
    {
        return $query->where('is_active', 1);
    }

    // ----------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------

    /** Find a template by its unique key. */
    public static function findByKey(string $key): ?self
    {
        return static::where('template_key', $key)->first();
    }
}
