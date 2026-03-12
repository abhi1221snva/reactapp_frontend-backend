<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sms_providers', function (Blueprint $table) {
            if (!Schema::hasColumn('sms_providers', 'uuid')) $table->uuid('uuid')->nullable()->unique()->after('id');
            if (!Schema::hasColumn('sms_providers', 'type')) $table->string('type', 50)->nullable()->after('provider');
            if (!Schema::hasColumn('sms_providers', 'deleted_at')) $table->timestamp('deleted_at')->nullable()->default(null);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("
            ALTER TABLE sms_providers
            DROP COLUMN uuid,
            DROP COLUMN type,
            DROP COLUMN deleted_at
        ");
    }
};
