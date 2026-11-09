<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Sale;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CustomerController extends Controller
{
    public function index(): View
    {
        $customers = Customer::query()
            ->with(['sales' => function (HasMany $query): void {
                $query->with('items:id,sale_id,product_name,quantity')
                    ->latest()
                    ->limit(3);
            }])
            ->withCount('sales')
            ->withSum(['sales as credit_sales_total' => function (Builder $query): void {
                $query->where('payment_method', 'credit');
            }], 'total')
            ->withSum('payments as payments_total', 'amount')
            ->orderBy('name')
            ->paginate(25);
        $deletedCustomers = null;

        if (request()->user()->isOwner()) {
            $deletedCustomers = Customer::onlyTrashed()
                ->withSum(['sales as credit_sales_total' => function (Builder $query): void {
                    $query->where('payment_method', 'credit');
                }], 'total')
                ->withSum('payments as payments_total', 'amount')
                ->orderByDesc('deleted_at')
                ->paginate(10, ['*'], 'recycle_page');
        }

        return view('customers.index', [
            'customers' => $customers,
            'deletedCustomers' => $deletedCustomers,
            'isOwner' => request()->user()->isOwner(),
        ]);
    }

    public function edit(Customer $customer): View
    {
        return view('customers.edit', [
            'customer' => $customer,
        ]);
    }

    public function show(Customer $customer): View
    {
        $sales = $customer->sales()
            ->with('items')
            ->withSum('customerPayments as payments_total', 'amount')
            ->latest()
            ->paginate(20, ['*'], 'sales_page');

        return view('customers.show', [
            'customer' => $customer,
            'sales' => $sales,
            'payments' => $customer->payments()->with(['sale', 'recorder'])->latest()->paginate(20, ['*'], 'payments_page'),
            'creditTotal' => (int) $customer->sales()->where('payment_method', 'credit')->sum('total'),
            'paidTotal' => (int) $customer->payments()->sum('amount'),
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:64'],
        ]);
        $query = trim($validated['q']);

        $customers = Customer::query()
            ->where(function (Builder $builder) use ($query): void {
                $builder->where('name', 'like', '%'.$query.'%')
                    ->orWhere('phone', 'like', '%'.$query.'%');
            })
            ->orderBy('name')
            ->limit(8)
            ->get(['id', 'name', 'phone', 'address']);

        return response()->json([
            'customers' => $customers,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:500'],
        ]);

        $customer = Customer::query()->create([
            'name' => trim($validated['name']),
            'phone' => isset($validated['phone']) ? trim($validated['phone']) : null,
            'address' => isset($validated['address']) ? trim($validated['address']) : null,
        ]);

        return response()->json([
            'customer' => $customer->only(['id', 'name', 'phone', 'address']),
        ], 201);
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:500'],
        ]);

        $customer->update([
            'name' => trim($validated['name']),
            'phone' => isset($validated['phone']) ? trim($validated['phone']) : null,
            'address' => isset($validated['address']) ? trim($validated['address']) : null,
        ]);

        return redirect()->route('customers.index')->with('success', 'Customer details updated.');
    }

    public function destroy(Customer $customer): RedirectResponse
    {
        abort_unless(request()->user()->isOwner(), 403);

        $customer->delete();

        return redirect()->route('customers.index')->with('success', 'Customer moved to the Recycle Bin. Sales and payment history are kept.');
    }

    public function restore(string $customerId): RedirectResponse
    {
        abort_unless(request()->user()->isOwner(), 403);

        $customer = Customer::withTrashed()->findOrFail($customerId);
        $customer->restore();

        return redirect()->route('customers.index')->with('success', 'Customer restored.');
    }

    public function recordPayment(Request $request, Customer $customer): RedirectResponse
    {
        $validated = $request->validate([
            'sale_id' => ['required', 'integer'],
            'amount' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'payment_method' => ['required', 'string', 'in:cash,gpay,bank_transfer'],
        ]);

        DB::transaction(function () use ($validated, $customer, $request): void {
            $sale = Sale::query()
                ->whereKey($validated['sale_id'])
                ->where('customer_id', $customer->id)
                ->where('payment_method', 'credit')
                ->lockForUpdate()
                ->first();

            if (! $sale) {
                throw ValidationException::withMessages([
                    'sale_id' => 'Select an outstanding credit sale for this customer.',
                ]);
            }

            $paidAmount = (int) $sale->customerPayments()->sum('amount');
            $balanceDue = $sale->total - $paidAmount;

            if ($validated['amount'] > $balanceDue) {
                throw ValidationException::withMessages([
                    'amount' => 'Payment cannot be more than the remaining balance of ₹'.number_format($balanceDue, 0, '.', ',').'.',
                ]);
            }

            CustomerPayment::query()->create([
                'customer_id' => $customer->id,
                'sale_id' => $sale->id,
                'user_id' => $request->user()->id,
                'amount' => $validated['amount'],
                'payment_method' => $validated['payment_method'],
            ]);
        });

        return redirect()->route('customers.show', $customer)->with('success', 'Customer payment recorded.');
    }
}
