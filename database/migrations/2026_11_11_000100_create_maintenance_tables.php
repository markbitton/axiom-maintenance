<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rent Manager locations (Idaho = 1, Arizona = 2, Utah = 9). Only enabled ones sync.
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('rm_location_id')->unique();
            $table->string('name');
            $table->string('timezone')->default('America/Boise');
            $table->boolean('enabled')->default(false);
            $table->timestamp('last_issue_sync_at')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('tech')->after('email'); // admin | scheduler | tech
            $table->string('phone')->nullable()->after('role');
            $table->foreignId('location_id')->nullable()->after('phone')->constrained()->nullOnDelete();
            $table->boolean('active')->default(true)->after('location_id');
        });

        Schema::create('skills', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique(); // plumbing, electrical, hvac, appliance, general, locks, pest
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('technicians', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained();
            $table->string('start_address')->nullable();
            $table->decimal('start_lat', 10, 7)->nullable();
            $table->decimal('start_lng', 10, 7)->nullable();
            $table->time('work_start')->default('08:00');
            $table->time('work_end')->default('17:00');
            $table->string('work_days')->default('1,2,3,4,5'); // ISO weekdays
            $table->unsignedSmallInteger('max_jobs_per_day')->default(8);
            $table->boolean('on_call')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('skill_technician', function (Blueprint $table) {
            $table->foreignId('skill_id')->constrained()->cascadeOnDelete();
            $table->foreignId('technician_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('level')->default(2); // 1 basic, 2 solid, 3 expert
            $table->primary(['skill_id', 'technician_id']);
        });

        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained();
            $table->unsignedBigInteger('rm_property_id')->nullable();
            $table->string('name');
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('state', 2)->nullable();
            $table->string('zip', 10)->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->timestamps();
            $table->unique(['location_id', 'rm_property_id']);
        });

        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('rm_unit_id')->nullable();
            $table->string('name');
            $table->timestamps();
            $table->unique(['property_id', 'rm_unit_id']);
        });

        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained();
            $table->unsignedBigInteger('rm_tenant_id')->nullable();
            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('phone')->nullable(); // E.164
            $table->string('email')->nullable();
            $table->boolean('sms_consent')->default(false);
            $table->timestamp('sms_opted_out_at')->nullable();
            $table->string('language', 5)->default('en');
            $table->timestamps();
            $table->unique(['location_id', 'rm_tenant_id']);
            $table->index('phone');
        });

        Schema::create('work_orders', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 12)->unique();
            $table->string('status_token', 40)->unique();
            $table->foreignId('location_id')->constrained();
            $table->unsignedBigInteger('rm_service_issue_id')->nullable();
            $table->foreignId('property_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source')->default('manual'); // rent_manager | website | manual
            $table->string('category')->nullable(); // as the tenant chose it
            $table->string('trade')->nullable(); // skill slug from triage
            $table->string('urgency')->default('routine'); // emergency | urgent | routine
            $table->text('description');
            $table->boolean('permission_to_enter')->default(false);
            $table->string('pets')->nullable();
            $table->string('best_times')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('contact_phone')->nullable();
            $table->string('address_text')->nullable();
            $table->string('unit_text')->nullable();
            $table->string('status')->default('new');
            $table->json('triage')->nullable();
            $table->decimal('triage_confidence', 3, 2)->nullable();
            $table->unsignedSmallInteger('estimated_minutes')->nullable();
            $table->foreignId('technician_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('scheduled_start')->nullable();
            $table->timestamp('scheduled_end')->nullable();
            $table->unsignedSmallInteger('route_position')->nullable();
            $table->string('review_reason')->nullable();
            $table->timestamp('needs_review_at')->nullable();
            $table->timestamp('en_route_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->text('completion_notes')->nullable();
            $table->unsignedSmallInteger('labor_minutes')->nullable();
            $table->unsignedTinyInteger('tenant_rating')->nullable();
            $table->timestamp('rm_synced_at')->nullable();
            $table->timestamps();
            $table->unique(['location_id', 'rm_service_issue_id']);
            $table->index(['status', 'location_id']);
            $table->index(['technician_id', 'scheduled_start']);
        });

        Schema::create('slot_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('technician_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('option_number');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->decimal('score', 8, 3)->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamps();
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('direction'); // inbound | outbound
            $table->string('channel')->default('sms'); // sms | app | system
            $table->string('from_number')->nullable();
            $table->string('to_number')->nullable();
            $table->text('body');
            $table->string('provider_sid')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
            $table->index(['work_order_id', 'created_at']);
        });

        Schema::create('photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('object_key')->unique();
            $table->string('kind')->default('request'); // request | before | after
            $table->string('content_type')->default('image/jpeg');
            $table->unsignedInteger('size_bytes')->nullable();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('rm_attachment_id')->nullable();
            $table->timestamp('archived_to_rm_at')->nullable();
            $table->timestamp('purged_at')->nullable();
            $table->timestamps();
        });

        Schema::create('parts', function (Blueprint $table) {
            $table->id();
            $table->string('sku')->unique();
            $table->string('name');
            $table->string('trade')->nullable();
            $table->json('keywords')->nullable();
            $table->decimal('unit_cost', 10, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('stock_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained();
            $table->string('type'); // shop | truck
            $table->foreignId('technician_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('stock_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_location_id')->constrained()->cascadeOnDelete();
            $table->foreignId('part_id')->constrained()->cascadeOnDelete();
            $table->integer('quantity')->default(0);
            $table->integer('reorder_point')->default(0);
            $table->integer('par_level')->default(0);
            $table->timestamps();
            $table->unique(['stock_location_id', 'part_id']);
        });

        Schema::create('work_order_parts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('part_id')->nullable()->constrained()->nullOnDelete();
            $table->string('part_name');
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->boolean('suggested')->default(false);
            $table->decimal('suggestion_confidence', 3, 2)->nullable();
            $table->boolean('used')->default(false);
            $table->foreignId('stock_location_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('activity_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event');
            $table->json('data')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['activity_log', 'work_order_parts', 'stock_levels', 'stock_locations', 'parts', 'photos',
            'messages', 'slot_offers', 'work_orders', 'tenants', 'units', 'properties', 'skill_technician',
            'technicians', 'skills'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('location_id');
            $table->dropColumn(['role', 'phone', 'active']);
        });
        Schema::dropIfExists('locations');
    }
};
