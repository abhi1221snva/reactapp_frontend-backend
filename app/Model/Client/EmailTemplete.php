<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;

class EmailTemplete  extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */

    protected $table = "email_templates";

    protected $fillable = ['template_name', 'template_html','subject'];
}
