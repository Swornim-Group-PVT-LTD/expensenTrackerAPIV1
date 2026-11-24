<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('savings', function (Blueprint $table) {
            $table->decimal('savings_deduction', 12, 2)->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('savings')->whereNotNull('savings_deduction')->exists()) {
            throw new RuntimeException('Cannot remove deduction amounts while savings transfers use them.');
        }

        Schema::table('savings', function (Blueprint $table) {
            $table->dropColumn('savings_deduction');
        });
    }
};
