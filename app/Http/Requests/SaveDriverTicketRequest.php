<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveDriverTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'driver_section' => [
                'required',
                'array:tripDate,driverName,governmentCardPlateNo,authorizedPassenger,placeVisitedInspected,purpose,passengerName,balanceInTank,issuedByOffice,purchasedDuringTrip,totalAvailableFuel,fuelUsedDuringTrip,remainingFuel,gearOilUsed',
            ],
            'driver_section.tripDate' => ['nullable', 'string', 'max:40'],
            'driver_section.driverName' => ['nullable', 'string', 'max:120'],
            'driver_section.governmentCardPlateNo' => ['nullable', 'string', 'max:120'],
            'driver_section.authorizedPassenger' => ['nullable', 'string', 'max:255'],
            'driver_section.placeVisitedInspected' => ['nullable', 'string', 'max:255'],
            'driver_section.purpose' => ['nullable', 'string', 'max:255'],
            'driver_section.passengerName' => ['nullable', 'string', 'max:255'],
            'driver_section.balanceInTank' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'driver_section.issuedByOffice' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'driver_section.purchasedDuringTrip' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'driver_section.totalAvailableFuel' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'driver_section.fuelUsedDuringTrip' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'driver_section.remainingFuel' => ['nullable', 'numeric', 'min:-100000', 'max:100000'],
            'driver_section.gearOilUsed' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ];
    }
}
