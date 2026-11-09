@extends('layouts.app')

@section('title', 'Stock')

@section('content')
    <section class="page-heading">
        <div>
            <p class="eyebrow">PRODUCTS & STOCK</p>
            <h1>Stock room</h1>
            <p class="subheading">Scan a barcode to add a product or update its details.</p>
        </div>
        <div class="stock-stats">
            <div class="stat-chip"><strong>{{ $productCount }}</strong><span>products</span></div>
            <div class="stat-chip warning"><strong>{{ $lowStockCount }}</strong><span>low stock</span></div>
            <div class="stat-chip danger"><strong>{{ $outOfStockCount }}</strong><span>out of stock</span></div>
        </div>
    </section>

    @if (session('success'))
        <div class="notice notice-success" role="status">{{ session('success') }}</div>
    @endif

    <section class="low-stock-alert {{ $lowStockCount + $outOfStockCount === 0 ? 'all-stocked' : '' }}"
        role="{{ $lowStockCount + $outOfStockCount > 0 ? 'alert' : 'status' }}">
        <div class="alert-icon" aria-hidden="true">{{ $lowStockCount + $outOfStockCount > 0 ? '!' : '✓' }}</div>
        <div class="alert-copy">
            <strong>
                @if ($lowStockCount + $outOfStockCount > 0)
                    {{ $lowStockCount + $outOfStockCount }}
                    {{ \Illuminate\Support\Str::plural('product', $lowStockCount + $outOfStockCount) }} need attention
                @else
                    Stock levels look good
                @endif
            </strong>
            <span>
                @if ($lowStockCount + $outOfStockCount > 0)
                    {{ $outOfStockCount }} out of stock · {{ $lowStockCount }} running low (at or below each product's threshold)
                @else
                    No products are out of stock or below their low-stock threshold.
                @endif
            </span>
        </div>
        @if ($lowStockCount + $outOfStockCount > 0)
            <a href="{{ route('products.index', ['stock' => 'low']) }}">Review stock <span aria-hidden="true">→</span></a>
        @endif
    </section>

    @if ($lowStockCount + $outOfStockCount > 0)
        <section class="panel low-stock-panel">
            <div class="inventory-heading">
                <div>
                    <h2>Stock needing attention</h2>
                    <p>Out of stock first, then products at or below their alert threshold.</p>
                </div>
                <a class="small-link" href="{{ route('products.index', ['stock' => 'low']) }}">View all needing
                    attention</a>
            </div>
            <div class="table-wrap">
                <table class="inventory-table">
                    <thead>
                        <tr>
                            <th>PRODUCT</th>
                            <th>BARCODE</th>
                            <th>STATUS</th>
                            <th>LEFT</th>
                            <th>ALERT AT</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($attentionProducts as $alertProduct)
                            <tr>
                                <td><strong>{{ $alertProduct->name }}</strong></td>
                                <td class="barcode-cell">{{ $alertProduct->barcode }}</td>
                                <td><span
                                        class="stock-status {{ $alertProduct->stock === 0 ? 'status-out' : 'status-low' }}">{{ $alertProduct->stock === 0 ? 'Out of stock' : 'Low stock' }}</span>
                                </td>
                                <td><span
                                        class="stock-badge {{ $alertProduct->stock <= $alertProduct->low_stock_threshold ? 'stock-low' : '' }}">{{ $alertProduct->stock }} {{ $alertProduct->quantity_unit }}</span>
                                </td>
                                <td>{{ $alertProduct->low_stock_threshold }} {{ $alertProduct->quantity_unit }}</td>
                                <td><a class="restock-link"
                                        href="{{ route('products.index', ['barcode' => $alertProduct->barcode]) }}">Update
                                        stock</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    <div class="stock-layout">
        <section class="panel product-form-panel">
            <div class="panel-heading">
                <div class="step-number">1</div>
                <div>
                    <h2>Product details</h2>
                    <p>Scan first, then fill in the details</p>
                </div>
            </div>
            <form id="product-form" action="{{ route('products.store') }}" method="POST" autocomplete="off">
                @csrf
                <div class="form-field">
                    <label for="product-barcode">BARCODE</label>
                    <div class="inline-input">
                        <input id="product-barcode" name="barcode" type="text"
                            value="{{ request('barcode', old('barcode')) }}" placeholder="Scan or type barcode" required>
                        <span class="enter-hint">ENTER</span>
                    </div>
                    @error('barcode')
                        <span class="field-error">{{ $message }}</span>
                    @enderror
                </div>
                <div id="product-mode" class="mode-note">Scan a barcode to start a new product.</div>
                <div class="form-field">
                    <label for="product-name">PRODUCT NAME</label>
                    <input id="product-name" name="name" type="text" value="{{ old('name') }}"
                        placeholder="Example: Frozen Chicken" maxlength="120" required>
                    @error('name')
                        <span class="field-error">{{ $message }}</span>
                    @enderror
                </div>
                <div class="form-field">
                    <label for="product-brand">BRAND / COMPANY</label>
                    <input id="product-brand" name="brand" type="text" value="{{ old('brand') }}"
                        list="product-brands" placeholder="Select or enter a brand" maxlength="120" autocomplete="off">
                    <datalist id="product-brands">
                        @foreach ($brands as $brand)
                            <option value="{{ $brand }}"></option>
                        @endforeach
                    </datalist>
                    @error('brand')
                        <span class="field-error">{{ $message }}</span>
                    @enderror
                </div>
                <div class="form-row">
                    <div class="form-field">
                        <label for="product-mrp">MRP</label>
                        <input id="product-mrp" name="mrp" type="number" min="1" step="1"
                            value="{{ old('mrp') }}" placeholder="25000" required>
                        @error('mrp')
                            <span class="field-error">{{ $message }}</span>
                        @enderror
                    </div>
                    <div class="form-field">
                        <label for="product-price">SELLING PRICE</label>
                        <input id="product-price" name="price" type="number" min="1" step="1"
                            value="{{ old('price') }}" placeholder="25000" required>
                        @error('price')
                            <span class="field-error">{{ $message }}</span>
                        @enderror
                    </div>
                    @if ($isOwner)
                        <div class="form-field">
                            <label for="product-unit-cost">PRODUCT COST</label>
                            <input id="product-unit-cost" name="unit_cost" type="number" min="0" step="1"
                                value="{{ old('unit_cost') }}" placeholder="Required for profit reports">
                            @error('unit_cost')
                                <span class="field-error">{{ $message }}</span>
                            @enderror
                        </div>
                    @endif
                    <div class="form-field stock-quantity-field">
                        <label for="product-stock">STOCK</label>
                        <input id="product-stock" name="stock" type="number" min="0" step="1"
                            value="{{ old('stock') }}" placeholder="12" required>
                        @error('stock')
                            <span class="field-error">{{ $message }}</span>
                        @enderror
                    </div>
                    <div class="form-field">
                        <label for="product-quantity-unit">UNIT</label>
                        <select id="product-quantity-unit" name="quantity_unit" required>
                            <option value="bottle" @selected(old('quantity_unit', 'piece') === 'bottle')>Bottle</option>
                            <option value="gram" @selected(old('quantity_unit', 'piece') === 'gram')>Gram</option>
                            <option value="kg" @selected(old('quantity_unit', 'piece') === 'kg')>Kilogram (kg)</option>
                            <option value="box" @selected(old('quantity_unit', 'piece') === 'box')>Box</option>
                            <option value="piece" @selected(old('quantity_unit', 'piece') === 'piece')>Piece</option>
                        </select>
                        @error('quantity_unit')
                            <span class="field-error">{{ $message }}</span>
                        @enderror
                    </div>
                    <div class="form-field">
                        <label for="product-low-stock-threshold">LOW-STOCK ALERT AT</label>
                        <input id="product-low-stock-threshold" name="low_stock_threshold" type="number" min="0"
                            max="999999" step="1" value="{{ old('low_stock_threshold', 5) }}" placeholder="5" required>
                        @error('low_stock_threshold')
                            <span class="field-error">{{ $message }}</span>
                        @enderror
                    </div>
                </div>
                @error('product')
                    <span class="field-error">{{ $message }}</span>
                @enderror
                <button type="submit" class="button button-primary save-product-button">Save product</button>
                <p class="form-tip">Already in the list? Scan its barcode to update the price or stock.</p>
                @unless ($isOwner)
                    <p class="form-tip">A product needs an owner-entered purchase cost before it can be sold.</p>
                @endunless
            </form>
        </section>

        <section class="panel inventory-panel">
            <div class="inventory-heading">
                <div>
                    <h2>Stock list</h2>
                    <p>{{ $products->total() }}
                        {{ \Illuminate\Support\Str::plural('product', $products->total()) }}{{ $stockFilter === 'low' ? ' · Low stock' : ($stockFilter === 'out' ? ' · Out of stock' : '') }}
                    </p>
                </div>
                <span class="inventory-count">{{ $productCount }} total</span>
            </div>
            <form class="stock-filters" action="{{ route('products.index') }}" method="GET">
                <label class="sr-only" for="stock-search">Search products by name or barcode</label>
                <input id="stock-search" name="search" type="search" value="{{ $search }}"
                    placeholder="Search product, brand, or barcode">
                <label class="sr-only" for="stock-filter">Filter stock list</label>
                <select id="stock-filter" name="stock">
                    <option value="all" @selected($stockFilter === 'all')>All stock</option>
                    <option value="low" @selected($stockFilter === 'low')>Low stock</option>
                    <option value="out" @selected($stockFilter === 'out')>Out of stock</option>
                </select>
                <label class="sr-only" for="brand-filter">Filter by brand or company</label>
                <select id="brand-filter" name="brand">
                    <option value="">All brands / companies</option>
                    @foreach ($brands as $brand)
                        <option value="{{ $brand }}" @selected($brandFilter === $brand)>{{ $brand }}</option>
                    @endforeach
                </select>
                <button type="submit" class="button button-secondary">Apply</button>
                @if ($search !== '' || $stockFilter !== 'all' || $brandFilter !== '')
                    <a class="clear-filters" href="{{ route('products.index') }}">Clear</a>
                @endif
            </form>
            @if ($products->isEmpty())
                <div class="empty-inventory">
                    <strong>{{ $productCount === 0 ? 'No products yet' : 'No products match this filter' }}</strong>
                    <span>{{ $productCount === 0 ? 'Scan your first product on the left.' : 'Try a different search or stock filter.' }}</span>
                </div>
            @else
                <div class="table-wrap">
                    <table class="inventory-table">
                        <thead>
                            <tr>
                                <th>PRODUCT</th>
                                <th>BRAND / COMPANY</th>
                                <th>BARCODE</th>
                                <th>MRP</th>
                                <th>SELLING PRICE</th>
                                @if ($isOwner)
                                    <th>PRODUCT COST</th>
                                @endif
                                <th>STOCK</th>
                                <th>ALERT AT</th>
                                <th>STATUS</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($products as $product)
                                <tr>
                                    <td><strong>{{ $product->name }}</strong></td>
                                    <td>{{ $product->brand ?: '—' }}</td>
                                    <td class="barcode-cell">{{ $product->barcode }}</td>
                                    <td>₹{{ number_format($product->mrp, 0, '.', ',') }}</td>
                                    <td>₹{{ number_format($product->price, 0, '.', ',') }}</td>
                                    @if ($isOwner)
                                        <td>{{ $product->unit_cost === null ? 'Needs cost' : '₹' . number_format($product->unit_cost, 0, '.', ',') }}
                                        </td>
                                    @endif
                                    <td><span
                                            class="stock-badge {{ $product->stock <= $product->low_stock_threshold ? 'stock-low' : '' }}">{{ $product->stock }} {{ $product->quantity_unit }}</span>
                                    </td>
                                    <td>{{ $product->low_stock_threshold }} {{ $product->quantity_unit }}</td>
                                    <td>
                                        @if ($product->stock === 0)
                                            <span class="stock-status status-out">Out of stock</span>
                                        @elseif ($product->stock <= $product->low_stock_threshold)
                                            <span class="stock-status status-low">Low stock</span>
                                        @else
                                            <span class="stock-status status-good">In stock</span>
                                        @endif
                                    </td>
                                    <td><a class="restock-link"
                                            href="{{ route('products.index', ['barcode' => $product->barcode]) }}">Update</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="pagination-wrap">{{ $products->links() }}</div>
            @endif
        </section>
    </div>
@endsection
