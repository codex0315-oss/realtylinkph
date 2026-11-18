<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The duplicate photo / address checks were switched off (agents from the same
 * developer seminar post the same project with the developer's photos). Close
 * the duplicate flags still waiting in the admin queue, and drop the duplicate
 * reasons from flags that also have another reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        $dup = ['duplicate_photos', 'duplicate_address'];

        DB::table('property_flags')->where('source', 'ai')->where('status', 'open')->orderBy('id')
            ->each(function ($flag) use ($dup) {
                $reasons = json_decode((string) $flag->reasons, true) ?: [];
                if (array_intersect($reasons, $dup) === []) {
                    return;
                }
                $left    = array_values(array_diff($reasons, $dup));
                $details = array_diff_key(json_decode((string) $flag->details, true) ?: [], array_flip($dup));

                DB::table('property_flags')->where('id', $flag->id)->update($left === [] && ! $flag->paused
                    ? ['status' => 'cleared', 'resolved_by' => 'System (duplicate check removed)', 'resolved_at' => now(), 'updated_at' => now()]
                    : ['reasons' => json_encode($left), 'details' => json_encode((object) $details), 'updated_at' => now()]);
            });
    }

    public function down(): void
    {
        // Nothing to restore: the check itself is gone.
    }
};
