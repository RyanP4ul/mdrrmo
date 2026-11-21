<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class MobileValidation
{
    public static function normalizePhone(Request $request, string $field): void
    {
        if (is_string($request->input($field))) {
            $request->merge([$field => preg_replace('/[\s-]+/', '', $request->input($field))]);
        }
    }

    public static function phoneRules(): array
    {
        return ['required', 'string', 'max:20', 'regex:/^(09\d{9}|\+639\d{9})$/'];
    }

    /**
     * Convert a PH mobile number (09XXXXXXXXX or +639XXXXXXXXX) to E.164 format
     * expected by the SMS Gateway (/^\+?[1-9]\d{6,14}$/).
     */
    public static function toE164(string $phone): string
    {
        $cleaned = preg_replace('/[\s\-()]+/', '', trim($phone)) ?? '';
        if (preg_match('/^09\d{9}$/', $cleaned) === 1) {
            return '+63'.substr($cleaned, 1);
        }
        if (preg_match('/^639\d{9}$/', $cleaned) === 1) {
            return '+'.$cleaned;
        }

        return $cleaned;
    }

    public static function nameParts(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name), 2);
        if (! $parts || empty($parts[1]) || mb_strlen($parts[0]) > 60 || mb_strlen($parts[1]) > 60) {
            throw ValidationException::withMessages(['name' => 'Enter your first and last name, each at most 60 characters.']);
        }

        return $parts;
    }
}
