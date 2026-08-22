<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kavlings', function (Blueprint $table) {
            $table->string('kluster', 50)->nullable()->after('project_id');
            $table->index(['project_id', 'kluster']);
        });
    }

    public function down(): void
    {
        Schema::table('kavlings', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'kluster']);
            $table->dropColumn('kluster');
        });
    }
};
