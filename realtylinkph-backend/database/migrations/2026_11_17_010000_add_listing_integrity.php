<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Listing integrity.
 *  - property_photos.phash: a 64-bit picture fingerprint (difference hash), so a
 *    photo re-used on another listing is noticed even after resizing/re-saving.
 *  - property_flags: why a listing needs an admin's look — found by RealtyLink AI
 *    (duplicate photos/address, contact details, payment requests) or reported by
 *    a buyer. Only a payment request pauses the listing; everything else is advice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('property_photos', function (Blueprint $table): void {
            $table->string('phash', 16)->nullable()->index();
        });

        Schema::create('property_flags', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('source', 10);                 // ai | report
            $table->json('reasons');                       // codes, see ListingIntegrityService::LABELS
            $table->json('details')->nullable();           // e.g. the matching listing, what the AI read
            $table->text('note')->nullable();              // a reporter's own words
            $table->foreignId('reporter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 10)->default('open'); // open | cleared | actioned
            $table->boolean('paused')->default(false);
            $table->string('resolved_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['property_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_flags');
        Schema::table('property_photos', fn (Blueprint $t) => $t->dropColumn('phash'));
    }
};
