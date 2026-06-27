<?php
require 'db.php';
$conn = getDbConnection();
if (!$conn) { die("DB Connection failed"); }

// 1. Add mobile_no to users if not exists
$sql1 = "ALTER TABLE users ADD COLUMN mobile_no VARCHAR(20) DEFAULT '' AFTER login_id";
if ($conn->query($sql1)) {
    echo "users.mobile_no added successfully!\n";
} else {
    echo "Error adding mobile_no: " . $conn->error . "\n";
}

// 2. Create password_resets table
$sql2 = "CREATE TABLE IF NOT EXISTS password_resets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(120) NOT NULL,
    token VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    INDEX (email)
)";
if ($conn->query($sql2)) {
    echo "password_resets table created successfully!\n";
} else {
    echo "Error creating password_resets: " . $conn->error . "\n";
}

$conn->close();
