<?php

return [
    'app_id' => env('AGORA_APP_ID', ''),
    'app_certificate' => env('AGORA_APP_CERTIFICATE', ''),
    'token_seconds' => (int) env('AGORA_TOKEN_SECONDS', 600),
    'sos_ring_seconds' => (int) env('AGORA_SOS_RING_SECONDS', 120),
    'sos_queue_seconds' => (int) env('AGORA_SOS_QUEUE_SECONDS', 600),
    'call_minutes' => (int) env('AGORA_CALL_MINUTES', 120),
];
