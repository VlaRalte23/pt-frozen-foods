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

class CustomerDebtWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_add_and_search_regular_customers(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'manager']));

        $this->postJson(route('customers.store'), [
            'name' => '  North Market  ',
            'phone' => '08123456789',
            'address' => 'Jakarta',
        ])
            ->assertCreated()
            ->assertJsonPath('customer.name', 'North Market')
            ->assertJsonPath('customer.phone', '08123456789');

        $this->getJson(route('customers.search', ['q' => 'North']))
            ->assertOk()
            ->assertJsonCount(1, 'customers')
            ->assertJsonPath('customers.0.name', 'North Market');

        $this->assertDatabaseHas('customers', [
            'name' => 'North Market',
            'phone' => '08123456789',
        ]);
    }

    public function test_manager_can_update_a_regular_customer(): void
    {
        $manager = User::factory()->create(['role' => 'manager']);
        $customer = Customer::query()->create([
            'name' => 'North Market',
            'phone' => '08111111111',
            'address' => 'Old address',
        ]);
        $this->actingAs($manager);

        $this->get(route('customers.index'))
            ->assertOk()
            ->assertSee('Edit')
            ->assertDontSee('Delete')
            ->assertDontSee('Recycle Bin')
            ->assertSee(route('customers.edit', $customer));
        $this->get(route('customers.edit', $customer))
            ->assertOk()
            ->assertSee('Edit customer')
            ->assertSee('North Market');

        $this->put(route('customers.update', $customer), [
            'name' => '  North Market Store  ',
            'phone' => '08222222222',
            'address' => 'New address',
        ])->assertRedirect(route('customers.index'))
            ->assertSessionHas('success', 'Customer details updated.');

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'name' => 'North Market Store',
            'phone' => '08222222222',
            'address' => 'New address',
            'deleted_at' => null,
        ]);
    }

    public function test_customer_update_rejects_a_missing_name(): void
    {
        $customer = Customer::query()->create([
            'name' => 'North Market',
            'phone' => '08111111111',
        ]);
        $this->actingAs(User::factory()->create(['role' => 'manager']));

        $this->from(route('customers.edit', $customer))
            ->put(route('customers.update', $customer), [
                'name' => '',
                'phone' => '08222222222',
            ])
            ->assertRedirect(route('customers.edit', $customer))
            ->assertSessionHasErrors('name');

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'name' => 'North Market',
            'phone' => '08111111111',
            'deleted_at' => null,
        ]);
    }

    public function test_deleted_customers_are_kept_in_recycle_bin_and_can_be_restored(): void
    {
        $seller = User::factory()->create(['role' => 'manager']);
        $owner = User::factory()->create(['role' => 'owner']);
        $customer = Customer::query()->create(['name' => 'North Market']);
        $sale = $this->createCreditSale($customer, $seller);
        $payment = CustomerPayment::query()->create([
            'customer_id' => $customer->id,
            'sale_id' => $sale->id,
            'user_id' => $seller->id,
            'amount' => 250,
            'payment_method' => 'cash',
        ]);
        $this->actingAs($owner);

        $this->delete(route('customers.destroy', $customer))
            ->assertRedirect(route('customers.index'))
            ->assertSessionHas('success', 'Customer moved to the Recycle Bin. Sales and payment history are kept.');

        $this->assertSoftDeleted($customer);
        $this->assertModelExists($sale);
        $this->assertModelExists($payment);
        $this->assertSame(0, Customer::query()->count());

        $this->get(route('customers.index'))
            ->assertOk()
            ->assertSee('Recycle Bin')
            ->assertSee('North Market')
            ->assertSee('₹750')
            ->assertSee(route('customers.restore', $customer->id));
        $this->getJson(route('customers.search', ['q' => 'North']))
            ->assertOk()
            ->assertJsonCount(0, 'customers');

        $this->post(route('customers.restore', $customer->id))
            ->assertRedirect(route('customers.index'))
            ->assertSessionHas('success', 'Customer restored.');

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'name' => 'North Market',
            'deleted_at' => null,
        ]);
        $this->assertModelExists($sale);
        $this->assertModelExists($payment);
    }

    public function test_only_owners_can_delete_or_restore_customers(): void
    {
        $customer = Customer::query()->create(['name' => 'North Market']);
        $deletedCustomer = Customer::query()->create(['name' => 'Archived Market']);
        $deletedCustomer->delete();
        $this->actingAs(User::factory()->create(['role' => 'manager']));

        $this->delete(route('customers.destroy', $customer))->assertForbidden();
        $this->post(route('customers.restore', $deletedCustomer->id))->assertForbidden();

        $this->assertModelExists($customer);
        $this->assertSoftDeleted($deletedCustomer);

        $this->actingAs(User::factory()->create(['role' => 'owner']))
            ->get(route('customers.index'))
            ->assertOk()
            ->assertSee('Delete')
            ->assertSee('Recycle Bin')
            ->assertSee('Archived Market');
    }

    public function test_credit_checkout_requires_a_regular_customer(): void
    {
        $seller = User::factory()->create(['role' => 'manager']);
        $salesManager = SalesManager::query()->create(['name' => 'Checkout Manager']);
        $product = Product::query()->create([
            'barcode' => '899100000090',
            'name' => 'Frozen Fish',
            'price' => 1000,
            'unit_cost' => 700,
            'stock' => 1,
        ]);
        $this->actingAs($seller);
        $this->postJson(route('cart.items.add'), ['barcode' => $product->barcode])->assertOk();

        $this->postJson(route('sales.store'), [
            'sales_manager_id' => $salesManager->id,
            'payment_method' => 'credit',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('customer_id');

        $this->assertDatabaseCount('sales', 0);
        $this->assertSame(1, $product->fresh()->stock);
    }

    public function test_credit_checkout_records_the_full_customer_balance(): void
    {
        $seller = User::factory()->create(['role' => 'manager']);
        $salesManager = SalesManager::query()->create(['name' => 'Checkout Manager']);
        $customer = Customer::query()->create([
            'name' => 'North Market',
            'phone' => '08123456789',
        ]);
        $product = Product::query()->create([
            'barcode' => '899100000091',
            'name' => 'Frozen Dumplings',
            'price' => 18000,
            'unit_cost' => 12000,
            'stock' => 2,
        ]);
        $this->actingAs($seller);
        $this->postJson(route('cart.items.add'), ['barcode' => $product->barcode])->assertOk();

        $this->postJson(route('sales.store'), [
            'sales_manager_id' => $salesManager->id,
            'customer_id' => $customer->id,
            'payment_method' => 'credit',
        ])
            ->assertOk()
            ->assertJsonPath('payment_method', 'credit')
            ->assertJsonPath('customer_name', 'North Market')
            ->assertJsonPath('balance_due', 18000)
            ->assertJsonPath('cash_received', 0)
            ->assertJsonPath('change_due', 0);

        $this->assertDatabaseHas('sales', [
            'customer_id' => $customer->id,
            'payment_method' => 'credit',
            'total' => 18000,
            'cash_received' => 0,
            'change_due' => 0,
        ]);
        $this->assertSame(1, $product->fresh()->stock);
    }

    public function test_manager_can_record_partial_and_full_debt_payments(): void
    {
        $manager = User::factory()->create(['role' => 'manager']);
        $customer = Customer::query()->create(['name' => 'North Market']);
        $sale = $this->createCreditSale($customer, $manager);
        $this->actingAs($manager);

        $this->post(route('customers.payments.store', $customer), [
            'sale_id' => $sale->id,
            'amount' => 300,
            'payment_method' => 'cash',
        ])->assertRedirect(route('customers.show', $customer));

        $this->assertDatabaseHas('customer_payments', [
            'customer_id' => $customer->id,
            'sale_id' => $sale->id,
            'user_id' => $manager->id,
            'amount' => 300,
            'payment_method' => 'cash',
        ]);

        $this->post(route('customers.payments.store', $customer), [
            'sale_id' => $sale->id,
            'amount' => 700,
            'payment_method' => 'gpay',
        ])->assertRedirect(route('customers.show', $customer));

        $this->assertDatabaseCount('customer_payments', 2);
        $this->get(route('customers.show', $customer))
            ->assertOk()
            ->assertSee('BALANCE DUE')
            ->assertSee('₹0')
            ->assertSee('Payment history');
    }

    public function test_customer_and_sales_lists_show_remaining_credit_balances(): void
    {
        $this->travelTo('2026-11-09 15:13:59+05:30');
        $manager = User::factory()->create(['role' => 'manager']);
        $customer = Customer::query()->create(['name' => 'North Market']);
        $sale = $this->createCreditSale($customer, $manager);
        SaleItem::query()->create([
            'sale_id' => $sale->id,
            'product_id' => Product::query()->create([
                'barcode' => '899100000099',
                'name' => 'Frozen Dumplings',
                'price' => 1000,
                'unit_cost' => 700,
                'stock' => 3,
            ])->id,
            'product_name' => 'Frozen Dumplings',
            'barcode' => '899100000099',
            'quantity' => 2,
            'unit_price' => 500,
            'unit_cost' => 350,
            'line_total' => 1000,
        ]);
        CustomerPayment::query()->create([
            'customer_id' => $customer->id,
            'sale_id' => $sale->id,
            'user_id' => $manager->id,
            'amount' => 250,
            'payment_method' => 'cash',
        ]);
        $this->actingAs($manager);

        $this->get(route('customers.index'))
            ->assertOk()
            ->assertSee('North Market')
            ->assertSee('RECENT PURCHASES')
            ->assertSee('09 Nov 2026, 03:13 pm')
            ->assertDontSee('IST')
            ->assertSee('Frozen Dumplings × 2')
            ->assertSee('₹1,000')
            ->assertSee('₹250')
            ->assertSee('₹750');

        $this->get(route('sales.index'))
            ->assertOk()
            ->assertSee('North Market')
            ->assertSee('Credit')
            ->assertSee('Due ₹750');
    }

    public function test_debt_payment_rejects_amounts_above_the_remaining_balance(): void
    {
        $manager = User::factory()->create(['role' => 'manager']);
        $customer = Customer::query()->create(['name' => 'North Market']);
        $sale = $this->createCreditSale($customer, $manager);
        $this->actingAs($manager);

        $this->from(route('customers.show', $customer))
            ->post(route('customers.payments.store', $customer), [
                'sale_id' => $sale->id,
                'amount' => 1001,
                'payment_method' => 'cash',
            ])
            ->assertRedirect(route('customers.show', $customer))
            ->assertSessionHasErrors('amount');

        $this->assertDatabaseCount('customer_payments', 0);
    }

    public function test_debt_payment_cannot_be_applied_to_another_customers_sale(): void
    {
        $manager = User::factory()->create(['role' => 'manager']);
        $customer = Customer::query()->create(['name' => 'North Market']);
        $otherCustomer = Customer::query()->create(['name' => 'South Market']);
        $sale = $this->createCreditSale($customer, $manager);
        $this->actingAs($manager);

        $this->from(route('customers.show', $otherCustomer))
            ->post(route('customers.payments.store', $otherCustomer), [
                'sale_id' => $sale->id,
                'amount' => 100,
                'payment_method' => 'cash',
            ])
            ->assertRedirect(route('customers.show', $otherCustomer))
            ->assertSessionHasErrors('sale_id');

        $this->assertDatabaseCount('customer_payments', 0);
    }

    public function test_customer_records_redirect_unauthenticated_users_to_login(): void
    {
        $customer = Customer::query()->create(['name' => 'North Market']);

        $this->get(route('customers.index'))->assertRedirect(route('login'));
        $this->get(route('customers.show', $customer))->assertRedirect(route('login'));
        $this->get(route('customers.edit', $customer))->assertRedirect(route('login'));
        $this->put(route('customers.update', $customer), ['name' => 'Changed'])->assertRedirect(route('login'));
        $this->delete(route('customers.destroy', $customer))->assertRedirect(route('login'));
        $this->post(route('customers.restore', $customer->id))->assertRedirect(route('login'));
        $this->getJson(route('customers.search', ['q' => 'North']))->assertUnauthorized();
        $this->postJson(route('customers.store'), ['name' => 'New customer'])->assertUnauthorized();
    }

    public function test_existing_named_sales_are_backfilled_to_regular_customers(): void
    {
        $seller = User::factory()->create(['role' => 'manager']);
        $salesManager = SalesManager::query()->create(['name' => 'Checkout Manager']);
        $sale = Sale::query()->create([
            'user_id' => $seller->id,
            'sales_manager_id' => $salesManager->id,
            'sales_manager_name' => $salesManager->name,
            'sold_by_name' => $seller->name,
            'customer_name' => 'Legacy Shop',
            'customer_phone' => '08123456789',
            'customer_address' => 'Jakarta',
            'payment_method' => 'cash',
            'total' => 1000,
            'cash_received' => 1000,
            'change_due' => 0,
        ]);
        $migration = require database_path('migrations/2026_11_09_090325_create_customers_and_customer_payments_tables.php');
        $migration->down();

        $migration->up();
        $softDeletesMigration = require database_path('migrations/2026_11_09_221204_add_soft_deletes_to_customers_table.php');
        $softDeletesMigration->up();

        $customer = Customer::query()->where('name', 'Legacy Shop')->firstOrFail();
        $this->assertSame($customer->id, $sale->fresh()->customer_id);
        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'phone' => '08123456789',
            'address' => 'Jakarta',
        ]);
    }

    private function createCreditSale(Customer $customer, User $seller): Sale
    {
        $salesManager = SalesManager::query()->create(['name' => 'Checkout Manager']);

        return Sale::query()->create([
            'user_id' => $seller->id,
            'sales_manager_id' => $salesManager->id,
            'sales_manager_name' => $salesManager->name,
            'sold_by_name' => $seller->name,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'customer_phone' => $customer->phone,
            'customer_address' => $customer->address,
            'payment_method' => 'credit',
            'total' => 1000,
            'cash_received' => 0,
            'change_due' => 0,
        ]);
    }
}
