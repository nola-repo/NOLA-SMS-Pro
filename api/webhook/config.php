<?php

return [

    'SEMAPHORE_API_KEY' => getenv('SEMAPHORE_API_KEY') ?: '8089fc9919bc05855ae0d354011f8e4b',
    'SEMAPHORE_URL' => getenv('SEMAPHORE_URL') ?: 'https://api.semaphore.co/api/v4/messages',




    // Default system sender ID (first entry is the fallback)
    'SENDER_IDS' => [
        'NOLASMSPro',
    ],

    // Sender names actually registered & approved on the NOLA master Semaphore account.
    // Only names on this list may be used when routing through the master billing gateway.
    // Subaccounts with their own API key can use any sender they have registered themselves.
    'MASTER_APPROVED_SENDERS' => [
        'NOLASMSPro',
        // Add more as Semaphore approves them on the master account, e.g.:
        // 'NOLACRMIO',
    ],
];