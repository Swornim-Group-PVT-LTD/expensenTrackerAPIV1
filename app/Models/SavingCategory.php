<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SavingCategory extends Model
{
    public $timestamps = false;
    protected $table = 'saving_categories';

    protected $fillable = [
        'sn',
        'customerid',
        'static_value',
        'saving_category',
        'additional_value1',
        'additional_value2',
        'additional_value3',
        'additional_value4',
        'created_date',
        'updated_date',
    ];
}