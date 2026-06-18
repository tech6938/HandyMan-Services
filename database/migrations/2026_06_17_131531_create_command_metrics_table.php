<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('command_metrics', function (Blueprint $table) {
            $table->id();
            $table->string('command');
            $table->integer('jobs_dispatched')->default(0);
            $table->float('execution_time')->nullable();
            $table->bigInteger('memory_usage')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('command_metrics');
    }
};
