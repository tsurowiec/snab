<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ynab_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('ynab_id');
            $table->string('name');
            $table->string('currency', 3);
            $table->boolean('enabled')->default(true);
            $table->unsignedBigInteger('server_knowledge')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'ynab_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ynab_accounts');
    }
};
