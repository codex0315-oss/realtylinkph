<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Photos, videos and files sent in buyer–agent chat (up to 5 per message). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10);                 // image | video | file
            $table->string('path');                     // on ChatFiles::disk(), unguessable name
            $table->string('thumb_path')->nullable();   // images: small preview
            $table->string('name');                     // original file name, cleaned
            $table->string('mime', 120);
            $table->unsignedBigInteger('size');         // bytes
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_attachments');
    }
};
