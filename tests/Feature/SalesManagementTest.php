<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_open_the_edit_form_and_see_sale_actions(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $product = $this->createProduct('EDIT-001', 'Frozen Peas', 1000, 600, 4);
        $sale = $this->createSale($product, 1, 1000, 600);

        $this->actingAs($owner)
            ->get(route('sales.index'))
            ->assertOk()
            ->assertSee(route('sales.edit', $sale))
            ->assertSee(route('sales.destroy', $sale));

        $this->get(route('sales.edit', $sale))
            ->assertOk()
            ->assertSee('Edit sale')
            ->assertSee('Frozen Peas')
            ->assertSee('Save sale changes');
    }

    public function test_owner_can_update_sale_items_and_related_stock_totals_and_customer(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $existingProduct = $this->createProduct('EDIT-002', 'Frozen Chicken', 1000, 600, 8);
        $newProduct = $this->createProduct('EDIT-003', 'Frozen Corn', 500, 200, 5);
        $sale = $this->createSale($existingProduct, 2, 800, 450);
        $customer = Customer::query()->create([
            'name' => 'Market Store',
            'phone' => '081234567890',
            'address' => 'Jakarta',
        ]);

        $this->actingAs($owner)
            ->put(route('sales.update', $sale), [
                'items' => [
                    ['product_id' => $existingProduct->id, 'quantity' => 3],
                    ['product_id' => $newProduct->id, 'quantity' => 2],
                ],
                'customer_id' => $customer->id,
                'fulfillment_type' => 'delivery',
                'payment_method' => 'cash',
                'cash_received' => 4000,
            ])
            ->assertRedirect(route('sales.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('sales', [
            'id' => $sale->id,
            'customer_id' => $customer->id,
            'customer_name' => 'Market Store',
            'customer_phone' => '081234567890',
            'customer_address' => 'Jakarta',
            'fulfillment_type' => 'delivery',
            'payment_method' => 'cash',
            'total' => 3400,
            'cash_received' => 4000,
            'change_due' => 600,
        ]);
        $this->assertDatabaseHas('sale_items', [
            'sale_id' => $sale->id,
            'product_id' => $existingProduct->id,
            'quantity' => 3,
            'unit_price' => 800,
            'unit_cost' => 450,
            'line_total' => 2400,
        ]);
        $this->assertDatabaseHas('sale_items', [
            'sale_id' => $sale->id,
            'product_id' => $newProduct->id,
            'quantity' => 2,
            'unit_price' => 500,
            'unit_cost' => 200,
            'line_total' => 1000,
        ]);
        $this->assertDatabaseHas('products', ['id' => $existingProduct->id, 'stock' => 7]);
        $this->assertDatabaseHas('products', ['id' => $newProduct->id, 'stock' => 3]);
    }

    public function test_owner_can_delete_sale_and_restore_sold_stock(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $product = $this->createProduct('DELETE-001', 'Frozen Fish', 1200, 700, 3);
        $sale = $this->createSale($product, 2, 1200, 700);

        $this->actingAs($owner)
            ->delete(route('sales.destroy', $sale))
            ->assertRedirect(route('sales.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('sales', ['id' => $sale->id]);
        $this->assertDatabaseMissing('sale_items', ['sale_id' => $sale->id]);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock' => 5]);
    }

    public function test_manager_cannot_view_or_change_sales_and_does_not_see_sale_actions(): void
    {
        $manager = User::factory()->create(['role' => 'manager']);
        $product = $this->createProduct('ROLE-001', 'Frozen Dumplings', 900, 500, 3);
        $sale = $this->createSale($product, 1, 900, 500);

        $this->actingAs($manager)
            ->get(route('sales.index'))
            ->assertOk()
            ->assertDontSee(route('sales.edit', $sale))
            ->assertDontSee(route('sales.destroy', $sale));

        $this->get(route('sales.edit', $sale))->assertForbidden();
        $this->put(route('sales.update', $sale), [])->assertForbidden();
        $this->delete(route('sales.destroy', $sale))->assertForbidden();

        $this->assertDatabaseHas('sales', ['id' => $sale->id]);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock' => 3]);
    }

    public function test_sale_with_debt_repayments_cannot_be_edited_or_deleted(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $customer = Customer::query()->create(['name' => 'Credit Customer']);
        $product = $this->createProduct('DEBT-001', 'Frozen Waffles', 1000, 650, 3);
        $sale = $this->createSale($product, 1, 1000, 650, $customer, 'credit');
        CustomerPayment::query()->create([
            'customer_id' => $customer->id,
            'sale_id' => $sale->id,
            'user_id' => $owner->id,
            'amount' => 300,
            'payment_method' => 'cash',
        ]);

        $this->actingAs($owner)
            ->put(route('sales.update', $sale), [
                'items' => [['product_id' => $product->id, 'quantity' => 2]],
                'customer_id' => $customer->id,
                'fulfillment_type' => 'walk_in',
                'payment_method' => 'credit',
            ])
            ->assertSessionHasErrors('sale');

        $this->delete(route('sales.destroy', $sale))
            ->assertSessionHasErrors('sale');

        $this->assertDatabaseHas('sales', ['id' => $sale->id, 'total' => 1000]);
        $this->assertDatabaseHas('sale_items', ['sale_id' => $sale->id, 'quantity' => 1]);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock' => 3]);
    }

    public function test_sale_update_rejects_insufficient_stock_without_changing_records(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $product = $this->createProduct('STOCK-001', 'Frozen Meatballs', 1100, 700, 2);
        $sale = $this->createSale($product, 1, 1100, 700);

        $this->actingAs($owner)
            ->put(route('sales.update', $sale), [
                'items' => [['product_id' => $product->id, 'quantity' => 4]],
                'fulfillment_type' => 'walk_in',
                'payment_method' => 'gpay',
            ])
            ->assertSessionHasErrors('items');

        $this->assertDatabaseHas('sales', ['id' => $sale->id, 'total' => 1100]);
        $this->assertDatabaseHas('sale_items', ['sale_id' => $sale->id, 'quantity' => 1]);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock' => 2]);
    }

    public function test_sale_update_rejects_a_new_product_without_a_saved_cost(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $existingProduct = $this->createProduct('COST-001', 'Frozen Spring Rolls', 900, 500, 2);
        $newProduct = Product::query()->create([
            'barcode' => 'COST-002',
            'name' => 'Frozen Tofu',
            'price' => 700,
            'unit_cost' => null,
            'stock' => 4,
        ]);
        $sale = $this->createSale($existingProduct, 1, 900, 500);

        $this->actingAs($owner)
            ->put(route('sales.update', $sale), [
                'items' => [['product_id' => $newProduct->id, 'quantity' => 1]],
                'fulfillment_type' => 'walk_in',
                'payment_method' => 'gpay',
            ])
            ->assertSessionHasErrors('items');

        $this->assertDatabaseHas('sales', ['id' => $sale->id, 'total' => 900]);
        $this->assertDatabaseHas('sale_items', ['sale_id' => $sale->id, 'product_id' => $existingProduct->id]);
        $this->assertDatabaseHas('products', ['id' => $existingProduct->id, 'stock' => 2]);
        $this->assertDatabaseHas('products', ['id' => $newProduct->id, 'stock' => 4]);
    }

    public function test_sale_update_rejects_insufficient_cash_without_changing_records(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $product = $this->createProduct('CASH-001', 'Frozen Pancakes', 1100, 700, 2);
        $sale = $this->createSale($product, 1, 1100, 700);

        $this->actingAs($owner)
            ->put(route('sales.update', $sale), [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'fulfillment_type' => 'walk_in',
                'payment_method' => 'cash',
                'cash_received' => 1000,
            ])
            ->assertSessionHasErrors('cash_received');

        $this->assertDatabaseHas('sales', ['id' => $sale->id, 'total' => 1100]);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock' => 2]);
    }

    public function test_delivery_update_requires_a_customer_with_a_saved_address(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $customer = Customer::query()->create(['name' => 'No Address Customer']);
        $product = $this->createProduct('DELIVERY-001', 'Frozen Yogurt', 800, 400, 2);
        $sale = $this->createSale($product, 1, 800, 400);

        $this->actingAs($owner)
            ->put(route('sales.update', $sale), [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'customer_id' => $customer->id,
                'fulfillment_type' => 'delivery',
                'payment_method' => 'gpay',
            ])
            ->assertSessionHasErrors('customer_id');

        $this->assertDatabaseHas('sales', [
            'id' => $sale->id,
            'customer_id' => null,
            'fulfillment_type' => 'walk_in',
            'total' => 800,
        ]);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock' => 2]);
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

    private function createSale(
        Product $product,
        int $quantity,
        int $unitPrice,
        int $unitCost,
        ?Customer $customer = null,
        string $paymentMethod = 'cash',
    ): Sale {
        $sale = Sale::query()->create([
            'customer_id' => $customer?->id,
            'customer_name' => $customer?->name,
            'customer_phone' => $customer?->phone,
            'customer_address' => $customer?->address,
            'payment_method' => $paymentMethod,
            'fulfillment_type' => 'walk_in',
            'total' => $quantity * $unitPrice,
            'cash_received' => $paymentMethod === 'cash' ? $quantity * $unitPrice : 0,
            'change_due' => 0,
        ]);

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
