<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {

            $table->string('sn', 20)
                ->unique()
                ->after('id');

            $table->string('customerid', 50)
                ->after('sn');

            $table->date('created_date')
                ->nullable()
                ->after('total_amount');

            $table->date('updated_date')
                ->nullable()
                ->after('created_date');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique(['sn']);
            $table->dropColumn([
                'sn',
                'customerid',
                'created_date',
                'updated_date',
            ]);
        });
    }
};