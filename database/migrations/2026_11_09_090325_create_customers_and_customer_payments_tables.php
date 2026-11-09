<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('phone', 30)->nullable();
            $table->text('address')->nullable();
            $table->timestamps();

            $table->index(['name', 'phone']);
        });

        Schema::table('sales', function (Blueprint $table): void {
            $table->foreignId('customer_id')->nullable()->after('sales_manager_name')->constrained()->restrictOnDelete();
        });

        Schema::create('customer_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('amount');
            $table->string('payment_method', 30);
            $table->timestamps();

            $table->index(['customer_id', 'created_at']);
            $table->index('sale_id');
        });

        DB::table('sales')
            ->whereNotNull('customer_name')
            ->where('customer_name', '!=', '')
            ->orderBy('id')
            ->chunkById(100, function ($sales): void {
                foreach ($sales as $sale) {
                    $customerQuery = DB::table('customers')->where('name', $sale->customer_name);

                    if ($sale->customer_phone === null || $sale->customer_phone === '') {
                        $customerQuery->whereNull('phone');
                    } else {
                        $customerQuery->where('phone', $sale->customer_phone);
                    }

                    $customerId = $customerQuery->value('id');

                    if (! $customerId) {
                        $customerId = DB::table('customers')->insertGetId([
                            'name' => $sale->customer_name,
                            'phone' => $sale->customer_phone,
                            'address' => $sale->customer_address,
                            'created_at' => $sale->created_at ?? now(),
                            'updated_at' => $sale->updated_at ?? now(),
                        ]);
                    }

                    DB::table('sales')->where('id', $sale->id)->update(['customer_id' => $customerId]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('customer_id');
        });

        Schema::dropIfExists('customer_payments');
        Schema::dropIfExists('customers');
    }
};
