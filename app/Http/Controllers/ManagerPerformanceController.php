<?php

namespace App\Http\Controllers;

use App\Models\SalesManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ManagerPerformanceController extends Controller
{
    public function index(Request $request): View
    {
        $validated = $request->validate([
            'range' => ['sometimes', 'integer', Rule::in([7, 30, 90])],
        ]);
        $range = (int) ($validated['range'] ?? 30);
        $endDate = today();
        $startDate = $endDate->copy()->subDays($range - 1);
        $start = $startDate->copy()->startOfDay();
        $end = $endDate->copy()->endOfDay();

        $saleItemTotals = DB::table('sale_items')
            ->select('sale_id')
            ->selectRaw('SUM(quantity) as units_sold')
            ->groupBy('sale_id');

        $salesByManager = DB::table('sales')
            ->leftJoinSub($saleItemTotals, 'sale_item_totals', function ($join): void {
                $join->on('sales.id', '=', 'sale_item_totals.sale_id');
            })
            ->select('sales.sales_manager_id')
            ->selectRaw('COUNT(*) as transaction_count')
            ->selectRaw('SUM(sales.total) as sales_revenue')
            ->selectRaw('COALESCE(SUM(sale_item_totals.units_sold), 0) as units_sold')
            ->whereBetween('sales.created_at', [$start, $end])
            ->groupBy('sales.sales_manager_id');

        $managers = SalesManager::query()
            ->leftJoinSub($salesByManager, 'manager_sales', function ($join): void {
                $join->on('sales_managers.id', '=', 'manager_sales.sales_manager_id');
            })
            ->select('sales_managers.id', 'sales_managers.name')
            ->selectRaw('COALESCE(manager_sales.transaction_count, 0) as transaction_count')
            ->selectRaw('COALESCE(manager_sales.units_sold, 0) as units_sold')
            ->selectRaw('COALESCE(manager_sales.sales_revenue, 0) as sales_revenue')
            ->orderByDesc('sales_revenue')
            ->orderByDesc('transaction_count')
            ->orderBy('sales_managers.name')
            ->get()
            ->values()
            ->map(fn (object $manager, int $index): array => [
                'rank' => $index + 1,
                'name' => $manager->name,
                'transaction_count' => (int) $manager->transaction_count,
                'units_sold' => (int) $manager->units_sold,
                'sales_revenue' => (int) $manager->sales_revenue,
                'average_transaction_value' => $manager->transaction_count > 0
                    ? $manager->sales_revenue / $manager->transaction_count
                    : 0,
            ]);

        $totals = [
            'transaction_count' => $managers->sum('transaction_count'),
            'units_sold' => $managers->sum('units_sold'),
            'sales_revenue' => $managers->sum('sales_revenue'),
        ];
        $totals['average_transaction_value'] = $totals['transaction_count'] > 0
            ? $totals['sales_revenue'] / $totals['transaction_count']
            : 0;

        return view('manager-performance.index', [
            'managers' => $managers,
            'range' => $range,
            'rangeLabel' => $startDate->format('d M Y').' – '.$endDate->format('d M Y'),
            'totals' => $totals,
        ]);
    }
}
