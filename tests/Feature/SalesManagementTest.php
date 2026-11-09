<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalesManager;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_edit_a_sale_and_related_stock_customer_and_totals_are_updated(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $firstProduct = $this->createProduct('899100000701', 'Frozen Fish', 500, 300, 8);
        $addedProduct = $this->createProduct('899100000702', 'Frozen Prawn', 200, 100, 5);
        $customer = Customer::query()->create(['name' => 'Updated Market', 'phone' => '081234567890', 'address' => 'Jakarta']);
        $sale = $this->createSale($owner, $firstProduct, 2, 400, 250);
        $this->actingAs($owner);

        $this->get(route('sales.edit', $sale))
            ->assertOk()
            ->assertSee('Edit sale')
            ->assertSee('Frozen Fish')
            ->assertSee('Saved unit price: ₹400');

        $response = $this->put(route('sales.update', $sale), [
            'items' => [
                ['product_id' => $firstProduct->id, 'quantity' => 3],
                ['product_id' => $addedProduct->id, 'quantity' => 2],
            ],
            'customer_id' => $customer->id,
            'customer_name' => 'Ignored walk-in name',
            'payment_method' => 'gpay',
        ]);

        $response->assertRedirect(route('sales.index'));
        $this->assertDatabaseHas('sales', [
            'id' => $sale->id,
            'customer_id' => $customer->id,
            'customer_name' => 'Updated Market',
            'payment_method' => 'gpay',
            'total' => 1600,
            'cash_received' => 1600,
            'change_due' => 0,
        ]);
        $this->assertDatabaseHas('sale_items', [
            'sale_id' => $sale->id,
            'product_id' => $firstProduct->id,
            'quantity' => 3,
            'unit_price' => 400,
            'unit_cost' => 250,
            'line_total' => 1200,
        ]);
        $this->assertDatabaseHas('sale_items', [
            'sale_id' => $sale->id,
            'product_id' => $addedProduct->id,
            'quantity' => 2,
            'unit_price' => 200,
            'unit_cost' => 100,
            'line_total' => 400,
        ]);
        $this->assertSame(7, $firstProduct->fresh()->stock);
        $this->assertSame(3, $addedProduct->fresh()->stock);
    }

    public function test_owner_can_permanently_delete_a_sale_and_restore_its_stock(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $product = $this->createProduct('899100000703', 'Frozen Squid', 800, 500, 4);
        $sale = $this->createSale($owner, $product, 3, 800, 500);
        $this->actingAs($owner);

        $this->delete(route('sales.destroy', $sale))
            ->assertRedirect(route('sales.index'));

        $this->assertDatabaseMissing('sales', ['id' => $sale->id]);
        $this->assertDatabaseMissing('sale_items', ['sale_id' => $sale->id]);
        $this->assertSame(7, $product->fresh()->stock);
    }

    public function test_owner_can_move_a_sale_to_a_customer_as_credit_and_customer_balance_updates(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $oldCustomer = Customer::query()->create(['name' => 'Old Market']);
        $newCustomer = Customer::query()->create(['name' => 'New Market']);
        $product = $this->createProduct('899100000708', 'Frozen Vegetables', 900, 600, 4);
        $sale = $this->createSale($owner, $product, 1, 900, 600, [
            'customer_id' => $oldCustomer->id,
            'customer_name' => $oldCustomer->name,
        ]);
        $this->actingAs($owner);

        $this->put(route('sales.update', $sale), [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'customer_id' => $newCustomer->id,
            'payment_method' => 'credit',
        ])->assertRedirect(route('sales.index'));

        $this->assertDatabaseHas('sales', [
            'id' => $sale->id,
            'customer_id' => $newCustomer->id,
            'customer_name' => 'New Market',
            'payment_method' => 'credit',
            'total' => 1800,
            'cash_received' => 0,
            'change_due' => 0,
        ]);
        $this->assertSame(3, $product->fresh()->stock);

        $this->get(route('customers.show', $newCustomer))
            ->assertOk()
            ->assertSee('BALANCE DUE')
            ->assertSee('₹1,800');
    }

    public function test_sale_with_a_repayment_cannot_be_edited_or_deleted(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $customer = Customer::query()->create(['name' => 'North Market']);
        $product = $this->createProduct('899100000704', 'Frozen Tuna', 900, 600, 4);
        $sale = $this->createSale($owner, $product, 1, 900, 600, [
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'payment_method' => 'credit',
            'total' => 900,
            'cash_received' => 0,
        ]);
        CustomerPayment::query()->create([
            'customer_id' => $customer->id,
            'sale_id' => $sale->id,
            'user_id' => $owner->id,
            'amount' => 300,
            'payment_method' => 'cash',
        ]);
        $this->actingAs($owner);

        $this->from(route('sales.edit', $sale))
            ->put(route('sales.update', $sale), [
                'items' => [['product_id' => $product->id, 'quantity' => 2]],
                'customer_id' => $customer->id,
                'payment_method' => 'credit',
            ])
            ->assertSessionHasErrors([
                'sale' => 'This sale has recorded debt payments and cannot be edited or deleted.',
            ]);

        $this->from(route('sales.index'))
            ->delete(route('sales.destroy', $sale))
            ->assertSessionHasErrors([
                'sale' => 'This sale has recorded debt payments and cannot be edited or deleted.',
            ]);

        $this->assertDatabaseHas('sales', ['id' => $sale->id, 'total' => 900]);
        $this->assertDatabaseHas('sale_items', ['sale_id' => $sale->id, 'quantity' => 1]);
        $this->assertSame(4, $product->fresh()->stock);
        $this->assertDatabaseHas('customer_payments', ['sale_id' => $sale->id, 'amount' => 300]);
    }

    public function test_manager_cannot_see_or_use_sale_edit_and_delete_actions(): void
    {
        $manager = User::factory()->create(['role' => 'manager']);
        $product = $this->createProduct('899100000705', 'Frozen Crab', 700, 400, 3);
        $sale = $this->createSale($manager, $product, 1, 700, 400);
        $this->actingAs($manager);

        $this->get(route('sales.index'))
            ->assertOk()
            ->assertDontSee(route('sales.edit', $sale))
            ->assertDontSee(route('sales.destroy', $sale));

        $this->get(route('sales.edit', $sale))->assertForbidden();
        $this->delete(route('sales.destroy', $sale))->assertForbidden();

        $this->assertDatabaseHas('sales', ['id' => $sale->id]);
        $this->assertSame(3, $product->fresh()->stock);
    }

    public function test_sale_edit_rejects_cash_below_the_new_total_without_side_effects(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $product = $this->createProduct('899100000706', 'Frozen Meatballs', 500, 300, 4);
        $sale = $this->createSale($owner, $product, 1, 500, 300);
        $this->actingAs($owner);

        $this->from(route('sales.edit', $sale))
            ->put(route('sales.update', $sale), [
                'items' => [['product_id' => $product->id, 'quantity' => 2]],
                'payment_method' => 'cash',
                'cash_received' => 500,
            ])
            ->assertSessionHasErrors([
                'cash_received' => 'Cash received must be at least the sale total.',
            ]);

        $this->assertDatabaseHas('sales', ['id' => $sale->id, 'total' => 500, 'cash_received' => 500]);
        $this->assertDatabaseHas('sale_items', ['sale_id' => $sale->id, 'quantity' => 1]);
        $this->assertSame(4, $product->fresh()->stock);
    }

    public function test_sale_edit_rejects_insufficient_stock_without_side_effects(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $product = $this->createProduct('899100000707', 'Frozen Sausage', 500, 300, 2);
        $sale = $this->createSale($owner, $product, 1, 500, 300);
        $this->actingAs($owner);

        $this->from(route('sales.edit', $sale))
            ->put(route('sales.update', $sale), [
                'items' => [['product_id' => $product->id, 'quantity' => 4]],
                'payment_method' => 'cash',
                'cash_received' => 2000,
            ])
            ->assertSessionHasErrors([
                'items' => 'Only 3 available for Frozen Sausage after accounting for this sale.',
            ]);

        $this->assertDatabaseHas('sales', ['id' => $sale->id, 'total' => 500]);
        $this->assertDatabaseHas('sale_items', ['sale_id' => $sale->id, 'quantity' => 1]);
        $this->assertSame(2, $product->fresh()->stock);
    }

    private function createProduct(string $barcode, string $name, int $price, int $unitCost, int $stock): Product
    {
        return Product::query()->create([
            'barcode' => $barcode,
            'name' => $name,
            'price' => $price,
            'unit_cost' => $unitCost,
            'stock' => $stock,
        ]);
    }

    /**
     * @param  array<string, int|string|null>  $attributes
     */
    private function createSale(
        User $seller,
        Product $product,
        int $quantity,
        int $unitPrice,
        int $unitCost,
        array $attributes = [],
    ): Sale {
        $salesManager = SalesManager::query()->create(['name' => 'Historical Manager']);
        $sale = Sale::query()->create(array_merge([
            'user_id' => $seller->id,
            'sales_manager_id' => $salesManager->id,
            'sales_manager_name' => $salesManager->name,
            'sold_by_name' => $seller->name,
            'customer_name' => 'Walk-in Customer',
            'payment_method' => 'cash',
            'total' => $quantity * $unitPrice,
            'cash_received' => $quantity * $unitPrice,
            'change_due' => 0,
        ], $attributes));

        SaleItem::query()->create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'barcode' => $product->barcode,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'unit_cost' => $unitCost,
            'line_total' => $quantity * $unitPrice,
        ]);

        return $sale;
    }
}
