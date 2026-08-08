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
        'created_date',
        'updated_date',
    ];

    public $timestamps = false;

    // Correct relationship: Expense belongs to the customer's Balance
    public function balance()
    {
        return $this->belongsTo(Balance::class, 'customerid', 'customerid');
    }
}
