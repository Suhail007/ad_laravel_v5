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
        Schema::create('brand_menus', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('term_id')->nullable();
            $table->string('categoryName')->nullable();
            $table->string('categorySlug')->nullable();
            $table->string('categoryVisbility')->nullable();
            $table->integer('order')->nullable();
            $table->integer('menupos')->nullable()->default(1);
            $table->string('menuImageUrl')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('brand_menus');
    }
};
