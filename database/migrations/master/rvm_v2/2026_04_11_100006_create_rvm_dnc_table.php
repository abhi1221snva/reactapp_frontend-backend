<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * rvm_dnc — do-not-call list.
 *
 * client_id IS NULL means a GLOBAL entry that all tenants must honour.
 * client_id filled in means a per-tenant suppression.
 *
 * Compliance check is O(1): indexed lookup on (client_id, phone_e164).
 */
class CreateRvmDncTable extends Migration
{
    public function up()
    {
        Schema::connection('master')->create('rvm_dnc', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('client_id')->nullable();
            $table->string('phone_e164', 16);
            $table->enum('source', ['manual', 'import', 'callback_reply', 'complaint', 'global'])->default('manual');
            $table->string('note', 255)->nullable();
            $table->unsignedInteger('created_by_user_id')->nullable();
            $table->dateTime('created_at');

            // Compliance gate hits this index on every drop.
            $table->unique(['client_id', 'phone_e164'], 'uk_rvm_dnc_client_phone');
            $table->index('phone_e164', 'idx_rvm_dnc_phone');
        });
    }

    public function down()
    {
        Schema::connection('master')->dropIfExists('rvm_dnc');
    }
}
