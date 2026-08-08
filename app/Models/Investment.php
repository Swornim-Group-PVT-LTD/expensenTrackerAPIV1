<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Investment extends Model
{
    use HasFactory;

    protected $table = 'investments';

    protected $fillable = [
        'sn',
        'customerid',
        'add_investment',
        'total_investment',
        'investment_category',
        'created_date',
        'updated_date',
    ];

    public $timestamps = false; // Since you're manually using created_date and updated_date
}
