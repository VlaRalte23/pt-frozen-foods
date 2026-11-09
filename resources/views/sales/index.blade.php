@extends('layouts.app')

@section('title', 'Sales List')

@section('content')
    <section class="page-heading">
        <div>
            <p class="eyebrow">TRANSACTION HISTORY</p>
            <h1>Sales List</h1>
            <p class="subheading">Review customer sales, outstanding balances, and the sales manager selected for each sale.</p>
        </div>
        <a class="button button-primary sales-new-button" href="{{ route('pos.index') }}">New sale</a>
    </section>

    <section class="panel sales-list-panel">
        <div class="inventory-heading">
            <div>
                <h2>Completed sales</h2>
                <p>{{ $sales->total() }} {{ \Illuminate\Support\Str::plural('sale', $sales->total()) }}</p>
            </div>
        </div>

        @if ($sales->isEmpty())
            <div class="empty-inventory">
                <strong>No sales yet</strong>
                <span>Completed sales will appear here.</span>
            </div>
        @else
            <div class="table-wrap">
                <table class="inventory-table sales-table">
                    <thead>
                        <tr>
                            <th>DATE / RECEIPT</th>
                            <th>CUSTOMER / DEBT</th>
                            <th>PRODUCTS SOLD</th>
                            <th>SALES MANAGER</th>
                            <th>PAYMENT</th>
                            <th>TOTAL</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($sales as $sale)
                            <tr>
                                <td>
                                    <strong>{{ $sale->created_at->format('d M Y, h:i A') }}</strong>
                                    <span class="sales-secondary">{{ $sale->receipt_number ?? 'No receipt number' }}</span>
                                </td>
                                <td>
                                    @if ($sale->customer)
                                        <a class="customer-history-link" href="{{ route('customers.show', $sale->customer) }}">{{ $sale->customer_name }}</a>
                                    @else
                                        <strong>{{ $sale->customer_name ?? 'Walk-in' }}</strong>
                                    @endif
                                    @if ($sale->customer_phone)
                                        <span class="sales-secondary">{{ $sale->customer_phone }}</span>
                                    @endif
                                </td>
                                <td class="sales-products-cell">
                                    @foreach ($sale->items as $item)
                                        <span><strong>{{ $item->product_name }}</strong> × {{ $item->quantity }}</span>
                                    @endforeach
                                </td>
                                <td>
                                    <strong>{{ $sale->sales_manager_name ?? $sale->sold_by_name ?? 'Not recorded' }}</strong>
                                    <span class="sales-secondary">Recorded by {{ $sale->sold_by_name ?? $sale->seller?->name ?? 'Not recorded' }}</span>
                                </td>
                                <td>
                                    {{ $sale->payment_method === 'credit' ? 'Credit' : ucfirst(str_replace('_', ' ', $sale->payment_method)) }}
                                    @if ($sale->payment_method === 'credit')
                                        <span class="sales-secondary">Due ₹{{ number_format(max(0, $sale->total - (int) $sale->payments_total), 0, '.', ',') }}</span>
                                    @endif
                                </td>
                                <td><strong>₹{{ number_format($sale->total, 0, '.', ',') }}</strong></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="pagination-wrap">{{ $sales->links() }}</div>
        @endif
    </section>
@endsection
