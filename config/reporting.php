<?php

return [
    'organization_name' => env(
        'MDRRMO_ORGANIZATION_NAME',
        'Municipal Disaster Risk Reduction and Management Office (MDRRMO)'
    ),
    'unit_name' => env(
        'MDRRMO_UNIT_NAME',
        'Emergency Operations & Dispatch Division'
    ),
    'timezone' => env('REPORTING_TIMEZONE', config('mobile.timezone', 'Asia/Manila')),
];
