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
        Schema::table('did', function (Blueprint $table) {
            // Unique UUID from Easify
            if (!Schema::hasColumn('did', 'uuid')) $table->uuid('uuid')->nullable()->unique()->after('id');

            // Link with credential table (UUID based)
            if (!Schema::hasColumn('did', 'credential_uuid')) $table->uuid('credential_uuid')->nullable()->index()->after('uuid');

            // Phone number type (local / tollfree / mobile)
            if (!Schema::hasColumn('did', 'type')) $table->string('type', 50)->nullable()->after('credential_uuid');

            // Status flag (maps to `active`)
            if (!Schema::hasColumn('did', 'status')) $table->boolean('status')->default(true)->after('credential_uuid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('did', function (Blueprint $table) {
            $table->dropColumn(['uuid', 'credential_uuid', 'status']);
        });
    }
};
