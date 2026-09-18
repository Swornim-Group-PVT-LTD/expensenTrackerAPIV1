<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    use HasFactory;

    protected $table = 'expenses';

    protected $fillable = [
        'sn',
        'customerid',
        'add_expenses',
        'total_expenses',
        'expense_category',
        'upload_receipt',
        'created_date',
        'updated_date',
    ];

    public $timestamps = false;

    /*
    |--------------------------------------------------------------------------
    | Balance Relationship
    |--------------------------------------------------------------------------
    */

    public function balance()
    {
        return $this->belongsTo(
            Balance::class,
            'customerid',
            'customerid'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Expense Documents Relationship
    |--------------------------------------------------------------------------
    */

    public function documents()
    {
        return $this->hasMany(
            ExpenseDocument::class,
            'expense_sn',
            'sn'
        );
    }
}