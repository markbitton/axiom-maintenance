<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Stores, receipt photos, receipt lines, and the price paid for each part over time. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->json('aliases')->nullable(); // other spellings seen on receipts ("THE HOME DEPOT #1802")
            $table->string('address')->nullable();
            $table->timestamps();
        });

        Schema::create('receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('technician_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('purchased_on')->nullable();
            $table->decimal('subtotal', 10, 2)->nullable();
            $table->decimal('tax', 10, 2)->nullable();
            $table->decimal('total', 10, 2)->nullable();
            $table->string('photo_path')->nullable();
            $table->string('photo_type', 40)->nullable();
            $table->string('read_by', 20)->default('manual'); // manual | ai
            $table->json('raw')->nullable();
            $table->timestamps();
        });

        Schema::create('receipt_work_order', function (Blueprint $table) {
            $table->foreignId('receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_order_id')->constrained()->cascadeOnDelete();
            $table->primary(['receipt_id', 'work_order_id']);
        });

        Schema::create('receipt_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receipt_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->string('sku', 60)->nullable();
            $table->decimal('quantity', 8, 2)->default(1);
            $table->decimal('unit_price', 10, 2)->default(0);
            $table->decimal('amount', 10, 2)->default(0);
            $table->foreignId('part_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('work_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('work_order_part_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('part_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('part_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('unit_cost', 10, 2);
            $table->date('purchased_on');
            $table->foreignId('receipt_line_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['part_id', 'purchased_on']);
        });

        Schema::table('work_order_parts', function (Blueprint $table) {
            $table->decimal('unit_cost', 10, 2)->nullable()->after('quantity'); // what was actually paid, from a receipt
            $table->foreignId('vendor_id')->nullable()->after('unit_cost')->constrained()->nullOnDelete();
        });

        Schema::table('parts', function (Blueprint $table) {
            $table->string('vendor_sku', 60)->nullable()->after('sku');
        });
    }

    public function down(): void
    {
        Schema::table('parts', fn (Blueprint $t) => $t->dropColumn('vendor_sku'));
        Schema::table('work_order_parts', function (Blueprint $t) {
            $t->dropConstrainedForeignId('vendor_id');
            $t->dropColumn('unit_cost');
        });
        foreach (['part_prices', 'receipt_lines', 'receipt_work_order', 'receipts', 'vendors'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
