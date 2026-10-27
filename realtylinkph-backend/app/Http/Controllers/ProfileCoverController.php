<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\ListingImage;
use App\Support\Uploads;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * A profile cover photo — agents and buyers: upload / replace, reposition,
 * remove. An agent's is public (profile, directory, "Listed by"); a buyer has
 * no public profile, so theirs shows on their own My Profile.
 *
 * Covers go live straight away (like the profile photo); an admin can take
 * one down from Admin → Users (AdminController::removeAgentCover).
 *
 * Uploads are resized to the listing gallery's size (long edge 1600 px, JPEG)
 * — a banner never needs more, and phone originals are 3–10 MB. If the image
 * can't be decoded within memory it's stored as uploaded, same as listings.
 */
class ProfileCoverController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $user = $this->ownerOrFail($request);

        $data = $request->validate([
            'cover'    => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'position' => ['nullable', 'integer', 'between:0,100'],
        ]);

        $file = $request->file('cover');
        $processed = ListingImage::process($file->getRealPath());

        if ($processed) {
            $path = 'covers/' . Str::uuid() . '.jpg';
            Uploads::disk()->put($path, $processed['main']);
        } else {
            $path = $file->store('covers', Uploads::name());
        }

        $this->deleteFile($user);
        $user->update([
            'cover_photo'    => $path,
            'cover_position' => $data['position'] ?? 50,
        ]);

        return ApiResponse::success(UserResource::make($user->fresh('agentProfile')), 'Cover photo updated.', 200);
    }

    /** Save where the banner sits on the photo after the owner drags it. */
    public function position(Request $request): JsonResponse
    {
        $user = $this->ownerOrFail($request);

        $data = $request->validate(['position' => ['required', 'integer', 'between:0,100']]);

        if (! $user->cover_photo) {
            return ApiResponse::error('Upload a cover photo first.', [], 422);
        }

        $user->update(['cover_position' => $data['position']]);

        return ApiResponse::success(UserResource::make($user->fresh('agentProfile')), 'Cover position saved.', 200);
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = $this->ownerOrFail($request);

        $this->deleteFile($user);
        $user->update(['cover_photo' => null, 'cover_position' => 50]);

        return ApiResponse::success(UserResource::make($user->fresh('agentProfile')), 'Cover photo removed.', 200);
    }

    /** Buyers and agents have profiles with covers; admin accounts don't. */
    private function ownerOrFail(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless(in_array($user->role_type, ['agent', 'buyer'], true), 403, "Admin accounts don't have a profile cover.");

        return $user;
    }

    private function deleteFile(User $user): void
    {
        if ($user->cover_photo) {
            Uploads::disk()->delete($user->cover_photo);
        }
    }
}
