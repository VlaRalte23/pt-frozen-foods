<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedBigInteger('unit_cost')->nullable()->after('price');
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->unsignedBigInteger('unit_cost')->nullable()->after('unit_price');
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->string('description', 120);
            $table->unsignedBigInteger('amount');
            $table->date('spent_on');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');

        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropColumn('unit_cost');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('unit_cost');
        });
    }
};
