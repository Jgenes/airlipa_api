<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Kyb\KybSubmitRequest;
use App\Http\Resources\BusinessProfileResource;
use App\Http\Responses\ApiResponse;
use App\Models\BusinessProfile;
use App\Services\KybService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class KybController extends Controller
{
    public function __construct(
        protected KybService $kybService
    ) {}

    /**
     * Submit business details and verification document.
     * POST /api/v1/kyb/submit
     */
    public function submit(KybSubmitRequest $request): JsonResponse
    {
        $user = $request->user();

        if ($user->isIndividual()) {
            return ApiResponse::error(
                message: 'KYB submission is designated for Business accounts only. Individual accounts must complete NIDA KYC.',
                errors: null,
                nextStep: 'kyc',
                status: 400
            );
        }

        $profile = $this->kybService->submit(
            $user,
            $request->validated(),
            $request->file('registration_document')
        );

        return ApiResponse::success(
            message: 'Business details submitted. Verification pending.',
            data: new BusinessProfileResource($profile),
            nextStep: 'login',
            status: 201
        );
    }

    /**
     * Download or stream the private registration document via signed URL or authorization check.
     * GET /api/v1/kyb/document/{id}
     */
    public function downloadDocument(Request $request, int $id): StreamedResponse|JsonResponse
    {
        $profile = BusinessProfile::findOrFail($id);
        $user = $request->user();

        // Must be authorized: valid signature OR authenticated owner/admin
        $isSigned = $request->hasValidSignature();
        $isOwnerOrAdmin = $user && ($user->id === $profile->user_id || $user->isAdmin());

        if (!$isSigned && !$isOwnerOrAdmin) {
            return ApiResponse::error(
                message: 'Unauthorized document access.',
                errors: null,
                nextStep: null,
                status: 403
            );
        }

        $path = $profile->registration_document_path;
        if (!Storage::disk('local')->exists($path)) {
            return ApiResponse::error(
                message: 'Document file not found on server.',
                errors: null,
                nextStep: null,
                status: 404
            );
        }

        $content = Storage::disk('local')->get($path);
        $mime = Storage::disk('local')->mimeType($path) ?: 'application/octet-stream';

        return response()->stream(function () use ($content) {
            echo $content;
        }, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="' . basename($path) . '"',
        ]);
    }
}
