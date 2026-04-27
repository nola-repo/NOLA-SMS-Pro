<?php

return [

    'SEMAPHORE_API_KEY' => getenv('SEMAPHORE_API_KEY') ?: '8089fc9919bc05855ae0d354011f8e4b',
    'SEMAPHORE_URL' => getenv('SEMAPHORE_URL') ?: 'https://api.semaphore.co/api/v4/messages',

    'GHL_CLIENT_ID'     => '6999da2b8f278296d95f7274-mm9wv85e',
    'GHL_CLIENT_SECRET' => 'dfc4380f-6132-49b3-8246-92e14f55ee78',

    'GHL_AGENCY_CLIENT_ID'     => '69d31f33b3071b25dbcc5656-mnqxvtt3',
    'GHL_AGENCY_CLIENT_SECRET' => '64b90a28-8cb1-4a44-8212-0a8f3f255322',
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