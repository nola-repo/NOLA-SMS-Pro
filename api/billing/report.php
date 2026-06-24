<?php

require_once __DIR__ . '/../cors.php';

require_once __DIR__ . '/../webhook/firestore_client.php';
require_once __DIR__ . '/../auth_helpers.php';
require_once __DIR__ . '/../services/CreditManager.php';

$db = get_firestore();

function report_json_response(array $payload, int $status = 200): void
{
    header('Content-Type: application/json');
    http_response_code($status);
    echo json_encode($payload, JSON_PRETTY_PRINT);
    exit;
}

function report_timestamp($value): string
{
    if ($value instanceof \Google\Cloud\Core\Timestamp) {
        return $value->get()->format('Y-m-d\TH:i:s\Z');
    }
    if ($value instanceof \DateTimeInterface) {
        return $value->format('Y-m-d\TH:i:s\Z');
    }
    return is_string($value) ? $value : '';
}

function report_csv_escape($value): string
{
    $value = (string)$value;
    return '"' . str_replace('"', '""', $value) . '"';
}

function report_pdf_escape(string $value): string
{
    $value = preg_replace('/[^\P{C}\t\r\n]/u', '', $value) ?? $value;
    $value = str_replace(["\\", "(", ")"], ["\\\\", "\\(", "\\)"], $value);
    return $value;
}

function report_build_pdf(array $lines): string
{
    $content = "BT\n/F1 10 Tf\n50 780 Td\n";
    $lineCount = 0;
    foreach ($lines as $line) {
        $line = substr((string)$line, 0, 110);
        if ($lineCount > 0) {
            $content .= "0 -14 Td\n";
        }
        $content .= '(' . report_pdf_escape($line) . ") Tj\n";
        $lineCount++;
        if ($lineCount >= 52) {
            $content .= "0 -14 Td\n(Report truncated. Use CSV export for full ledger.) Tj\n";
            break;
        }
    }
    $content .= "ET\n";

    $objects = [];
    $objects[] = "<< /Type /Catalog /Pages 2 0 R >>";
    $objects[] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
    $objects[] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>";
    $objects[] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";
    $objects[] = "<< /Length " . strlen($content) . " >>\nstream\n{$content}endstream";

    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $i => $object) {
        $offsets[] = strlen($pdf);
        $num = $i + 1;
        $pdf .= "{$num} 0 obj\n{$object}\nendobj\n";
    }

    $xrefOffset = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($i = 1; $i <= count($objects); $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    }
    $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
    $pdf .= "startxref\n{$xrefOffset}\n%%EOF\n";

    return $pdf;
}

function report_month_range(?string $month): array
{
    if ($month && preg_match('/^\d{4}-\d{2}$/', $month)) {
        $start = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $month . '-01 00:00:00');
        if ($start instanceof DateTimeImmutable) {
            return [$start, $start->modify('first day of next month')];
        }
    }

    $start = new DateTimeImmutable('first day of this month 00:00:00');
    return [$start, $start->modify('first day of next month')];
}

