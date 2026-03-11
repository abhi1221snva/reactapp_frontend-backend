<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * crm_labels — field configuration table (replaces crm_label).
 * Pure EAV architecture: all lead fields are defined here regardless of type.
 */
class CreateCrmLabelsTable extends Migration
{
    public function up(): void
    {
        Schema::create('crm_labels', function (Blueprint $table) {
            $table->id();
            $table->string('label_name');                        // Display name (e.g. "First Name")
            $table->string('field_key', 100)->unique();          // Lookup key used in crm_lead_values (e.g. "first_name")
            $table->string('field_type', 50)->default('text');   // text, email, phone_number, date, dropdown, radio, checkbox, textarea, number
            $table->string('section', 100)->default('general');  // Grouping section (owner, contact, business, address, …)
            $table->json('options')->nullable();                  // Options array for dropdown/radio fields
            $table->string('placeholder', 255)->nullable();      // Input placeholder text
            $table->json('conditions')->nullable();               // Conditional visibility rules
            $table->boolean('required')->default(false);
            $table->integer('display_order')->default(0);
            $table->boolean('status')->default(true);            // true = active
            $table->timestamps();

            $table->index('status');
            $table->index('display_order');
            $table->index('section');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_labels');
    }
}
