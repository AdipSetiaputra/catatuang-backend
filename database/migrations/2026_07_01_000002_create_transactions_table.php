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
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('wallet_id')->constrained()->onDelete('cascade');
            $table->enum('type', ['masuk', 'keluar']);
            $table->bigInteger('amount');
            $table->enum('category', [
                'Makanan & Minuman',
                'Transport',
                'Tagihan',
                'Gaji',
                'Investasi',
                'Belanja Harian',
                'Pendapatan Usaha',
                'Lainnya',
            ])->default('Lainnya');
            $table->string('item')->nullable();
            $table->string('platform')->nullable();
            $table->string('source')->nullable();
            $table->string('store')->nullable();
            $table->text('note')->nullable();
            $table->text('raw_input')->nullable();
            $table->enum('source_type', ['chat', 'receipt'])->default('chat');
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['user_id', 'category']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
