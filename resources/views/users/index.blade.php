@extends('layouts.app')

@section('title', 'Manage users')

@section('content')
<section class="page-heading">
    <div>
        <p class="eyebrow">OWNER SETTINGS</p>
        <h1>Manage users</h1>
        <p class="subheading">Create accounts for owners or sales managers. Select checkout sales managers separately.</p>
    </div>
</section>

@if (session('success'))
    <div class="notice notice-success" role="status">{{ session('success') }}</div>
@endif

<div class="users-layout">
    <section class="panel user-form-panel">
        <div class="panel-heading">
            <div class="step-number">+</div>
            <div>
                <h2>Create a user account</h2>
                <p>Sales manager accounts can sell, review sales, and manage stock.</p>
            </div>
        </div>
        <form action="{{ route('users.store') }}" method="POST" autocomplete="off">
            @csrf
            <div class="form-field">
                <label for="user-name">FULL NAME</label>
                <input id="user-name" name="name" type="text" value="{{ old('name') }}" maxlength="255" autocomplete="name" required>
                @error('name') <span class="field-error">{{ $message }}</span> @enderror
            </div>
            <div class="form-field">
                <label for="user-email">EMAIL ADDRESS</label>
                <input id="user-email" name="email" type="email" value="{{ old('email') }}" maxlength="255" autocomplete="off" required>
                @error('email') <span class="field-error">{{ $message }}</span> @enderror
            </div>
            <div class="form-field">
                <label for="user-role">ACCOUNT TYPE</label>
                <select id="user-role" name="role" required>
                    <option value="sales_manager" @selected(old('role', 'sales_manager') === 'sales_manager')>Sales manager — sell, sales list, and stock</option>
                    <option value="owner" @selected(old('role') === 'owner')>Owner — full access</option>
                </select>
                @error('role') <span class="field-error">{{ $message }}</span> @enderror
            </div>
            <div class="form-field">
                <label for="user-password">TEMPORARY PASSWORD</label>
                <input id="user-password" name="password" type="password" minlength="12" autocomplete="new-password" required>
                <span class="field-hint">At least 12 characters. Share it privately; users can sign in with this password.</span>
                @error('password') <span class="field-error">{{ $message }}</span> @enderror
            </div>
            <div class="form-field">
                <label for="user-password-confirmation">CONFIRM PASSWORD</label>
                <input id="user-password-confirmation" name="password_confirmation" type="password" minlength="12" autocomplete="new-password" required>
            </div>
            <button type="submit" class="button button-primary save-user-button">Create user</button>
        </form>
    </section>

    <section class="panel users-list-panel">
        <div class="inventory-heading">
            <div>
                <h2>Store accounts</h2>
                <p>{{ $users->count() }} {{ \Illuminate\Support\Str::plural('account', $users->count()) }}</p>
            </div>
        </div>
        <div class="table-wrap">
            <table class="inventory-table">
                <thead><tr><th>NAME</th><th>EMAIL</th><th>ROLE</th></tr></thead>
                <tbody>
                    @foreach ($users as $user)
                        <tr>
                            <td><strong>{{ $user->name }}</strong>@if ($user->is(auth()->user())) <span class="you-badge">YOU</span>@endif</td>
                            <td>{{ $user->email }}</td>
                            <td>
                                <span class="role-badge {{ $user->isOwner() ? 'role-owner' : '' }}">
                                    {{ $user->isOwner() ? 'Owner' : ($user->isSalesManager() ? 'Sales manager' : 'Store manager') }}
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
</div>
@endsection
