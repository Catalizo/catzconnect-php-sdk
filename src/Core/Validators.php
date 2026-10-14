<?php

namespace CatzConnect\Core;

class Validators
{
    public static function validateEmail(string $email): void
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \Exception("Invalid email: $email");
        }
    }

    /**
     * Rejects what can never be a WhatsApp recipient: an email address, or a
     * digit count no real number has. The server normalises the rest and
     * applies the number's default country code to national numbers.
     */
    public static function validatePhone(string $phone): void
    {
        if (strpos($phone, '@') !== false) {
            throw new \Exception("WhatsApp messages go to phone numbers, not email addresses: $phone");
        }
        if (!preg_match('/^[\d\s()+\-]+$/', $phone)) {
            throw new \Exception("Invalid phone number: $phone");
        }
        $digits = preg_replace('/\D/', '', $phone);
        if (strlen($digits) < 7 || strlen($digits) > 15) {
            throw new \Exception("Invalid phone number: $phone");
        }
    }
}
