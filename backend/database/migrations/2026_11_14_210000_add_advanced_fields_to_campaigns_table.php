<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->text('description')->nullable()->after('name');
            $table->string('target_url')->nullable()->after('description');
            $table->string('server_host')->nullable()->after('target_url');
            $table->string('server_domain')->nullable()->after('server_host');
            $table->boolean('proxy_enabled')->default(false)->after('server_domain');
            $table->boolean('proxy_on_the_fly')->default(false)->after('proxy_enabled');
            // 'url' = HTTP redirect, 'html' = serve an HTML page
            $table->string('redirect_type')->default('url')->after('proxy_on_the_fly');
            $table->text('redirect_target')->nullable()->after('redirect_type');
            // login | scan | click | submit | download | pageload
            $table->string('trigger_action')->default('login')->after('redirect_target');
            $table->string('kill_session_url')->nullable()->after('trigger_action');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn([
                'description', 'target_url', 'server_host', 'server_domain',
                'proxy_enabled', 'proxy_on_the_fly', 'redirect_type', 'redirect_target',
                'trigger_action', 'kill_session_url',
            ]);
        });
    }
};
