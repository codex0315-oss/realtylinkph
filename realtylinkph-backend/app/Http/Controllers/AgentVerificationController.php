<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Http\Requests\Agent\ReviewApplicationRequest;
use App\Http\Requests\Agent\SubmitVerificationRequest;
use App\Http\Resources\AgentProfileResource;
use App\Models\AgentProfile;
use App\Services\AgentVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentVerificationController extends Controller
{
    public function __construct(private readonly AgentVerificationService $service) {}

    public function submit(SubmitVerificationRequest $request): JsonResponse
    {
        $files = array_filter([
            'face_image'        => $request->file('face_image'),
            'license_doc'       => $request->file('license_doc'),
            'accreditation_doc' => $request->file('accreditation_doc'),
            'valid_id'          => $request->file('valid_id'),
        ]);

        try {
            $profile = $this->service->submitApplication(
                $request->user(),
                $request->validated(),
                $files
            );
        } catch (\RuntimeException $e) {
            // 429 = still inside the 12h re-apply cool-down.
            return ApiResponse::error($e->getMessage(), [], 429);
        }

        return ApiResponse::success(
            AgentProfileResource::make($profile),
            'Verification application submitted.',
            201
        );
    }

    public function status(Request $request): JsonResponse
    {
        $profile = $request->user()->agentProfile;

        if (! $profile) {
            return ApiResponse::error('No verification application found.', [], 404);
        }

        return ApiResponse::success(AgentProfileResource::make($profile), 'Verification status retrieved.', 200);
    }

    public function pending(Request $request): JsonResponse
    {
        $profiles = $this->service->getPendingApplications();

        return ApiResponse::paginated(AgentProfileResource::collection($profiles), 'Pending applications retrieved.');
    }

    public function review(ReviewApplicationRequest $request, AgentProfile $profile): JsonResponse
    {
        $action = $request->validated('action');

        $result = $action === 'approve'
            ? $this->service->approve($profile)
            : $this->service->reject($profile, $request->validated('reason'));

        $profile->loadMissing('user');
        \App\Support\AdminAudit::log(
            $action === 'approve' ? 'agent.approved' : 'agent.rejected',
            $profile,
            "{$profile->user?->name} ({$profile->user?->email})",
            array_filter([
                'applicant_type' => $profile->applicant_type,
                'reason'         => $action === 'approve' ? null : $request->validated('reason'),
            ]),
        );

        return ApiResponse::success(
            AgentProfileResource::make($result->load('user')),
            $action === 'approve' ? 'Application approved.' : 'Application rejected.',
            200
        );
    }
}
