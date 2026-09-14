<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AgentProfile;
use App\Models\User;
use App\Notifications\AgentApplicationApproved;
use App\Notifications\AgentApplicationRejected;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class AgentVerificationService
{
    public function __construct(
        private readonly GeminiService $gemini,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Submit (or re-submit) an agent application for either applicant type,
     * then run RealtyLink AI's advisory pre-screen.
     *
     * @param  array<string, \Illuminate\Http\UploadedFile>  $files
     */
    public function submitApplication(User $user, array $data, array $files): AgentProfile
    {
        $type = $data['applicant_type'];

        // Enforce the 12h cool-down for previously-rejected applicants.
        $existing = $user->agentProfile;
        if ($existing && $existing->inReapplyCooldown()) {
            $when = $existing->reapplyAt()?->setTimezone('Asia/Manila')->format('M j, Y g:i A');
            throw new RuntimeException("Your application was recently declined. You can re-apply after {$when}.");
        }

        $profile = DB::transaction(function () use ($user, $data, $files, $type): AgentProfile {
            // Keep the user's basic info in sync.
            $user->update(array_filter([
                'name'  => $data['full_name'] ?? null,
                'phone' => $data['mobile'] ?? null,
            ], static fn ($v) => $v !== null));

            $payload = [
                'applicant_type'     => $type,
                'prc_number'         => $data['prc_number'],
                'face_image'         => Storage::disk('public')->put('agent-faces', $files['face_image']),
                'status'             => 'pending',
                'admin_note'         => null,
                'ai_comment'         => null,
                'ai_assessed_at'     => null,
                'reviewed_at'        => null,
                // Reset both paths, then fill the relevant one.
                'license_doc'        => null,
                'accreditation_doc'  => null,
                'valid_id'           => null,
                'supervising_broker' => null,
            ];

            if ($type === 'broker') {
                $payload['license_doc'] = Storage::disk('public')->put('agent-docs', $files['license_doc']);
            } else {
                $payload['accreditation_doc']  = Storage::disk('public')->put('agent-docs', $files['accreditation_doc']);
                $payload['valid_id']           = Storage::disk('public')->put('agent-docs', $files['valid_id']);
                $payload['supervising_broker'] = $data['supervising_broker'] ?? null;
            }

            return AgentProfile::updateOrCreate(['user_id' => $user->id], $payload);
        });

        // Run the (slow, external) AI assessment OUTSIDE the DB transaction.
        $comment = $this->gemini->assessAgentApplication($type, $this->documentsForAi($type, $profile));
        $profile->update(['ai_comment' => $comment, 'ai_assessed_at' => now()]);

        // In-app alert: tell the applicant review takes up to 24 hours.
        $this->notifications->send($user, 'agent_application_submitted', [
            'message' => "Your agent application is under review. Our team will decide within 24 hours.",
        ]);

        // Alert every admin — in-app (clickable → /admin/agents) + queued email.
        User::whereIn('role_type', ['admin', 'super_admin'])->get()->each(function (User $admin) use ($user, $type): void {
            $this->notifications->send($admin, 'new_agent_application', [
                'applicant_name' => $user->name,
                'applicant_type' => $type,
                'message'        => "{$user->name} submitted a {$type} verification application for review.",
            ]);
            $admin->notify(new \App\Notifications\NewAgentApplication($user, $type));
        });

        return $profile->fresh(['user']);
    }

    /**
     * @return array<int, array{label:string,path:string}>
     */
    private function documentsForAi(string $type, AgentProfile $profile): array
    {
        if ($type === 'broker') {
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

    public function approve(AgentProfile $profile): AgentProfile
    {
        $profile = DB::transaction(function () use ($profile): AgentProfile {
            $profile->update(['status' => 'approved', 'admin_note' => null, 'reviewed_at' => now()]);
            $profile->user->update(['role_type' => 'agent']);

            return $profile->fresh(['user']);
        });

        // In-app bell + approval email.
        $this->notifications->send($profile->user, 'agent_application_approved', [
            'message' => '🎉 Your agent application was approved! You can now post listings.',
        ]);
        $profile->user->notify(new AgentApplicationApproved());

        return $profile;
    }

    public function reject(AgentProfile $profile, string $reason): AgentProfile
    {
        $profile->update(['status' => 'rejected', 'admin_note' => $reason, 'reviewed_at' => now()]);
        $profile = $profile->fresh(['user']);

        $reapplyAt = $profile->reapplyAt()?->setTimezone('Asia/Manila')->format('M j, Y g:i A');

        // In-app bell + rejection email (with reason + when they can re-apply).
        $this->notifications->send($profile->user, 'agent_application_rejected', [
            'message' => 'Your agent application was not approved. Reason: ' . $reason,
            'reason'  => $reason,
        ]);
        $profile->user->notify(new AgentApplicationRejected($reason, $reapplyAt));

        return $profile;
    }

    public function getPendingApplications(int $perPage = 15)
    {
        return AgentProfile::with('user')
            ->pending()
            ->latest()
            ->paginate($perPage);
    }
}
