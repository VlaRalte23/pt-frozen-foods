@extends('layouts.app')

@section('title', 'Sales Managers')

@section('content')
<section class="page-heading">
    <div>
        <p class="eyebrow">OWNER SETTINGS</p>
        <h1>Sales Managers</h1>
        <p class="subheading">Manage the names available in the checkout dropdown. These names do not need login accounts.</p>
    </div>
</section>

@if (session('success'))
    <div class="notice notice-success" role="status">{{ session('success') }}</div>
@endif
@if ($errors->any())
    <div class="notice" role="alert">{{ $errors->first() }}</div>
@endif

<div class="users-layout">
    <section class="panel user-form-panel">
        <div class="panel-heading">
            <div class="step-number">+</div>
            <div>
                <h2>Add a sales manager</h2>
                <p>Add a name for the checkout list</p>
            </div>
        </div>
        <form action="{{ route('sales-managers.store') }}" method="POST" autocomplete="off">
            @csrf
            <div class="form-field">
                <label for="sales-manager-name">FULL NAME</label>
                <input id="sales-manager-name" name="name" type="text" value="{{ old('name') }}" maxlength="255" required>
                @error('name') <span class="field-error">{{ $message }}</span> @enderror
            </div>
            <button type="submit" class="button button-primary save-user-button">Add name</button>
        </form>
    </section>

    <section class="panel users-list-panel">
        <div class="inventory-heading">
            <div>
                <h2>Manager names</h2>
                <p>{{ $salesManagers->count() }} {{ \Illuminate\Support\Str::plural('name', $salesManagers->count()) }}</p>
            </div>
        </div>
        @if ($salesManagers->isEmpty())
            <div class="empty-inventory">
                <strong>No sales managers yet</strong>
                <span>Add the first name to make it available at checkout.</span>
            </div>
        @else
            <div class="table-wrap">
                <table class="inventory-table">
                    <thead><tr><th>MANAGER NAME AND STATUS</th></tr></thead>
                    <tbody>
                        @foreach ($salesManagers as $salesManager)
                            <tr>
                                <td>
                                    <form class="sales-manager-edit" action="{{ route('sales-managers.update', $salesManager) }}" method="POST">
                                        @csrf
                                        @method('PATCH')
                                        <input class="sales-manager-name" aria-label="Sales manager name" name="name" type="text" value="{{ $salesManager->name }}" maxlength="255" required>
                                        <input type="hidden" name="active" value="0">
                                        <label class="sales-manager-active">
                                            <input name="active" type="checkbox" value="1" @checked($salesManager->active)>
                                            Active
                                        </label>
                                        <button class="button button-secondary" type="submit">Save</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
@endsection
