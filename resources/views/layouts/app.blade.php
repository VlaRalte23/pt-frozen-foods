<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'PT Frozen Foods') · PT Frozen Foods</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <div class="app-shell">
        <header class="topbar">
            <a class="brand" href="{{ route('pos.index') }}" aria-label="PT Frozen Foods home">
                <span class="brand-mark">PF</span>
                <span class="brand-name">PT FROZEN <strong>FOODS</strong></span>
            </a>
            @auth
                <nav class="main-nav" aria-label="Main navigation">
                    <a class="{{ request()->routeIs('pos.*', 'cart.*') ? 'active' : '' }}" href="{{ route('pos.index') }}">Sell</a>
                    <a class="{{ request()->routeIs('sales.index') ? 'active' : '' }}" href="{{ route('sales.index') }}">Sales List</a>
                    @unless (auth()->user()->isSalesManager())
                        <a class="{{ request()->routeIs('customers.*') ? 'active' : '' }}" href="{{ route('customers.index') }}">Customers &amp; Debts</a>
                    @endunless
                    <a class="{{ request()->routeIs('products.*') ? 'active' : '' }}" href="{{ route('products.index') }}">Stock</a>
                    @if (auth()->user()->isOwner())
                        <a class="{{ request()->routeIs('profits.*') ? 'active' : '' }}" href="{{ route('profits.index') }}">Profit</a>
                        <a class="{{ request()->routeIs('manager-performance.*') ? 'active' : '' }}" href="{{ route('manager-performance.index') }}">Manager Performance</a>
                        <a class="{{ request()->routeIs('sales-managers.*') ? 'active' : '' }}" href="{{ route('sales-managers.index') }}">Sales Managers</a>
                        <a class="{{ request()->routeIs('users.*') ? 'active' : '' }}" href="{{ route('users.index') }}">Users</a>
                    @endif
                </nav>
                <div class="account-menu">
                    <span class="store-status"><span class="status-dot"></span> {{ auth()->user()->name }}</span>
                    <form action="{{ route('logout') }}" method="POST">
                        @csrf
                        <button class="logout-button" type="submit">Sign out</button>
                    </form>
                </div>
            @else
                <span class="store-status">Team sign in</span>
            @endauth
        </header>
        <main class="page-content">
            @yield('content')
        </main>
        <footer class="app-footer">PT Frozen Foods <span>·</span> Simple checkout, every day.</footer>
    </div>
</body>
</html>
