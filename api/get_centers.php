<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

$conn = getDbConnection();
if ($conn === null) {
    echo json_encode(['ok' => false, 'error' => 'Database connection failed.']);
    exit;
}

$sql = '
    SELECT 
        c.id AS college_id, 
        c.name AS college_name, 
        c.district, 
        c.state, 
        c.country,
        coord.first_name, 
        coord.last_name, 
        coord.mobile_no,
        coord.address_line1,
        coord.address_line2
    FROM colleges c
    LEFT JOIN coordinator_colleges cc ON c.id = cc.college_id
    LEFT JOIN coordinators coord ON cc.coordinator_id = coord.id
    ORDER BY c.district ASC, c.name ASC
';

$result = $conn->query($sql);
$centers = [];

if ($result instanceof mysqli_result) {
    while ($row = $result->fetch_assoc()) {
        $collegeId = (int)$row['college_id'];
        
        if (!isset($centers[$collegeId])) {
            $centers[$collegeId] = [
                'id' => $collegeId,
                'name' => $row['college_name'],
                'district' => $row['district'],
                'state' => $row['state'],
                'country' => $row['country'],
                'coordinators' => []
            ];
        }

        if ($row['first_name'] !== null) {
            $centers[$collegeId]['coordinators'][] = [
                'name' => trim($row['first_name'] . ' ' . $row['last_name']),
                'mobile_no' => $row['mobile_no'],
                'address' => trim($row['address_line1'] . ' ' . $row['address_line2'])
            ];
        }
    }
    $result->free();
}

$conn->close();

echo json_encode([
    'ok' => true,
    'centers' => array_values($centers)
]);
