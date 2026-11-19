<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('download_tasks', function (Blueprint $table) {
            $table->id();
            // Public identifier (URLs, API); the numeric id is never exposed.
            $table->uuid('uuid')->unique();

            // Owner: a user, or a guest identified by an HMAC of a session token.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->char('guest_id', 64)->nullable()->index();
            // HMAC of the client IP, for guest limits only (no raw IPs are stored).
            $table->char('client_hash', 64)->index();

            $table->string('platform', 32);
            $table->string('media_id', 64);
            $table->string('type', 8);
            $table->string('format', 8);
            $table->string('bitrate', 8)->nullable();
            $table->string('quality', 8)->nullable();

            $table->string('status', 16)->default('pending');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->unsignedTinyInteger('attempts')->default(0);

            $table->string('title', 300)->nullable();
            $table->unsignedInteger('duration')->nullable();
            // File name inside storage/app/downloads/<uuid>/ (never a path) and the name shown to the user.
            $table->string('file_path', 64)->nullable();
            $table->string('file_name', 200)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();

            $table->string('error_code', 32)->nullable();
            $table->string('error_message', 500)->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('downloaded_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'expires_at']);
            $table->index(['status', 'updated_at']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('download_tasks');
    }
};
