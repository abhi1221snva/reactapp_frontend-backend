<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add unique index on rvm_queue_list.rvm_cdr_log_id.
 *
 * Closes the duplicate dispatch race documented in the RVM audit (R6):
 * two SendRvmJob workers could both insert rvm_queue_list rows for the
 * same rvm_cdr_log_id and both originate the call via AMI.
 *
 * Before adding the index we collapse any pre-existing duplicates so the
 * CREATE UNIQUE INDEX statement cannot fail on historical data. Only the
 * latest row (highest id) per rvm_cdr_log_id is kept.
 */
class AddUniqueToRvmQueueList extends Migration
{
    public function up(): void
    {
        if (!Schema::connection('master')->hasTable('rvm_queue_list')) {
            return;
        }

        // De-dupe historical rows first — keep the newest per rvm_cdr_log_id.
        DB::connection('master')->statement("
            DELETE q1 FROM rvm_queue_list q1
            INNER JOIN rvm_queue_list q2
                ON q1.rvm_cdr_log_id = q2.rvm_cdr_log_id
                AND q1.id < q2.id
        ");

        Schema::connection('master')->table('rvm_queue_list', function (Blueprint $table) {
            $table->unique('rvm_cdr_log_id', 'uniq_rvm_queue_cdr');
        });
    }

    public function down(): void
    {
        if (!Schema::connection('master')->hasTable('rvm_queue_list')) {
            return;
        }

        Schema::connection('master')->table('rvm_queue_list', function (Blueprint $table) {
            $table->dropUnique('uniq_rvm_queue_cdr');
        });
    }
}
