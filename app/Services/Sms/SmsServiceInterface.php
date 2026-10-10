<?php

namespace App\Services\Sms;

interface SmsServiceInterface
{
    /**
     * Send an SMS message to the given phone number.
     *
     * @param string $to Recipient phone number in E.164 format
     * @param string $message Text content of the SMS
     * @return bool
     */
    public function send(string $to, string $message): bool;
}
