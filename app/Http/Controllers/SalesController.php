<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use Illuminate\View\View;

class SalesController extends Controller
{
    public function index(): View
    {
        return view('sales.index', [
            'sales' => Sale::query()
                ->with(['items', 'seller', 'customer'])
                ->withSum('customerPayments as payments_total', 'amount')
                ->latest()
                ->paginate(20),
        ]);
    }
}
