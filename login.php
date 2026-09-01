<?php
session_start();
require_once 'db.php';

// ተጠቃሚው ቀደም ብሎ Login አድርጎ ከሆነ እንደ Role-ኡ ወደ ዳሽቦርዱ ይመራዋል
if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
    $role = strtolower($_SESSION['role']);
    if ($role === 'admin') {
        header("Location: admin_dashboard.php");
    } elseif ($role === 'manager') {
        header("Location: manager_dashboard.php");
    } else {
        header("Location: staff_dashboard.php");
    }
    exit();
}

$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (!empty($username) && !empty($password)) {
        try {
            // Fetch user details including status
            $stmt = $conn->prepare("SELECT id, CONCAT(COALESCE(first_name, ''), ' ', COALESCE(middle_name, ''), ' ', COALESCE(last_name, '')) AS full_name, password, role, department, COALESCE(status, 'active') AS status FROM users WHERE username = ?");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 1) {
                $user = $result->fetch_assoc();
                
                // Verify password
                if (password_verify($password, $user['password'])) {
                    
                    // Database ውስጥ 'deactive' ተብሎ የተጻፈውን ያረጋግጣል
                    $user_status = strtolower(trim($user['status']));
                    if ($user_status === 'deactive' || $user_status === 'inactive' || $user_status === '0' || $user_status === 'deactivated') {
                        $error = "This account is deactivated!";
                    } else {

                        $user_role = strtolower(trim($user['role']));

                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['full_name'] = trim($user['full_name']) !== '' ? trim($user['full_name']) : $username;
                        $_SESSION['role'] = $user_role;
                        $_SESSION['department'] = $user['department'] ?? 'General';

                        if (function_exists('logActivity')) {
                            logActivity($conn, $user['id'], 'LOGIN', 'User logged in successfully');
                        }

                        // እንደ Role-ኡ ይለየዋል
                        if ($user_role === 'admin') {
                            header("Location: admin_dashboard.php");
                        } elseif ($user_role === 'manager') {
                            header("Location: manager_dashboard.php");
                        } else {
                            header("Location: staff_dashboard.php");
                        }
                        exit();
                    }

                } else {
                    $error = "Incorrect Password!";
                }
            } else {
                $error = "Username not found!";
            }
            $stmt->close();
        } catch (mysqli_sql_exception $e) {
            $error = "Database Error: " . $e->getMessage();
        }
    } else {
        $error = "Please fill in all required fields!";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Office File Management System</title>
    <!-- Bootstrap 5 CSS -->
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <style>
        body {
            height: 100vh;
            margin: 0;
            padding: 0;
            overflow: hidden;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            position: relative;
        }

        .top-marquee {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            background: rgba(0, 10, 0, 0.7);
            color: #ffffff;
            padding: 8px 0;
            font-size: 1.1rem;
            font-weight: 600;
            letter-spacing: 1px;
            z-index: 1000;
            border-bottom: 2px solid #0d6efd;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.3);
        }

        .login-container {
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding-top: 40px;
        }

        .login-card {
            width: 100%;
            max-width: 420px;
            padding: 40px 30px;
            border-radius: 16px;
            background: rgba(19, 2, 2, 0.92);
            backdrop-filter: blur(8px);
            box-shadow: 0 15px 35px rgba(0,0,0,0.5);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .brand-title {
            color: #0d6efd;
            font-weight: 700;
        }

        .form-control {
            border-radius: 8px;
            padding: 10px 12px;
        }

        .btn-primary {
            border-radius: 8px;
            padding: 11px;
            font-size: 1rem;
        }
    </style>
</head>
<body>
    <!-- Top Scrolling Announcement Bar -->
    <div class="top-marquee">
        <marquee behavior="scroll" direction="left" scrollamount="5">
            Welcome to login Office File Management System
        </marquee>
    </div>

    <!-- Main Login Card Container -->
    <div class="login-container">
        <div class="login-card text-center">
            <div class="mb-4">
                <h3 class="brand-title">Office File Management System</h3>
                <p class="text-muted small">Please enter your login credentials</p>
            </div>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger py-2 text-center small" role="alert">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <form action="login.php" method="POST" class="text-start">
                <div class="mb-3">
                    <label for="username" class="form-label text-secondary small fw-bold">Username</label>
                    <input type="text" class="form-control" id="username" name="username" placeholder="Enter Username" required autofocus>
                </div>

                <div class="mb-3">
                    <label for="password" class="form-label text-secondary small fw-bold">Password</label>
                    <input type="password" class="form-control" id="password" name="password" placeholder="Enter password" required>
                </div>

                <button type="submit" class="btn btn-primary w-100 mt-2 fw-bold">Login</button>
            </form>

            <div class="text-center mt-4 text-muted small">
                &copy; <?php echo date('Y'); ?> Office File Management System
            </div>
        </div>
    </div>
</body>
</html>