<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->string('visibility')->default('public')->after('wing'); // public | authenticated
        });

        Schema::table('divisions', function (Blueprint $table) {
            $table->string('visibility')->default('public')->after('description'); // public | authenticated
        });
    }

    public function down(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->dropColumn('visibility');
        });

        Schema::table('divisions', function (Blueprint $table) {
            $table->dropColumn('visibility');
        });
    }
};
