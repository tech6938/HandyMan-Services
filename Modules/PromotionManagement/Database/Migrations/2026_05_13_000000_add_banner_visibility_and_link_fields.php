<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddBannerVisibilityAndLinkFields extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('banners', function (Blueprint $table) {
            $table->boolean('both')->default(0)->after('redirect_link');
            $table->boolean('only_service')->default(0)->after('both');
            $table->boolean('only_category')->default(0)->after('only_service');
        });

        DB::table('banners')
            ->where('resource_type', 'service')
            ->update(['only_service' => 1]);

        DB::table('banners')
            ->whereIn('resource_type', ['category', 'link'])
            ->update(['only_category' => 1]);

        DB::table('banners')
            ->whereNull('resource_type')
            ->update(['only_category' => 1]);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('banners', function (Blueprint $table) {
            $table->dropColumn(['both', 'only_service', 'only_category']);
        });
    }
}
