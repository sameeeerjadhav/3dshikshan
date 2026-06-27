<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function getDbConnection(): ?mysqli
{
    mysqli_report(MYSQLI_REPORT_OFF);

    $connection = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);

    if ($connection->connect_errno) {
        return null;
    }

    $connection->set_charset('utf8mb4');

    return $connection;
}
