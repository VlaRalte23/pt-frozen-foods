<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalesManager;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManagerPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_sees_ranked_manager_aggregates_and_period_totals(): void
    {
        $this->travelTo('2026-11-05 15:00:00');
        $owner = User::factory()->create(['role' => 'owner']);
        $ana = User::factory()->create(['name' => 'Ana', 'role' => 'manager']);
        $ben = User::factory()->create(['name' => 'Ben', 'role' => 'manager']);
        $product = $this->createProduct();

        $this->recordSale($ana, $product, '2026-11-04 10:00:00', 4000, [2, 2]);
        $this->recordSale($ana, $product, '2026-11-05 11:00:00', 3000, [3]);
        $this->recordSale($ben, $product, '2026-11-05 12:00:00', 9000, [4, 2]);
        $this->recordSale($owner, $product, '2026-11-05 13:00:00', 50000, [10]);

        $response = $this->actingAs($owner)->get(route('manager-performance.index', ['range' => 7]));

        $response->assertOk()
            ->assertSee('Manager Performance')
            ->assertSee('<a class="active" href="'.route('manager-performance.index').'">Manager Performance</a>', false)
            ->assertSee('₹16,000')
            ->assertSee('₹5,333')
            ->assertDontSee('PRODUCT COST')
            ->assertDontSee('Purchase cost');

        $this->assertSame([
            [
                'rank' => 1,
                'name' => 'Ben',
                'transaction_count' => 1,
                'units_sold' => 6,
                'sales_revenue' => 9000,
                'average_transaction_value' => 9000,
            ],
            [
                'rank' => 2,
                'name' => 'Ana',
                'transaction_count' => 2,
                'units_sold' => 7,
                'sales_revenue' => 7000,
                'average_transaction_value' => 3500,
            ],
        ], $response->viewData('managers')->all());

        $this->assertSame([
            'transaction_count' => 3,
            'units_sold' => 13,
            'sales_revenue' => 16000,
            'average_transaction_value' => 16000 / 3,
        ], $response->viewData('totals'));
    }

    public function test_period_selection_filters_sales_by_inclusive_calendar_dates(): void
    {
        $this->travelTo('2026-11-05 15:00:00');
        $owner = User::factory()->create(['role' => 'owner']);
        $manager = User::factory()->create(['role' => 'manager']);
        $product = $this->createProduct();

        $this->recordSale($manager, $product, '2026-10-06 09:00:00', 100, [1]);
        $this->recordSale($manager, $product, '2026-10-07 09:00:00', 200, [1]);
        $this->recordSale($manager, $product, '2026-10-30 09:00:00', 300, [1]);
        $this->recordSale($manager, $product, '2026-11-05 09:00:00', 400, [1]);
        $this->recordSale($manager, $product, '2026-11-06 09:00:00', 500, [1]);

        $this->actingAs($owner);

        $sevenDays = $this->get(route('manager-performance.index', ['range' => 7]));
        $sevenDays->assertOk();
        $this->assertSame(2, $sevenDays->viewData('totals')['transaction_count']);
        $this->assertSame(700, $sevenDays->viewData('totals')['sales_revenue']);

        $thirtyDays = $this->get(route('manager-performance.index', ['range' => 30]));
        $thirtyDays->assertOk();
        $this->assertSame(3, $thirtyDays->viewData('totals')['transaction_count']);
        $this->assertSame(900, $thirtyDays->viewData('totals')['sales_revenue']);

        $ninetyDays = $this->get(route('manager-performance.index', ['range' => 90]));
        $ninetyDays->assertOk();
        $this->assertSame(4, $ninetyDays->viewData('totals')['transaction_count']);
        $this->assertSame(1000, $ninetyDays->viewData('totals')['sales_revenue']);
    }

    public function test_managers_without_sales_are_shown_with_zero_values(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        SalesManager::query()->create(['name' => 'No Sales Manager']);

        $response = $this->actingAs($owner)->get(route('manager-performance.index'));

        $response->assertOk()
            ->assertSee('No Sales Manager')
            ->assertSee('Manager Performance')
            ->assertSee('Manager rankings');

        $this->assertSame([
            [
                'rank' => 1,
                'name' => 'No Sales Manager',
                'transaction_count' => 0,
                'units_sold' => 0,
                'sales_revenue' => 0,
                'average_transaction_value' => 0,
            ],
        ], $response->viewData('managers')->all());
        $this->assertSame([
            'transaction_count' => 0,
            'units_sold' => 0,
            'sales_revenue' => 0,
            'average_transaction_value' => 0,
        ], $response->viewData('totals'));
    }

    public function test_manager_is_forbidden_from_performance_page_and_does_not_see_its_navigation_link(): void
    {
        $manager = User::factory()->create(['role' => 'manager']);

        $this->actingAs($manager)
            ->get(route('manager-performance.index'))
            ->assertForbidden();

        $this->get(route('pos.index'))
            ->assertOk()
            ->assertDontSee('Manager Performance');
    }

    private function createProduct(): Product
    {
        return Product::query()->create([
            'barcode' => 'MANAGER-PERFORMANCE-1',
            'name' => 'Performance Test Product',
            'price' => 1000,
            'stock' => 100,
        ]);
    }

    /**
     * @param  list<int>  $itemQuantities
     */
    private function recordSale(User $manager, Product $product, string $soldAt, int $total, array $itemQuantities): void
    {
        $salesManager = $manager->role === 'manager'
            ? SalesManager::query()->firstOrCreate(['name' => $manager->name])
            : null;
        $sale = Sale::query()->create([
            'user_id' => $manager->id,
            'sales_manager_id' => $salesManager?->id,
            'sales_manager_name' => $salesManager?->name,
            'sold_by_name' => $manager->name,
            'total' => $total,
            'cash_received' => $total,
            'change_due' => 0,
        ]);
        $sale->forceFill(['created_at' => $soldAt, 'updated_at' => $soldAt])->save();

        foreach ($itemQuantities as $quantity) {
            SaleItem::query()->create([
                'sale_id' => $sale->id,
                'product_id' => $product->id,
                'product_name' => $product->name,
                'barcode' => $product->barcode,
                'quantity' => $quantity,
                'unit_price' => 1000,
                'line_total' => $quantity * 1000,
            ]);
        }
    }
}
