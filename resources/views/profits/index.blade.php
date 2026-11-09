@extends('layouts.app')

@section('title', 'Profit report')

@section('content')
<section class="page-heading">
    <div>
        <p class="eyebrow">OWNER REPORT</p>
        <h1>Profit overview</h1>
        <p class="subheading">Your store performance at a glance, in rupiah.</p>
    </div>
    <form class="chart-range-form" action="{{ route('profits.index') }}" method="GET">
        <label for="profit-range">PERIOD</label>
        <select id="profit-range" name="range">
            <option value="7" @selected($range === 7)>Last 7 days</option>
            <option value="30" @selected($range === 30)>Last 30 days</option>
            <option value="90" @selected($range === 90)>Last 90 days</option>
        </select>
    </form>
</section>

@if (session('success'))
    <div class="notice notice-success" role="status">{{ session('success') }}</div>
@endif

@if ($hasMissingCost)
    <div class="notice" role="alert">
        Some older sales have no recorded product purchase cost. Their gross and net profit are shown as unavailable. Update product costs in Stock; new sales are blocked until a cost is entered.
    </div>
@endif

<section class="sales-period-summary" aria-label="Daily and monthly sales">
    <article class="panel profit-card"><span>DAILY SALES</span><strong>₹{{ number_format($dailySales, 0, '.', ',') }}</strong><small>{{ today()->format('d M Y') }}</small></article>
    <article class="panel profit-card"><span>MONTHLY SALES</span><strong>₹{{ number_format($monthlySales, 0, '.', ',') }}</strong><small>{{ today()->format('M Y') }} to date</small></article>
</section>

<section class="profit-summary">
    <article class="panel profit-card"><span>TOTAL SALES</span><strong>₹{{ number_format($totals['sales'], 0, '.', ',') }}</strong><small>{{ $rangeLabel }}</small></article>
    <article class="panel profit-card"><span>PRODUCT COST</span><strong>{{ $totals['product_cost'] === null ? 'Unavailable' : '₹'.number_format($totals['product_cost'], 0, '.', ',') }}</strong><small>Cost of products sold</small></article>
    <article class="panel profit-card gross-card"><span>GROSS PROFIT</span><strong>{{ $totals['gross_profit'] === null ? 'Unavailable' : '₹'.number_format($totals['gross_profit'], 0, '.', ',') }}</strong><small>Sales minus product cost</small></article>
    <article class="panel profit-card"><span>STORE EXPENSES</span><strong>₹{{ number_format($totals['expenses'], 0, '.', ',') }}</strong><small>Expenses recorded below</small></article>
    <article class="panel profit-card net-card"><span>NET PROFIT</span><strong>{{ $totals['net_profit'] === null ? 'Unavailable' : '₹'.number_format($totals['net_profit'], 0, '.', ',') }}</strong><small>Gross profit minus expenses</small></article>
</section>

<section class="panel profit-chart-panel">
    <div class="chart-heading">
        <div>
            <p class="eyebrow">PROFIT TREND</p>
            <h2>Gross vs net profit</h2>
            <p>{{ $rangeLabel }} <span>·</span> Daily performance</p>
        </div>
        <div class="chart-legend" aria-label="Chart legend">
            <span><i class="legend-dot gross-dot"></i> Gross profit</span>
            <span><i class="legend-dot net-dot"></i> Net profit</span>
        </div>
    </div>
    @if ($totals['sales'] === 0 && $totals['expenses'] === 0)
        <div class="chart-empty">
            <div class="chart-empty-icon" aria-hidden="true">⌁</div>
            <strong>Your profit chart will appear here</strong>
            <span>Once sales and expenses are recorded, daily gross and net profit will show here.</span>
        </div>
    @else
        <div class="profit-chart-wrap" id="profit-chart-wrap">
            <svg id="profit-chart" class="profit-chart" viewBox="0 0 1000 340" role="img" aria-label="Daily gross and net profit chart">
                <text x="500" y="170" text-anchor="middle" class="chart-loading">Preparing profit chart…</text>
            </svg>
        </div>
    @endif
    <p class="chart-footnote">Gross profit = sales minus product cost. Net profit = gross profit minus store expenses.</p>
</section>

