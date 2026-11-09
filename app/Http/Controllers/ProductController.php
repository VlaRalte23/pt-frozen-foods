<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function index(Request $request): View
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'stock' => ['nullable', Rule::in(['all', 'low', 'out'])],
            'brand' => ['nullable', 'string', 'max:120'],
        ]);
        $search = trim($validated['search'] ?? '');
        $stockFilter = $validated['stock'] ?? 'all';
        $brandFilter = $validated['brand'] ?? '';
        $matchingProducts = Product::query()
            ->when($brandFilter !== '', fn ($query) => $query->where('brand', $brandFilter))
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('brand', 'like', "%{$search}%")
                        ->orWhere('barcode', 'like', "%{$search}%");
                });
            });

        $productsQuery = (clone $matchingProducts);

        if ($stockFilter === 'low') {
            $productsQuery->whereColumn('stock', '<=', 'low_stock_threshold');
        } elseif ($stockFilter === 'out') {
            $productsQuery->where('stock', 0);
        }

        return view('products.index', [
            'products' => $productsQuery->paginate(20)->withQueryString(),
            'productCount' => Product::query()->count(),
            'lowStockCount' => Product::query()
                ->where('stock', '>', 0)
                ->whereColumn('stock', '<=', 'low_stock_threshold')
                ->count(),
            'outOfStockCount' => Product::query()->where('stock', 0)->count(),
            'attentionProducts' => Product::query()
                ->whereColumn('stock', '<=', 'low_stock_threshold')
                ->when($brandFilter !== '', fn ($query) => $query->where('brand', $brandFilter))
                ->when($search !== '', function ($query) use ($search): void {
                    $query->where(function ($query) use ($search): void {
                        $query->where('name', 'like', "%{$search}%")
                            ->orWhere('brand', 'like', "%{$search}%")
                            ->orWhere('barcode', 'like', "%{$search}%");
                    });
                })
                ->orderBy('stock')
                ->orderBy('name')
                ->limit(5)
                ->get(),
            'brands' => Product::query()
                ->whereNotNull('brand')
                ->where('brand', '!=', '')
                ->distinct()
                ->orderBy('brand')
                ->pluck('brand'),
            'search' => $search,
            'stockFilter' => $stockFilter,
            'brandFilter' => $brandFilter,
            'isOwner' => request()->user()->isOwner(),
        ]);
    }

    public function lookup(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'barcode' => ['required', 'string', 'max:64'],
        ]);

        $product = Product::query()
            ->where('barcode', trim($validated['barcode']))
            ->first();

        if (! $product) {
            return response()->json(['message' => 'New barcode. Enter the product details below.'], 404);
        }

        $details = [
            'id' => $product->id,
            'barcode' => $product->barcode,
            'name' => $product->name,
            'brand' => $product->brand,
            'mrp' => $product->mrp,
            'price' => $product->price,
            'stock' => $product->stock,
            'quantity_unit' => $product->quantity_unit,
            'low_stock_threshold' => $product->low_stock_threshold,
        ];

        if ($request->user()->isOwner()) {
            $details['unit_cost'] = $product->unit_cost;
        }

        return response()->json(['product' => $details]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'barcode' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:120'],
            'brand' => ['nullable', 'string', 'max:120'],
            'mrp' => ['required', 'integer', 'min:1', 'max:999999999'],
            'price' => ['required', 'integer', 'min:1', 'max:999999999'],
            'stock' => ['required', 'integer', 'min:0', 'max:999999'],
            'quantity_unit' => ['required', Rule::in(['bottle', 'gram', 'kg', 'box', 'piece'])],
            'low_stock_threshold' => ['required', 'integer', 'min:0', 'max:999999'],
        ]);

        if ($request->user()->isOwner()) {
            $validated += $request->validate([
                'unit_cost' => ['required', 'integer', 'min:0', 'max:999999999'],
            ]);
        }

        $validated['barcode'] = trim($validated['barcode']);
        $product = Product::query()->firstOrNew(['barcode' => $validated['barcode']]);
        $wasExisting = $product->exists;
        $product->fill([
            'name' => $validated['name'],
            'brand' => trim($validated['brand'] ?? '') ?: null,
            'mrp' => $validated['mrp'],
            'price' => $validated['price'],
            'stock' => $validated['stock'],
            'quantity_unit' => $validated['quantity_unit'],
            'low_stock_threshold' => $validated['low_stock_threshold'],
        ]);

        if ($request->user()->isOwner()) {
            $product->unit_cost = $validated['unit_cost'];
        }

        $product->save();

        return redirect()
            ->route('products.index')
            ->with('success', $wasExisting ? 'Product updated.' : 'Product added.');
    }
}
