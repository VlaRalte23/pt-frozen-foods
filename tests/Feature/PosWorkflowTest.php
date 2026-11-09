<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalesManager;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PosWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private SalesManager $salesManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['role' => 'manager']));
        $this->salesManager = SalesManager::query()->create(['name' => 'Checkout Manager']);
    }

    public function test_the_stock_screen_loads(): void
    {
        $this->get(route('products.index'))
            ->assertOk()
            ->assertSee('Stock room');
    }

    public function test_manager_can_view_sales_list_with_customer_products_and_seller(): void
    {
        $seller = User::factory()->create(['name' => 'Store Manager Ana', 'role' => 'manager']);
        $product = Product::query()->create([
            'barcode' => '899100000030',
            'name' => 'Frozen Chicken Breast',
            'price' => 32000,
            'unit_cost' => 22000,
            'stock' => 4,
        ]);
        $salesManager = SalesManager::query()->create(['name' => 'Store Manager Ana']);
        $this->actingAs($seller);
        $this->postJson('/cart/items', ['barcode' => $product->barcode])->assertOk();

        $this->postJson('/sales', [
            'sales_manager_id' => $salesManager->id,
            'cash_received' => 32000,
            'customer_name' => 'Fresh Foods Store',
            'customer_phone' => '081234567890',
            'customer_address' => 'Jakarta',
        ])
            ->assertOk()
            ->assertJsonPath('sales_manager_name', 'Store Manager Ana');

        $this->assertDatabaseHas('sales', [
            'user_id' => $seller->id,
            'sales_manager_id' => $salesManager->id,
            'sales_manager_name' => 'Store Manager Ana',
            'sold_by_name' => 'Store Manager Ana',
            'customer_name' => 'Fresh Foods Store',
        ]);

        $this->get(route('sales.index'))
            ->assertOk()
            ->assertSee('Sales List')
            ->assertSee('Fresh Foods Store')
            ->assertSee('Frozen Chicken Breast')
            ->assertSee('× 1')
            ->assertSee('Store Manager Ana')
            ->assertSee('Manager')
            ->assertSee('₹32,000');
    }

    public function test_sales_list_keeps_sales_manager_and_account_names_recorded_at_sale_time(): void
    {
        $seller = User::factory()->create(['name' => 'Manager Before Rename', 'role' => 'manager']);
        $product = Product::query()->create([
            'barcode' => '899100000031',
            'name' => 'Frozen Fish',
            'price' => 24000,
            'unit_cost' => 16000,
            'stock' => 1,
        ]);
        $salesManager = SalesManager::query()->create(['name' => 'Assigned Manager']);
        $this->actingAs($seller);
        $this->postJson('/cart/items', ['barcode' => $product->barcode])->assertOk();
        $this->postJson('/sales', [
            'sales_manager_id' => $salesManager->id,
            'cash_received' => 24000,
            'customer_name' => 'Market Store',
            'customer_phone' => '081234567890',
            'customer_address' => 'Jakarta',
        ])->assertOk();

        $seller->update(['name' => 'Manager After Rename']);

        $this->get(route('sales.index'))
            ->assertOk()
            ->assertSee('Assigned Manager')
            ->assertSee('Recorded by Manager Before Rename')
            ->assertDontSee('Recorded by Manager After Rename');
    }

    public function test_sales_list_is_available_to_the_owner(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']))
            ->get(route('sales.index'))
            ->assertOk()
            ->assertSee('Completed sales');
    }

    public function test_the_checkout_displays_the_rupee_symbol_for_zero_totals(): void
    {
        $this->get(route('pos.index'))
            ->assertOk()
            ->assertSee('Asia/Kolkata')
            ->assertSee('aria-label="Current time in New Delhi"', false)
            ->assertSee('id="sell-page-time"', false)
            ->assertSee('₹0')
            ->assertSee('Customer')
            ->assertSee('optional')
            ->assertSee('REGULAR CUSTOMER')
            ->assertSee('SALES MANAGER')
            ->assertSee('Print receipt')
            ->assertSee('Price / unit')
            ->assertDontSee('Rp0');
    }

    public function test_sell_page_displays_the_new_delhi_clock_in_24_hour_format(): void
    {
        $this->travelTo('2026-11-09 15:05:07+05:30');

        $this->get(route('pos.index'))
            ->assertOk()
            ->assertSee('Mon, 09 Nov')
            ->assertSee('15:05:07', false)
            ->assertSee('IST');
    }

    public function test_checkout_offers_cash_gpay_and_bank_transfer(): void
    {
        $this->get(route('pos.index'))
            ->assertOk()
            ->assertSee('PAYMENT METHOD')
            ->assertSee('SALES MANAGER')
            ->assertSee('Checkout Manager')
            ->assertSee('Cash')
            ->assertSee('GPay')
            ->assertSee('Bank transfer')
            ->assertSee('Credit / pay later');
    }

    public function test_cash_checkout_rejects_a_missing_cash_received_amount(): void
    {
        $product = Product::query()->create([
            'barcode' => '899100000034',
            'name' => 'Frozen Chicken',
            'price' => 18000,
            'unit_cost' => 12000,
            'stock' => 2,
        ]);
        $this->postJson(route('cart.items.add'), ['barcode' => $product->barcode])->assertOk();

        $this->postJson(route('sales.store'), [
            'sales_manager_id' => $this->salesManager->id,
            'payment_method' => 'cash',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'cash_received' => 'Enter the amount received in cash.',
            ]);

        $this->assertDatabaseCount('sales', 0);
        $this->assertSame(2, $product->fresh()->stock);
    }

    public function test_products_can_be_searched_by_name_or_barcode_for_checkout(): void
    {
        Product::query()->create([
            'barcode' => '899100000020',
            'name' => 'Frozen Chicken Breast',
            'price' => 32000,
            'unit_cost' => 22000,
            'stock' => 6,
            'quantity_unit' => 'kg',
        ]);
        Product::query()->create([
            'barcode' => '899100000021',
            'name' => 'Frozen Chicken Wings',
            'price' => 28000,
            'unit_cost' => 18000,
            'stock' => 3,
        ]);

        $this->getJson(route('cart.products.search', ['q' => 'Chicken']))
            ->assertOk()
            ->assertJsonCount(2, 'products')
            ->assertJsonPath('products.0.name', 'Frozen Chicken Breast')
            ->assertJsonPath('products.0.quantity_unit', 'kg')
            ->assertJsonPath('products.1.name', 'Frozen Chicken Wings')
            ->assertJsonMissingPath('products.0.unit_cost');

        $this->getJson(route('cart.products.search', ['q' => '899100000020']))
            ->assertOk()
            ->assertJsonCount(1, 'products')
            ->assertJsonPath('products.0.barcode', '899100000020');
    }

    public function test_product_search_rejects_queries_that_are_too_short(): void
    {
        $this->getJson(route('cart.products.search', ['q' => 'a']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('q');
    }

    public function test_cart_and_receipt_include_the_product_unit_without_changing_whole_quantity(): void
    {
        $product = Product::query()->create([
            'barcode' => 'UNIT-KG-SALE',
            'name' => 'Frozen Fish',
            'price' => 18000,
            'unit_cost' => 12000,
            'stock' => 3,
            'quantity_unit' => 'kg',
        ]);

        $this->postJson(route('cart.items.add'), ['barcode' => $product->barcode])
            ->assertOk()
            ->assertJsonPath('cart.items.0.quantity', 1)
            ->assertJsonPath('cart.items.0.quantity_unit', 'kg');

        $this->checkout()
            ->assertOk()
            ->assertJsonPath('items.0.quantity', 1)
            ->assertJsonPath('items.0.quantity_unit', 'kg');

        $this->assertSame(2, $product->fresh()->stock);
    }

    public function test_stock_list_shows_low_stock_alerts_and_can_filter_inventory(): void
    {
        Product::query()->create([
            'barcode' => 'OUT-OF-STOCK',
            'name' => 'Frozen Out',
            'price' => 10000,
            'unit_cost' => 5000,
            'stock' => 0,
        ]);
        Product::query()->create([
            'barcode' => 'LOW-STOCK',
            'name' => 'Frozen Low',
            'price' => 10000,
            'unit_cost' => 5000,
            'stock' => 5,
        ]);
        Product::query()->create([
            'barcode' => 'HEALTHY-STOCK',
            'name' => 'Frozen Healthy',
            'price' => 10000,
            'unit_cost' => 5000,
            'stock' => 6,
        ]);

        $this->get(route('products.index'))
            ->assertOk()
            ->assertSee('2')
            ->assertSee('products need attention')
            ->assertSee('Frozen Out')
            ->assertSee('Frozen Low')
            ->assertSee('Frozen Healthy')
            ->assertSee('₹10,000')
            ->assertSee('LOW-STOCK ALERT AT')
            ->assertSee('Out of stock')
            ->assertSee('Low stock');

        $this->get(route('products.index', ['stock' => 'low']))
            ->assertOk()
            ->assertSee('Frozen Out')
            ->assertSee('Frozen Low')
            ->assertDontSee('Frozen Healthy');

        $this->get(route('products.index', ['search' => 'HEALTHY-STOCK']))
            ->assertOk()
            ->assertSee('Frozen Healthy')
            ->assertDontSee('Frozen Low')
            ->assertDontSee('Frozen Out');
    }

    public function test_a_product_uses_its_custom_low_stock_threshold_in_stock_alerts(): void
    {
        Product::query()->create([
            'barcode' => 'CUSTOM-LOW-STOCK',
            'name' => 'Frozen Custom Low',
            'price' => 10000,
            'unit_cost' => 5000,
            'stock' => 7,
            'low_stock_threshold' => 8,
        ]);
        Product::query()->create([
            'barcode' => 'CUSTOM-HEALTHY-STOCK',
            'name' => 'Frozen Custom Healthy',
            'price' => 10000,
            'unit_cost' => 5000,
            'stock' => 9,
            'low_stock_threshold' => 8,
        ]);

        $this->get(route('products.index'))
            ->assertOk()
            ->assertSee('Frozen Custom Low')
            ->assertSee('Frozen Custom Healthy')
            ->assertSee('product need attention')
            ->assertSee("running low (at or below each product's threshold)", false)
            ->assertSee('ALERT AT');

        $this->get(route('products.index', ['stock' => 'low']))
            ->assertOk()
            ->assertSee('Frozen Custom Low')
            ->assertDontSee('Frozen Custom Healthy');
    }

    public function test_stock_product_unit_can_be_saved_displayed_and_loaded_for_editing(): void
    {
        $this->post(route('products.store'), [
            'barcode' => 'UNIT-KG-001',
            'name' => 'Frozen Fish',
            'mrp' => 12000,
            'price' => 10000,
            'stock' => 7,
            'quantity_unit' => 'kg',
            'low_stock_threshold' => 2,
        ])->assertRedirect(route('products.index'));

        $this->assertDatabaseHas('products', [
            'barcode' => 'UNIT-KG-001',
            'stock' => 7,
            'quantity_unit' => 'kg',
        ]);

        $this->get(route('products.index'))
            ->assertOk()
            ->assertSee('7 kg')
            ->assertSee('2 kg')
            ->assertSee('₹12,000')
            ->assertSee('₹10,000')
            ->assertSee('Kilogram (kg)');

        $this->getJson(route('products.lookup', ['barcode' => 'UNIT-KG-001']))
            ->assertOk()
            ->assertJsonPath('product.mrp', 12000)
            ->assertJsonPath('product.quantity_unit', 'kg');
    }

    public function test_product_brand_can_be_created_suggested_searched_and_loaded_for_editing(): void
    {
        $this->post(route('products.store'), [
            'barcode' => 'BRAND-001',
            'name' => 'Frozen Fish',
            'brand' => 'Ocean Fresh',
            'mrp' => 12000,
            'price' => 10000,
            'stock' => 7,
            'quantity_unit' => 'piece',
            'low_stock_threshold' => 2,
        ])->assertRedirect(route('products.index'));

        $this->assertDatabaseHas('products', [
            'barcode' => 'BRAND-001',
            'brand' => 'Ocean Fresh',
        ]);

        $this->get(route('products.index'))
            ->assertOk()
            ->assertSee('BRAND / COMPANY')
            ->assertSee('Ocean Fresh')
            ->assertSee('Select or enter a brand');

        $this->get(route('products.index', ['search' => 'Ocean Fresh']))
            ->assertOk()
            ->assertSee('Frozen Fish');

        $this->getJson(route('products.lookup', ['barcode' => 'BRAND-001']))
            ->assertOk()
            ->assertJsonPath('product.brand', 'Ocean Fresh');
    }

    public function test_product_brand_is_optional_and_rejects_values_over_120_characters(): void
    {
        $this->post(route('products.store'), [
            'barcode' => 'BRAND-OPTIONAL',
            'name' => 'Plain Frozen Fish',
            'mrp' => 10000,
            'price' => 10000,
            'stock' => 7,
            'quantity_unit' => 'piece',
            'low_stock_threshold' => 2,
        ])->assertRedirect(route('products.index'));

        $this->assertDatabaseHas('products', [
            'barcode' => 'BRAND-OPTIONAL',
            'brand' => null,
        ]);

        $this->from(route('products.index'))
            ->post(route('products.store'), [
                'barcode' => 'BRAND-TOO-LONG',
                'name' => 'Frozen Fish',
                'brand' => str_repeat('B', 121),
                'mrp' => 10000,
                'price' => 10000,
                'stock' => 7,
                'quantity_unit' => 'piece',
                'low_stock_threshold' => 2,
            ])
            ->assertRedirect(route('products.index'))
            ->assertSessionHasErrors('brand');

        $this->assertDatabaseMissing('products', [
            'barcode' => 'BRAND-TOO-LONG',
        ]);
    }

    public function test_stock_product_rejects_an_unsupported_quantity_unit(): void
    {
        $this->from(route('products.index'))
            ->post(route('products.store'), [
                'barcode' => 'UNIT-INVALID-001',
                'name' => 'Frozen Fish',
                'mrp' => 10000,
                'price' => 10000,
                'stock' => 7,
                'quantity_unit' => 'crate',
                'low_stock_threshold' => 2,
            ])
            ->assertRedirect(route('products.index'))
            ->assertSessionHasErrors([
                'quantity_unit' => 'The selected quantity unit is invalid.',
            ]);

        $this->assertDatabaseMissing('products', [
            'barcode' => 'UNIT-INVALID-001',
        ]);
    }

    public function test_a_manager_can_add_a_product_by_barcode_and_scan_it_into_a_sale(): void
    {
        $this->post('/products', [
            'barcode' => '899100000001',
            'name' => 'Frozen Chicken',
            'mrp' => 30000,
            'price' => 25000,
            'stock' => 4,
            'quantity_unit' => 'piece',
            'low_stock_threshold' => 8,
            'unit_cost' => 5000,
        ])->assertRedirect(route('products.index'));

        $this->assertDatabaseHas('products', [
            'barcode' => '899100000001',
            'mrp' => 30000,
            'price' => 25000,
            'unit_cost' => null,
            'low_stock_threshold' => 8,
        ]);

        $this->actingAs(User::factory()->create(['role' => 'owner']));
        $this->post('/products', [
            'barcode' => '899100000001',
            'name' => 'Frozen Chicken',
            'mrp' => 32000,
            'price' => 25000,
            'stock' => 4,
            'quantity_unit' => 'piece',
            'low_stock_threshold' => 12,
            'unit_cost' => 10000,
        ])->assertRedirect(route('products.index'));

        $this->assertDatabaseHas('products', [
            'barcode' => '899100000001',
            'mrp' => 32000,
            'price' => 25000,
            'low_stock_threshold' => 12,
        ]);

        $this->actingAs(User::factory()->create(['role' => 'manager']));
        $this->postJson('/cart/items', ['barcode' => '899100000001'])
            ->assertOk()
            ->assertJsonPath('cart.count', 1)
            ->assertJsonPath('cart.total', 25000);

        $this->checkout([
            'customer_name' => 'Walk-in Customer',
            'customer_phone' => '081234567890',
            'customer_address' => 'Jakarta',
        ])
            ->assertOk()
            ->assertJsonPath('total', 25000)
            ->assertJsonPath('change_due', 0)
            ->assertJsonPath('items.0.name', 'Frozen Chicken')
            ->assertJsonPath('items.0.quantity', 1)
            ->assertJsonPath('items.0.unit_price', 25000)
            ->assertJsonPath('items.0.line_total', 25000)
            ->assertJsonPath('payment_method', 'cash');

        $this->assertDatabaseHas('products', [
            'barcode' => '899100000001',
            'stock' => 3,
        ]);
        $this->assertDatabaseHas('sale_items', [
            'product_name' => 'Frozen Chicken',
            'quantity' => 1,
            'unit_price' => 25000,
            'unit_cost' => 10000,
            'line_total' => 25000,
        ]);
        $this->assertSame(1, Sale::query()->count());
    }

    public function test_customer_details_are_saved_and_returned_with_the_receipt(): void
    {
        $product = Product::query()->create([
            'barcode' => '899100000012',
            'name' => 'Frozen Sausages',
            'price' => 22000,
            'unit_cost' => 15000,
            'stock' => 1,
        ]);
        $this->postJson('/cart/items', ['barcode' => $product->barcode])->assertOk();

        $this->checkout([
            'customer_name' => 'Fresh Foods Store',
            'customer_phone' => '+62 812 3456 7890',
            'customer_address' => 'Jl. Merdeka No. 10',
        ])
            ->assertOk()
            ->assertJsonPath('customer_name', 'Fresh Foods Store')
            ->assertJsonPath('customer_phone', '+62 812 3456 7890')
            ->assertJsonPath('customer_address', 'Jl. Merdeka No. 10');

        $this->assertDatabaseHas('sales', [
            'customer_name' => 'Fresh Foods Store',
            'customer_phone' => '+62 812 3456 7890',
            'customer_address' => 'Jl. Merdeka No. 10',
        ]);
    }

    public function test_receipt_returns_sale_time_as_a_timezone_aware_timestamp(): void
    {
        $this->travelTo('2026-11-05 09:00:00+05:30');
        $product = Product::query()->create([
            'barcode' => '899100000015',
            'name' => 'Frozen Vegetables',
            'price' => 16000,
            'unit_cost' => 10000,
            'stock' => 1,
        ]);
        $this->postJson('/cart/items', ['barcode' => $product->barcode])->assertOk();

        $this->checkout([
            'customer_name' => 'Walk-in Customer',
            'customer_phone' => '081234567890',
            'customer_address' => 'Jakarta',
        ])
            ->assertOk()
            ->assertJsonPath('sold_at', '2026-11-05T03:30:00+00:00');
    }

    public function test_checkout_allows_a_walk_in_sale_without_customer_details(): void
    {
        $product = Product::query()->create([
            'barcode' => '899100000014',
            'name' => 'Frozen Fish Fillet',
            'price' => 27000,
            'unit_cost' => 19000,
            'stock' => 1,
        ]);
        $this->postJson('/cart/items', ['barcode' => $product->barcode])->assertOk();

        $this->checkout()
            ->assertOk()
            ->assertJsonPath('customer_name', null)
            ->assertJsonPath('customer_phone', null)
            ->assertJsonPath('customer_address', null);

        $this->assertDatabaseHas('sales', [
            'customer_id' => null,
            'customer_name' => null,
            'payment_method' => 'cash',
        ]);
        $this->assertSame(0, $product->fresh()->stock);
    }

    public function test_checkout_requires_an_active_sales_manager_selection(): void
    {
        $product = Product::query()->create([
            'barcode' => '899100000032',
            'name' => 'Frozen Shrimp',
            'price' => 27000,
            'unit_cost' => 19000,
            'stock' => 1,
        ]);
        $this->postJson('/cart/items', ['barcode' => $product->barcode])->assertOk();

        $this->postJson('/sales', [
            'customer_name' => 'Walk-in Customer',
            'customer_phone' => '081234567890',
            'customer_address' => 'Jakarta',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sales_manager_id');

        $this->salesManager->update(['active' => false]);
        $this->checkout([
            'customer_name' => 'Walk-in Customer',
            'customer_phone' => '081234567890',
            'customer_address' => 'Jakarta',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sales_manager_id');

        $this->assertSame(0, Sale::query()->count());
        $this->assertSame(1, $product->fresh()->stock);
    }

    public function test_checkout_rejects_customer_details_that_exceed_the_allowed_length(): void
    {
        $product = Product::query()->create([
            'barcode' => '899100000013',
            'name' => 'Frozen Meatballs',
            'price' => 19000,
            'unit_cost' => 12000,
            'stock' => 1,
        ]);
        $this->postJson('/cart/items', ['barcode' => $product->barcode])->assertOk();

        $this->checkout([
            'customer_name' => str_repeat('A', 121),
            'customer_phone' => '081234567890',
            'customer_address' => 'Jakarta',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('customer_name');

        $this->assertSame(0, Sale::query()->count());
        $this->assertSame(1, $product->fresh()->stock);
    }

    public function test_scanning_an_unknown_barcode_suggests_adding_the_product(): void
    {
        $this->postJson('/cart/items', ['barcode' => '899100000002'])
            ->assertNotFound()
            ->assertJsonPath('add_product_url', route('products.index', ['barcode' => '899100000002']));
    }

    public function test_a_sale_cannot_exceed_available_stock(): void
    {
        $product = Product::query()->create([
            'barcode' => '899100000003',
            'name' => 'Frozen Dumplings',
            'price' => 18000,
            'unit_cost' => 12000,
            'stock' => 1,
        ]);

        $this->postJson('/cart/items', ['barcode' => $product->barcode])->assertOk();
        $this->postJson('/cart/items', ['barcode' => $product->barcode])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Only 1 in stock for Frozen Dumplings.');

        $this->checkout([
            'cash_received' => 10000,
            'customer_name' => 'Walk-in Customer',
            'customer_phone' => '081234567890',
            'customer_address' => 'Jakarta',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cash_received');

        $this->assertSame(0, Sale::query()->count());
        $this->assertSame(1, $product->fresh()->stock);
    }

    public function test_cart_quantity_can_be_incremented_and_decremented_from_two(): void
    {
        $product = Product::query()->create([
            'barcode' => '899100000033',
            'name' => 'Frozen Chicken',
            'price' => 18000,
            'unit_cost' => 12000,
            'stock' => 5,
        ]);
        $this->postJson('/cart/items', ['barcode' => $product->barcode])->assertOk();
        $this->postJson('/cart/items', ['barcode' => $product->barcode])
            ->assertOk()
            ->assertJsonPath('cart.items.0.quantity', 2);

        $this->patch(route('cart.items.update', $product), ['quantity' => 3], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('cart.items.0.quantity', 3);

        $this->patch(route('cart.items.update', $product), ['quantity' => 4], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('cart.items.0.quantity', 4);

        $this->patch(route('cart.items.update', $product), ['quantity' => 5], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('cart.items.0.quantity', 5);

        $this->patchJson(route('cart.items.update', $product), ['quantity' => 6])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Only 5 in stock for Frozen Chicken.');

        $this->patch(route('cart.items.update', $product), ['quantity' => 2], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('cart.items.0.quantity', 2);

        $this->patch(route('cart.items.update', $product), ['quantity' => 1], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('cart.items.0.quantity', 1);
    }

    public function test_custom_cart_price_is_used_for_checkout_and_profit_without_changing_product_price(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $product = Product::query()->create([
            'barcode' => 'CUSTOM-PRICE-001',
            'name' => 'Frozen Fish',
            'price' => 18000,
            'unit_cost' => 12000,
            'stock' => 2,
        ]);
        $this->postJson(route('cart.items.add'), ['barcode' => $product->barcode])->assertOk();

        $this->patchJson(route('cart.items.update', $product), ['price' => 15000])
            ->assertOk()
            ->assertJsonPath('cart.items.0.price', 15000)
            ->assertJsonPath('cart.items.0.catalog_price', 18000)
            ->assertJsonPath('cart.items.0.line_total', 15000)
            ->assertJsonPath('cart.total', 15000);

        $this->patchJson(route('cart.items.update', $product), ['price' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('price')
            ->assertSessionHas('cart_prices', [$product->id => 15000]);

        $this->deleteJson(route('cart.items.remove', $product))->assertOk();
        $this->postJson(route('cart.items.add'), ['barcode' => $product->barcode])
            ->assertOk()
            ->assertJsonPath('cart.items.0.price', 18000);
        $this->patchJson(route('cart.items.update', $product), ['price' => 15000])
            ->assertOk();

        $this->actingAs($owner);
        $this->checkout(['cash_received' => 15000])
            ->assertOk()
            ->assertJsonPath('total', 15000)
            ->assertJsonPath('items.0.unit_price', 15000)
            ->assertJsonPath('items.0.line_total', 15000)
            ->assertSessionMissing('cart_prices')
            ->assertSessionMissing('cart');

        $this->assertDatabaseHas('sale_items', [
            'product_name' => 'Frozen Fish',
            'unit_price' => 15000,
            'unit_cost' => 12000,
            'line_total' => 15000,
        ]);
        $this->assertDatabaseHas('sales', ['total' => 15000]);
        $this->assertSame(18000, $product->fresh()->price);
        $this->assertSame(1, $product->fresh()->stock);

        $this->get(route('profits.index'))
            ->assertOk()
            ->assertSee('₹3,000')
            ->assertViewHas('totals', fn (array $totals): bool => $totals['gross_profit'] === 3000
                && $totals['net_profit'] === 3000);
    }

    public function test_gpay_and_bank_transfer_sales_are_recorded_without_cash_change(): void
    {
        $product = Product::query()->create([
            'barcode' => '899100000010',
            'name' => 'Frozen Nuggets',
            'price' => 18000,
            'unit_cost' => 12000,
            'stock' => 2,
        ]);

        foreach (['gpay', 'bank_transfer'] as $paymentMethod) {
            $this->postJson('/cart/items', ['barcode' => $product->barcode])->assertOk();

            $this->checkout([
                'payment_method' => $paymentMethod,
                'customer_name' => 'Walk-in Customer',
                'customer_phone' => '081234567890',
                'customer_address' => 'Jakarta',
            ])
                ->assertOk()
                ->assertJsonPath('payment_method', $paymentMethod)
                ->assertJsonPath('change_due', 0);

            $this->assertDatabaseHas('sales', [
                'payment_method' => $paymentMethod,
                'total' => 18000,
                'cash_received' => 18000,
                'change_due' => 0,
            ]);
        }

        $this->assertSame(0, $product->fresh()->stock);
    }

    public function test_checkout_rejects_an_unsupported_payment_method(): void
    {
        $product = Product::query()->create([
            'barcode' => '899100000011',
            'name' => 'Frozen Fries',
            'price' => 15000,
            'unit_cost' => 10000,
            'stock' => 1,
        ]);

        $this->postJson('/cart/items', ['barcode' => $product->barcode])->assertOk();

        $this->checkout([
            'payment_method' => 'cheque',
            'customer_name' => 'Walk-in Customer',
            'customer_phone' => '081234567890',
            'customer_address' => 'Jakarta',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment_method');

        $this->assertSame(0, Sale::query()->count());
        $this->assertSame(1, $product->fresh()->stock);
    }

    public function test_scanning_an_existing_product_updates_its_details(): void
    {
        $product = Product::query()->create([
            'barcode' => '899100000004',
            'name' => 'Old Name',
            'mrp' => 10000,
            'price' => 10000,
            'unit_cost' => 6000,
            'stock' => 2,
        ]);

        $this->getJson(route('products.lookup', ['barcode' => $product->barcode]))
            ->assertOk()
            ->assertJsonPath('product.name', 'Old Name')
            ->assertJsonPath('product.mrp', 10000)
            ->assertJsonPath('product.quantity_unit', 'piece')
            ->assertJsonPath('product.low_stock_threshold', 5);

        $this->post('/products', [
            'barcode' => $product->barcode,
            'name' => 'New Name',
            'mrp' => 15000,
            'price' => 12500,
            'stock' => 8,
            'quantity_unit' => 'box',
            'low_stock_threshold' => 10,
            'unit_cost' => 1,
        ])->assertRedirect(route('products.index'));

        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'name' => 'New Name',
            'mrp' => 15000,
            'price' => 12500,
            'stock' => 8,
            'quantity_unit' => 'box',
            'low_stock_threshold' => 10,
            'unit_cost' => 6000,
        ]);

        $this->getJson(route('products.lookup', ['barcode' => $product->barcode]))
            ->assertOk()
            ->assertJsonPath('product.mrp', 15000)
            ->assertJsonPath('product.quantity_unit', 'box')
            ->assertJsonPath('product.low_stock_threshold', 10);
    }

    public function test_only_owners_can_see_profit_or_product_cost(): void
    {
        $product = Product::query()->create([
            'barcode' => '899100000005',
            'name' => 'Frozen Fish',
            'mrp' => 30000,
            'price' => 30000,
            'unit_cost' => 16000,
            'stock' => 5,
        ]);

        $this->get(route('profits.index'))->assertForbidden();
        $this->post(route('expenses.store'), [
            'category' => 'utilities',
            'description' => 'Electricity',
            'amount' => 10000,
            'spent_on' => now()->toDateString(),
        ])->assertForbidden();

        $this->getJson(route('products.lookup', ['barcode' => $product->barcode]))
            ->assertOk()
            ->assertJsonPath('product.mrp', 30000)
            ->assertJsonMissingPath('product.unit_cost');

        $this->get(route('products.index'))
            ->assertOk()
            ->assertSee('MRP')
            ->assertSee('SELLING PRICE')
            ->assertDontSee('PRODUCT COST');
    }

    public function test_owner_sees_gross_and_net_profit_in_separate_columns(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $product = Product::query()->create([
            'barcode' => '899100000006',
            'name' => 'Frozen Shrimp',
            'mrp' => 30000,
            'price' => 25000,
            'unit_cost' => 10000,
            'stock' => 5,
        ]);

        $this->actingAs($owner);
        $this->postJson('/cart/items', ['barcode' => $product->barcode])->assertOk();
        $this->checkout([
            'customer_name' => 'Walk-in Customer',
            'customer_phone' => '081234567890',
            'customer_address' => 'Jakarta',
        ])->assertOk();
        $this->assertDatabaseHas('sale_items', [
            'product_name' => 'Frozen Shrimp',
            'unit_price' => 25000,
            'unit_cost' => 10000,
            'line_total' => 25000,
        ]);
        $this->post(route('expenses.store'), [
            'category' => 'utilities',
            'description' => 'Electricity',
            'amount' => 5000,
            'spent_on' => now()->toDateString(),
        ])->assertRedirect(route('profits.index'));

        $this->get(route('profits.index'))
            ->assertOk()
            ->assertSee('GROSS PROFIT')
            ->assertSee('NET PROFIT')
            ->assertSee('₹15,000')
            ->assertSee('₹10,000')
            ->assertSee('Electricity');

        $this->get(route('products.index'))
            ->assertOk()
            ->assertSee('PRODUCT COST')
            ->assertSee('₹30,000')
            ->assertSee('₹25,000')
            ->assertSee('₹10,000');
    }

    public function test_owner_can_record_employee_wages_as_an_expense_category(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);

        $this->actingAs($owner)
            ->post(route('expenses.store'), [
                'category' => 'employee_wages',
                'description' => 'November staff wages',
                'amount' => 180000,
                'spent_on' => '2026-11-09',
            ])
            ->assertRedirect(route('profits.index'));

        $this->assertDatabaseHas('expenses', [
            'category' => 'employee_wages',
            'description' => 'November staff wages',
            'amount' => 180000,
        ]);

        $this->get(route('profits.index'))
            ->assertOk()
            ->assertSee('Employee wages')
            ->assertSee('November staff wages');
    }

    public function test_owner_cannot_record_an_expense_with_an_unknown_category(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);

        $this->actingAs($owner)
            ->from(route('profits.index'))
            ->post(route('expenses.store'), [
                'category' => 'unknown',
                'description' => 'November staff wages',
                'amount' => 180000,
                'spent_on' => '2026-11-09',
            ])
            ->assertRedirect(route('profits.index'))
            ->assertSessionHasErrors([
                'category' => 'The selected category is invalid.',
            ]);

        $this->assertDatabaseMissing('expenses', [
            'description' => 'November staff wages',
        ]);
    }

    public function test_owner_profit_page_displays_daily_and_monthly_sales_totals(): void
    {
        $this->travelTo('2026-11-09 12:00:00');
        $owner = User::factory()->create(['role' => 'owner']);
        $todaySale = Sale::query()->create([
            'total' => 25000,
            'cash_received' => 25000,
            'change_due' => 0,
        ]);
        $todaySale->created_at = '2026-11-09 10:00:00';
        $todaySale->save();
        $earlierThisMonthSale = Sale::query()->create([
            'total' => 12000,
            'cash_received' => 12000,
            'change_due' => 0,
        ]);
        $earlierThisMonthSale->created_at = '2026-11-02 10:00:00';
        $earlierThisMonthSale->save();
        $lastMonthSale = Sale::query()->create([
            'total' => 44000,
            'cash_received' => 44000,
            'change_due' => 0,
        ]);
        $lastMonthSale->created_at = '2026-10-31 23:59:59';
        $lastMonthSale->save();

        $response = $this->actingAs($owner)->get(route('profits.index'));

        $response->assertOk()
            ->assertSee('DAILY SALES')
            ->assertSee('MONTHLY SALES')
            ->assertViewHas('dailySales', 25000)
            ->assertViewHas('monthlySales', 37000)
            ->assertSee('₹25,000')
            ->assertSee('₹37,000');
    }

    public function test_owner_profit_chart_uses_the_selected_daily_date_range(): void
    {
        $this->travelTo('2026-11-05 12:00:00');
        $owner = User::factory()->create(['role' => 'owner']);
        $product = Product::query()->create([
            'barcode' => '899100000009',
            'name' => 'Frozen Squid',
            'price' => 30000,
            'unit_cost' => 18000,
            'stock' => 5,
        ]);
        $oldSale = Sale::query()->create([
            'receipt_number' => 'OLD-RANGE',
            'total' => 30000,
            'cash_received' => 30000,
            'change_due' => 0,
        ]);
        $oldSale->created_at = now()->subDays(10);
        $oldSale->save();
        $oldSale->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'barcode' => $product->barcode,
            'quantity' => 1,
            'unit_price' => 30000,
            'unit_cost' => 18000,
            'line_total' => 30000,
        ]);
        $recentSale = Sale::query()->create([
            'receipt_number' => 'RECENT-RANGE',
            'total' => 30000,
            'cash_received' => 30000,
            'change_due' => 0,
        ]);
        $recentSale->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'barcode' => $product->barcode,
            'quantity' => 1,
            'unit_price' => 30000,
            'unit_cost' => 18000,
            'line_total' => 30000,
        ]);

        $response = $this->actingAs($owner)->get(route('profits.index', ['range' => 7]));

        $response->assertOk()
            ->assertSee('Last 7 days')
            ->assertSee('id="profit-chart"', false)
            ->assertViewHas('range', 7)
            ->assertViewHas('daily', fn ($daily): bool => $daily->count() === 7)
            ->assertViewHas('totals', fn (array $totals): bool => $totals['sales'] === 30000)
            ->assertViewHas('chartData', fn ($chartData): bool => $chartData->count() === 7
                && $chartData->last()['gross'] === 12000
                && $chartData->last()['net'] === 12000);

        $this->get(route('profits.index', ['range' => 365]))
            ->assertRedirect()
            ->assertSessionHasErrors('range');
    }

    public function test_a_product_without_purchase_cost_cannot_be_sold(): void
    {
        Product::query()->create([
            'barcode' => '899100000007',
            'name' => 'New Frozen Product',
            'price' => 18000,
            'stock' => 3,
        ]);

        $this->postJson('/cart/items', ['barcode' => '899100000007'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This product needs a purchase cost before it can be sold. Ask the owner to update it in Stock.');
    }

    public function test_old_sales_without_costs_are_not_reported_as_profit(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $sale = Sale::query()->create([
            'receipt_number' => 'OLD-RECEIPT',
            'total' => 25000,
            'cash_received' => 25000,
            'change_due' => 0,
        ]);
        SaleItem::query()->create([
            'sale_id' => $sale->id,
            'product_id' => Product::query()->create([
                'barcode' => '899100000008',
                'name' => 'Historical Product',
                'price' => 25000,
                'stock' => 0,
            ])->id,
            'product_name' => 'Historical Product',
            'barcode' => '899100000008',
            'quantity' => 1,
            'unit_price' => 25000,
            'unit_cost' => null,
            'line_total' => 25000,
        ]);

        $this->actingAs($owner)
            ->get(route('profits.index'))
            ->assertOk()
            ->assertSee('Some older sales have no recorded product purchase cost.')
            ->assertSee('Unavailable');
    }

    /**
     * @param  array<string, int|string>  $values
     */
    private function checkout(array $values = []): TestResponse
    {
        $values = array_merge([
            'sales_manager_id' => $this->salesManager->id,
        ], $values);

        if (($values['payment_method'] ?? 'cash') === 'cash' && ! array_key_exists('cash_received', $values)) {
            $cart = session('cart', []);
            $values['cash_received'] = Product::query()
                ->whereKey(array_keys($cart))
                ->get()
                ->sum(fn (Product $product): int => $product->price * $cart[$product->id]);
        }

        return $this->postJson('/sales', $values);
    }
}
