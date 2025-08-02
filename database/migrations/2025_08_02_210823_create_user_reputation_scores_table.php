<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    
    public function up(): void {
        Schema::create('user_reputation_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reporter_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('reported_id')->constrained('users')->onDelete('cascade');
            $table->integer('score')->default(1);
            $table->timestamps();

            $table->unique(['reporter_id', 'reported_id']); // Only 1 row per pair
        });
    }

    public function down(): void {
        Schema::dropIfExists('user_reputation_scores');
    }
};
