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
        Schema::table('daily_balances', function (Blueprint $table) {

            // Add unique constraint so one customer can have only one record per date
            $table->unique(
                ['customerid', 'balance_date'],
                'daily_balances_customerid_balance_date_unique'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('daily_balances', function (Blueprint $table) {

            // Remove the unique constraint
            $table->dropUnique('daily_balances_customerid_balance_date_unique');
        });
    }
};