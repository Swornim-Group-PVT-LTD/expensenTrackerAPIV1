<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DailyBalance extends Model
{
    protected $fillable = [
        'customerid',
        'balance_date',
        'opening_balance',
        'total_credit',
        'total_debit',
        'closing_balance',
        'currency_id',
    ];

    public function currency()
    {
        return $this->belongsTo(Currency::class);
    }
}