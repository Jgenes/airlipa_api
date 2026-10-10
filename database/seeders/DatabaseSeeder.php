<?php

namespace Database\Seeders;

use App\Enums\AccountStatus;
use App\Enums\KybStatus;
use App\Enums\KycStatus;
use App\Enums\UserType;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            NidaMockSeeder::class,
        ]);

        // Seed an admin user for reviewing KYB submissions
        User::firstOrCreate(
            ['email' => 'admin@airlipa.com'],
            [
                'first_name' => 'System',
                'last_name' => 'Admin',
                'phone' => '+255700000000',
                'user_type' => UserType::Individual,
                'email_verified_at' => now(),
                'phone_verified_at' => now(),
                'password' => Hash::make('AdminSecure#2026!'),
                'account_status' => AccountStatus::Active,
                'is_active' => true,
                'kyc_status' => KycStatus::Verified,
                'kyb_status' => KybStatus::NotStarted,
                'role' => 'admin',
            ]
        );
    }
}
