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
        Schema::table('gmail_oauth_tokens', function (Blueprint $table) {
            $table->timestamp('watch_expiration')->nullable()->after('last_history_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('gmail_oauth_tokens', function (Blueprint $table) {
            $table->dropColumn('watch_expiration');
        });
    }
};
