<?php
// Set timezone for proper time tracking
date_default_timezone_set('Africa/Addis_Ababa');

// Database Connection Credentials
$servername = "127.0.0.1";
$username   = "root"; 
$password   = ""; 
$dbname     = "fms"; 

// Enable full error reporting for debugging
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli($servername, $username, $password, $dbname);
    $conn->set_charset("utf8mb4");
} catch (mysqli_sql_exception $e) {
    die("Database Connection Failed: " . $e->getMessage());
}

// Global Activity Logger Function
if (!function_exists('logActivity')) {
    function logActivity($conn, $user_id, $action, $details) {
        if (empty($user_id)) {
            return false;
        }

        try {
            $stmt = $conn->prepare("INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)");
            if ($stmt) {
                $stmt->bind_param("iss", $user_id, $action, $details);
                $result = $stmt->execute();
                $stmt->close();
                return $result;
            }
        } catch (mysqli_sql_exception $e) {
            return false;
        }
        return false;
    }
}
?>