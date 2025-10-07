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
        Schema::table('clients', function (Blueprint $table) {
            $table->boolean('reserved')->default(false)->after('id'); // add after id, adjust as needed
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('reserved')->default(false)->after('id'); // add after id, adjust as needed
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('reserved');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('reserved');
        });
    }
};
