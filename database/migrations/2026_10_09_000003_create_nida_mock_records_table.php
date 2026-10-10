<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('nida_mock_records', function (Blueprint $table) {
            $table->id();
            $table->string('nida_number', 20)->unique();
            $table->string('first_name');
            $table->string('last_name');
            $table->date('date_of_birth');
            $table->string('gender', 10);
            $table->string('place_of_birth');
            $table->string('mother_first_name');
            $table->string('region');
            $table->string('district');
            $table->string('ward');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nida_mock_records');
    }
};
