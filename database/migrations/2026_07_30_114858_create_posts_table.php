<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            // Lié à la table users (car l'artisan_id dans ton PostController fait référence à l'id de la table users)
            $table->foreignId('artisan_id')->constrained('users')->onDelete('cascade');
            $table->string('artisan_category');
            $table->string('image_url');
            $table->text('description');
            $table->unsignedInteger('likes_count')->default(0);
            $table->unsignedInteger('comments_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
};
