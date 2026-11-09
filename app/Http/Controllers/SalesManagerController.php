<?php

namespace App\Http\Controllers;

use App\Models\SalesManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SalesManagerController extends Controller
{
    public function index(): View
    {
        return view('sales-managers.index', [
            'salesManagers' => SalesManager::query()
                ->orderByDesc('active')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $name = $request->input('name');

        if (is_string($name)) {
            $request->merge(['name' => trim($name)]);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:sales_managers,name'],
        ]);

        SalesManager::query()->create([
            'name' => trim($validated['name']),
            'active' => true,
        ]);

        return redirect()->route('sales-managers.index')->with('success', 'Sales manager added.');
    }

    public function update(Request $request, SalesManager $salesManager): RedirectResponse
    {
        $name = $request->input('name');

        if (is_string($name)) {
            $request->merge(['name' => trim($name)]);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('sales_managers', 'name')->ignore($salesManager->id)],
            'active' => ['required', 'boolean'],
        ]);

        $salesManager->update([
            'name' => trim($validated['name']),
            'active' => (bool) $validated['active'],
        ]);

        return redirect()->route('sales-managers.index')->with('success', 'Sales manager updated.');
    }
}
