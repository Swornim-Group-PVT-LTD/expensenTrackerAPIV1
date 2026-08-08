<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Currency extends Model
{
    use HasFactory;

    protected $fillable = [
        'sn',
        'country_name',
        'currency',
        'symbol',
        'customerid', // add this
    ];


    // Auto-generate SN before creating
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $lastSn = Currency::max('sn') ?? 0;
            $model->sn = $lastSn + 1;
        });
    }
}
