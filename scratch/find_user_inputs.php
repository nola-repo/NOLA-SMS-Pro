<?php

$path = 'C:/Users/niceo/.gemini/antigravity/brain/4ed7e08d-613f-4aa9-97e1-bd7416412274/.system_generated/logs/transcript.jsonl';
$lines = file($path);
foreach ($lines as $i => $line) {
    $data = json_decode($line, true);
    if ($data && isset($data['type']) && $data['type'] === 'USER_INPUT') {
        $content = $data['content'] ?? '';
        if (strpos($content, 'Handoff') !== false || strpos($content, 'Free System') !== false) {
            echo "Line $i matches! Content length: " . strlen($content) . "\n";
            file_put_contents("scratch/user_input_$i.md", $content);
        }
    }
}
