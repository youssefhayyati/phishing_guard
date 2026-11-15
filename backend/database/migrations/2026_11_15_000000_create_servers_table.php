<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('servers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('ip_address');
            $table->unsignedSmallInteger('ssh_port')->default(22);
            $table->string('ssh_user')->default('root');
            // Encrypted at rest via the model's 'encrypted' cast (never stored plaintext).
            $table->text('password')->nullable();
            $table->string('status')->default('unknown'); // unknown | online | offline
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('servers');
    }
};
