<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('discount_type', 7)->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('invoices')->whereNotNull('discount_type')->exists()) {
            throw new RuntimeException('Cannot remove discount types while invoices use them.');
        }

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('discount_type');
        });
    }
};
