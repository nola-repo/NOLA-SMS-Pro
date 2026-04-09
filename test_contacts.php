<?php
ini_set('display_errors', '1');
error_reporting(E_ALL);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_X_WEBHOOK_SECRET'] = 'f7RkQ2pL9zV3tX8cB1nS4yW6';
$_SERVER['HTTP_X_GHL_LOCATION_ID'] = 'ugBqfQsPtGijLjrmLdmA';
require 'api/ghl_contacts.php';
