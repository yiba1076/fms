<?php
// 1. start Session 
session_start();

// 2. Respons Header 
header('Content-Type: application/json');

// 3. Database Connection 
require_once 'db.php'; 

// 4.  check Login
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access. Please login.']);
    exit;
}

// 5. POST Request 
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    $file_id = intval($_POST['id']);

    if ($file_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid file ID.']);
        exit;
    }

    // 6.Status to 'archived' 
    $stmt = $conn->prepare("UPDATE files_registry SET status = 'archived' WHERE id = ?");
    
    if ($stmt) {
        $stmt->bind_param("i", $file_id);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'File archived successfully.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Database update failed: ' . $conn->error]);
        }
        $stmt->close();
    } else {
        echo json_encode(['success' => false, 'message' => 'Query preparation failed: ' . $conn->error]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method or missing parameters.']);
}
?>