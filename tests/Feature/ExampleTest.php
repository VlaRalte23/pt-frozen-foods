<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_application_returns_a_successful_response(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'manager']));

        $response = $this->get('/');

        $response->assertStatus(200);
    }
}
