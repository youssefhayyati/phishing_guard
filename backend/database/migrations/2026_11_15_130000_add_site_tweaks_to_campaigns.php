<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            // Site Tweaker JSON ({version, pages:{...}}) applied automatically on
            // the deployed browser via the extension's seed.json.
            $table->longText('site_tweaks')->nullable()->after('deploy_branch');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn('site_tweaks');
        });
    }
};
