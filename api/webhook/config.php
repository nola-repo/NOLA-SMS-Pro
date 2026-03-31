<?php

return [

    'SEMAPHORE_API_KEY' => getenv('SEMAPHORE_API_KEY') ?: '8089fc9919bc05855ae0d354011f8e4b',
    'SEMAPHORE_URL' => getenv('SEMAPHORE_URL') ?: 'https://api.semaphore.co/api/v4/messages',

    'MYCRMSIM_API_KEY' => getenv('MYCRMSIM_API_KEY') ?: '',
    'MYCRMSIM_URL' => getenv('MYCRMSIM_URL') ?: 'https://r6bszuuso6.execute-api.ap-southeast-2.amazonaws.com/prod/webhook',

    // Multiple Sender IDs (first = default when none sent)
    'SENDER_IDS' => [
        'NOLASMSPro',
    ],
];