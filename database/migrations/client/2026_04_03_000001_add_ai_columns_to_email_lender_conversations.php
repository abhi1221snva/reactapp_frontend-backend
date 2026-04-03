<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_lender_conversations', function (Blueprint $table) {
            $table->string('ai_response_status', 30)->nullable()->after('note_id');
            $table->string('ai_merchant_name', 500)->nullable()->after('ai_response_status');
            $table->tinyInteger('ai_confidence')->unsigned()->nullable()->after('ai_merchant_name');
            $table->json('ai_raw_response')->nullable()->after('ai_confidence');
            $table->unsignedBigInteger('submission_id')->nullable()->after('ai_raw_response');
        });

        // Expand detection_source ENUM to include 'ai_fuzzy'
        DB::statement("ALTER TABLE `email_lender_conversations` MODIFY COLUMN `detection_source` ENUM('subject','body','both','ai_fuzzy') NULL DEFAULT NULL");

        // Expand crm_lender_submissions.response_status to include 'under_review'
        if (Schema::hasTable('crm_lender_submissions')) {
            DB::statement("ALTER TABLE `crm_lender_submissions` MODIFY COLUMN `response_status` ENUM('pending','approved','declined','needs_documents','no_response','under_review') DEFAULT 'pending'");
        }
    }

    public function down(): void
    {
        // Revert crm_lender_submissions.response_status
        if (Schema::hasTable('crm_lender_submissions')) {
            DB::statement("ALTER TABLE `crm_lender_submissions` MODIFY COLUMN `response_status` ENUM('pending','approved','declined','needs_documents','no_response') DEFAULT 'pending'");
        }

        // Revert detection_source ENUM
        DB::statement("ALTER TABLE `email_lender_conversations` MODIFY COLUMN `detection_source` ENUM('subject','body','both') NULL DEFAULT NULL");

        Schema::table('email_lender_conversations', function (Blueprint $table) {
            $table->dropColumn(['ai_response_status', 'ai_merchant_name', 'ai_confidence', 'ai_raw_response', 'submission_id']);
        });
    }
};
