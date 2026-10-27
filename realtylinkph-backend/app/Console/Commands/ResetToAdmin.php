<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AdminAction;
use App\Models\PropertyPhoto;
use App\Models\User;
use App\Support\Uploads;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Empties the database down to a single admin account: every user, listing,
 * viewing, chat, review and ticket goes, and so do the listing photos those
 * rows point at in storage. For clearing demo data off a deployment.
 *
 * It runs once per code. Render re-runs the container's entrypoint every time
 * the service wakes up, so a reset left switched on would otherwise wipe real
 * data on every wake. The code is written to the audit log after the reset; a
 * later run with the same code sees it there and does nothing.
 */
class ResetToAdmin extends Command
{
    protected $signature = 'app:reset-to-admin
        {code : Any word that identifies this reset; a second run with the same code is skipped}
        {--email= : The admin account to keep}
        {--password= : Its password (8+ characters)}';

    protected $description = 'Wipe all data except one admin account (runs once per code)';

    public function handle(): int
    {
        $code     = (string) $this->argument('code');
        $email    = (string) $this->option('email');
        $password = (string) $this->option('password');

        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($password) < 8) {
            $this->error('An admin --email and a --password of 8+ characters are required.');

            return self::FAILURE;
        }

        $done = AdminAction::query()->where('action', 'system.reset')->get()
            ->contains(fn (AdminAction $a) => ($a->details['code'] ?? null) === $code);
        if ($done) {
            $this->info("Reset \"{$code}\" already ran; nothing to do. Remove RESET_TO_ADMIN from the environment.");

            return self::SUCCESS;
        }

        // Files first, while the rows that name them still exist.
        $files = PropertyPhoto::query()->get(['url', 'thumb_url', 'preview_url'])
            ->flatMap(fn (PropertyPhoto $p) => [$p->url, $p->thumb_url, $p->preview_url])
            ->filter()->unique()->values()->all();
        foreach (array_chunk($files, 100) as $chunk) {
            try {
                Uploads::disk()->delete($chunk);
            } catch (\Throwable $e) {
                $this->warn('Some photo files could not be deleted: ' . $e->getMessage());
            }
        }

        // Every table but the migration log, ids restarting at 1.
        $tables = collect(DB::select('select tablename from pg_tables where schemaname = current_schema()'))
            ->pluck('tablename')->reject(fn ($t) => $t === 'migrations')
            ->map(fn ($t) => '"' . str_replace('"', '""', $t) . '"')->implode(', ');
        DB::statement("TRUNCATE {$tables} RESTART IDENTITY CASCADE");

        $admin = User::forceCreate([
            'name'              => 'Site Administrator',
            'email'             => $email,
            'password'          => $password,
            'role_type'         => 'admin',
            'email_verified_at' => now(),
        ]);

        AdminAction::create([
            'admin_id'      => $admin->id,
            'admin_name'    => $admin->name,
            'action'        => 'system.reset',
            'subject_type'  => 'system',
            'subject_label' => 'All data except this admin',
            'details'       => ['code' => $code, 'photos_deleted' => count($files)],
            'created_at'    => now(),
        ]);

        $this->info('Reset done: only ' . $email . ' remains (' . count($files) . ' photo files deleted).');

        return self::SUCCESS;
    }
}
