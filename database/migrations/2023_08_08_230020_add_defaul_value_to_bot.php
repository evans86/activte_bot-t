<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddDefaulValueToBot extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('bot', function (Blueprint $table) {
            $table->dropColumn('retail');
        });
        Schema::table('bot', function (Blueprint $table) {
            $table->smallInteger('retail')->after('black')->default(1);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('bot', function (Blueprint $table) {
            //
        });
    }
}
