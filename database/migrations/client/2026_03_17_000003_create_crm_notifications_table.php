<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * In-app notifications for admin/agent users.
 * Created automatically when a merchant updates a lead.
 */
class CreateCrmNotificationsTable extends Migration
{
    public function up(): void
    {
        Schema::create('crm_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lead_id')->nullable()->index();
            $table->unsignedBigInteger('recipient_user_id')->nullable()
                  ->comment('Target admin/agent user ID; NULL = broadcast to all admins');
            $table->string('type', 60)->default('merchant_update')
                  ->comment('merchant_update | system | alert');
            $table->string('title', 200)->nullable();
            $table->text('message');
            $table->boolean('is_read')->default(false)->index();
            $table->timestamp('read_at')->nullable();
            $table->json('meta')->nullable()->comment('Extra context as JSON');
            $table->timestamps();

            $table->index(['recipient_user_id', 'is_read']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_notifications');
    }
}
