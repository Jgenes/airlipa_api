<?php

use App\Enums\BusinessProfileStatus;
use App\Enums\KybStatus;
use App\Mail\KybStatusUpdatedMail;
use App\Models\BusinessProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('business user can submit KYB details with valid registration document', function () {
    Storage::fake('local');

    $user = User::factory()->active()->business()->create([
        'kyb_status' => KybStatus::NotStarted,
    ]);

    $file = UploadedFile::fake()->create('registration_cert.pdf', 1024, 'application/pdf');

    Sanctum::actingAs($user, ['kyc']);

    $response = $this->postJson('/api/v1/kyb/submit', [
        'business_name' => 'Safari Tech Ltd',
        'registration_number' => 'BRELA-987654',
        'tin_number' => '123456789',
        'business_type' => 'Limited Liability',
        'date_of_incorporation' => '2022-01-15',
        'business_address' => 'Ali Hassan Mwinyi Rd, Dar es Salaam',
        'region' => 'Dar es Salaam',
        'district' => 'Kinondoni',
        'contact_person' => 'Juma Jux',
        'contact_phone' => '+255712345678',
        'contact_email' => 'info@safaritech.co.tz',
        'registration_document' => $file,
    ]);

    $response->assertStatus(201)
        ->assertJson([
            'success' => true,
            'message' => 'Business details submitted. Verification pending.',
            'next_step' => 'login',
        ]);

    $user->refresh();
    expect($user->kyb_status)->toBe(KybStatus::Pending);

    $profile = BusinessProfile::where('user_id', $user->id)->first();
    expect($profile)->not->toBeNull()
        ->and($profile->status)->toBe(BusinessProfileStatus::Pending)
        ->and($profile->business_name)->toBe('Safari Tech Ltd');

    // Stored on private local disk
    Storage::disk('local')->assertExists($profile->registration_document_path);
    expect(str_starts_with($profile->registration_document_path, 'private/kyb_documents/'))->toBeTrue();
});

test('kyb submit rejects files with double extensions', function () {
    Storage::fake('local');

    $user = User::factory()->active()->business()->create();

    $file = UploadedFile::fake()->create('malicious.php.pdf', 500, 'application/pdf');

    Sanctum::actingAs($user, ['kyc']);

    $response = $this->postJson('/api/v1/kyb/submit', [
        'business_name' => 'Safari Tech Ltd',
        'registration_number' => 'BRELA-987654',
        'tin_number' => '123456789',
        'business_type' => 'Limited Liability',
        'date_of_incorporation' => '2022-01-15',
        'business_address' => 'Dar es Salaam',
        'region' => 'Dar es Salaam',
        'district' => 'Kinondoni',
        'contact_person' => 'Juma Jux',
        'contact_phone' => '+255712345678',
        'contact_email' => 'info@safaritech.co.tz',
        'registration_document' => $file,
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['registration_document']);
});

test('kyb submit rejects files exceeding 5MB', function () {
    Storage::fake('local');

    $user = User::factory()->active()->business()->create();

    // 6MB file
    $file = UploadedFile::fake()->create('large_cert.pdf', 6144, 'application/pdf');

    Sanctum::actingAs($user, ['kyc']);

    $response = $this->postJson('/api/v1/kyb/submit', [
        'business_name' => 'Safari Tech Ltd',
        'registration_number' => 'BRELA-987654',
        'tin_number' => '123456789',
        'business_type' => 'Limited Liability',
        'date_of_incorporation' => '2022-01-15',
        'business_address' => 'Dar es Salaam',
        'region' => 'Dar es Salaam',
        'district' => 'Kinondoni',
        'contact_person' => 'Juma Jux',
        'contact_phone' => '+255712345678',
        'contact_email' => 'info@safaritech.co.tz',
        'registration_document' => $file,
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['registration_document']);
});

test('authorized user or valid signed URL can download registration document', function () {
    Storage::fake('local');

    $user = User::factory()->active()->business()->create();
    $path = 'private/kyb_documents/test_doc.pdf';
    Storage::disk('local')->put($path, 'Sample PDF content');

    $profile = BusinessProfile::factory()->create([
        'user_id' => $user->id,
        'registration_document_path' => $path,
    ]);

    // 1. Owner can download
    Sanctum::actingAs($user, ['*']);
    $ownerResponse = $this->get("/api/v1/kyb/document/{$profile->id}");
    $ownerResponse->assertStatus(200);

    // 2. Unauthorized stranger user without signature cannot download
    $stranger = User::factory()->create();
    Sanctum::actingAs($stranger, ['*']);
    $strangerResponse = $this->getJson("/api/v1/kyb/document/{$profile->id}");
    $strangerResponse->assertStatus(403);

    // 3. User with signed URL can download
    $signedUrl = URL::temporarySignedRoute('kyb.document.download', now()->addMinutes(15), ['id' => $profile->id]);
    $signedResponse = $this->get($signedUrl);
    $signedResponse->assertStatus(200);
});

test('admin can approve business KYB and trigger notification', function () {
    Mail::fake();

    $admin = User::factory()->admin()->create();
    $user = User::factory()->kybPending()->create();

    $profile = BusinessProfile::factory()->create([
        'user_id' => $user->id,
        'status' => BusinessProfileStatus::Pending,
    ]);

    Sanctum::actingAs($admin, ['*']);

    $response = $this->patchJson("/api/v1/admin/kyb/{$profile->id}/approve");

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'message' => 'Business KYB successfully approved.',
        ]);

    $user->refresh();
    expect($user->kyb_status)->toBe(KybStatus::Verified);

    $profile->refresh();
    expect($profile->status)->toBe(BusinessProfileStatus::Verified);

    Mail::assertQueued(KybStatusUpdatedMail::class, function ($mail) use ($user) {
        return $mail->user->id === $user->id && $mail->status === 'Verified';
    });
});

test('admin can reject business KYB with reason and trigger notification', function () {
    Mail::fake();

    $admin = User::factory()->admin()->create();
    $user = User::factory()->kybPending()->create();

    $profile = BusinessProfile::factory()->create([
        'user_id' => $user->id,
        'status' => BusinessProfileStatus::Pending,
    ]);

    Sanctum::actingAs($admin, ['*']);

    $response = $this->patchJson("/api/v1/admin/kyb/{$profile->id}/reject", [
        'reason' => 'TIN registration number could not be validated with TRA database.',
    ]);

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'message' => 'Business KYB successfully rejected.',
        ]);

    $user->refresh();
    expect($user->kyb_status)->toBe(KybStatus::Rejected);

    $profile->refresh();
    expect($profile->status)->toBe(BusinessProfileStatus::Rejected)
        ->and($profile->rejection_reason)->toBe('TIN registration number could not be validated with TRA database.');

    Mail::assertQueued(KybStatusUpdatedMail::class, function ($mail) use ($user) {
        return $mail->user->id === $user->id && $mail->status === 'Rejected';
    });
});
