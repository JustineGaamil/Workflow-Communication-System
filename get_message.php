<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Not authenticated']);
    exit();
}

$user_id = $_SESSION['user_id'];
$other_user_id = isset($_GET['user']) ? (int)$_GET['user'] : 0;

if (!$other_user_id) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid user ID']);
    exit();
}

// Connect to database
$host = "localhost"; 
$dbname = "accountant"; 
$username = "root"; 
$password = ""; 

$conn = new mysqli($host, $username, $password, $dbname);

if ($conn->connect_error) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Database connection failed']);
    exit();
}

// Fetch messages between the two users
$query = "SELECT m.*, 
          u1.first_name as sender_first_name, u1.last_name as sender_last_name,
          u2.first_name as receiver_first_name, u2.last_name as receiver_last_name
          FROM messages m
          JOIN users u1 ON m.sender_id = u1.id
          JOIN users u2 ON m.receiver_id = u2.id
          WHERE (m.sender_id = ? AND m.receiver_id = ?)
          OR (m.sender_id = ? AND m.receiver_id = ?)
          ORDER BY m.created_at ASC";

$stmt = $conn->prepare($query);
$stmt->bind_param("iiii", $user_id, $other_user_id, $other_user_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();

$messages = [];
while ($row = $result->fetch_assoc()) {
    $messages[] = [
        'id' => $row['id'],
        'message' => $row['message'],
        'sender_id' => $row['sender_id'],
        'receiver_id' => $row['receiver_id'],
        'created_at' => $row['created_at'],
        'is_read' => $row['is_read'],
        'sender_name' => $row['sender_first_name'] . ' ' . $row['sender_last_name'],
        'receiver_name' => $row['receiver_first_name'] . ' ' . $row['receiver_last_name']
    ];
}

// Mark messages as read
$update_query = "UPDATE messages 
                SET is_read = 1 
                WHERE receiver_id = ? AND sender_id = ? AND is_read = 0";
$update_stmt = $conn->prepare($update_query);
$update_stmt->bind_param("ii", $user_id, $other_user_id);
$update_stmt->execute();
$update_stmt->close();

$stmt->close();
$conn->close();

header('Content-Type: application/json');
echo json_encode($messages); 