<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['barcode', 'name', 'brand', 'mrp', 'price', 'unit_cost', 'stock', 'quantity_unit', 'low_stock_threshold'])]
class Product extends Model
{
    protected $attributes = [
        'low_stock_threshold' => 5,
        'quantity_unit' => 'piece',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'mrp' => 'integer',
            'unit_cost' => 'integer',
            'stock' => 'integer',
            'low_stock_threshold' => 'integer',
        ];
    }
}
