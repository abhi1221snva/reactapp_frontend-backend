<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds escalation support to crm_lead_approvals.
 * When a reviewer declines, the system can automatically reassign
 * the approval to the user specified in escalate_to.
 */
class AddEscalationToCrmLeadApprovals extends Migration
{
    public function up()
    {
        Schema::table('crm_lead_approvals', function (Blueprint $table) {
            $table->unsignedInteger('escalate_to')->nullable()->after('reviewed_by')
                  ->comment('User ID to escalate to if declined');
            $table->timestamp('escalated_at')->nullable()->after('escalate_to');
            $table->text('escalation_note')->nullable()->after('escalated_at');
        });
    }

    public function down()
    {
        Schema::table('crm_lead_approvals', function (Blueprint $table) {
            $table->dropColumn(['escalate_to', 'escalated_at', 'escalation_note']);
        });
    }
}
