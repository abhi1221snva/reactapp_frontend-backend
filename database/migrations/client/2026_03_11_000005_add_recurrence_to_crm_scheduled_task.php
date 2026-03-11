<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds recurring task support to crm_scheduled_task.
 * When is_sent=1 and recurrence_rule != 'none', a new task is
 * auto-created by ProcessRecurringTasksCommand.
 */
class AddRecurrenceToCrmScheduledTask extends Migration
{
    public function up()
    {
        Schema::table('crm_scheduled_task', function (Blueprint $table) {
            $table->enum('recurrence_rule', ['none', 'daily', 'weekly', 'monthly'])
                  ->default('none')
                  ->after('is_sent');
            $table->date('recurrence_end')->nullable()->after('recurrence_rule')
                  ->comment('Stop creating new occurrences after this date');
            $table->unsignedBigInteger('parent_task_id')->nullable()->after('recurrence_end')
                  ->comment('ID of the original recurring task');
        });
    }

    public function down()
    {
        Schema::table('crm_scheduled_task', function (Blueprint $table) {
            $table->dropColumn(['recurrence_rule', 'recurrence_end', 'parent_task_id']);
        });
    }
}
