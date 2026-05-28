<?php
require_once __DIR__ . '/api/auth/user_profile_helper.php';
$d = [
    'email' => 'davidemonzy05@gmail.com',
    'active_location_id' => 'ugBqfQsPtGijLjrmLdmA',
    'location_name' => 'NORWIN LACSON',
    'firstName' => 'David',
    'lastName' => 'Monzy',
    'name' => 'David Monzy'
];
echo json_encode(['user' => auth_user_payload_for_api($d)]);
