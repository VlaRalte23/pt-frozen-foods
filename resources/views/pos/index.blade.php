@extends('layouts.app')

@section('title', 'Sell')

@section('content')
    <section class="page-heading">
        <div>
            <h1>Ready to sell</h1>
            <p class="subheading">Scan each product barcode to add it to the bill.</p>
        </div>
        <div class="today-chip">
            <span class="today-dot"></span>
            Today <strong>{{ now()->format('D, d M') }}</strong>
            <time id="sell-page-time" class="today-time" datetime="{{ now()->toIso8601String() }}"
                data-timezone="{{ config('app.timezone') }}" aria-label="Current time in New Delhi">{{ now()->format('H:i:s') }}</time>
            <span class="today-timezone">IST</span>
        </div>
    </section>

    <div class="pos-layout" id="pos-app">
        <section class="panel scan-panel">
            <div class="panel-heading">
                <div class="step-number">1</div>
                <div>
                    <h2>Add products</h2>
                    <p>Scan a barcode or search by product name</p>
                </div>
            </div>
            <form id="scan-form" class="scan-form" autocomplete="off">
                <label for="scan-barcode">BARCODE OR PRODUCT NAME</label>
                <div class="scan-input-wrap">
                    <span class="scan-icon" aria-hidden="true">▥</span>
                    <input id="scan-barcode" name="barcode" type="search" placeholder="Scan barcode or type product name"
                        autofocus required autocomplete="off" aria-controls="product-search-results" aria-expanded="false">
                    <span class="enter-hint">ENTER</span>
                </div>
                <div id="product-search-results" class="product-search-results" role="listbox" hidden></div>
                <p class="help-text">Scan a barcode to add it, or type a name and select a matching product.</p>
            </form>

            <div class="cart-title-row">
                <div class="step-number">2</div>
                <div>
                    <h2>Current bill <span class="count-pill" id="item-count">{{ $cart['count'] }}</span></h2>
                    <p>Check items before taking payment</p>
                </div>
                <a href="{{ route('products.index') }}" class="small-link">Add a product</a>
            </div>

            <div id="cart-items" class="cart-items" aria-live="polite"></div>
            <div id="empty-cart" class="empty-state" @if ($cart['items']) hidden @endif>
                <div class="empty-graphic" aria-hidden="true">▤</div>
                <strong>Nothing scanned yet</strong>
                <span>Scan the first product to start a bill.</span>
            </div>
        </section>

        <aside class="panel payment-panel">
            <div class="panel-heading">
                <div class="step-number">3</div>
                <div>
                    <h2>Payment</h2>
                    <p>Choose how the customer pays</p>
                </div>
            </div>
            <div class="total-box">
                <span>TOTAL TO PAY</span>
                <strong id="grand-total">₹ 0</strong>
            </div>
            <label class="field-label" for="payment-method">PAYMENT METHOD</label>
            <select id="payment-method" class="payment-method-select">
                <option value="cash">Cash</option>
                <option value="gpay">GPay</option>
                <option value="bank_transfer">Bank transfer</option>
                <option value="credit">Credit / pay later</option>
            </select>
            <label class="field-label" for="sales-manager">SALES MANAGER</label>
            <select id="sales-manager" name="sales_manager_id" class="payment-method-select" required>
                <option value="">Choose a sales manager</option>
                @foreach ($salesManagers as $salesManager)
                    <option value="{{ $salesManager->id }}">{{ $salesManager->name }}</option>
                @endforeach
            </select>
            @if ($salesManagers->isEmpty())
                <p class="help-text">Ask the owner to add a sales manager before completing a sale.</p>
            @endif
            <section class="customer-details" aria-labelledby="customer-details-title">
                <h3 id="customer-details-title">Customer <span>optional</span></h3>
                <div class="customer-picker" id="customer-picker">
                    <label class="field-label" for="customer-search">REGULAR CUSTOMER</label>
                    <div class="customer-search-wrap">
                        <input id="customer-search" type="search" maxlength="64"
                            placeholder="Search name or phone" autocomplete="off" aria-controls="customer-search-results" aria-expanded="false">
                        <div id="customer-search-results" class="product-search-results customer-search-results" role="listbox" hidden></div>
                    </div>
                    <div id="selected-customer" class="selected-customer" hidden>
                        <span><strong id="selected-customer-name"></strong><small id="selected-customer-details"></small></span>
                        <button id="clear-customer" class="clear-customer-button" type="button">Clear</button>
                    </div>
                    <button id="show-new-customer" class="button button-secondary add-customer-button" type="button">Add regular customer</button>
                    <form id="new-customer-form" class="new-customer-form" hidden>
                        <label class="field-label" for="new-customer-name">NAME / STORE NAME</label>
                        <input id="new-customer-name" name="name" type="text" maxlength="120" placeholder="Customer or store name" required>
                        <label class="field-label" for="new-customer-phone">PHONE NUMBER <span>optional</span></label>
                        <input id="new-customer-phone" name="phone" type="tel" maxlength="30" placeholder="Phone number">
                        <label class="field-label" for="new-customer-address">ADDRESS <span>optional</span></label>
                        <textarea id="new-customer-address" name="address" maxlength="500" placeholder="Customer or store address" rows="2"></textarea>
                        <div class="new-customer-actions">
                            <button class="button button-primary" type="submit">Save customer</button>
                            <button id="cancel-new-customer" class="clear-customer-button" type="button">Cancel</button>
                        </div>
                        <div id="customer-message" class="field-error" role="status" hidden></div>
                    </form>
                </div>
                <p id="credit-hint" class="help-text" hidden>Credit sales must be assigned to a regular customer. The full balance will appear in Customer Debts.</p>
            </section>
            <div id="cash-payment-fields">
                <label class="field-label" for="cash-received">CASH RECEIVED <span id="cash-received-requirement">required</span></label>
                <div class="money-input">
                    <span>₹</span>
                    <input id="cash-received" type="number" min="0" step="1" required
                        placeholder="Enter amount received">
                </div>
                <div class="change-row">
                    <span>Change</span>
                    <strong id="change-due">₹0</strong>
                </div>
            </div>
            <div id="pos-message" class="notice" role="status" hidden></div>
            <button id="print-receipt-button" class="button button-secondary print-receipt-button" type="button" hidden>
                Print receipt
            </button>
            <button id="checkout-button" class="button button-primary checkout-button" type="button" disabled>
                <span>Complete sale</span><span aria-hidden="true">→</span>
            </button>
            <p class="payment-note">Stock updates automatically when the sale is complete.</p>
        </aside>
    </div>
    <section id="print-receipt-content" class="print-receipt" aria-label="Sale receipt">
        <header class="receipt-heading">
            <h2>PT FROZEN FOODS</h2>
            <p>Sales receipt</p>
        </header>
        <div class="receipt-meta">
            <div><span>Receipt</span><strong id="receipt-number"></strong></div>
            <div><span>Date</span><strong id="receipt-date"></strong></div>
            <div><span>Sales manager</span><strong id="receipt-sales-manager"></strong></div>
            <div><span>Payment</span><strong id="receipt-payment-method"></strong></div>
        </div>
        <div id="receipt-customer-details" class="receipt-customer-details" hidden>
            <strong>Customer</strong>
            <div id="receipt-customer-name"></div>
            <div id="receipt-customer-phone"></div>
            <div id="receipt-customer-address"></div>
        </div>
        <table class="receipt-items">
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Qty</th>
                    <th>Price / unit</th>
                    <th>Total</th>
                </tr>
            </thead>
            <tbody id="receipt-items"></tbody>
        </table>
        <div class="receipt-total">
            <span id="receipt-total-label">Total</span>
            <strong id="receipt-total"></strong>
        </div>
        <div id="receipt-credit-details" class="receipt-cash-details" hidden>
            <div><span>Balance due</span><strong id="receipt-balance-due"></strong></div>
        </div>
        <div id="receipt-cash-details" class="receipt-cash-details">
            <div><span>Cash received</span><strong id="receipt-cash-received"></strong></div>
            <div><span>Change</span><strong id="receipt-change-due"></strong></div>
        </div>
        <footer class="receipt-footer">KAN LAWM LUTUK E!</footer>
    </section>
    <script>
        window.initialPosCart = @json($cart);
    </script>
@endsection
