<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Income extends Model
{
    protected $fillable = [
        'sn',
        'customerid',
        'add_income',
        'total_income',
        'income_category',
        'created_date',
        'updated_date',
    ];
    
    public $timestamps = false; // Since you're manually using created_date and updated_date

    public function balance()
    {
        return $this->belongsTo(Balance::class, 'customerid', 'customerid');
    }
}
