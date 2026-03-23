<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widen mail_password from varchar(255) to text.
 * Laravel's Crypt::encryptString() produces ~300-400 chars for typical API keys,
 * which exceeds the old varchar(255) limit causing STRICT_TRANS_TABLES errors.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_smtp_setting', function (Blueprint $table) {
            $table->text('mail_password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('crm_smtp_setting', function (Blueprint $table) {
            $table->string('mail_password')->nullable()->change();
        });
    }
};
