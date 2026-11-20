<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SavingCategoryLookupTest extends TestCase
{
    public function test_lookup_returns_only_a_category_owned_by_the_authenticated_customer(): void
    {
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);

        Schema::create('saving_categories', function (Blueprint $table) {
            $table->id();
            $table->string('sn');
            $table->string('customerid');
            $table->string('saving_category');
        });

        DB::table('saving_categories')->insert([
            ['sn' => 'OWNED', 'customerid' => 'CUST1', 'saving_category' => 'Emergency'],
            ['sn' => 'OTHER', 'customerid' => 'CUST2', 'saving_category' => 'Travel'],
        ]);

        Sanctum::actingAs(new User(['customerid' => 'CUST1']));

        $this->getJson('/api/savingCategory/OWNED')
            ->assertOk()
            ->assertJsonPath('data.saving_category', 'Emergency');
        $this->getJson('/api/savingCategory/OTHER')->assertNotFound();
    }
}
