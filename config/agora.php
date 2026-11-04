<?php

return [
    'app_id' => env('AGORA_APP_ID', ''),
    'app_certificate' => env('AGORA_APP_CERTIFICATE', ''),
    'token_seconds' => (int) env('AGORA_TOKEN_SECONDS', 600),
    'call_minutes' => (int) env('AGORA_CALL_MINUTES', 120),
];
