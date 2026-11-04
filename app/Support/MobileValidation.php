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

    public static function nameParts(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name), 2);
        if (! $parts || empty($parts[1]) || mb_strlen($parts[0]) > 60 || mb_strlen($parts[1]) > 60) {
            throw ValidationException::withMessages(['name' => 'Enter your first and last name, each at most 60 characters.']);
        }

        return $parts;
    }
}
