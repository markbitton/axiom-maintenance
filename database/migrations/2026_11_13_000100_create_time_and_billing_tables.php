<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->string('office_address')->nullable()->after('timezone');
            $table->decimal('office_lat', 10, 7)->nullable()->after('office_address');
            $table->decimal('office_lng', 10, 7)->nullable()->after('office_lat');
        });

        Schema::table('properties', function (Blueprint $table) {
            // Axiom Property Management jobs count drive time toward a tech's utilization.
            $table->boolean('managed_by_axiom')->default(true)->after('lng');
            $table->string('bill_to')->nullable()->after('managed_by_axiom');
        });

        // Work types: default hourly rate and service call fee. Licensed trades usually cost more.
        Schema::table('skills', function (Blueprint $table) {
            $table->decimal('hourly_rate', 8, 2)->default(0)->after('name');
            $table->decimal('service_call_fee', 8, 2)->default(0)->after('hourly_rate');
            $table->boolean('licensed')->default(false)->after('service_call_fee');
        });

        Schema::table('technicians', function (Blueprint $table) {
            $table->decimal('hourly_rate', 8, 2)->nullable()->after('max_jobs_per_day');
        });

        // A tech's rate for one kind of work (overrides their own rate and the trade's default).
        Schema::table('skill_technician', function (Blueprint $table) {
            $table->decimal('hourly_rate', 8, 2)->nullable()->after('level');
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->unique();
            $table->foreignId('work_order_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('draft'); // draft | sent | paid | void
            $table->string('bill_to')->nullable();
            $table->decimal('labor_total', 10, 2)->default(0);
            $table->decimal('travel_total', 10, 2)->default(0);
            $table->decimal('parts_total', 10, 2)->default(0);
            $table->decimal('fees_total', 10, 2)->default(0);
            $table->decimal('total', 10, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        // Clock entries. shift = employee clock; travel / work / break happen inside a shift.
        Schema::create('time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('technician_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_order_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type'); // shift | travel | work | break
            $table->dateTime('started_at');
            $table->dateTime('ended_at')->nullable();
            $table->decimal('start_lat', 10, 7)->nullable();
            $table->decimal('start_lng', 10, 7)->nullable();
            $table->decimal('end_lat', 10, 7)->nullable();
            $table->decimal('end_lng', 10, 7)->nullable();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->string('notes')->nullable();
            $table->timestamps();
            $table->index(['technician_id', 'started_at']);
            $table->index(['work_order_id', 'type']);
        });

        Schema::create('location_pings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('technician_id')->constrained()->cascadeOnDelete();
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->unsignedInteger('accuracy_m')->nullable();
            $table->string('reason')->default('interval'); // interval | clock_in | clock_out | job
            $table->dateTime('recorded_at');
            $table->timestamps();
            $table->index(['technician_id', 'recorded_at']);
        });

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('kind'); // labor | travel | part | fee
            $table->string('description');
            $table->decimal('quantity', 8, 2)->default(1);
            $table->decimal('unit_price', 10, 2)->default(0);
            $table->decimal('amount', 10, 2)->default(0);
            $table->foreignId('time_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('work_order_part_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('work_order_parts', function (Blueprint $table) {
            $table->foreignId('invoice_id')->nullable()->after('stock_location_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('work_order_parts', fn (Blueprint $t) => $t->dropConstrainedForeignId('invoice_id'));
        foreach (['invoice_lines', 'location_pings', 'time_entries', 'invoices', 'settings'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('skill_technician', fn (Blueprint $t) => $t->dropColumn('hourly_rate'));
        Schema::table('technicians', fn (Blueprint $t) => $t->dropColumn('hourly_rate'));
        Schema::table('skills', fn (Blueprint $t) => $t->dropColumn(['hourly_rate', 'service_call_fee', 'licensed']));
        Schema::table('properties', fn (Blueprint $t) => $t->dropColumn(['managed_by_axiom', 'bill_to']));
        Schema::table('locations', fn (Blueprint $t) => $t->dropColumn(['office_address', 'office_lat', 'office_lng']));
    }
};
