@extends('layouts.app')

@section('title', 'Sign in')

@section('content')
<section class="login-wrap">
    <div class="panel login-panel">
        <div class="login-emblem">PF</div>
        <p class="eyebrow">PT FROZEN FOODS</p>
        <h1>Welcome back</h1>
        <p class="subheading">Sign in to open the store POS.</p>

        <form action="{{ route('login.store') }}" method="POST" class="login-form">
            @csrf
            <div class="form-field">
                <label for="email">EMAIL</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus>
                @error('email') <span class="field-error">{{ $message }}</span> @enderror
            </div>
            <div class="form-field">
                <label for="password">PASSWORD</label>
                <input id="password" name="password" type="password" autocomplete="current-password" required>
                @error('password') <span class="field-error">{{ $message }}</span> @enderror
            </div>
            <button type="submit" class="button button-primary login-button">Sign in</button>
        </form>
        @if ($showOwnerSetup)
            <div class="setup-prompt">
                <span>First time setting up the store?</span>
                <a href="{{ route('owner.setup') }}">Create the owner account</a>
            </div>
        @endif
    </div>
</section>
@endsection