<section class="panel report-panel">
    <div class="inventory-heading">
        <div>
            <h2>Daily profit</h2>
            <p>Gross and net profit are shown in separate columns.</p>
        </div>
    </div>
    @if ($totals['sales'] === 0 && $totals['expenses'] === 0)
        <div class="empty-inventory">
            <strong>No sales or expenses yet</strong>
            <span>Daily profit will appear here after the first sale or expense.</span>
        </div>
    @else
        <div class="table-wrap">
            <table class="inventory-table profit-table">
                <thead>
                    <tr><th>DATE</th><th>SALES</th><th>PRODUCT COST</th><th>GROSS PROFIT</th><th>EXPENSES</th><th>NET PROFIT</th></tr>
                </thead>
                <tbody>
                    @foreach ($daily as $day)
                        <tr>
                            <td><strong>{{ \Illuminate\Support\Carbon::parse($day['day'])->format('d M Y') }}</strong></td>
                            <td>₹{{ number_format($day['sales'], 0, '.', ',') }}</td>
                            <td>{{ $day['has_missing_cost'] ? 'Unavailable' : '₹'.number_format($day['product_cost'], 0, '.', ',') }}</td>
                            <td class="gross-value">{{ $day['gross_profit'] === null ? 'Unavailable' : '₹'.number_format($day['gross_profit'], 0, '.', ',') }}</td>
                            <td>₹{{ number_format($day['expenses'], 0, '.', ',') }}</td>
                            <td class="net-value">{{ $day['net_profit'] === null ? 'Unavailable' : '₹'.number_format($day['net_profit'], 0, '.', ',') }}</td>
                        </tr>
                    @endforeach
                    <tr class="table-total">
                        <td>Total</td>
                        <td>₹{{ number_format($totals['sales'], 0, '.', ',') }}</td>
                        <td>{{ $totals['product_cost'] === null ? 'Unavailable' : '₹'.number_format($totals['product_cost'], 0, '.', ',') }}</td>
                        <td class="gross-value">{{ $totals['gross_profit'] === null ? 'Unavailable' : '₹'.number_format($totals['gross_profit'], 0, '.', ',') }}</td>
                        <td>₹{{ number_format($totals['expenses'], 0, '.', ',') }}</td>
                        <td class="net-value">{{ $totals['net_profit'] === null ? 'Unavailable' : '₹'.number_format($totals['net_profit'], 0, '.', ',') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    @endif
</section>
<script>
    window.profitChartData = {{ Illuminate\Support\Js::from($chartData) }};
</script>

<div class="owner-tools">
    <section class="panel expense-form-panel">
        <div class="panel-heading">
            <div class="step-number">+</div>
            <div>
                <h2>Record an expense</h2>
                <p>Expenses reduce net profit, not gross profit.</p>
            </div>
        </div>
        <form action="{{ route('expenses.store') }}" method="POST" class="expense-form">
            @csrf
            <div class="form-field">
                <label for="expense-category">EXPENSE CATEGORY</label>
                <select id="expense-category" name="category" required>
                    @foreach ($expenseCategories as $value => $label)
                        <option value="{{ $value }}" @selected(old('category', 'other') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                @error('category') <span class="field-error">{{ $message }}</span> @enderror
            </div>
            <div class="form-field">
                <label for="expense-description">DESCRIPTION</label>
                <input id="expense-description" name="description" type="text" value="{{ old('description') }}" maxlength="120" placeholder="Example: Wages for November" required>
                @error('description') <span class="field-error">{{ $message }}</span> @enderror
            </div>
            <div class="form-row">
                <div class="form-field">
                    <label for="expense-amount">AMOUNT</label>
                    <input id="expense-amount" name="amount" type="number" min="1" step="1" value="{{ old('amount') }}" required>
                    @error('amount') <span class="field-error">{{ $message }}</span> @enderror
                </div>
                <div class="form-field">
                    <label for="expense-date">DATE</label>
                    <input id="expense-date" name="spent_on" type="date" value="{{ old('spent_on', now()->toDateString()) }}" required>
                    @error('spent_on') <span class="field-error">{{ $message }}</span> @enderror
                </div>
            </div>
            <button type="submit" class="button button-primary">Save expense</button>
        </form>
    </section>

    <section class="panel recent-expenses-panel">
        <div class="inventory-heading">
            <div><h2>Recent expenses</h2><p>Latest 10 entries</p></div>
        </div>
        @if ($recentExpenses->isEmpty())
            <div class="empty-inventory"><span>No expenses recorded.</span></div>
        @else
            <div class="table-wrap">
                <table class="inventory-table">
                    <thead><tr><th>DATE</th><th>CATEGORY</th><th>DESCRIPTION</th><th>AMOUNT</th></tr></thead>
                    <tbody>
                        @foreach ($recentExpenses as $expense)
                            <tr>
                                <td>{{ $expense->spent_on->format('d M Y') }}</td>
                                <td>{{ $expenseCategories[$expense->category] ?? $expenseCategories['other'] }}</td>
                                <td><strong>{{ $expense->description }}</strong></td>
                                <td>₹{{ number_format($expense->amount, 0, '.', ',') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
@endsection
