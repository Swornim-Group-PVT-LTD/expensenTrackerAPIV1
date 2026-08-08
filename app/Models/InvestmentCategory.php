<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvestmentCategory extends Model
{
    public $timestamps = false;
    protected $table = 'investment_category';

    protected $fillable = [
        'sn',
        'customerid',
        'static_value',
        'investment_category',
        'additional_value1',
        'additional_value2',
        'additional_value3',
        'additional_value4',
        'created_date',
        'updated_date',
    ];
}

