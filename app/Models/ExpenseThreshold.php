<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExpenseThreshold extends Model
{
    protected $table = 'expense_threshold';
    protected $fillable = [
        'sn',
        'customerid',
        'expense_threshold_amount',
        'frequency',
        'isEnable',
        'create_date',
        'update_date',
    ];

    public $timestamps = false; // because you are NOT using created_at / updated_at
}