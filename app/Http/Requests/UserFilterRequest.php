<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserFilterRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
          'search' => ['nullable', 'string', 'max:100'],
          'role' => ['nullable', Rule::in(['admin', 'dispatcher', 'driver/responder', 'resident'])],
          'status' => ['nullable', Rule::in(['active', 'inactive', 'suspended'])],
          'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
          'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
