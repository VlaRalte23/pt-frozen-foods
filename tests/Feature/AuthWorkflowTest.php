<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeding_does_not_create_a_default_login(): void
    {
        $this->seed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_store_screens_require_a_signed_in_user(): void
    {
        $this->get(route('pos.index'))->assertRedirect(route('login'));
        $this->get(route('sales.index'))->assertRedirect(route('login'));
        $this->get(route('products.index'))->assertRedirect(route('login'));
        $this->get(route('profits.index'))->assertRedirect(route('login'));
    }

    public function test_login_page_offers_one_time_owner_setup_only_before_an_account_exists(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Create the owner account');

        User::factory()->create(['role' => 'owner']);

        $this->get(route('login'))
            ->assertOk()
            ->assertDontSee('Create the owner account');
        $this->get(route('owner.setup'))->assertNotFound();
    }

    public function test_first_owner_can_be_created_from_setup_and_is_signed_in(): void
    {
        $response = $this->post(route('owner.setup.store'), [
            'name' => 'Store Owner',
            'email' => 'Owner@Example.Test',
            'password' => 'a-strong-owner-password',
            'password_confirmation' => 'a-strong-owner-password',
            'role' => 'manager',
        ]);

        $response->assertRedirect(route('pos.index'));
        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', [
            'name' => 'Store Owner',
            'email' => 'owner@example.test',
            'role' => 'owner',
            'is_initial_owner' => true,
        ]);
    }

    public function test_owner_setup_closes_after_the_first_account_and_rejects_bad_passwords(): void
    {
        $this->from(route('owner.setup'))
            ->post(route('owner.setup.store'), [
                'name' => 'Weak Owner',
                'email' => 'weak@example.test',
                'password' => 'short',
                'password_confirmation' => 'different',
            ])
            ->assertRedirect(route('owner.setup'))
            ->assertSessionHasErrors(['password']);

        $this->assertDatabaseCount('users', 0);

        User::factory()->create(['role' => 'manager']);

        $this->get(route('owner.setup'))->assertNotFound();
        $this->post(route('owner.setup.store'), [
            'name' => 'Second Owner',
            'email' => 'second@example.test',
            'password' => 'a-strong-owner-password',
            'password_confirmation' => 'a-strong-owner-password',
        ])->assertNotFound();
    }

    public function test_a_user_can_sign_in_and_sign_out(): void
    {
        $user = User::factory()->create([
            'email' => 'manager@example.test',
            'password' => 'a-strong-password',
            'role' => 'manager',
        ]);

        $this->post(route('login.store'), [
            'email' => 'manager@example.test',
            'password' => 'a-strong-password',
        ])->assertRedirect(route('pos.index'))
            ->assertSessionHas('_token');

        $this->assertAuthenticatedAs($user);

        $this->post(route('logout'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_invalid_credentials_do_not_sign_a_user_in(): void
    {
        User::factory()->create(['email' => 'owner@example.test']);

        $this->from(route('login'))
            ->post(route('login.store'), [
                'email' => 'owner@example.test',
                'password' => 'incorrect',
            ])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_owner_can_create_an_owner_account(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']))
            ->get(route('users.index'))
            ->assertOk()
            ->assertSee('Create an owner account')
            ->assertSee('Existing store manager accounts can still sign in.')
            ->assertDontSee('Store manager — sell and manage stock');

        $this->post(route('users.store'), [
            'name' => 'New Owner',
            'email' => 'new.owner@example.test',
            'role' => 'owner',
            'password' => 'owner-password-123',
            'password_confirmation' => 'owner-password-123',
        ])->assertRedirect(route('users.index'))
            ->assertSessionHas('success', 'User account created.');

        $user = User::query()->where('email', 'new.owner@example.test')->firstOrFail();
        $this->assertSame('owner', $user->role);
        $this->assertNotSame('owner-password-123', $user->password);
        $this->assertTrue(password_verify('owner-password-123', $user->password));
    }

    public function test_owner_cannot_create_new_manager_sign_in_accounts(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']))
            ->from(route('users.index'))
            ->post(route('users.store'), [
                'name' => 'Manager With Login',
                'email' => 'new.manager@example.test',
                'role' => 'manager',
                'password' => 'manager-password-123',
                'password_confirmation' => 'manager-password-123',
            ])
            ->assertRedirect(route('users.index'))
            ->assertSessionHasErrors('role');

        $this->assertDatabaseMissing('users', ['email' => 'new.manager@example.test']);
    }

    public function test_manager_cannot_open_user_management_or_create_users(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'manager']))
            ->get(route('users.index'))
            ->assertForbidden();

        $this->post(route('users.store'), [
            'name' => 'Unauthorized Owner',
            'email' => 'unauthorized@example.test',
            'role' => 'owner',
            'password' => 'manager-password-123',
            'password_confirmation' => 'manager-password-123',
        ])->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'unauthorized@example.test']);
    }

    public function test_user_creation_rejects_duplicate_email_invalid_role_and_short_password(): void
    {
        User::factory()->create(['email' => 'existing@example.test']);

        $this->actingAs(User::factory()->create(['role' => 'owner']))
            ->from(route('users.index'))
            ->post(route('users.store'), [
                'name' => 'Invalid User',
                'email' => 'Existing@Example.Test',
                'role' => 'admin',
                'password' => 'short',
                'password_confirmation' => 'different',
            ])
            ->assertRedirect(route('users.index'))
            ->assertSessionHasErrors(['email', 'role', 'password']);

        $this->assertDatabaseCount('users', 2);
    }
}
