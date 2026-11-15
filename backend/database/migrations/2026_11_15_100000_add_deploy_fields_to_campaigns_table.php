<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            // Which server this campaign deploys to (set when activated).
            $table->foreignId('server_id')->nullable()->after('user_id')
                ->constrained()->nullOnDelete();
            // Git repository the campaign server clones to run the platform.
            $table->string('deploy_repo_url')->nullable()->after('server_domain');
            $table->string('deploy_branch')->nullable()->after('deploy_repo_url');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('server_id');
            $table->dropColumn(['deploy_repo_url', 'deploy_branch']);
        });
    }
};
