<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use Carbon\CarbonPeriod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProfitController extends Controller
{
    private const EXPENSE_CATEGORIES = [
        'employee_wages' => 'Employee wages',
        'rent' => 'Rent',
        'utilities' => 'Utilities (electricity / water)',
        'transport' => 'Transportation',
        'packaging' => 'Packaging',
        'internet_phone' => 'Internet / phone',
        'maintenance' => 'Repairs / maintenance',
        'supplies' => 'Store supplies',
        'other' => 'Other',
    ];

    public function index(Request $request): View
    {
        $validated = $request->validate([
            'range' => ['sometimes', 'integer', Rule::in([7, 30, 90])],
        ]);
        $range = (int) ($validated['range'] ?? 30);
        $endDate = today();
        $monthStart = $endDate->copy()->startOfMonth()->toDateString();
        $startDate = $endDate->copy()->subDays($range - 1);
        $start = $startDate->toDateString();
        $end = $endDate->toDateString();
        $dailySales = DB::table('sales')
            ->whereDate('created_at', $end)
            ->sum('total');
        $monthlySales = DB::table('sales')
            ->whereBetween(DB::raw('DATE(created_at)'), [$monthStart, $end])
            ->sum('total');

        $salesByDay = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->selectRaw('DATE(sales.created_at) as day')
            ->selectRaw('SUM(sale_items.line_total) as sales')
            ->selectRaw('SUM(sale_items.unit_cost * sale_items.quantity) as product_cost')
            ->selectRaw('SUM(CASE WHEN sale_items.unit_cost IS NULL THEN 1 ELSE 0 END) as missing_cost_items')
            ->whereBetween(DB::raw('DATE(sales.created_at)'), [$start, $end])
            ->groupByRaw('DATE(sales.created_at)')
            ->get()
            ->keyBy('day');

        $expensesByDay = Expense::query()
            ->selectRaw('spent_on as day, SUM(amount) as expenses')
            ->whereBetween('spent_on', [$start, $end])
            ->groupBy('spent_on')
            ->get()
            ->keyBy('day');

        $days = collect(CarbonPeriod::create($startDate, $endDate))
            ->map(fn ($date): string => $date->toDateString());

        $daily = $days->map(function (string $day) use ($salesByDay, $expensesByDay): array {
            $salesForDay = $salesByDay->get($day);
            $expensesForDay = $expensesByDay->get($day);
            $sales = (int) ($salesForDay?->sales ?? 0);
            $productCost = (int) ($salesForDay?->product_cost ?? 0);
            $hasMissingCost = (int) ($salesForDay?->missing_cost_items ?? 0) > 0;
            $expenses = (int) ($expensesForDay?->expenses ?? 0);
            $grossProfit = $hasMissingCost ? null : $sales - $productCost;

            return [
                'day' => $day,
                'sales' => $sales,
                'product_cost' => $productCost,
                'gross_profit' => $grossProfit,
                'expenses' => $expenses,
                'net_profit' => $grossProfit === null ? null : $grossProfit - $expenses,
                'has_missing_cost' => $hasMissingCost,
            ];
        });

        $hasMissingCost = $daily->contains(fn (array $row): bool => $row['has_missing_cost']);
        $productCostTotal = $daily->sum('product_cost');

        return view('profits.index', [
            'daily' => $daily,
            'dailySales' => (int) $dailySales,
            'monthlySales' => (int) $monthlySales,
            'range' => $range,
            'rangeLabel' => $startDate->format('d M Y').' – '.$endDate->format('d M Y'),
            'hasMissingCost' => $hasMissingCost,
            'totals' => [
                'sales' => $daily->sum('sales'),
                'product_cost' => $hasMissingCost ? null : $productCostTotal,
                'gross_profit' => $hasMissingCost ? null : $daily->sum('gross_profit'),
                'expenses' => $daily->sum('expenses'),
                'net_profit' => $hasMissingCost ? null : $daily->sum('net_profit'),
            ],
            'chartData' => $daily->map(fn (array $day): array => [
                'date' => $day['day'],
                'gross' => $day['gross_profit'],
                'net' => $day['net_profit'],
            ])->values(),
            'expenseCategories' => self::EXPENSE_CATEGORIES,
            'recentExpenses' => Expense::query()->latest('spent_on')->latest()->limit(10)->get(),
        ]);
    }

    public function storeExpense(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'category' => ['required', Rule::in(array_keys(self::EXPENSE_CATEGORIES))],
            'description' => ['required', 'string', 'max:120'],
            'amount' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'spent_on' => ['required', 'date_format:Y-m-d'],
        ]);

        $request->user()->expenses()->create($validated);

        return redirect()->route('profits.index')->with('success', 'Expense recorded.');
    }
}
