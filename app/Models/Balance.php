<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Balance extends Model
{
    protected $table = 'balances';

    public $timestamps = false;

    const CREATED_AT = 'created_date';
    const UPDATED_AT = 'updated_date';

    protected $fillable = [
        'sn',
        'customerid',
        'opening_balance',
        'currency_id',
        'created_date',
        'updated_date',
    ];

    protected $casts = [
        'opening_balance' => 'decimal:2',
    ];

    public function currency()
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    public function dailyBalances()
    {
        return $this->hasMany(DailyBalance::class, 'customerid', 'customerid');
    }
}