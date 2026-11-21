<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    public const STATUSES = ['Active', 'Completed'];

    protected $table = 'invoices';

    protected $fillable = [
        'sn',
        'customerid',
        'invoice_number',
        'purchase_order',
        'logo',
        'company_details',
        'bill_to',
        'currency_id',
        'invoice_date',
        'due_date',
        'notes_payment_terms',
        'bank_account_details',
        'subtotal',
        'tax_percentage',
        'discount_amount',
        'shipping_fee',
        'total_amount',
        'created_date',
        'updated_date',
        'status',
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'due_date' => 'date',

        'subtotal' => 'decimal:2',
        'tax_percentage' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'shipping_fee' => 'decimal:2',
        'total_amount' => 'decimal:2',

        'created_date' => 'date',
        'updated_date' => 'date',
    ];

    /**
     * Invoice Items
     */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    /**
     * Currency
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }
}