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
        Schema::table('email_logs', function (Blueprint $table) {
            if (!Schema::hasColumn('email_logs', 'folder')) $table->string('folder')->default('sent')->after('id');
            if (!Schema::hasColumn('email_logs', 'cc')) $table->json('cc')->nullable()->after('folder');
            if (!Schema::hasColumn('email_logs', 'bcc')) $table->json('bcc')->nullable()->after('cc');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('email_logs', function (Blueprint $table) {
            //
        });
    }
};
