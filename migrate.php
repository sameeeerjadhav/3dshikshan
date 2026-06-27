<?php
require 'db.php';
$conn = getDbConnection();
if (!$conn) { die("DB Connection failed"); }
$sql = "ALTER TABLE student_profiles 
        ADD COLUMN academic_year VARCHAR(10) NOT NULL DEFAULT 'Unknown', 
        ADD COLUMN semester VARCHAR(10) NOT NULL DEFAULT 'Unknown'";
if ($conn->query($sql)) {
    echo "Columns added successfully!";
} else {
    echo "Error: " . $conn->error;
}
$conn->close();
