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
            $table->unsignedBigInteger('source_saving_id')->nullable()->index();
            $table->uuid('transfer_batch_key')->nullable();
            $table->unique(['customerid', 'transfer_batch_key', 'source_saving_id'], 'savings_bulk_transfer_unique');
        });
    }

    public function down(): void
    {
        if (DB::table('savings')->whereNotNull('source_saving_id')->exists()) {
            throw new RuntimeException('Cannot remove source links while bulk savings transfers exist.');
        }

        Schema::table('savings', function (Blueprint $table) {
            $table->dropUnique('savings_bulk_transfer_unique');
            $table->dropIndex(['source_saving_id']);
            $table->dropColumn(['source_saving_id', 'transfer_batch_key']);
        });
    }
};
