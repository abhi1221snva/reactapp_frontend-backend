<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
         DB::statement("
            ALTER TABLE did 
            MODIFY default_did VARCHAR(2) DEFAULT '0'
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
       DB::statement("
            ALTER TABLE did 
            MODIFY default_did VARCHAR(2) NULL DEFAULT NULL
        ");
    }
};
