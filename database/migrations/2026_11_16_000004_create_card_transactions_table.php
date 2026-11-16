<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('source', 32)->default('revolut');
            $table->dateTime('occurred_at');
            $table->string('description');
            $table->bigInteger('amount'); // milliunits, negative = spending
            $table->string('currency', 3);
            $table->bigInteger('original_amount')->nullable();
            $table->string('original_currency', 3)->nullable();
            $table->char('hash', 64);
            $table->string('status', 16)->default('new');
            $table->string('ynab_transaction_id')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'hash']);
            $table->index(['user_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_transactions');
    }
};
