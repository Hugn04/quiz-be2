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
        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->longText('question');
        });
        Schema::create('sectors', function (Blueprint $table) {
            $table->id();
            $table->longText('name');
        });
        Schema::create('examples', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->integer('count_test');
            $table->integer('count_like');

            $table->string('image')->nullable();
            $table->integer('credits');
            $table->integer('num_question')->default(0);
            $table->timestamps();
            $table->foreignId('sector_id')->constrained('sectors')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('question_id')->constrained('questions')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('questions');
        Schema::dropIfExists('sectors');
        Schema::dropIfExists('examples');
    }
};