<?php

namespace App\Services\Sms;

use Illuminate\Support\Manager;

class SmsManager extends Manager implements SmsServiceInterface
{
    public function getDefaultDriver(): string
    {
        return config('auth_otp.sms.default_driver', 'log');
    }

    public function createLogDriver(): SmsServiceInterface
    {
        return new LogSmsDriver();
    }

    public function createHttpDriver(): SmsServiceInterface
    {
        return new HttpSmsDriver();
    }

    public function send(string $to, string $message): bool
    {
        return $this->driver()->send($to, $message);
    }
}
