@extends('layouts.app')

@section('title', 'Customers & Debts')

@section('content')
<section class="page-heading">
    <div>
        <p class="eyebrow">CUSTOMER ACCOUNTS</p>
        <h1>Customers &amp; Debts</h1>
        <p class="subheading">Review regular customer sales and outstanding credit balances.</p>
    </div>
    <a class="button button-primary sales-new-button" href="{{ route('pos.index') }}">New sale</a>
</section>

<section class="panel customers-list-panel">
    <div class="inventory-heading">
        <div>
            <h2>Regular customers</h2>
            <p>{{ $customers->total() }} {{ \Illuminate\Support\Str::plural('customer', $customers->total()) }}</p>
        </div>
    </div>
    @if ($customers->isEmpty())
        <div class="empty-inventory">
            <strong>No regular customers yet</strong>
            <span>Add a customer during checkout to keep sales history and manage debts.</span>
        </div>
    @else
        <div class="table-wrap">
            <table class="inventory-table customers-table">
                <thead>
                    <tr><th>CUSTOMER</th><th>PHONE</th><th>SALES</th><th>RECENT PURCHASES</th><th>TOTAL CREDIT</th><th>PAID</th><th>BALANCE DUE</th></tr>
                </thead>
                <tbody>
                    @foreach ($customers as $customer)
                        @php
                            $creditTotal = (int) $customer->credit_sales_total;
                            $paidTotal = (int) $customer->payments_total;
                        @endphp
                        <tr>
                            <td>
                                <a class="customer-history-link" href="{{ route('customers.show', $customer) }}">{{ $customer->name }}</a>
                                @if ($customer->address)
                                    <span class="sales-secondary">{{ $customer->address }}</span>
                                @endif
                            </td>
                            <td>{{ $customer->phone ?? '—' }}</td>
                            <td>{{ $customer->sales_count }}</td>
                            <td class="sales-products-cell">
                                @forelse ($customer->sales as $sale)
                                    <span class="sales-secondary">
                                        {{ $sale->created_at->timezone(config('app.timezone'))->format('d M Y, h:i a') }}
                                    </span>
                                    @foreach ($sale->items as $item)
                                        <span>{{ $item->product_name }} × {{ $item->quantity }}</span>
                                    @endforeach
                                @empty
                                    —
                                @endforelse
                            </td>
                            <td>₹{{ number_format($creditTotal, 0, '.', ',') }}</td>
                            <td>₹{{ number_format($paidTotal, 0, '.', ',') }}</td>
                            <td><strong class="{{ $creditTotal > $paidTotal ? 'customer-balance-due' : '' }}">₹{{ number_format(max(0, $creditTotal - $paidTotal), 0, '.', ',') }}</strong></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="pagination-wrap">{{ $customers->links() }}</div>
    @endif
</section>
@endsection
