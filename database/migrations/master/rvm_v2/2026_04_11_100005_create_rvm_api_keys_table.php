<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * rvm_api_keys — scoped API keys for external integrations.
 *
 * Keys take the form  rvm_{env}_{prefix}_{secret}
 * where `prefix` is the row's lookup key (fast index) and `secret` is
 * only stored as argon2id hash. The raw key is shown ONCE at creation.
 */
class CreateRvmApiKeysTable extends Migration
{
    public function up()
    {
        Schema::connection('master')->create('rvm_api_keys', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('client_id');
            $table->unsignedInteger('created_by_user_id')->nullable();

            $table->string('name', 100);
            $table->string('key_prefix', 16)->unique();      // public lookup handle
            $table->string('key_hash', 255);                 // argon2id(raw_key)
            $table->string('hmac_secret', 128)->nullable();  // optional request signing

            $table->json('scopes')->nullable();              // ["drops.create","campaigns.read"]
            $table->unsignedInteger('rate_limit_per_minute')->default(2000);

            $table->dateTime('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->string('revoked_reason', 255)->nullable();

            $table->timestamps();

            $table->index(['client_id', 'revoked_at'], 'idx_rvm_api_keys_client_active');
        });
    }

    public function down()
    {
        Schema::connection('master')->dropIfExists('rvm_api_keys');
    }
}
