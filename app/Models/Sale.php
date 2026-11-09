<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'receipt_number',
    'user_id',
    'sales_manager_id',
    'sales_manager_name',
    'sold_by_name',
    'customer_id',
    'payment_method',
    'fulfillment_type',
    'customer_name',
    'customer_phone',
    'customer_address',
    'total',
    'cash_received',
    'change_due',
])]
class Sale extends Model
{
    protected function casts(): array
    {
        return [
            'total' => 'integer',
            'cash_received' => 'integer',
            'change_due' => 'integer',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function salesManager(): BelongsTo
    {
        return $this->belongsTo(SalesManager::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function customerPayments(): HasMany
    {
        return $this->hasMany(CustomerPayment::class);
    }
}
