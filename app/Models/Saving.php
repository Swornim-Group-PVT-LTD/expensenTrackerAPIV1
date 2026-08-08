<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Saving extends Model
{
    use HasFactory;

    protected $table = 'savings';

    protected $fillable = [
        'sn',
        'customerid',
        'add_saving',
        'total_saving',
        'saving_category',
        'created_date',
        'updated_date',
        'want_to_deduct_from_balance',
    ];

    // Cast the flag as boolean
    protected $casts = [
        'want_to_deduct_from_balance' => 'boolean',
    ];

    // Disable Laravel's default timestamps
    public $timestamps = false;
}
