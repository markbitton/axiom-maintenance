<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Suggested tech + time waiting for the office to approve (new bookings and drive-time moves). Nothing is texted until approved. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->foreignId('proposed_technician_id')->nullable()->after('route_position')->constrained('technicians')->nullOnDelete();
            $table->dateTime('proposed_start')->nullable()->after('proposed_technician_id');
            $table->string('proposed_kind', 10)->nullable()->after('proposed_start'); // place | move
            $table->string('proposed_reason')->nullable()->after('proposed_kind');
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('proposed_technician_id');
            $table->dropColumn(['proposed_start', 'proposed_kind', 'proposed_reason']);
        });
    }
};
