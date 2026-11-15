<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deployments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('server_id')->nullable()->constrained()->nullOnDelete();
            // pending | running | success | failed
            $table->string('status')->default('pending');
            // Human-readable label of the step currently running.
            $table->string('current_step')->nullable();
            // Appended, streamed provisioning output.
            $table->longText('log')->nullable();
            // Public link to the modified-site session once it's up.
            $table->string('link')->nullable();
            // Failure reason, if any.
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deployments');
    }
};
