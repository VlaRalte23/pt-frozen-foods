<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\ManagerPerformanceController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProfitController;
use App\Http\Controllers\SalesController;
use App\Http\Controllers\SalesManagerController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->middleware('throttle:5,1')->name('login.store');
    Route::get('/setup', [AuthController::class, 'setup'])->name('owner.setup');
    Route::post('/setup', [AuthController::class, 'storeInitialOwner'])->middleware('throttle:5,1')->name('owner.setup.store');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');

    Route::get('/', [PosController::class, 'index'])->name('pos.index');
    Route::get('/sales', [SalesController::class, 'index'])->name('sales.index');
    Route::get('/customers/search', [CustomerController::class, 'search'])->name('customers.search');
    Route::post('/customers', [CustomerController::class, 'store'])->name('customers.store');
    Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
    Route::get('/customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');
    Route::post('/customers/{customer}/payments', [CustomerController::class, 'recordPayment'])->name('customers.payments.store');
    Route::get('/cart/products', [PosController::class, 'searchProducts'])->name('cart.products.search');
    Route::post('/cart/items', [PosController::class, 'addItem'])->name('cart.items.add');
    Route::patch('/cart/items/{product}', [PosController::class, 'updateItem'])->name('cart.items.update');
    Route::delete('/cart/items/{product}', [PosController::class, 'removeItem'])->name('cart.items.remove');
    Route::post('/sales', [PosController::class, 'checkout'])->name('sales.store');

    Route::get('/products', [ProductController::class, 'index'])->name('products.index');
    Route::get('/products/lookup', [ProductController::class, 'lookup'])->name('products.lookup');
    Route::post('/products', [ProductController::class, 'store'])->name('products.store');

    Route::middleware('role:owner')->group(function () {
        Route::get('/sales-managers', [SalesManagerController::class, 'index'])->name('sales-managers.index');
        Route::post('/sales-managers', [SalesManagerController::class, 'store'])->name('sales-managers.store');
        Route::patch('/sales-managers/{salesManager}', [SalesManagerController::class, 'update'])->name('sales-managers.update');
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::get('/profits', [ProfitController::class, 'index'])->name('profits.index');
        Route::get('/manager-performance', [ManagerPerformanceController::class, 'index'])->name('manager-performance.index');
        Route::post('/expenses', [ProfitController::class, 'storeExpense'])->name('expenses.store');
    });
});
