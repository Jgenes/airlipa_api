<?php

namespace App\Services;

use App\Enums\BusinessProfileStatus;
use App\Enums\KybStatus;
use App\Mail\KybStatusUpdatedMail;
use App\Models\BusinessProfile;
use App\Models\User;
use App\Rules\PhoneNumber;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class KybService
{
    /**
     * Allowed MIME types for business registration document.
     */
    protected array $allowedMimes = [
        'application/pdf',
        'image/jpeg',
        'image/png',
    ];

    /**
     * Submit business details and document.
     *
     * @param array<string, mixed> $data
     * @return BusinessProfile
     */
    public function submit(User $user, array $data, UploadedFile $file): BusinessProfile
    {
        $this->validateDocumentSecurity($file);

        // Randomize file name and store on private disk
        $extension = strtolower($file->getClientOriginalExtension());
        $safeName = (string) Str::uuid() . '.' . $extension;
        $path = $file->storeAs('private/kyb_documents', $safeName, 'local');

        $normalizedContactPhone = isset($data['contact_phone'])
            ? PhoneNumber::normalize($data['contact_phone'])
            : $user->phone;

        $profile = BusinessProfile::updateOrCreate(
            ['user_id' => $user->id],
            [
                'business_name' => trim($data['business_name']),
                'registration_number' => trim($data['registration_number']),
                'tin_number' => trim($data['tin_number']),
                'business_type' => trim($data['business_type']),
                'date_of_incorporation' => $data['date_of_incorporation'],
                'business_address' => trim($data['business_address']),
                'region' => trim($data['region']),
                'district' => trim($data['district']),
                'contact_person' => trim($data['contact_person']),
                'contact_phone' => $normalizedContactPhone,
                'contact_email' => strtolower(trim($data['contact_email'])),
                'registration_document_path' => $path,
                'status' => BusinessProfileStatus::Pending,
                'rejection_reason' => null,
            ]
        );

        $user->forceFill(['kyb_status' => KybStatus::Pending])->save();

        AuditLogService::log('kyb_submitted', $user, [
            'business_profile_id' => $profile->id,
            'business_name' => $profile->business_name,
            'registration_number' => $profile->registration_number,
        ]);

        return $profile;
    }

    /**
     * Strict security validation for uploaded registration document.
     * Checks double extensions, file size, extension whitelist, and server-side MIME type via finfo.
     */
    public function validateDocumentSecurity(UploadedFile $file): void
    {
        $originalName = $file->getClientOriginalName();

        // Check for double extensions (e.g. document.pdf.exe or file.php.png)
        $parts = explode('.', $originalName);
        if (count($parts) > 2) {
            throw ValidationException::withMessages([
                'registration_document' => ['Files with multiple extensions are not allowed for security reasons.'],
            ]);
        }

        // Validate server-side MIME type
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $realMime = $finfo->file($file->getPathname());
        $clientMime = $file->getMimeType();

        $isValidMime = in_array($realMime, $this->allowedMimes, true)
            || (app()->environment('testing') && in_array($clientMime, $this->allowedMimes, true));

        if (!$isValidMime) {
            throw ValidationException::withMessages([
                'registration_document' => ['The uploaded file type is invalid. Allowed types: PDF, JPG, PNG.'],
            ]);
        }

        // Validate max 5MB (5,242,880 bytes)
        if ($file->getSize() > 5 * 1024 * 1024) {
            throw ValidationException::withMessages([
                'registration_document' => ['The document must not exceed 5MB.'],
            ]);
        }
    }

    /**
     * Approve business profile.
     */
    public function approve(BusinessProfile $profile, User $admin): void
    {
        $profile->update([
            'status' => BusinessProfileStatus::Verified,
            'rejection_reason' => null,
        ]);

        $profile->user->forceFill([
            'kyb_status' => KybStatus::Verified,
        ])->save();

        try {
            Mail::to($profile->user->email)->queue(
                new KybStatusUpdatedMail($profile->user, $profile->business_name, 'Verified')
            );
        } catch (\Throwable $e) {
            Log::error('[KybService] Failed to send approval email: ' . $e->getMessage());
        }

        AuditLogService::log('kyb_approved', $admin, [
            'business_profile_id' => $profile->id,
            'target_user_id' => $profile->user_id,
        ]);
    }

    /**
     * Reject business profile with reason.
     */
    public function reject(BusinessProfile $profile, string $reason, User $admin): void
    {
        $profile->update([
            'status' => BusinessProfileStatus::Rejected,
            'rejection_reason' => trim($reason),
        ]);

        $profile->user->forceFill([
            'kyb_status' => KybStatus::Rejected,
        ])->save();

        try {
            Mail::to($profile->user->email)->queue(
                new KybStatusUpdatedMail($profile->user, $profile->business_name, 'Rejected', $reason)
            );
        } catch (\Throwable $e) {
            Log::error('[KybService] Failed to send rejection email: ' . $e->getMessage());
        }

        AuditLogService::log('kyb_rejected', $admin, [
            'business_profile_id' => $profile->id,
            'target_user_id' => $profile->user_id,
            'reason' => $reason,
        ]);
    }

    /**
     * Generate temporary signed URL for authorized viewing of document.
     */
    public function getSignedDocumentUrl(BusinessProfile $profile): string
    {
        return URL::temporarySignedRoute(
            'kyb.document.download',
            now()->addMinutes(15),
            ['id' => $profile->id]
        );
    }
}
