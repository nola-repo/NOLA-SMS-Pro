<?php

function find_keys($dir) {
    $files = scandir($dir);
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        $path = $dir . '/' . $file;
        if (is_dir($path)) {
            if ($file === 'vendor' || $file === 'node_modules' || $file === '.git') continue;
            find_keys($path);
        } else {
            if (pathinfo($path, PATHINFO_EXTENSION) === 'json') {
                echo $path . " (" . filesize($path) . " bytes)\n";
            }
        }
    }
}

find_keys('C:/Users/niceo/public_html');
