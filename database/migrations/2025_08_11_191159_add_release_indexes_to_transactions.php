<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Composite index for fast lookup in scheduled command
            $table->index(['purpose', 'status', 'disputed', 'scheduled_release_at'], 'transactions_release_idx');

            // If you also query by scheduled_release_at alone often, a single index is helpful:
            $table->index('scheduled_release_at', 'transactions_scheduled_release_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex('transactions_release_idx');
            $table->dropIndex('transactions_scheduled_release_at_idx');
        });
    }
};
