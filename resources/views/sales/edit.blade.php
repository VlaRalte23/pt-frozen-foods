<div>
    <!-- Nothing worth having comes easy. - Theodore Roosevelt -->
</div>
@extends('layouts.app')

@section('title', 'Edit sale')

@section('content')
    <section class="page-heading">
        <div>
            <p class="eyebrow">TRANSACTION HISTORY</p>
            <h1>Edit sale</h1>
            <p class="subheading">{{ $sale->receipt_number ?? 'Sale #'.$sale->id }} · Stock and totals adjust when saved.</p>
        </div>
        <a class="button button-secondary sales-new-button" href="{{ route('sales.index') }}">Back to sales</a>
    </section>

    @if ($errors->any())
        <div class="notice" role="alert">{{ $errors->first() }}</div>
    @endif

    <section class="panel sale-edit-panel">
        <form id="sale-edit-form" action="{{ route('sales.update', $sale) }}" method="POST">
            @csrf
            @method('PUT')

            <div class="sale-edit-section">
                <div class="inventory-heading">
                    <div>
                        <h2>Products sold</h2>
                        <p>Existing item prices stay the same. New products use their current selling price.</p>
                    </div>
                    <button id="add-sale-item" class="button button-secondary" type="button">Add product</button>
                </div>
                @php
                    $formItems = old(
                        'items',
                        $sale->items->map(fn ($item): array => [
                            'product_id' => $item->product_id,
                            'quantity' => $item->quantity,
                        ])->all(),
                    );
                @endphp
                <div id="sale-items-list" class="sale-items-list" data-next-index="{{ count($formItems) }}">
                    @foreach ($formItems as $index => $item)
                        @php
                            $selectedProductId = (int) ($item['product_id'] ?? 0);
                            $selectedProduct = $products->firstWhere('id', $selectedProductId);
                            $savedItem = $sale->items->firstWhere('product_id', $selectedProductId);
                        @endphp
                        <div class="sale-item-row">
                            <div class="form-field">
                                <label for="sale-product-{{ $index }}">PRODUCT</label>
                                <select id="sale-product-{{ $index }}" name="items[{{ $index }}][product_id]" required>
                                    <option value="">Choose a product</option>
                                    @foreach ($products as $product)
                                        @php
                                            $productSaleItem = $sale->items->firstWhere('product_id', $product->id);
                                        @endphp
                                        <option value="{{ $product->id }}"
                                            data-unit-price="{{ $productSaleItem?->unit_price ?? $product->price }}"
                                            data-price-label="{{ $productSaleItem ? 'Saved price' : 'Current price' }}"
                                            @selected($selectedProductId === $product->id)>
                                            {{ $product->name }} · {{ $product->barcode }} · {{ $product->stock }} in stock
                                        </option>
                                    @endforeach
                                </select>
                                <small class="sale-line-price" data-price-label>
                                    @if ($savedItem)
                                        Saved price: ₹{{ number_format($savedItem->unit_price, 0, '.', ',') }}
                                    @elseif ($selectedProduct)
                                        Current price: ₹{{ number_format($selectedProduct->price, 0, '.', ',') }}
                                    @else
                                        Select a product to see its price.
                                    @endif
                                </small>
                            </div>
                            <div class="form-field">
                                <label for="sale-quantity-{{ $index }}">QUANTITY</label>
                                <input id="sale-quantity-{{ $index }}" name="items[{{ $index }}][quantity]" type="number"
                                    min="1" max="999999" step="1" value="{{ $item['quantity'] ?? 1 }}" required>
                            </div>
                            <button class="button button-danger remove-sale-item" type="button" aria-label="Remove product row">Remove</button>
                        </div>
                    @endforeach
                </div>
                <template id="sale-item-template">
                    <div class="sale-item-row">
                        <div class="form-field">
                            <label for="sale-product-__INDEX__">PRODUCT</label>
                            <select id="sale-product-__INDEX__" name="items[__INDEX__][product_id]" required>
                                <option value="">Choose a product</option>
                                @foreach ($products as $product)
                                    @php
                                        $productSaleItem = $sale->items->firstWhere('product_id', $product->id);
                                    @endphp
                                    <option value="{{ $product->id }}"
                                        data-unit-price="{{ $productSaleItem?->unit_price ?? $product->price }}"
                                        data-price-label="{{ $productSaleItem ? 'Saved price' : 'Current price' }}">
                                        {{ $product->name }} · {{ $product->barcode }} · {{ $product->stock }} in stock
                                    </option>
                                @endforeach
                            </select>
                            <small class="sale-line-price" data-price-label>Select a product to see its price.</small>
                        </div>
                        <div class="form-field">
                            <label for="sale-quantity-__INDEX__">QUANTITY</label>
                            <input id="sale-quantity-__INDEX__" name="items[__INDEX__][quantity]" type="number"
                                min="1" max="999999" step="1" value="1" required>
                        </div>
                        <button class="button button-danger remove-sale-item" type="button" aria-label="Remove product row">Remove</button>
                    </div>
                </template>
            </div>

            <div class="sale-edit-section">
                <div class="inventory-heading">
                    <div>
                        <h2>Customer & order type</h2>
                        <p>Delivery requires a customer with a saved address.</p>
                    </div>
                </div>
                <div class="form-field">
                    <label for="sale-fulfillment-type">ORDER TYPE</label>
                    <select id="sale-fulfillment-type" name="fulfillment_type" required>
                        <option value="walk_in" @selected(old('fulfillment_type', $sale->fulfillment_type ?? 'walk_in') === 'walk_in')>Walk-in</option>
                        <option value="delivery" @selected(old('fulfillment_type', $sale->fulfillment_type ?? 'walk_in') === 'delivery')>Delivery</option>
                    </select>
                </div>
                <div class="form-field">
                    <label for="sale-customer">REGULAR CUSTOMER</label>
                    <select id="sale-customer" name="customer_id">
                        <option value="">No regular customer</option>
                        @foreach ($customers as $customer)
                            <option value="{{ $customer->id }}" @selected((string) old('customer_id', $sale->customer_id) === (string) $customer->id)>
                                {{ $customer->name }}{{ $customer->phone ? ' · '.$customer->phone : '' }}{{ $customer->address ? ' · '.$customer->address : ' · No saved address' }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="form-row">
                    <div class="form-field">
                        <label for="sale-customer-name">CUSTOMER NAME / STORE</label>
                        <input id="sale-customer-name" name="customer_name" type="text" maxlength="120"
                            value="{{ old('customer_name', $sale->customer_name) }}">
                    </div>
                    <div class="form-field">
                        <label for="sale-customer-phone">PHONE</label>
                        <input id="sale-customer-phone" name="customer_phone" type="text" maxlength="30"
                            value="{{ old('customer_phone', $sale->customer_phone) }}">
                    </div>
                </div>
                <div class="form-field">
                    <label for="sale-customer-address">ADDRESS</label>
                    <input id="sale-customer-address" name="customer_address" type="text" maxlength="500"
                        value="{{ old('customer_address', $sale->customer_address) }}">
                </div>
            </div>

            <div class="sale-edit-section">
                <div class="inventory-heading">
                    <div>
                        <h2>Payment</h2>
                        <p>Sale total and change recalculate from the saved item prices.</p>
                    </div>
                </div>
                <div class="form-field">
                    <label for="sale-payment-method">PAYMENT METHOD</label>
                    <select id="sale-payment-method" name="payment_method" required>
                        @foreach (['cash' => 'Cash', 'gpay' => 'GPay', 'bank_transfer' => 'Bank transfer', 'credit' => 'Credit / pay later'] as $value => $label)
                            <option value="{{ $value }}" @selected(old('payment_method', $sale->payment_method) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div id="sale-cash-fields" class="form-field">
                    <label for="sale-cash-received">CASH RECEIVED</label>
                    <input id="sale-cash-received" name="cash_received" type="number" min="0" max="999999999999" step="1"
                        value="{{ old('cash_received', $sale->cash_received) }}">
                </div>
                <p class="help-text">Invoices with recorded debt repayments cannot be edited or deleted.</p>
            </div>

            <div class="sale-edit-actions">
                <button class="button button-primary" type="submit">Save sale changes</button>
                <a class="button button-secondary" href="{{ route('sales.index') }}">Cancel</a>
            </div>
        </form>
    </section>
@endsection
