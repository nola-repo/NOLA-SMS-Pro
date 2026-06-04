<?php

$path = 'C:/Users/niceo/.gemini/antigravity/brain/4ed7e08d-613f-4aa9-97e1-bd7416412274/.system_generated/logs/transcript.jsonl';
$lines = file($path);
$line = $lines[89]; // 90th line
$data = json_decode($line, true);
if ($data === null) {
    echo "Decode failed: " . json_last_error_msg() . "\n";
} else {
    echo "Keys: " . implode(', ', array_keys($data)) . "\n";
    if (isset($data['content'])) {
        echo "Length of content: " . strlen($data['content']) . "\n";
        file_put_contents('scratch/handoff.md', $data['content']);
        echo "Wrote to scratch/handoff.md\n";
    } else {
        echo "Content not set\n";
    }
}
