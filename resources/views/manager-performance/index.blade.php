@extends('layouts.app')

@section('title', 'Manager Performance')

@section('content')
<section class="page-heading">
    <div>
        <p class="eyebrow">OWNER REPORT</p>
        <h1>Manager Performance</h1>
        <p class="subheading">Sales activity by manager, ranked by revenue.</p>
    </div>
    <form class="chart-range-form" action="{{ route('manager-performance.index') }}" method="GET">
        <label for="performance-range">PERIOD</label>
        <select id="performance-range" name="range">
            <option value="7" @selected($range === 7)>Last 7 days</option>
            <option value="30" @selected($range === 30)>Last 30 days</option>
            <option value="90" @selected($range === 90)>Last 90 days</option>
        </select>
    </form>
</section>

<section class="manager-performance-summary" aria-label="Period totals">
    <article class="panel manager-performance-card">
        <span>TOTAL TRANSACTIONS</span>
        <strong>{{ number_format($totals['transaction_count']) }}</strong>
        <small>{{ $rangeLabel }}</small>
    </article>
    <article class="panel manager-performance-card">
        <span>UNITS SOLD</span>
        <strong>{{ number_format($totals['units_sold']) }}</strong>
        <small>Across all managers</small>
    </article>
    <article class="panel manager-performance-card">
        <span>SALES REVENUE</span>
        <strong>₹{{ number_format($totals['sales_revenue'], 0, '.', ',') }}</strong>
        <small>Across all managers</small>
    </article>
    <article class="panel manager-performance-card">
        <span>AVERAGE TRANSACTION VALUE</span>
        <strong>₹{{ number_format($totals['average_transaction_value'], 0, '.', ',') }}</strong>
        <small>Period revenue per transaction</small>
    </article>
</section>

<section class="panel manager-performance-panel">
    <div class="inventory-heading">
        <div>
            <h2>Manager rankings</h2>
            <p>{{ $rangeLabel }} <span>·</span> Ranked by sales revenue</p>
        </div>
    </div>
    @if ($managers->isEmpty())
        <div class="empty-inventory">
            <strong>No managers found</strong>
            <span>Add sales manager names to see performance here.</span>
        </div>
    @else
        <div class="table-wrap">
            <table class="inventory-table manager-performance-table">
                <thead>
                    <tr>
                        <th scope="col">RANK</th>
                        <th scope="col">MANAGER</th>
                        <th scope="col">TRANSACTIONS</th>
                        <th scope="col">UNITS SOLD</th>
                        <th scope="col">SALES REVENUE</th>
                        <th scope="col">AVG. TRANSACTION VALUE</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($managers as $manager)
                        <tr>
                            <td><span class="performance-rank">{{ $manager['rank'] }}</span></td>
                            <td><strong>{{ $manager['name'] }}</strong></td>
                            <td>{{ number_format($manager['transaction_count']) }}</td>
                            <td>{{ number_format($manager['units_sold']) }}</td>
                            <td>₹{{ number_format($manager['sales_revenue'], 0, '.', ',') }}</td>
                            <td>₹{{ number_format($manager['average_transaction_value'], 0, '.', ',') }}</td>
                        </tr>
                    @endforeach
                    <tr class="table-total">
                        <td colspan="2">Period total</td>
                        <td>{{ number_format($totals['transaction_count']) }}</td>
                        <td>{{ number_format($totals['units_sold']) }}</td>
                        <td>₹{{ number_format($totals['sales_revenue'], 0, '.', ',') }}</td>
                        <td>₹{{ number_format($totals['average_transaction_value'], 0, '.', ',') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    @endif
</section>
@endsection
