<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IncomeCategory extends Model
{
    use HasFactory;

    protected $table = 'income_categories';

    protected $fillable = [
        'sn',
        'customerid',
        'static_value',
        'income_category',
        'additional_value1',
        'additional_value2',
        'additional_value3',
        'additional_value4',
        'created_date',
        'updated_date',
    ];

    public $timestamps = false; // since you're using custom date fields
}
