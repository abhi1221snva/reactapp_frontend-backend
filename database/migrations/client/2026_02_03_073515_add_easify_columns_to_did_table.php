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
            $table->uuid('uuid')->nullable()->unique()->after('id');

            // Link with credential table (UUID based)
            $table->uuid('credential_uuid')->nullable()->index()->after('uuid');
         // Phone number type (local / tollfree / mobile)
           $table->string('type', 50)->nullable()->after('credential_uuid');
            // Status flag (maps to `active`)
            $table->boolean('status')->default(true)->after('credential_uuid');
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
