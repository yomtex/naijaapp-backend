<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('sender_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('receiver_id')->constrained('users')->onDelete('cascade');

            $table->decimal('amount', 16, 2);
            $table->enum('type', ['send', 'request']); // send or request
            $table->enum('purpose', ['friends_family', 'goods_services']);

            $table->string('reference')->unique(); // For traceability
            $table->timestamp('processed_at')->nullable(); // When approved

            $table->text('note')->nullable(); // Optional message
            $table->enum('status', ['pending', 'completed', 'failed', 'disputed'])->default('pending');
            $table->timestamp('scheduled_release_at')->nullable(); // When to auto-release
            $table->boolean('disputed')->default(false);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};

