<?php

return [

    'SEMAPHORE_API_KEY' => getenv('SEMAPHORE_API_KEY') ?: '8089fc9919bc05855ae0d354011f8e4b',
    'SEMAPHORE_URL' => getenv('SEMAPHORE_URL') ?: 'https://api.semaphore.co/api/v4/messages',

    // Multiple Sender IDs (first = default when none sent)
    'SENDER_IDS' => [
        'NOLASMSPro',
    ],
];