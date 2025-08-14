<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddCanceledToTransactionStatusColumn extends Migration
{
    public function up()
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Update enum to include 'canceled'
            $table->enum('status', ['pending', 'completed', 'failed', 'disputed', 'canceled'])
                  ->default('pending')
                  ->change();
        });
    }

    public function down()
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Revert back to original enum
            $table->enum('status', ['pending', 'completed', 'failed', 'disputed'])
                  ->default('pending')
                  ->change();
        });
    }
}

