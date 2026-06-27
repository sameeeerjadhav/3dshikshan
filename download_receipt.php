<?php
declare(strict_types=1);

session_start();

$user = $_SESSION['user'] ?? null;
if (!$user) {
    header('Location: index.php?login=1');
    exit;
}

if (($user['role'] ?? '') !== 'student') {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$paymentId = (int)($_GET['payment_id'] ?? 0);
if ($paymentId <= 0) {
    http_response_code(400);
    echo 'Invalid payment id.';
    exit;
}

$conn = getDbConnection();
if ($conn === null) {
    http_response_code(500);
    echo 'Database connection failed.';
    exit;
}

$sql = '
    SELECT
        rp.id,
        rp.razorpay_order_id,
        rp.razorpay_payment_id,
        rp.amount_rupees,
        rp.currency,
        rp.status,
        rp.created_at,
        sp.first_name,
        sp.middle_name,
        sp.last_name,
        sp.email,
        sp.mobile_no,
        c.name AS college_name,
        cr.course_name,
        cr.duration
    FROM registration_payments rp
    INNER JOIN student_profiles sp ON sp.id = rp.student_profile_id
    LEFT JOIN colleges c ON c.id = sp.college_id
    LEFT JOIN courses cr ON cr.id = sp.course_id
    WHERE rp.id = ? AND sp.user_id = ?
    LIMIT 1
';

$stmt = $conn->prepare($sql);
if ($stmt === false) {
    $conn->close();
    http_response_code(500);
    echo 'Unable to prepare receipt query.';
    exit;
}

$userId = (int)$user['id'];
$stmt->bind_param('ii', $paymentId, $userId);
$stmt->execute();
$result = $stmt->get_result();
$receipt = $result ? $result->fetch_assoc() : null;
$stmt->close();
$conn->close();

if (!$receipt) {
    http_response_code(404);
    echo 'Receipt not found.';
    exit;
}

function pdfEscape(string $value): string
{
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);
}

function lineText(string $value, int $maxLen = 95): string
{
    $text = trim($value);
    if ($text === '') {
        return '-';
    }
    if (strlen($text) > $maxLen) {
        return substr($text, 0, $maxLen - 3) . '...';
    }
    return $text;
}

function pdfTextAt(float $x, float $y, int $fontSize, string $text, float $gray = 0.0): string
{
    return "BT\n"
    . number_format($gray, 3, '.', '') . " g\n"
        . "/F1 " . $fontSize . " Tf\n"
        . "1 0 0 1 " . number_format($x, 2, '.', '') . " " . number_format($y, 2, '.', '') . " Tm\n"
        . "(" . pdfEscape($text) . ") Tj\n"
        . "ET\n";
}

function pdfTextAtWithFont(float $x, float $y, int $fontSize, string $text, string $font = 'F1', float $gray = 0.0): string
{
    return "BT\n"
    . number_format($gray, 3, '.', '') . " g\n"
        . "/" . $font . " " . $fontSize . " Tf\n"
        . "1 0 0 1 " . number_format($x, 2, '.', '') . " " . number_format($y, 2, '.', '') . " Tm\n"
        . "(" . pdfEscape($text) . ") Tj\n"
        . "ET\n";
}

$studentName = trim(
    (string)$receipt['first_name'] . ' ' .
    (string)$receipt['middle_name'] . ' ' .
    (string)$receipt['last_name']
);

$amount = number_format((float)$receipt['amount_rupees'], 2);
$status = strtoupper((string)$receipt['status']);
$currency = (string)$receipt['currency'];
$createdAtRaw = (string)$receipt['created_at'];
$createdAt = $createdAtRaw;
$dt = DateTime::createFromFormat('Y-m-d H:i:s', $createdAtRaw);
if ($dt instanceof DateTime) {
    $createdAt = $dt->format('d M Y, h:i A');
}
$receiptNo = 'REC-' . str_pad((string)$receipt['id'], 6, '0', STR_PAD_LEFT);
$fileName = 'fee-receipt-' . (int)$receipt['id'] . '.pdf';
$companyName = defined('RAZORPAY_COMPANY') && RAZORPAY_COMPANY !== '' ? RAZORPAY_COMPANY : '3D SHIKSHAN';

