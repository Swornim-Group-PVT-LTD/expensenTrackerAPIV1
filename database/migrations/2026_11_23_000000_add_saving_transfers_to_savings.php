<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('savings', function (Blueprint $table) {
            $table->uuid('transfer_key')->nullable();
            $table->string('remarks')->nullable();
            $table->unique(['customerid', 'transfer_key'], 'savings_customer_transfer_unique');
        });
    }

    public function down(): void
    {
        if (DB::table('savings')->whereNotNull('transfer_key')->exists()) {
            throw new RuntimeException('Cannot remove transfer metadata while savings transfers exist.');
        }

        Schema::table('savings', function (Blueprint $table) {
            $table->dropUnique('savings_customer_transfer_unique');
            $table->dropColumn(['transfer_key', 'remarks']);
        });
    }
};
