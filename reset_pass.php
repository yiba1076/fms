<?php
require_once 'db.php';

// passward Hash 
$new_password = password_hash('admin123', PASSWORD_DEFAULT);

$sql = "UPDATE users SET password = '$new_password' WHERE username = 'admin'";

if ($conn->query($sql) === TRUE) {
    echo "<h3 style='color:green;'>Admin password has been successfully changed!</h3>";
    echo "<p>go to <a href='login.php'>login.php</a> back <b>admin</b> and <b>admin123</b> login</p>";
} else {
    echo "Error updating record: " . $conn->error;
}
?>