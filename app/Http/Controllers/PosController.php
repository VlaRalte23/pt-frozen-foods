<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SalesManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PosController extends Controller
{
    public function index(Request $request): View
    {
        return view('pos.index', [
            'cart' => $this->cartData($request),
            'salesManagers' => SalesManager::query()->where('active', true)->orderBy('name')->get(),
        ]);
    }

    public function searchProducts(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:64'],
        ]);
        $query = trim($validated['q']);

        $products = Product::query()
            ->where(function ($builder) use ($query): void {
                $builder->where('name', 'like', '%'.$query.'%')
                    ->orWhere('barcode', 'like', '%'.$query.'%');
            })
            ->orderBy('name')
            ->limit(8)
            ->get(['name', 'barcode', 'price', 'stock', 'quantity_unit']);

        return response()->json([
            'products' => $products->map(fn (Product $product): array => [
                'name' => $product->name,
                'barcode' => $product->barcode,
                'price' => $product->price,
                'stock' => $product->stock,
                'quantity_unit' => $product->quantity_unit,
            ]),
        ]);
    }

    public function addItem(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'barcode' => ['required', 'string', 'max:64'],
        ]);

        $barcode = trim($validated['barcode']);
        $product = Product::query()->where('barcode', $barcode)->first();

        if (! $product) {
            return response()->json([
                'message' => 'Barcode not found. Add this product in Stock first.',
                'add_product_url' => route('products.index', ['barcode' => $barcode]),
            ], 404);
        }

        if ($product->unit_cost === null) {
            return response()->json([
                'message' => 'This product needs a purchase cost before it can be sold. Ask the owner to update it in Stock.',
            ], 422);
        }

        $cart = $request->session()->get('cart', []);
        $quantity = ($cart[$product->id] ?? 0) + 1;

        if ($quantity > $product->stock) {
            return response()->json([
                'message' => "Only {$product->stock} in stock for {$product->name}.",
            ], 422);
        }

        $cart[$product->id] = $quantity;
        $request->session()->put('cart', $cart);

        return response()->json([
            'message' => "{$product->name} added.",
            'cart' => $this->cartData($request),
        ]);
    }

    public function updateItem(Request $request, Product $product): JsonResponse
    {
        $validated = $request->validate([
            'quantity' => ['required_without:price', 'integer', 'min:1'],
            'price' => ['required_without:quantity', 'integer', 'min:1', 'max:999999999'],
        ]);

        if (isset($validated['quantity']) && $validated['quantity'] > $product->stock) {
            return response()->json([
                'message' => "Only {$product->stock} in stock for {$product->name}.",
            ], 422);
        }

        $cart = $request->session()->get('cart', []);

        if (! array_key_exists($product->id, $cart)) {
            return response()->json(['message' => 'This item is no longer in the cart.'], 404);
        }

        if (isset($validated['quantity'])) {
            $cart[$product->id] = $validated['quantity'];
            $request->session()->put('cart', $cart);
        }

        if (isset($validated['price'])) {
            $cartPrices = $request->session()->get('cart_prices', []);

            if ($validated['price'] === $product->price) {
                unset($cartPrices[$product->id]);
            } else {
                $cartPrices[$product->id] = $validated['price'];
            }

            $request->session()->put('cart_prices', $cartPrices);
        }

        return response()->json([
            'message' => 'Cart updated.',
            'cart' => $this->cartData($request),
        ]);
    }

    public function removeItem(Request $request, Product $product): JsonResponse
    {
        $cart = $request->session()->get('cart', []);
        unset($cart[$product->id]);
        $request->session()->put('cart', $cart);
        $cartPrices = $request->session()->get('cart_prices', []);
        unset($cartPrices[$product->id]);
        $request->session()->put('cart_prices', $cartPrices);

        return response()->json([
            'message' => 'Item removed.',
            'cart' => $this->cartData($request),
        ]);
    }

    public function checkout(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'payment_method' => ['sometimes', 'required', 'string', 'in:cash,gpay,bank_transfer,credit'],
            'cash_received' => [
                Rule::requiredIf(($request->input('payment_method') ?? 'cash') === 'cash'),
                'integer',
                'min:0',
                'max:999999999999',
            ],
            'sales_manager_id' => ['required', 'integer', Rule::exists('sales_managers', 'id')->where('active', true)],
            'customer_id' => [
                'nullable',
                'integer',
                Rule::requiredIf($request->input('payment_method') === 'credit'),
                'exists:customers,id',
            ],
            'customer_name' => ['nullable', 'string', 'max:120'],
            'customer_phone' => ['nullable', 'string', 'max:30'],
            'customer_address' => ['nullable', 'string', 'max:500'],
        ], [
            'cash_received.required' => 'Enter the amount received in cash.',
        ]);
        $cart = $request->session()->get('cart', []);
        $cartPrices = $request->session()->get('cart_prices', []);

        if ($cart === []) {
            return response()->json(['message' => 'Scan at least one product before completing the sale.'], 422);
        }

        $sellerId = $request->user()->id;
        $sellerName = $request->user()->name;
        [$sale, $receiptItems] = DB::transaction(function () use ($cart, $cartPrices, $validated, $sellerId, $sellerName): array {
            $salesManager = SalesManager::query()
                ->whereKey($validated['sales_manager_id'])
                ->where('active', true)
                ->lockForUpdate()
                ->first();

            if (! $salesManager) {
                throw ValidationException::withMessages([
                    'sales_manager_id' => 'Select an active sales manager.',
                ]);
            }

            $products = [];
            $total = 0;

            foreach ($cart as $productId => $quantity) {
                $product = Product::query()->lockForUpdate()->find($productId);

                if (! $product || $quantity > $product->stock) {
                    throw ValidationException::withMessages([
                        'cart' => 'Stock changed while this sale was open. Please check the cart and try again.',
                    ]);
                }

                $unitPrice = $cartPrices[$productId] ?? $product->price;
                $products[] = [$product, $quantity, $unitPrice];
                $total += $unitPrice * $quantity;
            }

            $paymentMethod = $validated['payment_method'] ?? 'cash';
            $customer = isset($validated['customer_id'])
                ? Customer::query()->lockForUpdate()->find($validated['customer_id'])
                : null;

            if (isset($validated['customer_id']) && ! $customer) {
                throw ValidationException::withMessages([
                    'customer_id' => 'Select a valid customer.',
                ]);
            }

            if ($paymentMethod === 'credit' && ! $customer) {
                throw ValidationException::withMessages([
                    'customer_id' => 'Select a customer before recording a credit sale.',
                ]);
            }

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

            $sale = Sale::query()->create([
                'user_id' => $sellerId,
                'sales_manager_id' => $salesManager->id,
                'sales_manager_name' => $salesManager->name,
                'sold_by_name' => $sellerName,
                'customer_id' => $customer?->id,
                'payment_method' => $paymentMethod,
                'customer_name' => $customer?->name ?? ($validated['customer_name'] ?? null),
                'customer_phone' => $customer?->phone ?? ($validated['customer_phone'] ?? null),
                'customer_address' => $customer?->address ?? ($validated['customer_address'] ?? null),
                'total' => $total,
                'cash_received' => $cashReceived,
                'change_due' => $paymentMethod === 'cash' ? $cashReceived - $total : 0,
            ]);

            $sale->update([
                'receipt_number' => 'PF-'.now()->format('Ymd').'-'.str_pad((string) $sale->id, 6, '0', STR_PAD_LEFT),
            ]);

            $receiptItems = [];

            foreach ($products as [$product, $quantity, $unitPrice]) {
                $lineTotal = $unitPrice * $quantity;

                $sale->items()->create([
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'barcode' => $product->barcode,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'unit_cost' => $product->unit_cost,
                    'line_total' => $lineTotal,
                ]);

                $receiptItems[] = [
                    'name' => $product->name,
                    'quantity' => $quantity,
                    'quantity_unit' => $product->quantity_unit,
                    'unit_price' => $unitPrice,
                    'line_total' => $lineTotal,
                ];

                $product->decrement('stock', $quantity);
            }

            return [$sale, $receiptItems];
        });

        $request->session()->forget('cart');
        $request->session()->forget('cart_prices');

        return response()->json([
            'message' => 'Sale complete.',
            'receipt_number' => $sale->receipt_number,
            'sales_manager_name' => $sale->sales_manager_name,
            'payment_method' => $sale->payment_method,
            'customer_name' => $sale->customer_name,
            'customer_phone' => $sale->customer_phone,
            'customer_address' => $sale->customer_address,
            'balance_due' => $sale->payment_method === 'credit' ? $sale->total : 0,
            'total' => $sale->total,
            'cash_received' => $sale->cash_received,
            'change_due' => $sale->change_due,
            'sold_at' => $sale->created_at->utc()->toIso8601String(),
            'items' => $receiptItems,
        ]);
    }

    /**
     * @return array{items: array<int, array<string, int|string>>, total: int, count: int}
     */
    private function cartData(Request $request): array
    {
        $cart = $request->session()->get('cart', []);
        $cartPrices = $request->session()->get('cart_prices', []);
        $products = Product::query()->whereKey(array_keys($cart))->get()->keyBy('id');
        $items = [];
        $total = 0;
        $count = 0;

        foreach ($cart as $productId => $quantity) {
            $product = $products->get($productId);

            if (! $product) {
                continue;
            }

            $price = $cartPrices[$productId] ?? $product->price;
            $lineTotal = $price * $quantity;
            $items[] = [
                'id' => $product->id,
                'barcode' => $product->barcode,
                'name' => $product->name,
                'price' => $price,
                'catalog_price' => $product->price,
                'quantity' => $quantity,
                'quantity_unit' => $product->quantity_unit,
                'stock' => $product->stock,
                'line_total' => $lineTotal,
            ];
            $total += $lineTotal;
            $count += $quantity;
        }

        return [
            'items' => $items,
            'total' => $total,
            'count' => $count,
        ];
    }
}
