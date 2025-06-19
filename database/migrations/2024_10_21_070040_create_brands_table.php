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
        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('term_id')->nullable();
            $table->string('categoryName')->nullable();
            $table->string('categorySlug')->nullable();
            $table->string('categoryVisbility')->nullable();
            $table->string('brandName')->nullable();
            $table->string('brandUrl')->nullable();
            $table->string('brandImageurl')->nullable();
            $table->string('visibility')->default('public');
            $table->boolean('status')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('brands');
    }
};
