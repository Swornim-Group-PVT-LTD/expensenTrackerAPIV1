<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExpenseCategory extends Model
{
    public $timestamps = false;

    protected $fillable = [
    'customerid',
    'static_value',
    'expense_category',
    'additional_value1',
    'additional_value2',
    'additional_value3',
    'additional_value4',
    'sn',
    'created_date',
    'updated_date',
];
}
