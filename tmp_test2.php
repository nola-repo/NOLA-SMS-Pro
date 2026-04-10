<?php
$response = false;
$errorResponse = json_decode($response, true);
try {
    echo $errorResponse['message'] ?? $response;
} catch (Exception $e) {
    echo "Caught exception!";
}
