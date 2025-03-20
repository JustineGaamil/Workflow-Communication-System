<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
$host = "localhost"; 
$dbname = "accountant"; 
$username = "root"; 
$password = ""; 

$conn = new mysqli($host, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

// Get user ID from URL
$edit_user_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Check permissions
if ($role === 'staff' && $edit_user_id !== $user_id) {
    $_SESSION['error_message'] = "You can only edit your own profile";
    header("Location: manage_staff.php");
    exit();
}

$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param("i", $edit_user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['update_user'])) {
    $update_id = (int)$_POST['user_id'];
    
    // Re-fetch user data for role reference
    $stmt = $conn->prepare("SELECT role, password FROM users WHERE id = ?");
    $stmt->bind_param("i", $update_id);
    $stmt->execute();
    $current_user_data = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Verify permissions
    if ($role === 'admin' || ($role === 'staff' && $update_id === $user_id)) {
        // Collect form data
        $first_name = $_POST['first_name'];
        $last_name = $_POST['last_name'];
        $middle_name = $_POST['middle_name'];
        $username = $_POST['username'];
        $phone = $_POST['phone'];
        $new_role = isset($_POST['role']) ? $_POST['role'] : $current_user_data['role'];
        
        // Initialize password variables
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        // Start building query
        $query = "UPDATE users SET 
            first_name = ?, 
            last_name = ?, 
            middle_name = ?, 
            username = ?, 
            phone = ?";
        $types = "sssss";
        $params = [
            $first_name, 
            $last_name, 
            $middle_name, 
            $username, 
            $phone
        ];
        
        // Handle password update
        $password_updated = false;
        if (!empty($current_password) || !empty($new_password) || !empty($confirm_password)) {
            // Verify current password
            if (!password_verify($current_password, $current_user_data['password'])) {
                $_SESSION['error_message'] = "Current password is incorrect";
                header("Location: edit_staff.php?id=".$update_id);
                exit();
            }
            
            if ($new_password !== $confirm_password) {
                $_SESSION['error_message'] = "New passwords don't match";
                header("Location: edit_staff.php?id=".$update_id);
                exit();
            }
            
            if (strlen($new_password) < 6) {
                $_SESSION['error_message'] = "Password must be at least 6 characters";
                header("Location: edit_staff.php?id=".$update_id);
                exit();
            }
            
            $query .= ", password = ?";
            $types .= "s";
            $params[] = password_hash($new_password, PASSWORD_DEFAULT);
            $password_updated = true;
        }
        
        // Add role update for admin
        if ($role === 'admin') {
            $query .= ", role = ?";
            $types .= "s";
            $params[] = $new_role;
        }
        
        // Finalize query
        $query .= " WHERE id = ?";
        $types .= "i";
        $params[] = $update_id;
        
        // Prepare and execute statement
        $stmt = $conn->prepare($query);
        if (!$stmt) {
            die("Prepare failed: " . $conn->error);
        }
        
        $stmt->bind_param($types, ...$params);
        if (!$stmt->execute()) {
            die("Execute failed: " . $stmt->error);
        }
        
        // Check if any rows were affected
        if ($stmt->affected_rows > 0) {
            $_SESSION['success_message'] = "Profile updated successfully!";
        } else {
            $_SESSION['error_message'] = "No changes were made";
        }
        $stmt->close();
    }
    
    header("Location: edit_staff.php?id=".$update_id);
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit User</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        .user-card {
            background: white;
            padding: 20px;
            margin: 20px auto;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            max-width: 600px;
        }
        .form-group {
            margin-bottom: 15px;
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        .form-group input, .form-group select {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        .save-btn {
            background: #4CAF50;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            width: 100%;
            margin-top: 15px;
        }
        .back-btn {
            display: inline-block;
            margin-bottom: 20px;
            padding: 8px 16px;
            background: #007bff;
            color: white;
            text-decoration: none;
            border-radius: 4px;
        }
        .success, .error {
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 15px;
            text-align: center;
        }
        .success {
            background: #d4edda;
            color: #155724;
        }
        .error {
            background: #f8d7da;
            color: #721c24;
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="manage_staff.php" class="back-btn">← Back to Staff List</a>
        <h2>Edit User: <?php echo htmlspecialchars($user['first_name'].' '.$user['last_name']); ?></h2>
        
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="success"><?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?></div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="error"><?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?></div>
        <?php endif; ?>

        <div class="user-card">
            <form method="POST">
                <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                
                <div class="form-group">
                    <div>
                        <label>First Name</label>
                        <input type="text" name="first_name" value="<?php echo htmlspecialchars($user['first_name']); ?>" required>
                    </div>
                    <div>
                        <label>Last Name</label>
                        <input type="text" name="last_name" value="<?php echo htmlspecialchars($user['last_name']); ?>" required>
                    </div>
                    <div>
                        <label>Middle Name</label>
                        <input type="text" name="middle_name" value="<?php echo htmlspecialchars($user['middle_name'] ?? ''); ?>">
                    </div>
                </div>
                
                <div class="form-group">
                    <div>
                        <label>Username</label>
                        <input type="text" name="username" value="<?php echo htmlspecialchars($user['username']); ?>" required>
                    </div>
                    <div>
                        <label>Phone Number</label>
                        <input type="text" name="phone" value="<?php echo htmlspecialchars($user['phone']); ?>">
                    </div>
                </div>
                
                <div class="form-group">
                    <div>
                        <label>Current Password</label>
                        <input type="password" name="current_password" placeholder="Enter current password to change">
                    </div>
                    <div>
                        <label>New Password</label>
                        <input type="password" name="new_password" placeholder="New password">
                    </div>
                </div>
                
                <div class="form-group">
                    <div>
                        <label>Confirm New Password</label>
                        <input type="password" name="confirm_password" placeholder="Confirm new password">
                    </div>
                    <?php if ($role === 'admin'): ?>
                    <div>
                        <label>Role</label>
                        <select name="role">
                            <option value="admin" <?php echo $user['role'] === 'admin' ? 'selected' : ''; ?>>Admin</option>
                            <option value="staff" <?php echo $user['role'] === 'staff' ? 'selected' : ''; ?>>Staff</option>
                        </select>
                    </div>
                    <?php endif; ?>
                </div>

                <button type="submit" name="update_user" class="save-btn">Save Changes</button>
            </form>
        </div>
    </div>

    <script>
    function validateForm(form) {
        // ... [keep the existing validation script unchanged] ...
    }

    // Fade out messages
    document.addEventListener('DOMContentLoaded', function() {
        var messages = document.querySelectorAll('.success, .error');
        messages.forEach(function(msg) {
            setTimeout(function() {
                msg.style.display = 'none';
            }, 3000);
        });
    });
    </script>
</body>
</html>