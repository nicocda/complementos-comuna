<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            // Internal fleet number ("Interno N°"); some implements have none (S/N).
            $table->unsignedInteger('internal_number')->nullable()->unique();
            $table->string('type', 40);
            $table->string('name', 150);
            $table->string('license_plate', 20)->nullable();
            $table->string('status', 30)->default('activa');
            // Implement currently hitched to another unit (e.g. trailer on a tractor).
            $table->foreignId('attached_to_unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['type', 'status']);
        });

        Schema::create('drivers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150)->unique();
            $table->string('phone', 40)->nullable();
            // Optional login account for drivers that report from the web.
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('driver_unit', function (Blueprint $table) {
            $table->foreignId('driver_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained()->cascadeOnDelete();
            $table->primary(['driver_id', 'unit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_unit');
        Schema::dropIfExists('drivers');
        Schema::dropIfExists('units');
    }
};