$studentInfoRows = [
    ['Student Name', lineText($studentName, 50), 'Email', lineText((string)$receipt['email'], 52)],
    ['Mobile', lineText((string)$receipt['mobile_no'], 50), 'College', lineText((string)($receipt['college_name'] ?? ''), 52)],
    ['Course', lineText((string)($receipt['course_name'] ?? ''), 50), 'Duration', lineText((string)($receipt['duration'] ?? ''), 52)],
];

$paymentInfoRows = [
    ['Amount Paid', $currency . ' ' . $amount],
    ['Status', lineText($status, 70)],
    ['Payment ID', lineText((string)$receipt['razorpay_payment_id'], 70)],
    ['Order ID', lineText((string)$receipt['razorpay_order_id'], 70)],
    ['Paid On', lineText($createdAt, 70)],
];

$content = "q\n";

// Page border and body background
$content .= "0.95 0.96 0.98 rg 22 22 551 798 re f\n";
$content .= "0.82 0.86 0.92 RG 1.2 w 22 22 551 798 re S\n";

// Watermark (receipt number)
$content .= pdfTextAtWithFont(120, 415, 58, $receiptNo, 'F3', 0.92);

// Header
$content .= "0.11 0.32 0.56 rg 34 752 527 62 re f\n";
$content .= pdfTextAtWithFont(46, 787, 18, strtoupper(lineText($companyName, 28)), 'F2', 1.00);
$content .= pdfTextAtWithFont(46, 769, 10, 'STUDENT FEE RECEIPT', 'F1', 1.00);
$content .= pdfTextAtWithFont(364, 787, 10, 'Receipt No: ' . $receiptNo, 'F2', 1.00);
$content .= pdfTextAtWithFont(364, 771, 10, 'Issued On: ' . lineText($createdAt, 28), 'F1', 1.00);

// Section heading helper rectangles
$content .= "0.89 0.94 0.99 rg 34 710 527 24 re f\n";
$content .= "0.79 0.86 0.95 RG 1 w 34 710 527 24 re S\n";
$content .= pdfTextAtWithFont(44, 717, 11, 'STUDENT & COURSE INFORMATION', 'F2');

// Student information table
$tableX = 34;
$tableTopY = 710;
$rowH = 28;
$tableW = 527;
$labelW = 110;
$valueW = 156;
$label2W = 102;
$studentRowsCount = count($studentInfoRows);
$tableH = $rowH * $studentRowsCount;
$x1 = $tableX + $labelW;
$x2 = $x1 + $valueW;
$x3 = $x2 + $label2W;

$content .= "0.79 0.86 0.95 RG 1 w {$tableX} " . ($tableTopY - $tableH) . " {$tableW} {$tableH} re S\n";
$content .= "0.88 0.93 0.99 rg {$tableX} " . ($tableTopY - $tableH) . " {$labelW} {$tableH} re f\n";
$content .= "0.88 0.93 0.99 rg {$x2} " . ($tableTopY - $tableH) . " {$label2W} {$tableH} re f\n";

$content .= "0.79 0.86 0.95 RG 1 w {$x1} " . ($tableTopY - $tableH) . " m {$x1} {$tableTopY} l S\n";
$content .= "0.79 0.86 0.95 RG 1 w {$x2} " . ($tableTopY - $tableH) . " m {$x2} {$tableTopY} l S\n";
$content .= "0.79 0.86 0.95 RG 1 w {$x3} " . ($tableTopY - $tableH) . " m {$x3} {$tableTopY} l S\n";

for ($i = 1; $i < $studentRowsCount; $i++) {
    $y = $tableTopY - ($i * $rowH);
    $content .= "0.79 0.86 0.95 RG 1 w {$tableX} {$y} m " . ($tableX + $tableW) . " {$y} l S\n";
}

for ($i = 0; $i < $studentRowsCount; $i++) {
    $row = $studentInfoRows[$i];
    $textY = $tableTopY - (($i + 1) * $rowH) + 9;
    $content .= pdfTextAtWithFont($tableX + 8, $textY, 9, $row[0], 'F2');
    $content .= pdfTextAtWithFont($x1 + 8, $textY, 9, $row[1], 'F1');
    $content .= pdfTextAtWithFont($x2 + 8, $textY, 9, $row[2], 'F2');
    $content .= pdfTextAtWithFont($x3 + 8, $textY, 9, $row[3], 'F1');
}

