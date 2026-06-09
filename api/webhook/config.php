<?php

return [

    'SEMAPHORE_API_KEY' => getenv('SEMAPHORE_API_KEY') ?: '',
    'SEMAPHORE_URL' => getenv('SEMAPHORE_URL') ?: 'https://api.semaphore.co/api/v4/messages',

    'UNISMS_API_KEY' => getenv('UNISMS_API_KEY') ?: '',
    'UNISMS_SENDER_ID' => getenv('UNISMS_SENDER_ID') ?: '',
    'UNISMS_ENDPOINT' => getenv('UNISMS_ENDPOINT') ?: 'https://unismsapi.com/api',

    'GHL_CLIENT_ID'     => getenv('GHL_CLIENT_ID') ?: '',

    'GHL_CLIENT_SECRET' => getenv('GHL_CLIENT_SECRET') ?: '',


    'GHL_AGENCY_CLIENT_ID'     => getenv('GHL_AGENCY_CLIENT_ID') ?: '',
    'GHL_AGENCY_CLIENT_SECRET' => getenv('GHL_AGENCY_CLIENT_SECRET') ?: '',
    // Default system sender ID (first entry is the fallback)
    'SENDER_IDS' => [
        'NOLASMSPro',
    ],

    // [DEPRECATED] — The master sender whitelist is now stored dynamically in Firestore
    // at admin_config/master_senders.approved_senders (auto-managed by admin_sender_requests.php).
    // This static config is kept only as fallback documentation. Both send_sms.php and
    // ghl_provider.php now read from Firestore instead.
    'MASTER_APPROVED_SENDERS' => [
        'NOLASMSPro',
        'NOLA CRM',
    ],
];
