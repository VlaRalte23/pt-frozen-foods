@extends('layouts.app')

@section('title', $customer->name)

@section('content')
<section class="page-heading">
    <div>
        <p class="eyebrow">CUSTOMER ACCOUNT</p>
        <h1>{{ $customer->name }}</h1>
        <p class="subheading">{{ $customer->phone ?? 'No phone number' }}@if ($customer->address) · {{ $customer->address }}@endif</p>
    </div>
    <div class="customer-page-actions">
        @if ($isOwner)
            <a class="button button-secondary" href="{{ route('customers.edit', $customer) }}">Edit customer</a>
        @endif
        <a class="button button-secondary" href="{{ route('customers.index') }}">All customers</a>
    </div>
</section>

@if (session('success'))
    <div class="notice notice-success" role="status">{{ session('success') }}</div>
@endif
@if ($errors->any())
    <div class="notice" role="alert">{{ $errors->first() }}</div>
@endif

<section class="customer-balance-summary" aria-label="Customer balance">
    <article class="panel customer-balance-card">
        <span>TOTAL CREDIT SALES</span>
        <strong>₹{{ number_format($creditTotal, 0, '.', ',') }}</strong>
    </article>
    <article class="panel customer-balance-card">
        <span>PAYMENTS RECEIVED</span>
        <strong>₹{{ number_format($paidTotal, 0, '.', ',') }}</strong>
    </article>
    <article class="panel customer-balance-card customer-balance-highlight">
        <span>BALANCE DUE</span>
        <strong>₹{{ number_format(max(0, $creditTotal - $paidTotal), 0, '.', ',') }}</strong>
    </article>
</section>

<section class="panel customer-history-panel">
    <div class="inventory-heading">
        <div>
            <h2>Sales history</h2>
            <p>Every sale linked to this customer</p>
        </div>
    </div>
    @if ($sales->isEmpty())
        <div class="empty-inventory"><strong>No sales recorded</strong></div>
    @else
        <div class="table-wrap">
            <table class="inventory-table customer-history-table">
                <thead>
                    <tr><th>DATE / RECEIPT</th><th>PRODUCTS</th><th>SALES MANAGER</th><th>PAYMENT</th><th>TOTAL</th><th>BALANCE</th></tr>
                </thead>
                <tbody>
                    @foreach ($sales as $sale)
                        @php
                            $paidForSale = (int) $sale->payments_total;
                            $balanceForSale = $sale->payment_method === 'credit' ? max(0, $sale->total - $paidForSale) : 0;
                        @endphp
                        <tr>
                            <td>
                                <strong>{{ $sale->created_at->format('d M Y, h:i A') }}</strong>
                                <span class="sales-secondary">{{ $sale->receipt_number ?? 'No receipt number' }}</span>
                            </td>
                            <td class="sales-products-cell">
                                @foreach ($sale->items as $item)
                                    <span>{{ $item->product_name }} × {{ $item->quantity }}</span>
                                @endforeach
                            </td>
                            <td>{{ $sale->sales_manager_name ?? 'Not recorded' }}</td>
                            <td>{{ $sale->payment_method === 'credit' ? ($balanceForSale > 0 ? 'Credit — owing' : 'Credit — paid') : ucfirst(str_replace('_', ' ', $sale->payment_method)) }}</td>
                            <td>₹{{ number_format($sale->total, 0, '.', ',') }}</td>
                            <td>
                                @if ($sale->payment_method === 'credit')
                                    <strong class="{{ $balanceForSale > 0 ? 'customer-balance-due' : '' }}">₹{{ number_format($balanceForSale, 0, '.', ',') }}</strong>
                                    @if ($balanceForSale > 0)
                                        <form class="debt-payment-form" action="{{ route('customers.payments.store', $customer) }}" method="POST">
                                            @csrf
                                            <input type="hidden" name="sale_id" value="{{ $sale->id }}">
                                            <label class="sr-only" for="payment-amount-{{ $sale->id }}">Amount to pay</label>
                                            <input id="payment-amount-{{ $sale->id }}" name="amount" type="number" min="1" max="{{ $balanceForSale }}" value="{{ $balanceForSale }}" required>
                                            <select name="payment_method" aria-label="Payment method" required>
                                                <option value="cash">Cash</option>
                                                <option value="gpay">GPay</option>
                                                <option value="bank_transfer">Bank transfer</option>
                                            </select>
                                            <button class="button button-primary" type="submit">Record payment</button>
                                        </form>
                                    @endif
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="pagination-wrap">{{ $sales->links() }}</div>
    @endif
</section>

<section class="panel customer-history-panel">
    <div class="inventory-heading">
        <div>
            <h2>Payment history</h2>
            <p>Recorded debt repayments</p>
        </div>
    </div>
    @if ($payments->isEmpty())
        <div class="empty-inventory"><strong>No debt payments recorded</strong></div>
    @else
        <div class="table-wrap">
            <table class="inventory-table">
                <thead>
                    <tr>
                        <th>DATE</th>
                        <th>RECEIPT</th>
                        <th>RECORDED BY</th>
                        <th>METHOD</th>
                        <th>AMOUNT</th>
                        @if ($isOwner)
                            <th>ACTIONS</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @foreach ($payments as $payment)
                        <tr>
                            <td>{{ $payment->created_at->format('d M Y, h:i A') }}</td>
                            <td>{{ $payment->sale?->receipt_number ?? 'Sale #'.$payment->sale_id }}</td>
                            <td>{{ $payment->recorder?->name ?? 'Former user' }}</td>
                            <td>{{ ucfirst(str_replace('_', ' ', $payment->payment_method)) }}</td>
                            <td><strong>₹{{ number_format($payment->amount, 0, '.', ',') }}</strong></td>
                            @if ($isOwner)
                                <td>
                                    <details class="debt-payment-edit">
                                        <summary class="button button-secondary">Edit</summary>
                                        <form class="debt-payment-edit-form" action="{{ route('customers.payments.update', [$customer, $payment]) }}" method="POST">
                                            @csrf
                                            @method('PUT')
                                            <label class="sr-only" for="edit-payment-amount-{{ $payment->id }}">Repayment amount</label>
                                            <input id="edit-payment-amount-{{ $payment->id }}" name="amount" type="number" min="1"
                                                max="{{ $payment->sale?->total ?? $payment->amount }}" value="{{ $payment->amount }}" required>
                                            <label class="sr-only" for="edit-payment-method-{{ $payment->id }}">Repayment method</label>
                                            <select id="edit-payment-method-{{ $payment->id }}" name="payment_method" required>
                                                <option value="cash" @selected($payment->payment_method === 'cash')>Cash</option>
                                                <option value="gpay" @selected($payment->payment_method === 'gpay')>GPay</option>
                                                <option value="bank_transfer" @selected($payment->payment_method === 'bank_transfer')>Bank transfer</option>
                                            </select>
                                            <button class="button button-primary" type="submit">Save payment</button>
                                        </form>
                                    </details>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="pagination-wrap">{{ $payments->links() }}</div>
    @endif
</section>
@endsection
