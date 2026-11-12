<?php

namespace Database\Seeders;

use App\Models\Hospital;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class HospitalSeeder extends Seeder
{
    public function run(): void
    {
        $hospitals = [
            [
                'name' => 'United Balagtas Doctors Hospital',
                'address' => '516 MacArthur Highway, Wawa, Balagtas, Bulacan',
                'lat' => 14.81828, 'lng' => 120.90416,
                'contact_number' => '(044) 693-2992', 'specialization' => [],
                'source_url' => 'https://www.philhealth.gov.ph/partners/providers/facilities/accredited/HOSP_093025_v2.pdf',
                'location_source_url' => 'https://mapcarta.com/W1118718035',
            ],
            [
                'name' => 'Jesus of Nazareth Hospital',
                'address' => 'MacArthur Highway, Poblacion, Guiguinto, Bulacan',
                'lat' => 14.82797, 'lng' => 120.87792,
                'contact_number' => '(044) 794-1202', 'specialization' => [],
                'source_url' => 'https://www.paramount.com.ph/er-card-hospitals',
                'location_source_url' => 'https://mapcarta.com/W301182262',
            ],
            [
                'name' => 'Bulacan Medical Center',
                'address' => '99 Potenciano Street, City of Malolos, Bulacan',
                'lat' => 14.858880, 'lng' => 120.817018,
                'contact_number' => '(044) 791-0630', 'specialization' => ['Emergency'],
                'source_url' => 'https://bulacan.gov.ph/health/bulacan-medical-center/',
                'location_source_url' => 'https://cms-cdn.e.gov.ph/DICT/pdf/DTS_PICS-PP-Phase-2-Region-3-Bulacan-Pampanga.pdf',
            ],
        ];

        DB::transaction(function () use ($hospitals): void {
            foreach ($hospitals as $hospital) {
                Hospital::firstOrCreate(['name' => $hospital['name']], $hospital + ['available_beds' => null]);
            }
        });
    }
}
