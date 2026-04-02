<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_lender_submissions', function (Blueprint $table) {
            $table->string('email_status', 20)->nullable()->after('submission_type');
            $table->timestamp('email_status_at')->nullable()->after('email_status');
        });
    }

    public function down(): void
    {
        Schema::table('crm_lender_submissions', function (Blueprint $table) {
            $table->dropColumn(['email_status', 'email_status_at']);
        });
    }
};
