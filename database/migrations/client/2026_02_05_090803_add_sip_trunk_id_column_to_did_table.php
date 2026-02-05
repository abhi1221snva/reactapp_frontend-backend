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
              $table->string('sip_trunk_id', 100)
                  ->nullable()->after('phone_number_sid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('did', function (Blueprint $table) {
            //
        });
    }
};
