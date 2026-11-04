<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SavePatientReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('updateAsDriver', $this->route('report')) ?? false;
    }

    public function rules(): array
    {
        return [
            'driver_section' => ['required', 'array:driverName,governmentCardPlateNo,authorizedPassenger,placeVisitedInspected,purpose,passengerName'],
            'driver_section.driverName' => ['nullable', 'string', 'max:120'],
            'driver_section.governmentCardPlateNo' => ['nullable', 'string', 'max:80'],
            'driver_section.authorizedPassenger' => ['nullable', 'string', 'max:120'],
            'driver_section.placeVisitedInspected' => ['nullable', 'string', 'max:255'],
            'driver_section.purpose' => ['nullable', 'string', 'max:255'],
            'driver_section.passengerName' => ['nullable', 'string', 'max:120'],
            'emergency_section' => ['required', 'array:timeReported,timeOfArrival,patientName,age,sex,address,typeOfIncident,allergies,medications,assessmentComment,treatmentManagement,vitalSigns'],
            'emergency_section.timeReported' => ['nullable', 'date_format:H:i'],
            'emergency_section.timeOfArrival' => ['nullable', 'date_format:H:i'],
            'emergency_section.patientName' => ['nullable', 'string', 'max:120'],
            'emergency_section.age' => ['nullable', 'integer', 'between:0,130'],
            'emergency_section.sex' => ['required', 'in:Male,Female'],
            'emergency_section.address' => ['nullable', 'string', 'max:255'],
            'emergency_section.typeOfIncident' => ['required', 'string', 'max:80'],
            'emergency_section.allergies' => ['nullable', 'string', 'max:1000'],
            'emergency_section.medications' => ['nullable', 'string', 'max:1000'],
            'emergency_section.assessmentComment' => ['nullable', 'string', 'max:2000'],
            'emergency_section.treatmentManagement' => ['nullable', 'string', 'max:2000'],
            'emergency_section.vitalSigns' => ['required', 'array:bloodPressure,pulseRate,respiration'],
            'emergency_section.vitalSigns.bloodPressure' => ['nullable', 'string', 'max:40'],
            'emergency_section.vitalSigns.pulseRate' => ['nullable', 'string', 'max:40'],
            'emergency_section.vitalSigns.respiration' => ['nullable', 'string', 'max:40'],
        ];
    }
}
