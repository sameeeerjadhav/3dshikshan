<?php
require 'db.php';
$conn = getDbConnection();
if (!$conn) { die("DB Connection failed"); }
$sql = "ALTER TABLE colleges 
        ADD COLUMN address VARCHAR(255) NOT NULL DEFAULT '', 
        ADD COLUMN latitude VARCHAR(50) NOT NULL DEFAULT '', 
        ADD COLUMN longitude VARCHAR(50) NOT NULL DEFAULT ''";
if ($conn->query($sql)) {
    echo "Columns added successfully!";
} else {
    echo "Error: " . $conn->error;
}
$conn->close();
