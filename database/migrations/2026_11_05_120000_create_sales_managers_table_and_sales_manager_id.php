<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_managers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('sales_manager_id')
                ->nullable()
                ->after('user_id')
                ->constrained()
                ->nullOnDelete();
            $table->string('sales_manager_name')->nullable()->after('sales_manager_id');
        });

        foreach (DB::table('users')->where('role', 'manager')->get(['id', 'name']) as $user) {
            DB::table('sales_managers')->insertOrIgnore([
                'name' => $user->name,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $salesManagerId = DB::table('sales_managers')->where('name', $user->name)->value('id');

            $historicalSales = DB::table('sales')
                ->where('user_id', $user->id)
                ->whereNull('sales_manager_id')
                ->get(['id', 'sold_by_name']);

            foreach ($historicalSales as $sale) {
                DB::table('sales')->where('id', $sale->id)->update([
                    'sales_manager_id' => $salesManagerId,
                    'sales_manager_name' => $sale->sold_by_name ?? $user->name,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('sales_manager_name');
            $table->dropConstrainedForeignId('sales_manager_id');
        });

        Schema::dropIfExists('sales_managers');
    }
};
