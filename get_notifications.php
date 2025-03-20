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
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Not authenticated']);
    exit();
}

// Get notifications with context
$query = "SELECT n.*, 
          t.id AS task_id,
          m.id AS message_id,
          CASE 
            WHEN n.task_id IS NOT NULL THEN 'task'
            WHEN n.message_id IS NOT NULL THEN 'message'
            ELSE 'general'
          END AS notification_type
          FROM notifications n
          LEFT JOIN tasks t ON n.task_id = t.id
          LEFT JOIN messages m ON n.message_id = m.id
          WHERE n.user_id = ?
          ORDER BY n.created_at DESC";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();

$notifications = [];
while ($row = $result->fetch_assoc()) {
    // Format the notification context based on type
    $context = '';
    switch ($row['type']) {
        case 'task_assigned':
            $context = "Task: " . htmlspecialchars($row['task_title']) . "\n" .
                      "Description: " . htmlspecialchars($row['task_description']) . "\n" .
                      "Assigned by: " . htmlspecialchars($row['sender_first_name'] . ' ' . $row['sender_last_name']);
            break;
        case 'task_updated':
            $context = "Task: " . htmlspecialchars($row['task_title']) . "\n" .
                      "Updated by: " . htmlspecialchars($row['sender_first_name'] . ' ' . $row['sender_last_name']) . "\n" .
                      "Changes: " . htmlspecialchars($row['message']);
            break;
        case 'task_completed':
            $context = "Task: " . htmlspecialchars($row['task_title']) . "\n" .
                      "Completed by: " . htmlspecialchars($row['sender_first_name'] . ' ' . $row['sender_last_name']);
            break;
        default:
            $context = htmlspecialchars($row['message']);
    }
    
    $notifications[] = [
        'id' => $row['id'],
        'message' => $row['message'],
        'context' => $context,
        'type' => $row['type'],
        'read' => $row['read'] == 1,
        'created_at' => $row['created_at'],
        'task_id' => $row['task_id']
    ];
}

header('Content-Type: application/json');
echo json_encode($notifications); 