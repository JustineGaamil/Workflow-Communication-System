<?php
session_start();
$host = "localhost"; 
$dbname = "accountant"; 
$username = "root"; 
$password = ""; 

$conn = new mysqli($host, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $first_name = $conn->real_escape_string($_POST['first_name']);
    $last_name = $conn->real_escape_string($_POST['last_name']);
    $username = $conn->real_escape_string($_POST['username']);
    $password = $conn->real_escape_string($_POST['password']);
    $role = $conn->real_escape_string($_POST['role']);
    $phone = isset($_POST['phone']) ? $conn->real_escape_string($_POST['phone']) : null;

    // Check if username already exists
    $check = $conn->prepare("SELECT id FROM users WHERE username = ?");
    $check->bind_param("s", $username);
    $check->execute();
    $result = $check->get_result();
    
    if ($result->num_rows > 0) {
        $error = "Username already exists";
    } else {
        $stmt = $conn->prepare("INSERT INTO users (first_name, last_name, username, password, role, phone) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssss", $first_name, $last_name, $username, $password, $role, $phone);
        
        if ($stmt->execute()) {
            $_SESSION['success_message'] = "Staff member registered successfully.";
            header("Location: manage_staff.php");
            exit();
        } else {
            $error = "Error creating account: " . $conn->error;
        }
        $stmt->close();
    }
    $check->close();
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register New Staff</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <section class="container">
        <h2>Register New Staff</h2>
        
        <?php if (isset($error)): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>

        <form method="POST">
            <label>First Name</label>
            <input type="text" name="first_name" required>
            
            <label>Last Name</label>
            <input type="text" name="last_name" required>
            
            <label>Username</label>
            <input type="text" name="username" required>
            
            <label>Password</label>
            <input type="password" name="password" required>

            <label>Phone Number</label>
            <input type="text" name="phone">
            
            <label>Role</label>
            <select name="role" required>
                <option value="staff">Staff</option>
                <option value="admin">Admin</option>
            </select>
            
            <button type="submit">Register</button>
        </form>
        
        <div class="action-buttons">
            <a href="manage_staff.php" class="button">Back to Manage Staff</a>
        </div>
    </section>
</body>
</html>
