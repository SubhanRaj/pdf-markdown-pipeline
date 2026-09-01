<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Same reasoning as the documents-table migration alongside this one. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quick_conversions', function (Blueprint $table) {
            $table->string('pdf_path')->nullable()->change();
            $table->string('native_path')->nullable()->after('pdf_path');
            $table->string('original_format')->default('pdf')->after('native_path');
        });
    }

    public function down(): void
    {
        Schema::table('quick_conversions', function (Blueprint $table) {
            $table->dropColumn(['native_path', 'original_format']);
            $table->string('pdf_path')->nullable(false)->change();
        });
    }
};
