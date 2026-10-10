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
        Schema::create('business_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('business_name');
            $table->string('registration_number');
            $table->string('tin_number');
            $table->string('business_type');
            $table->date('date_of_incorporation');
            $table->string('business_address');
            $table->string('region');
            $table->string('district');
            $table->string('contact_person');
            $table->string('contact_phone');
            $table->string('contact_email');
            $table->string('registration_document_path');
            $table->enum('status', ['Pending', 'Verified', 'Rejected'])->default('Pending');
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('business_profiles');
    }
};
