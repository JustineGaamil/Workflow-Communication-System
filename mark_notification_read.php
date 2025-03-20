<?php
session_start();
require_once 'db_connect.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $notification_id = $data['notification_id'];

    $stmt = $conn->prepare("UPDATE notifications SET `read` = 1 WHERE id = ?");
    $stmt->bind_param("i", $notification_id);
    $stmt->execute();
    
    echo json_encode(['success' => true]);
    exit;
}
?>