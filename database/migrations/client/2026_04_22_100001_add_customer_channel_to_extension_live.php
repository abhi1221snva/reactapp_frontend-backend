<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $conn = config('database.default');

        if (!Schema::connection($conn)->hasColumn('extension_live', 'customer_channel')) {
            Schema::connection($conn)->table('extension_live', function (Blueprint $table) {
                $table->string('customer_channel', 255)->nullable();
            });
        }
    }

    public function down(): void
    {
        $conn = config('database.default');

        if (Schema::connection($conn)->hasColumn('extension_live', 'customer_channel')) {
            Schema::connection($conn)->table('extension_live', function (Blueprint $table) {
                $table->dropColumn('customer_channel');
            });
        }
    }
};
