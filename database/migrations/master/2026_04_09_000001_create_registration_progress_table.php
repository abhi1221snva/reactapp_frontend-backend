<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'master';

    public function up(): void
    {
        Schema::connection('master')->create('registration_progress', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('registration_id')->comment('FK → prospect_initial_data.id');
            $table->string('email')->nullable();
            $table->string('phone', 20)->nullable();

            // Onboarding path
            $table->enum('path', ['fast', 'slow'])->default('slow');

            // Current provisioning stage
            $table->enum('stage', [
                'queued',           // Job dispatched, waiting in queue
                'creating_record',  // Creating client + user records
                'creating_database',// Creating tenant DB + running migrations
                'seeding_data',     // Seeding default CRM data
                'assigning_trial',  // Assigning trial package
                'sending_welcome',  // Sending welcome email
                'completed',        // All done
                'failed',           // Something went wrong
            ])->default('queued');

            $table->unsignedTinyInteger('progress_pct')->default(0)->comment('0-100');

            // Result data (populated on completion)
            $table->unsignedBigInteger('client_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();

            // Error tracking
            $table->string('error_message', 500)->nullable();
            $table->unsignedTinyInteger('retry_count')->default(0);

            $table->timestamps();

            $table->index('registration_id');
            $table->index(['stage', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('master')->dropIfExists('registration_progress');
    }
};
