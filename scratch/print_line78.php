<?php

$path = 'C:/Users/niceo/.gemini/antigravity/brain/4ed7e08d-613f-4aa9-97e1-bd7416412274/.system_generated/logs/transcript.jsonl';
$lines = file($path);
$line = $lines[78]; // line 79
$data = json_decode($line, true);
if ($data === null) {
    echo "Decode failed: " . json_last_error_msg() . "\n";
} else {
    echo "Keys: " . implode(', ', array_keys($data)) . "\n";
    if (isset($data['content'])) {
        echo "Length of content: " . strlen($data['content']) . "\n";
        file_put_contents('scratch/handoff_full.md', $data['content']);
        echo "Wrote to scratch/handoff_full.md\n";
    } else {
        echo "Content not set\n";
    }
}
