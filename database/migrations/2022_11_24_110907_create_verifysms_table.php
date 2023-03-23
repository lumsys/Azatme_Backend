<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateVerifysmsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('verifysms', function (Blueprint $table) {
            $table->id();
            $table->string('phone');
            $table->string('otp');
            $table->string('email')->nullable();
            $table->string('medium');
            $table->string('otp_expires_time')->nullable();
            $table->foreignId('user_id');
            $table->timestamps();
        });
    }

}
