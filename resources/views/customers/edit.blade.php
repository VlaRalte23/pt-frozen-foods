@extends('layouts.app')

@section('title', 'Edit customer')

@section('content')
<section class="page-heading">
    <div>
        <p class="eyebrow">CUSTOMER ACCOUNT</p>
        <h1>Edit customer</h1>
        <p class="subheading">Update {{ $customer->name }}'s contact details.</p>
    </div>
    <a class="button button-secondary sales-new-button" href="{{ route('customers.index') }}">All customers</a>
</section>

@if ($errors->any())
    <div class="notice" role="alert">{{ $errors->first() }}</div>
@endif

<section class="panel customer-edit-panel">
    <form action="{{ route('customers.update', $customer) }}" method="POST">
        @csrf
        @method('PUT')
        <div class="form-field">
            <label for="customer-name">NAME / STORE NAME</label>
            <input id="customer-name" name="name" type="text" maxlength="120" value="{{ old('name', $customer->name) }}" required>
        </div>
        <div class="form-field">
            <label for="customer-phone">PHONE NUMBER</label>
            <input id="customer-phone" name="phone" type="tel" maxlength="30" value="{{ old('phone', $customer->phone) }}">
        </div>
        <div class="form-field">
            <label for="customer-address">ADDRESS</label>
            <input id="customer-address" name="address" type="text" maxlength="500" value="{{ old('address', $customer->address) }}">
        </div>
        <div class="customer-edit-actions">
            <button class="button button-primary" type="submit">Save changes</button>
            <a class="button button-secondary" href="{{ route('customers.index') }}">Cancel</a>
        </div>
    </form>
</section>
@endsection
