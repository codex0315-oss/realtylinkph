<?php

declare(strict_types=1);

namespace App\Helpers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\ResourceCollection;

class ApiResponse
{
    public static function success(
        mixed $data = null,
        string $message = 'Success.',
        int $status = 200
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ], $status);
    }

    public static function error(
        string $message,
        array $errors = [],
        int $status = 422
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors'  => $errors,
        ], $status);
    }

    public static function paginated(
        ResourceCollection $collection,
        string $message = 'Success.'
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data'    => $collection->items(),
            'meta'    => [
                'current_page' => $collection->currentPage(),
                'last_page'    => $collection->lastPage(),
                'per_page'     => $collection->perPage(),
                'total'        => $collection->total(),
            ],
        ]);
    }
}
