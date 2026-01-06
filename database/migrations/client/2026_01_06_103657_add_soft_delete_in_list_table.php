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
            Schema::table('list', function (Blueprint $table) {
            if (!Schema::hasColumn('list', 'is_deleted')) {
                $table->tinyInteger('is_deleted')
                      ->default(0)
                      ->comment('0 = active, 1 = soft deleted')
                      ->after('id');
            }

            if (!Schema::hasColumn('list', 'deleted_at')) {
                $table->timestamp('deleted_at')
                      ->nullable()
                      ->after('is_deleted');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('list', function (Blueprint $table) {
            //
        });
    }
};
