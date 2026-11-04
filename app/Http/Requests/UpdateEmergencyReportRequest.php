<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEmergencyReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('report')) ?? false;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'in:pending,acknowledged,dispatched,resolved,invalid'],
            'priority' => ['required', 'in:low,medium,high,critical'],
        ];
    }
}
