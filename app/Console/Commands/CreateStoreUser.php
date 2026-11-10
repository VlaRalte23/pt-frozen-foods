<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class CreateStoreUser extends Command
{
    protected $signature = 'app:user:create';

    protected $description = 'Create a sales manager or owner login';

    public function handle(): int
    {
        $name = trim(text('Name'));
        $email = strtolower(trim(text('Email')));
        $role = select('Role', [
            'sales_manager' => 'Sales manager',
            'owner' => 'Owner',
        ]);
        $password = password('Password (at least 12 characters)');
        $confirmation = password('Confirm password');

        if ($name === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->components->error('Enter a name and a valid email address.');

            return self::FAILURE;
        }

        if (User::query()->where('email', $email)->exists()) {
            $this->components->error('A user with this email already exists.');

            return self::FAILURE;
        }

        if (strlen($password) < 12 || ! hash_equals($password, $confirmation)) {
            $this->components->error('Passwords must match and be at least 12 characters long.');

            return self::FAILURE;
        }

        User::query()->create([
            'name' => $name,
            'email' => $email,
            'role' => $role,
            'password' => Hash::make($password),
        ]);

        $this->components->info("Created {$role} account for {$email}.");

        return self::SUCCESS;
    }
}
