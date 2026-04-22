<?php

return [

    'SEMAPHORE_API_KEY' => getenv('SEMAPHORE_API_KEY') ?: '8089fc9919bc05855ae0d354011f8e4b',
    'SEMAPHORE_URL' => getenv('SEMAPHORE_URL') ?: 'https://api.semaphore.co/api/v4/messages',




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