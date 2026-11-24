<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Saving extends Model
{
    use HasFactory;

    public const NET_AMOUNT = 'CASE WHEN transfer_key IS NULL THEN add_saving ELSE -add_saving END';

    protected $hidden = ['transfer_key', 'transfer_batch_key'];

    protected $appends = ['is_transfer'];

    public function getIsTransferAttribute(): bool
    {
        return $this->transfer_key !== null;
    }

    protected $table = 'savings';

    protected $fillable = [
        'sn',
        'transfer_key',
        'source_saving_id',
        'transfer_batch_key',
        'remarks',
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