// Payment heading
$paymentHeadY = 590;
$content .= "0.89 0.94 0.99 rg 34 {$paymentHeadY} 527 24 re f\n";
$content .= "0.79 0.86 0.95 RG 1 w 34 {$paymentHeadY} 527 24 re S\n";
$content .= pdfTextAtWithFont(44, $paymentHeadY + 7, 11, 'PAYMENT INFORMATION', 'F2');

// Payment table
$pTableTopY = $paymentHeadY;
$pRowH = 28;
$pRowsCount = count($paymentInfoRows);
$pTableH = $pRowH * $pRowsCount;
$pTableX = 34;
$pTableW = 527;
$pLabelW = 185;
$pValX = $pTableX + $pLabelW;

$content .= "0.79 0.86 0.95 RG 1 w {$pTableX} " . ($pTableTopY - $pTableH) . " {$pTableW} {$pTableH} re S\n";
$content .= "0.88 0.93 0.99 rg {$pTableX} " . ($pTableTopY - $pTableH) . " {$pLabelW} {$pTableH} re f\n";
$content .= "0.79 0.86 0.95 RG 1 w {$pValX} " . ($pTableTopY - $pTableH) . " m {$pValX} {$pTableTopY} l S\n";

for ($i = 1; $i < $pRowsCount; $i++) {
    $y = $pTableTopY - ($i * $pRowH);
    $content .= "0.79 0.86 0.95 RG 1 w {$pTableX} {$y} m " . ($pTableX + $pTableW) . " {$y} l S\n";
}

for ($i = 0; $i < $pRowsCount; $i++) {
    $row = $paymentInfoRows[$i];
    $textY = $pTableTopY - (($i + 1) * $pRowH) + 9;
    $content .= pdfTextAtWithFont($pTableX + 8, $textY, 9, $row[0], 'F2');
    $content .= pdfTextAtWithFont($pValX + 8, $textY, 9, $row[1], 'F1');
}

// Highlight total paid row area
$content .= "0.93 0.98 0.94 rg " . ($pValX + 1) . " " . ($pTableTopY - $pRowH + 1) . " " . ($pTableW - $pLabelW - 2) . " " . ($pRowH - 2) . " re f\n";
$content .= pdfTextAtWithFont($pValX + 8, $pTableTopY - $pRowH + 9, 9, $paymentInfoRows[0][1], 'F2');

// Footer
$content .= "0.90 0.92 0.96 RG 1 w 34 92 527 48 re S\n";
$content .= pdfTextAtWithFont(44, 122, 9, 'This is a system-generated receipt and does not require physical signature.', 'F1');
$content .= pdfTextAtWithFont(44, 106, 8, 'Generated for: ' . lineText((string)$receipt['email'], 42), 'F1');
$content .= pdfTextAtWithFont(412, 122, 9, 'Authorized Signatory', 'F2');
$content .= pdfTextAtWithFont(412, 106, 8, lineText($companyName, 24), 'F1');

$content .= "Q\n";

$objects = [];
$objects[] = "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
$objects[] = "2 0 obj\n<< /Type /Pages /Count 1 /Kids [3 0 R] >>\nendobj\n";
$objects[] = "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R /F2 5 0 R /F3 6 0 R >> >> /Contents 7 0 R >>\nendobj\n";
$objects[] = "4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n";
$objects[] = "5 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>\nendobj\n";
$objects[] = "6 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Oblique >>\nendobj\n";
$objects[] = "7 0 obj\n<< /Length " . strlen($content) . " >>\nstream\n" . $content . "endstream\nendobj\n";

$pdf = "%PDF-1.4\n";
$offsets = [0];
for ($i = 0; $i < count($objects); $i++) {
    $offsets[] = strlen($pdf);
    $pdf .= $objects[$i];
}

$xrefPos = strlen($pdf);
$pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
$pdf .= "0000000000 65535 f \n";
for ($i = 1; $i <= count($objects); $i++) {
    $pdf .= str_pad((string)$offsets[$i], 10, '0', STR_PAD_LEFT) . " 00000 n \n";
}

$pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
$pdf .= "startxref\n" . $xrefPos . "\n%%EOF";

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $fileName . '"');
header('Content-Length: ' . strlen($pdf));

echo $pdf;
