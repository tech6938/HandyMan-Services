<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class ChangeReadableIdToVarcharInBookingsTable extends Migration
{
    /**
     * Run the migrations.
     * Change readable_id from integer to varchar to support HC format (HC101, HC102, etc.)
     *
     * @return void
     */
    public function up()
    {
        Schema::table('bookings', function (Blueprint $table) {
            // Change readable_id from integer to varchar(255)
            $table->string('readable_id', 255)->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('bookings', function (Blueprint $table) {
            // Revert back to integer if needed
            $table->integer('readable_id')->change();
        });
    }
}
