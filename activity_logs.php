<?php
session_start();
require_once 'db.php';

// Security Check 1: Ensure user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Security Check 2: ONLY ADMIN can view this page
$user_role = $_SESSION['role'] ?? '';
if (strtolower($user_role) !== 'admin') {
    if ($user_role === 'manager') {
        header("Location: manager_dashboard.php");
    } else {
        header("Location: staff_dashboard.php");
    }
    exit();
}

// Fetch all activity logs across all user roles (Admin, Manager, and Staff)
$sql = "SELECT audit_logs.*, users.username, users.role 
        FROM audit_logs 
        LEFT JOIN users ON audit_logs.user_id = users.id 
        ORDER BY audit_logs.id DESC";

$result = $conn->query($sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Activity Logs - Admin Only</title>
   <link rel="stylesheet" href="assets/css/bootstrap.min.css">
  <link rel="stylesheet" href="assets/icons/bootstrap-icons.min.css"> 
    <style>
        body { background-color: #f4f6f9; }
        .logs-card { margin: 30px auto; border-radius: 12px; border: none; }
    </style>
</head>
<body>

<div class="container py-4">
    <div class="card logs-card shadow-sm p-4 bg-white">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="fw-bold mb-0 text-primary">
                    <i class="bi bi-shield-lock-fill me-2"></i>System Activity Logs
                </h4>
                <small class="text-muted">Audit trail for all users (Admin, Manager, Staff) - Admin View Only</small>
            </div>
            <a href="admin_dashboard.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i>Back to Admin Dashboard
            </a>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle border">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>User</th>
                        <th>Role</th>
                        <th>Action</th>
                        <th>Details</th>
                        <th>Timestamp</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result && $result->num_rows > 0): ?>
                        <?php $count = 1; ?>
                        <?php while ($row = $result->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo $count++; ?></td>
                                <td class="fw-bold">
                                    <?php echo htmlspecialchars($row['username'] ?? 'System / Deleted User'); ?>
                                </td>
                                <td>
                                    <?php 
                                        $role = strtolower($row['role'] ?? 'guest');
                                        $badge_class = 'bg-secondary';
                                        if ($role === 'admin') $badge_class = 'bg-danger';
                                        elseif ($role === 'manager') $badge_class = 'bg-warning text-dark';
                                        elseif ($role === 'staff') $badge_class = 'bg-info text-dark';
                                    ?>
                                    <span class="badge <?php echo $badge_class; ?>">
                                        <?php echo strtoupper($role); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border">
                                        <?php echo htmlspecialchars($row['action']); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($row['details']); ?></td>
                                <td class="small text-muted">
                                    <?php echo date('M d, Y - h:i A', strtotime($row['created_at'])); ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="text-center py-4 text-muted">No activity logs found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

</body>
</html>