function report_collect_transactions($db, string $scope, ?string $agencyId, ?string $locationId, ?DateTimeImmutable $monthStart, ?DateTimeImmutable $monthEnd): array
{
    $query = $db->collection('credit_transactions')->where('wallet_scope', '==', $scope);

    if ($scope === 'agency' && $agencyId) {
        $query = $query->where('account_id', '==', $agencyId);
    } elseif ($scope === 'subaccount' && $locationId) {
        $query = $query->where('account_id', '==', CreditManager::integration_doc_id_for_location($locationId));
    }

    $rows = [];
    foreach ($query->documents() as $doc) {
        if (!$doc->exists()) {
            continue;
        }
        $data = $doc->data();
        $createdAt = $data['created_at'] ?? null;
        if ($monthStart && $monthEnd && $createdAt instanceof \Google\Cloud\Core\Timestamp) {
            $ts = $createdAt->get()->getTimestamp();
            if ($ts < $monthStart->getTimestamp() || $ts >= $monthEnd->getTimestamp()) {
                continue;
            }
        }

        $rows[] = [
            'id' => $data['transaction_id'] ?? $doc->id(),
            'type' => $data['type'] ?? '',
            'wallet_scope' => $data['wallet_scope'] ?? $scope,
            'account_id' => $data['account_id'] ?? '',
            'agency_id' => $data['agency_id'] ?? $agencyId,
            'amount' => (int)($data['amount'] ?? 0),
            'balance_after' => (int)($data['balance_after'] ?? 0),
            'description' => $data['description'] ?? '',
            'reference_id' => $data['reference_id'] ?? '',
            'provider' => $data['provider'] ?? '',
            'created_at' => report_timestamp($createdAt),
        ];
    }

    usort($rows, fn($a, $b) => strcmp((string)$b['created_at'], (string)$a['created_at']));
    return $rows;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        report_json_response(['success' => false, 'error' => 'Method not allowed'], 405);
    }

    $scope = $_GET['scope'] ?? 'subaccount';
    $scope = $scope === 'agency' ? 'agency' : 'subaccount';
    $agencyId = trim((string)($_GET['agency_id'] ?? ''));
    $locationId = trim((string)($_GET['location_id'] ?? get_ghl_location_id() ?? ''));
    $month = trim((string)($_GET['month'] ?? ''));
    $format = strtolower(trim((string)($_GET['format'] ?? 'pdf')));
    if (!in_array($format, ['pdf', 'csv', 'json'], true)) {
        $format = 'pdf';
    }

    if ($scope === 'agency') {
        if ($agencyId === '') {
            report_json_response(['success' => false, 'error' => 'agency_id is required'], 400);
        }
        auth_assert_agency_billing_read_allowed($db, $agencyId);
        $identity = $agencyId;
    } else {
        if ($locationId === '') {
            report_json_response(['success' => false, 'error' => 'location_id is required'], 400);
        }
        auth_require_api_or_jwt_for_location($db, $locationId);
        $identity = $locationId;
    }

    [$monthStart, $monthEnd] = report_month_range($month !== '' ? $month : null);
    $monthLabel = $monthStart->format('Y-m');
    $rows = report_collect_transactions(
        $db,
        $scope,
        $agencyId !== '' ? $agencyId : null,
        $locationId !== '' ? $locationId : null,
        $monthStart,
        $monthEnd
    );

    $creditsIn = 0;
    $creditsOut = 0;
    foreach ($rows as $row) {
        $amount = (int)$row['amount'];
        if ($amount >= 0) {
            $creditsIn += $amount;
        } else {
            $creditsOut += abs($amount);
        }
    }

    $summary = [
        'success' => true,
        'scope' => $scope,
        'account_id' => $identity,
        'month' => $monthLabel,
        'generated_at' => gmdate('c'),
        'total_transactions' => count($rows),
        'credits_in' => $creditsIn,
        'credits_out' => $creditsOut,
        'net_change' => $creditsIn - $creditsOut,
        'transactions' => $rows,
    ];

    $safeName = preg_replace('/[^A-Za-z0-9_-]+/', '_', "{$scope}_{$identity}_{$monthLabel}");

    if ($format === 'json') {
        report_json_response($summary);
    }

    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="nola_sms_report_' . $safeName . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['NOLA SMS PRO Billing Report']);
        fputcsv($out, ['Scope', $scope]);
        fputcsv($out, ['Account', $identity]);
        fputcsv($out, ['Month', $monthLabel]);
        fputcsv($out, ['Generated At', $summary['generated_at']]);
        fputcsv($out, []);
        fputcsv($out, ['ID', 'Created At', 'Type', 'Amount', 'Balance After', 'Description', 'Reference', 'Provider']);
        foreach ($rows as $row) {
            fputcsv($out, [
                $row['id'],
                $row['created_at'],
                $row['type'],
                $row['amount'],
                $row['balance_after'],
                $row['description'],
                $row['reference_id'],
                $row['provider'],
            ]);
        }
        fclose($out);
        exit;
    }

    $lines = [
        'NOLA SMS PRO Billing Report',
        'Scope: ' . $scope,
        'Account: ' . $identity,
        'Month: ' . $monthLabel,
        'Generated: ' . $summary['generated_at'],
        'Total transactions: ' . count($rows),
        'Credits in: ' . $creditsIn,
        'Credits out: ' . $creditsOut,
        'Net change: ' . ($creditsIn - $creditsOut),
        '',
        'Date | Type | Amount | Balance | Description',
    ];
    foreach ($rows as $row) {
        $lines[] = implode(' | ', [
            $row['created_at'],
            $row['type'],
            (string)$row['amount'],
            (string)$row['balance_after'],
            $row['description'],
        ]);
    }
    if (empty($rows)) {
        $lines[] = 'No transactions for this month.';
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="nola_sms_report_' . $safeName . '.pdf"');
    echo report_build_pdf($lines);
    exit;
} catch (\Throwable $e) {
    error_log('[billing/report] Failed to generate report: ' . $e->getMessage());
    report_json_response([
        'success' => false,
        'error' => 'Failed to generate billing report',
        'message' => $e->getMessage(),
    ], 500);
}

