<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geo_restrictions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('rule_type', ['allow', 'disallow']);
            $table->enum('scope', ['product', 'category', 'brand']);
            $table->json('target_entities'); // Array of product/category/brand IDs
            $table->json('locations'); // Array of location objects with type and value
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // Create a table for tracking rule changes
        Schema::create('geo_restriction_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('geo_restriction_id');
            $table->unsignedBigInteger('user_id');
            $table->string('action'); // created, updated, deleted, enabled, disabled
            $table->json('changes')->nullable();
            $table->timestamps();

            $table->foreign('geo_restriction_id')
                  ->references('id')
                  ->on('geo_restrictions')
                  ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geo_restriction_logs');
        Schema::dropIfExists('geo_restrictions');
    }
}; 