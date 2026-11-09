@extends('layouts.app')

@section('title', 'Set up owner account')

@section('content')
<section class="login-wrap">
    <div class="panel login-panel">
        <div class="login-emblem">PF</div>
        <p class="eyebrow">ONE-TIME STORE SETUP</p>
        <h1>Create the owner account</h1>
        <p class="subheading">This first account will have owner access. After it is created, this setup page is closed.</p>

        <form action="{{ route('owner.setup.store') }}" method="POST" class="login-form">
            @csrf
            <div class="form-field">
                <label for="name">OWNER NAME</label>
                <input id="name" name="name" type="text" value="{{ old('name') }}" maxlength="255" autocomplete="name" required autofocus>
                @error('name') <span class="field-error">{{ $message }}</span> @enderror
            </div>
            <div class="form-field">
                <label for="email">EMAIL ADDRESS</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" maxlength="255" autocomplete="email" required>
                @error('email') <span class="field-error">{{ $message }}</span> @enderror
            </div>
            <div class="form-field">
                <label for="password">PASSWORD</label>
                <input id="password" name="password" type="password" minlength="12" autocomplete="new-password" required>
                <span class="field-hint">Use a unique password with at least 12 characters.</span>
                @error('password') <span class="field-error">{{ $message }}</span> @enderror
            </div>
            <div class="form-field">
                <label for="password-confirmation">CONFIRM PASSWORD</label>
                <input id="password-confirmation" name="password_confirmation" type="password" minlength="12" autocomplete="new-password" required>
            </div>
            <button type="submit" class="button button-primary login-button">Create owner account</button>
        </form>
        <div class="setup-prompt">
            <a href="{{ route('login') }}">Back to sign in</a>
        </div>
    </div>
</section>
@endsection
