<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateServiceCommissionsTable extends Migration
{
    public function up()
    {
        Schema::create('service_commissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('service_id');
            $table->decimal('commission', 24, 3)->default(0);
            $table->string('commission_type', 20)->default('fixed');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('service_id')->references('id')->on('services')->cascadeOnDelete();
        });
    }

    public function down()
    {
        Schema::dropIfExists('service_commissions');
    }
}
