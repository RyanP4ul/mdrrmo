<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GenerateEmergencyOperationsReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasRole('admin');
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('include_patient_details')) {
            $parsed = filter_var(
                $this->input('include_patient_details'),
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE
            );

            if ($parsed !== null) {
                $this->merge(['include_patient_details' => $parsed]);
            }
        }
    }

    /**
     * @return array<string, array<int, string>|string>
     */
    public function rules(): array
    {
        return [
            'period_type' => ['required', 'in:weekly,monthly'],
            'week_date' => ['required_if:period_type,weekly', 'nullable', 'date'],
            'month' => ['required_if:period_type,monthly', 'nullable', 'integer', 'between:1,12'],
            'year' => ['required_if:period_type,monthly', 'nullable', 'integer', 'between:2020,2100'],
            'mode' => ['nullable', 'in:preview,download'],
            'include_patient_details' => ['nullable', 'boolean'],
        ];
    }

    public function includePatientDetails(): bool
    {
        if (! $this->user()?->hasRole('admin')) {
            return false;
        }

        if ($this->input('include_patient_details') === null || $this->input('include_patient_details') === '') {
            return true;
        }

        return $this->boolean('include_patient_details');
    }
}
