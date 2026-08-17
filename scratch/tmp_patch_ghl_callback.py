from pathlib import Path
path = Path(r'c:\Users\niceo\public_html\ghl_callback.php')
text = path.read_text(encoding='utf-8')
start = text.find('    $allLocationIds = [];')
end = text.find('/**\n * Returns true if any user is already linked to this location.')
print('start', start, 'end', end)
if start == -1 or end == -1:
    raise SystemExit(f'start={start} end={end}')
text = text[:start] + text[end:]
old = (
    "    $url = $baseUrl . '/api/agency/install/provision?session_id=' . urlencode($sessionId) . '&token=' . urlencode((string)$secret);\n"
    "    $ch = curl_init($url);\n"
    "    curl_setopt_array($ch, [\n"
    "        CURLOPT_RETURNTRANSFER => true,\n"
    "        CURLOPT_TIMEOUT_MS => 700,\n"
    "        CURLOPT_CONNECTTIMEOUT_MS => 300,\n"
    "        CURLOPT_HTTPHEADER => ['Accept: application/json'],\n"
    "    ]);\n"
)
new = (
    "    $url = $baseUrl . '/api/agency/install/provision?session_id=' . urlencode($sessionId);\n"
    "    $ch = curl_init($url);\n"
    "    curl_setopt_array($ch, [\n"
    "        CURLOPT_RETURNTRANSFER => true,\n"
    "        CURLOPT_TIMEOUT_MS => 700,\n"
    "        CURLOPT_CONNECTTIMEOUT_MS => 300,\n"
    "        CURLOPT_HTTPHEADER => ['Accept: application/json', 'X-Webhook-Secret: ' . (string)$secret],\n"
    "    ]);\n"
)
if old not in text:
    raise SystemExit('header block not found')
text = text.replace(old, new, 1)
path.write_text(text, encoding='utf-8')
print('updated')
