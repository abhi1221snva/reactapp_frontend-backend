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
       Schema::table('sms_templete', function (Blueprint $table) {
            if (!Schema::hasColumn('sms_templete', 'status')) $table->enum('status', ['0', '1'])->default('1'); // 0 - no, 1 - yes
            if (!Schema::hasColumn('sms_templete', 'created_at') && !Schema::hasColumn('sms_templete', 'updated_at')) $table->timestamps(); // adds created_at and updated_at
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sms_templete', function (Blueprint $table) {
            //
        });
    }
};
