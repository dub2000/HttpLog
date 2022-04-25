<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateHttpLogTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('http_logs', function (Blueprint $table) {

            $table->id();
            $table->timestamps();
            $table->text('method');
            $table->text('url');
            $table->text('payload')->nullable();
            $table->text('headers')->nullable();
            $table->text('response_code')->nullable();
            $table->text('response_content')->nullable();
            $table->text('response_headers')->after('response_content')->nullable();
            $table->float('duration')->nullable();
            $table->text('group')->after('url')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('http_logs');
    }
}
