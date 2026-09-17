<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExpenseDocument extends Model
{
    use HasFactory;

    protected $table = 'expense_documents';

    protected $fillable = [
        'expense_sn',
        'file_name',
        'file_path',
        'file_type',
    ];

    public function expense()
    {
        return $this->belongsTo(
            Expense::class,
            'expense_sn',
            'sn'
        );
    }
}