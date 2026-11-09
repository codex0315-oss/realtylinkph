<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AgentProfile;
use App\Services\GeminiService;
use App\Support\AgentApplicationVerdict;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * RealtyLink AI's advisory pre-check of an agent application, for the admin.
 *
 * This used to run inside the submit request with a 12-second Gemini timeout.
 * In production the documents come from object storage in another region and
 * are sent to Gemini as multi-megabyte base64 — 12 seconds wasn't enough, so
 * the applicant waited through the whole call and the admin still got
 * "assessment unavailable". Here the request returns as soon as the
 * application is saved, and this job gets the time the call actually needs.
 */
class AssessAgentApplication implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Gemini gets 60s per model here; the worker's own limit sits above that. */
    public int $timeout = 150;

    public int $tries = 2;

    public function __construct(public int $profileId) {}

    public function handle(GeminiService $gemini): void
    {
        $profile = AgentProfile::find($this->profileId);

        // The applicant may have withdrawn, or an admin may already have
        // decided — an assessment that lands after the decision is just noise.
        if (! $profile || $profile->status !== 'pending' || $profile->ai_assessed_at !== null) {
            return;
        }

        $profile->loadMissing('user');

        // The model only READS the documents; the checklist and verdict are
        // worked out in code, so the same documents always give the same advice.
        $read = $gemini->readAgentApplication($profile->applicant_type, $this->documents($profile), timeout: 60);

        if ($read === null) {
            $profile->update([
                'ai_comment'     => 'AI assessment unavailable. Please review this application manually, or try Re-check.',
                'ai_checks'      => null,
                'ai_verdict'     => null,
                'ai_assessed_at' => now(),
            ]);

            return;
        }

        $result = AgentApplicationVerdict::evaluate($read, [
            'name'               => $profile->user?->name,
            'number'             => $profile->prc_number,
            'supervising_broker' => $profile->supervising_broker,
            'type'               => $profile->applicant_type,
        ], now()->timezone('Asia/Manila'));

        $profile->update([
            'ai_comment'     => $result['summary'] ?? AgentApplicationVerdict::headline($result['verdict']),
            'ai_checks'      => $result['checks'],
            'ai_verdict'     => $result['verdict'],
            'ai_assessed_at' => now(),
        ]);
    }

    /** @return array<int, array{label:string,path:string}> */
    private function documents(AgentProfile $profile): array
    {
        if ($profile->applicant_type === 'broker') {
            return [
                ['label' => 'Broker license card', 'path' => $profile->license_doc],
                ['label' => 'Live selfie',         'path' => $profile->face_image],
            ];
        }

        return [
            ['label' => 'Accreditation document (front)', 'path' => $profile->accreditation_doc],
            ['label' => 'Valid ID',                       'path' => $profile->valid_id],
            ['label' => 'Live selfie',                    'path' => $profile->face_image],
        ];
    }
}
