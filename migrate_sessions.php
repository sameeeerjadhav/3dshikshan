<?php
require 'db.php';
$conn = getDbConnection();
if (!$conn) { die("DB Connection failed"); }

$sql = "ALTER TABLE coordinator_sessions 
        ADD COLUMN session_type VARCHAR(50) NOT NULL DEFAULT 'Class',
        ADD COLUMN notes VARCHAR(2000) NOT NULL DEFAULT ''";

if ($conn->query($sql)) {
    echo "Columns 'session_type' and 'notes' added successfully to coordinator_sessions!";
} else {
    echo "Error: " . $conn->error;
}
$conn->close();
