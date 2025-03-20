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

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            margin: 0;
            padding: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
        }
        .container {
            max-width: 600px;
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
            text-align: center;
        }
        h2 {
            color: #333;
        }
        .profile-img {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            object-fit: cover;
            margin-bottom: 15px;
            border: 3px solid #007BFF;
        }
        .staff-info-list {
            background: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-top: 10px;
        }
        .info-item {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid #eee;
            font-size: 16px;
        }
        .info-item:last-child {
            border-bottom: none;
        }
        .info-label {
            font-weight: bold;
            color: #007BFF;
        }
        .info-value {
            color: #333;
        }
        .btn {
            background: #007BFF;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            margin-top: 15px;
            text-decoration: none;
            display: inline-block;
        }
        .btn:hover {
            background: #0056b3;
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>My Profile</h2>
        <div class="staff-info-list">
            <div class="info-item">
                <div class="info-label">Name:</div>
                <div class="info-value"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Position:</div>
                <div class="info-value"><?php echo ucfirst($user['role']); ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Phone Number:</div>
                <div class="info-value"><?php echo htmlspecialchars($user['phone']); ?></div>
            </div>
        </div>
        <div style="text-align: center; margin-top: 20px;">
            <a href="dashboard.php" class="button">Back to Dashboard</a>
        </div>
    </div>
</body>
</html>
