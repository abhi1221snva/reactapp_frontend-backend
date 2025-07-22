<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddCurrencyCodeAndPackageIdAndUserIdToCdrArchiveTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasColumn('cdr_archive', 'currency_code')) {
            Schema::table('cdr_archive', function (Blueprint $table) {
                $table->dropColumn('currency_code');
            });
        }
        if (Schema::hasColumn('cdr_archive', 'client_package_id')) {
            Schema::table('cdr_archive', function (Blueprint $table) {
                $table->dropColumn('client_package_id');
            });
        }
        if (Schema::hasColumn('cdr_archive', 'user_id')) {
            Schema::table('cdr_archive', function (Blueprint $table) {
                $table->dropColumn('user_id');
            });
        }
        if (Schema::hasColumn('cdr_archive', 'billable_minutes')) {
            Schema::table('cdr_archive', function (Blueprint $table) {
                $table->dropColumn('billable_minutes');
            });
        }
        if (Schema::hasColumn('cdr_archive', 'billable_charge')) {
            Schema::table('cdr_archive', function (Blueprint $table) {
                $table->dropColumn('billable_charge');
            });
        }
        Schema::table('cdr_archive', function (Blueprint $table) {
            $table->string('currency_code', 3)->nullable()->comment('ISO 4217');
            $table->integer('client_package_id')->nullable()->comment('Reference from master.permissions.client_package_id');
            $table->integer('user_id')->nullable()->comment('Reference from master.user.id');
            $table->integer('billable_minutes')->nullable()->comment('Number of minutes billed');
            $table->decimal('billable_charge', 8,4)->nullable()->unsigned();
            $table->decimal('charge', 8,4)->nullable()->unsigned()->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('cdr_archive', 'currency_code')) {
            Schema::table('cdr_archive', function (Blueprint $table) {
                $table->dropColumn('currency_code');
            });
        }
        if (Schema::hasColumn('cdr_archive', 'client_package_id')) {
            Schema::table('cdr_archive', function (Blueprint $table) {
                $table->dropColumn('client_package_id');
            });
        }
        if (Schema::hasColumn('cdr_archive', 'user_id')) {
            Schema::table('cdr_archive', function (Blueprint $table) {
                $table->dropColumn('user_id');
            });
        }
        if (Schema::hasColumn('cdr_archive', 'billable_charge')) {
            Schema::table('cdr_archive', function (Blueprint $table) {
                $table->dropColumn('billable_charge');
            });
        }
        if (Schema::hasColumn('cdr_archive', 'billable_minutes')) {
            Schema::table('cdr_archive', function (Blueprint $table) {
                $table->dropColumn('billable_minutes');
            });
        }
    }
}
