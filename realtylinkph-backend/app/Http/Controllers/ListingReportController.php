<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Models\Property;
use App\Services\ListingIntegrityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** "Report this listing": a signed-in user tells the admins something is wrong with a listing. */
class ListingReportController extends Controller
{
    public function store(Request $request, Property $property, ListingIntegrityService $integrity): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'in:' . implode(',', ListingIntegrityService::REPORT_REASONS)],
            'note'   => ['nullable', 'required_if:reason,other', 'string', 'max:500'],
        ], [
            'reason.required'  => 'Please choose what is wrong with the listing.',
            'note.required_if' => 'Please tell us what is wrong.',
        ]);
        abort_unless($property->status === 'published', 404);

        $integrity->report($property, $request->user(), $data['reason'], $data['note'] ?? null);

        return ApiResponse::success(null, 'Thanks for reporting. Our team will check this listing.', 201);
    }
}
