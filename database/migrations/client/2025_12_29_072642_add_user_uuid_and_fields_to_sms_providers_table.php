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
         DB::statement("
            ALTER TABLE sms_providers
            ADD COLUMN uuid CHAR(36) NULL UNIQUE AFTER id,
            ADD COLUMN type VARCHAR(50) NULL AFTER provider,
            ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL
        ");

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
