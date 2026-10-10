<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;

class ApiResponse
{
    /**
     * Return a standardized success JSON response.
     *
     * @param string $message
     * @param mixed $data
     * @param string|null $nextStep
     * @param int $status
     * @param array<string, string> $headers
     * @return JsonResponse
     */
    public static function success(
        string $message,
        mixed $data = null,
        ?string $nextStep = null,
        int $status = 200,
        array $headers = []
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'errors' => null,
            'next_step' => $nextStep,
        ], $status, $headers);
    }

    /**
     * Return a standardized error JSON response.
     *
     * @param string $message
     * @param mixed $errors
     * @param string|null $nextStep
     * @param int $status
     * @param mixed $data
     * @param array<string, string> $headers
     * @return JsonResponse
     */
    public static function error(
        string $message,
        mixed $errors = null,
        ?string $nextStep = null,
        int $status = 400,
        mixed $data = null,
        array $headers = []
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => $data,
            'errors' => $errors,
            'next_step' => $nextStep,
        ], $status, $headers);
    }
}
