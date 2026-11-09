<?php

namespace Tests\Feature;

use App\Models\SalesManager;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesManagerManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_manage_sales_manager_names_available_at_checkout(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner);

        $this->post(route('sales-managers.store'), ['name' => '  Ana  '])
            ->assertRedirect(route('sales-managers.index'))
            ->assertSessionHas('success', 'Sales manager added.');

        $manager = SalesManager::query()->where('name', 'Ana')->firstOrFail();
        $this->assertTrue($manager->active);

        $this->get(route('pos.index'))
            ->assertOk()
            ->assertSee('Ana')
            ->assertSee('Choose a sales manager');

        $this->patch(route('sales-managers.update', $manager), [
            'name' => 'Ana Putri',
            'active' => '0',
        ])->assertRedirect(route('sales-managers.index'));

        $this->assertDatabaseHas('sales_managers', [
            'id' => $manager->id,
            'name' => 'Ana Putri',
            'active' => false,
        ]);

        $this->get(route('pos.index'))
            ->assertOk()
            ->assertDontSee('Ana Putri');
    }

    public function test_sales_manager_names_must_be_unique(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        SalesManager::query()->create(['name' => 'Ana']);

        $this->actingAs($owner)
            ->from(route('sales-managers.index'))
            ->post(route('sales-managers.store'), ['name' => 'Ana'])
            ->assertRedirect(route('sales-managers.index'))
            ->assertSessionHasErrors('name');

        $this->assertDatabaseCount('sales_managers', 1);
    }

    public function test_manager_accounts_cannot_manage_sales_manager_names(): void
    {
        $manager = User::factory()->create(['role' => 'manager']);

        $this->actingAs($manager)
            ->get(route('sales-managers.index'))
            ->assertForbidden();

        $this->post(route('sales-managers.store'), ['name' => 'Unauthorized Manager'])
            ->assertForbidden();

        $this->assertDatabaseMissing('sales_managers', ['name' => 'Unauthorized Manager']);
    }
}
