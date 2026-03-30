<?php
// ghl_debug.php
header('Content-Type: text/plain');
$file = sys_get_temp_dir() . '/ghl_sync_debug.log';
if (file_exists($file)) {
    echo file_get_contents($file);
} else {
    echo "No debug log found at $file";
}
