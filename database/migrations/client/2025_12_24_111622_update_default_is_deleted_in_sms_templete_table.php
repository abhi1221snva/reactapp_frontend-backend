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
        if (Schema::hasColumn('sms_templete', 'is_deleted')) {
            DB::statement("
                ALTER TABLE sms_templete 
                MODIFY is_deleted ENUM('1','0') NOT NULL DEFAULT '0'
            ");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('sms_templete', 'is_deleted')) {
            DB::statement("
                ALTER TABLE sms_templete 
                MODIFY is_deleted ENUM('1','0') NOT NULL DEFAULT '1'
            ");
        }
    }
};
