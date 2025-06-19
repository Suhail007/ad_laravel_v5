<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('location_list', function (Blueprint $table) {
            $table->id();
            $table->string('zip')->nullable();
            $table->string('city')->nullable();
            $table->string('state_id')->nullable();
            $table->string('state_name')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('location_list');
    }
};
