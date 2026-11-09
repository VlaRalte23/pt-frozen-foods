<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SalesController extends Controller
{
    public function index(): View
    {
        return view('sales.index', [
            'sales' => Sale::query()
                ->with(['items', 'seller', 'customer'])
                ->withSum('customerPayments as payments_total', 'amount')
                ->latest()
                ->paginate(20),
            'isOwner' => request()->user()->isOwner(),
        ]);
    }

    public function edit(Sale $sale): View
    {
        $sale->load('items');

        return view('sales.edit', [
            'sale' => $sale,
            'products' => Product::query()->orderBy('name')->get(['id', 'name', 'barcode', 'price', 'stock']),
            'customers' => Customer::query()->orderBy('name')->get(['id', 'name', 'phone', 'address']),
        ]);
    }

    public function update(Request $request, Sale $sale): RedirectResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'integer', 'distinct', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:999999'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'customer_name' => ['nullable', 'string', 'max:120'],
            'customer_phone' => ['nullable', 'string', 'max:30'],
            'customer_address' => ['nullable', 'string', 'max:500'],
            'fulfillment_type' => ['required', 'string', 'in:walk_in,delivery'],
            'payment_method' => ['required', 'string', 'in:cash,gpay,bank_transfer,credit'],
            'cash_received' => ['required_if:payment_method,cash', 'nullable', 'integer', 'min:0', 'max:999999999999'],
        ], [
            'cash_received.required_if' => 'Enter the amount received in cash.',
        ]);

        DB::transaction(function () use ($validated, $sale): void {
            $sale = Sale::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();
            $existingItems = $sale->items()->get();

            if ($sale->customerPayments()->lockForUpdate()->exists()) {
                throw ValidationException::withMessages([
                    'sale' => 'This sale has recorded debt payments and cannot be edited or deleted.',
                ]);
            }

            $existingByProduct = $existingItems->keyBy('product_id');
            $newItems = collect($validated['items'])->keyBy('product_id');
            $productIds = $existingItems->pluck('product_id')
                ->merge($newItems->keys())
                ->unique()
                ->sort()
                ->values();
            $products = Product::query()
                ->whereKey($productIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $customer = null;

            if (! empty($validated['customer_id'])) {
                $customer = Customer::query()
                    ->whereKey($validated['customer_id'])
                    ->lockForUpdate()
                    ->first();

                if (! $customer) {
                    throw ValidationException::withMessages([
                        'customer_id' => 'Select an active regular customer.',
                    ]);
                }
            }

            if ($validated['payment_method'] === 'credit' && ! $customer) {
                throw ValidationException::withMessages([
                    'customer_id' => 'Select a regular customer for a credit sale.',
                ]);
            }

            if ($validated['fulfillment_type'] === 'delivery' && ! trim($customer?->address ?? '')) {
                throw ValidationException::withMessages([
                    'customer_id' => 'Delivery sales require a customer with a saved address.',
                ]);
            }

            $updatedItems = [];
            $total = 0;

            foreach ($newItems as $productId => $item) {
                $product = $products->get($productId);

                if (! $product) {
                    throw ValidationException::withMessages([
                        'items' => 'A selected product is no longer available.',
                    ]);
                }

                $existingItem = $existingByProduct->get($product->id);
                $availableStock = $product->stock + ($existingItem?->quantity ?? 0);

                if ($item['quantity'] > $availableStock) {
                    throw ValidationException::withMessages([
                        'items' => "Only {$availableStock} available for {$product->name} after accounting for this sale.",
                    ]);
                }

                if (! $existingItem && $product->unit_cost === null) {
                    throw ValidationException::withMessages([
                        'items' => "Add a product cost for {$product->name} before adding it to this sale.",
                    ]);
                }

                $unitPrice = $existingItem?->unit_price ?? $product->price;
                $unitCost = $existingItem?->unit_cost ?? $product->unit_cost;
                $lineTotal = $unitPrice * $item['quantity'];
                $total += $lineTotal;
                $updatedItems[] = [
                    'product' => $product,
                    'quantity' => $item['quantity'],
                    'unit_price' => $unitPrice,
                    'unit_cost' => $unitCost,
                    'line_total' => $lineTotal,
                ];
            }

            $paymentMethod = $validated['payment_method'];
            $cashReceived = match ($paymentMethod) {
                'credit' => 0,
                'cash' => $validated['cash_received'],
                default => $total,
            };

            if ($paymentMethod === 'cash' && $cashReceived < $total) {
                throw ValidationException::withMessages([
                    'cash_received' => 'Cash received must be at least the sale total.',
                ]);
            }

            $sale->update([
                'customer_id' => $customer?->id,
                'customer_name' => $customer?->name ?? (trim($validated['customer_name'] ?? '') ?: null),
                'customer_phone' => $customer?->phone ?? (trim($validated['customer_phone'] ?? '') ?: null),
                'customer_address' => $customer?->address ?? (trim($validated['customer_address'] ?? '') ?: null),
                'fulfillment_type' => $validated['fulfillment_type'],
                'payment_method' => $paymentMethod,
                'total' => $total,
                'cash_received' => $cashReceived,
                'change_due' => $paymentMethod === 'cash' ? $cashReceived - $total : 0,
            ]);

            foreach ($products as $product) {
                $oldQuantity = (int) ($existingByProduct->get($product->id)?->quantity ?? 0);
                $newQuantity = (int) ($newItems->get($product->id)['quantity'] ?? 0);
                $product->stock += $oldQuantity - $newQuantity;
                $product->save();
            }

            $sale->items()->delete();

            foreach ($updatedItems as $item) {
                $product = $item['product'];
                $sale->items()->create([
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'barcode' => $product->barcode,
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'unit_cost' => $item['unit_cost'],
                    'line_total' => $item['line_total'],
                ]);
            }
        });

        return redirect()->route('sales.index')->with('success', 'Sale and related stock, customer, and totals were updated.');
    }

    public function destroy(Sale $sale): RedirectResponse
    {
        DB::transaction(function () use ($sale): void {
            $sale = Sale::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();
            $items = $sale->items()->get();

            if ($sale->customerPayments()->lockForUpdate()->exists()) {
                throw ValidationException::withMessages([
                    'sale' => 'This sale has recorded debt payments and cannot be edited or deleted.',
                ]);
            }

            $products = Product::query()
                ->whereKey($items->pluck('product_id')->unique())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($items as $item) {
                $product = $products->get($item->product_id);

                if (! $product) {
                    throw ValidationException::withMessages([
                        'sale' => 'A product from this sale no longer exists, so stock cannot be restored safely.',
                    ]);
                }

                $product->increment('stock', $item->quantity);
            }

            $sale->delete();
        });

        return redirect()->route('sales.index')->with('success', 'Sale deleted and sold stock returned to inventory.');
    }
}
