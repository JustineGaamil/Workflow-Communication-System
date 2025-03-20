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

// Create message_attachments table if it doesn't exist
$create_attachments_table = "CREATE TABLE IF NOT EXISTS message_attachments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    message_id INT NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    file_size INT NOT NULL,
    file_type VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE
)";
$conn->query($create_attachments_table);

// Create uploads directory if it doesn't exist
$upload_dir = 'uploads/messages';
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

// Get current tab
$current_tab = isset($_GET['tab']) ? $_GET['tab'] : 'inbox';

// Handle message sending with attachments
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (isset($_POST['send_message'])) {
        $subject = $conn->real_escape_string($_POST['subject']);
        $message = $conn->real_escape_string($_POST['message']);
        $parent_id = isset($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
        $message_type = $conn->real_escape_string($_POST['message_type']);
        $is_group = isset($_POST['is_group']) && $_POST['is_group'] === '1';
        
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Insert the message
            $stmt = $conn->prepare("INSERT INTO messages (subject, message, sender_id, receiver_id, parent_id, message_type, is_group) VALUES (?, ?, ?, ?, ?, ?, ?)");
            
            if ($is_group) {
                // For group messages, set receiver_id to sender (used for sent items)
                $stmt->bind_param("ssiiiis", $subject, $message, $user_id, $user_id, $parent_id, $message_type, $is_group);
            } else {
                $receiver_id = (int)$_POST['receiver_id'];
                $stmt->bind_param("ssiiiis", $subject, $message, $user_id, $receiver_id, $parent_id, $message_type, $is_group);
            }
            
            $stmt->execute();
            $message_id = $conn->insert_id;
            $stmt->close();
            
            // Handle file uploads
            if (!empty($_FILES['attachments']['name'][0])) {
                $attachment_stmt = $conn->prepare("INSERT INTO message_attachments (message_id, file_name, file_path, file_size, file_type) VALUES (?, ?, ?, ?, ?)");
                
                foreach ($_FILES['attachments']['tmp_name'] as $key => $tmp_name) {
                    if ($_FILES['attachments']['error'][$key] === 0) {
                        $file_name = $_FILES['attachments']['name'][$key];
                        $file_type = $_FILES['attachments']['type'][$key];
                        $file_size = $_FILES['attachments']['size'][$key];
                        
                        // Generate unique filename
                        $unique_name = uniqid() . '_' . $file_name;
                        $file_path = $upload_dir . '/' . $unique_name;
                        
                        // Move uploaded file
                        if (move_uploaded_file($tmp_name, $file_path)) {
                            $attachment_stmt->bind_param("issis", $message_id, $file_name, $file_path, $file_size, $file_type);
                            $attachment_stmt->execute();
                        }
                    }
                }
                $attachment_stmt->close();
            }
            
            if ($is_group && isset($_POST['recipients']) && is_array($_POST['recipients'])) {
                // Insert recipients for group message
                $recipient_stmt = $conn->prepare("INSERT INTO message_recipients (message_id, user_id) VALUES (?, ?)");
                foreach ($_POST['recipients'] as $recipient_id) {
                    $recipient_stmt->bind_param("ii", $message_id, $recipient_id);
                    $recipient_stmt->execute();
                    
                    // Create notification for each recipient
                    $notify_stmt = $conn->prepare("INSERT INTO notifications (user_id, message_id, type) VALUES (?, ?, 'new_message')");
                    $notify_stmt->bind_param("ii", $recipient_id, $message_id);
                    $notify_stmt->execute();
                    $notify_stmt->close();
                }
                $recipient_stmt->close();
            } else if (!$is_group) {
                // Create notification for single recipient
                $notify_stmt = $conn->prepare("INSERT INTO notifications (user_id, message_id, type) VALUES (?, ?, 'new_message')");
                $notify_stmt->bind_param("ii", $receiver_id, $message_id);
                $notify_stmt->execute();
                $notify_stmt->close();
            }
            
            $conn->commit();
            $_SESSION['success_message'] = "Message sent successfully.";
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error_message'] = "Error sending message: " . $e->getMessage();
        }
        
        header("Location: inbox.php?tab=" . $current_tab);
        exit();
    } else if (isset($_POST['save_draft'])) {
        $subject = $conn->real_escape_string($_POST['subject']);
        $message = $conn->real_escape_string($_POST['message']);
        $message_type = $conn->real_escape_string($_POST['message_type']);
        
        $stmt = $conn->prepare("INSERT INTO messages (subject, message, sender_id, receiver_id, message_type, is_draft) VALUES (?, ?, ?, ?, ?, 1)");
        $stmt->bind_param("ssiis", $subject, $message, $user_id, $user_id, $message_type);
        
        if ($stmt->execute()) {
            $_SESSION['success_message'] = "Draft saved successfully.";
        } else {
            $_SESSION['error_message'] = "Error saving draft: " . $conn->error;
        }
        $stmt->close();
        
        header("Location: inbox.php?tab=drafts");
        exit();
    }
}

// Fetch messages based on current tab
switch ($current_tab) {
    case 'sent':
        $messages_query = "SELECT DISTINCT m.*, 
                          u1.first_name as sender_first_name, u1.last_name as sender_last_name,
                          CASE 
                              WHEN m.is_group = 1 THEN 'Group Message'
                              ELSE CONCAT(u2.first_name, ' ', u2.last_name)
                          END as receiver_name,
                          u2.first_name as receiver_first_name, u2.last_name as receiver_last_name
                          FROM messages m
                          JOIN users u1 ON m.sender_id = u1.id
                          LEFT JOIN users u2 ON m.receiver_id = u2.id
                          WHERE m.sender_id = ? AND m.is_draft = 0
                          ORDER BY m.created_at DESC";
        break;
        
    case 'drafts':
        $messages_query = "SELECT m.*, 
                          u1.first_name as sender_first_name, u1.last_name as sender_last_name
                          FROM messages m
                          JOIN users u1 ON m.sender_id = u1.id
                          WHERE m.sender_id = ? AND m.is_draft = 1
                          ORDER BY m.created_at DESC";
        break;
        
    default: // inbox
        $messages_query = "SELECT DISTINCT m.*, 
                          u1.first_name as sender_first_name, u1.last_name as sender_last_name,
                          u2.first_name as receiver_first_name, u2.last_name as receiver_last_name
                          FROM messages m
                          JOIN users u1 ON m.sender_id = u1.id
                          LEFT JOIN users u2 ON m.receiver_id = u2.id
                          LEFT JOIN message_recipients mr ON m.id = mr.message_id
                          WHERE (
                              (m.receiver_id = ? AND NOT m.is_group) 
                              OR (mr.user_id = ? AND m.is_group)
                          )
                          AND m.is_draft = 0
                          ORDER BY m.created_at DESC";
        break;
}

$stmt = $conn->prepare($messages_query);
if ($current_tab === 'inbox') {
    $stmt->bind_param("ii", $user_id, $user_id);
} else {
    $stmt->bind_param("i", $user_id);
}
$stmt->execute();
$messages_result = $stmt->get_result();
$stmt->close();

// Fetch all staff members for recipient selection
$staff_query = "SELECT id, first_name, last_name FROM users WHERE role = 'staff' ORDER BY last_name, first_name";
$staff_result = $conn->query($staff_query);

// Fetch message thread if viewing a specific message
$current_message = null;
if (isset($_GET['view'])) {
    $message_id = (int)$_GET['view'];
    $thread_query = "WITH RECURSIVE message_thread AS (
        -- Get the initial message and all its replies
        SELECT m.*, 1 as depth
        FROM messages m
        WHERE m.id = ? OR m.parent_id = ?
        
        UNION ALL
        
        -- Get parent messages recursively
        SELECT m.*, mt.depth + 1
        FROM messages m
        JOIN message_thread mt ON m.id = mt.parent_id
    )
    SELECT DISTINCT mt.*, 
           u1.first_name as sender_first_name, u1.last_name as sender_last_name,
           CASE 
               WHEN mt.is_group = 1 THEN 'Group Message'
               ELSE CONCAT(u2.first_name, ' ', u2.last_name)
           END as receiver_name,
           u2.first_name as receiver_first_name, u2.last_name as receiver_last_name
    FROM message_thread mt
    JOIN users u1 ON mt.sender_id = u1.id
    LEFT JOIN users u2 ON mt.receiver_id = u2.id
    ORDER BY mt.depth DESC, mt.created_at ASC";
    
    $thread_stmt = $conn->prepare($thread_query);
    $thread_stmt->bind_param("ii", $message_id, $message_id);
    $thread_stmt->execute();
    $thread_result = $thread_stmt->get_result();
    $thread_stmt->close();

    // Check if the message exists
    if ($thread_result->num_rows === 0) {
        header("Location: inbox.php");
        exit();
    }

    // Get the first message for the reply form
    $thread_result->data_seek(0);
    $current_message = $thread_result->fetch_assoc();
}

// Get unread notifications count
$notifications_query = "SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND `read` = 0";
$notify_stmt = $conn->prepare($notifications_query);
$notify_stmt->bind_param("i", $user_id);
$notify_stmt->execute();
$notify_result = $notify_stmt->get_result();
$notify_count = $notify_result->fetch_assoc()['count'];
$notify_stmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inbox - Task Management System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #4a90e2;
            --secondary-color: #f5f6fa;
            --text-color: #2c3e50;
            --light-text: #7f8c8d;
            --border-color: #e0e0e0;
            --success-color: #2ecc71;
            --danger-color: #e74c3c;
            --warning-color: #f1c40f;
            --shadow-sm: 0 2px 4px rgba(0,0,0,0.1);
            --shadow-md: 0 4px 6px rgba(0,0,0,0.1);
            --shadow-lg: 0 8px 16px rgba(0,0,0,0.1);
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 0;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            color: var(--text-color);
            height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .container {
            max-width: 1200px;
            margin: 20px auto;
            padding: 20px;
            background: white;
            border-radius: 16px;
            box-shadow: var(--shadow-lg);
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid var(--border-color);
        }

        .header h1 {
            margin: 0;
            font-size: 24px;
            color: var(--text-color);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .notification-badge {
            background: var(--danger-color);
            color: white;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 500;
        }

        .back-btn, .compose-btn {
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-weight: 500;
            transition: all 0.3s ease;
            cursor: pointer;
            border: none;
        }

        .back-btn {
            background: var(--secondary-color);
            color: var(--text-color);
            margin-right: 10px;
        }

        .compose-btn {
            background: var(--primary-color);
            color: white;
        }

        .back-btn:hover, .compose-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .message-list {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: var(--shadow-sm);
        }

        .message-item {
            display: flex;
            align-items: center;
            padding: 15px 20px;
            border-bottom: 1px solid var(--border-color);
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .message-item:hover {
            background: var(--secondary-color);
        }

        .message-item.unread {
            background: #e3f2fd;
            font-weight: 500;
        }

        .message-item.resolved {
            background: #e8f5e9;
        }

        .message-checkbox {
            margin-right: 15px;
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .message-sender {
            width: 200px;
            font-weight: 500;
        }

        .message-subject {
            flex-grow: 1;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .message-type {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
        }

        .type-concern {
            background: #ffebee;
            color: #c62828;
        }

        .type-reminder {
            background: #fff3e0;
            color: #ef6c00;
        }

        .type-casual {
            background: #e3f2fd;
            color: #1565c0;
        }

        .message-date {
            width: 150px;
            text-align: right;
            color: var(--light-text);
        }

        .compose-form {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: var(--shadow-lg);
            margin-top: 20px;
        }

        .compose-form h2 {
            margin: 0 0 20px 0;
            color: var(--text-color);
            font-size: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--text-color);
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(74, 144, 226, 0.1);
        }

        .form-group textarea {
            height: 150px;
            resize: vertical;
        }

        .button-group {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }

        .send-btn, .cancel-btn {
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
        }

        .send-btn {
            background: var(--primary-color);
            color: white;
        }

        .cancel-btn {
            background: var(--secondary-color);
            color: var(--text-color);
        }

        .send-btn:hover, .cancel-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .message-thread {
            background: white;
            border-radius: 12px;
            box-shadow: var(--shadow-lg);
            overflow: hidden;
            margin-top: 20px;
        }

        .message-thread-item {
            padding: 20px;
            border-bottom: 1px solid var(--border-color);
            position: relative;
        }

        .message-thread-item:last-child {
            border-bottom: none;
        }

        .message-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            color: var(--light-text);
            font-size: 14px;
        }

        .message-header strong {
            color: var(--text-color);
            margin-right: 5px;
            margin-left: 15px;
        }

        .message-header strong:first-child {
            margin-left: 0;
        }

        .message-content {
            white-space: pre-wrap;
            line-height: 1.6;
            color: var(--text-color);
            padding: 15px;
            background: var(--secondary-color);
            border-radius: 8px;
            margin-top: 10px;
        }

        .reply-form {
            padding: 20px;
            background: var(--secondary-color);
            border-radius: 0 0 12px 12px;
        }

        .reply-form h3 {
            margin: 0 0 20px 0;
            color: var(--text-color);
            font-size: 18px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .reply-form h3:before {
            content: '\f3e5';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            color: var(--primary-color);
        }

        .message-thread-item .message-subject {
            margin: 10px 0;
            padding: 0;
        }

        .message-thread-item .message-type {
            margin-right: 10px;
        }

        @media (max-width: 768px) {
            .container {
                margin: 10px;
                padding: 15px;
            }

            .header {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }

            .message-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }

            .message-sender, .message-date {
                width: 100%;
            }

            .message-date {
                text-align: left;
            }

            .button-group {
                flex-direction: column;
            }

            .send-btn, .cancel-btn {
                width: 100%;
            }

            .message-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }

            .message-content {
                padding: 10px;
            }

            .reply-form {
                padding: 15px;
            }
        }

        .tabs {
            display: flex;
            margin-bottom: 20px;
            background: white;
            padding: 10px;
            border-radius: 8px;
            box-shadow: var(--shadow-sm);
        }
        
        .tab {
            padding: 10px 20px;
            cursor: pointer;
            border-radius: 6px;
            margin-right: 10px;
            transition: all 0.3s ease;
            color: var(--text-color);
            text-decoration: none;
        }
        
        .tab.active {
            background: var(--primary-color);
            color: white;
        }
        
        .tab:hover:not(.active) {
            background: var(--secondary-color);
        }
        
        .recipient-selector {
            margin-top: 10px;
            display: none;
        }
        
        .recipient-selector.show {
            display: block;
        }
        
        .recipient-list {
            max-height: 150px;
            overflow-y: auto;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 10px;
            margin-top: 10px;
        }
        
        .recipient-item {
            display: flex;
            align-items: center;
            margin-bottom: 5px;
        }
        
        .recipient-item input[type="checkbox"] {
            margin-right: 10px;
        }

        .file-input {
            border: 2px dashed var(--border-color);
            padding: 20px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .file-input:hover {
            border-color: var(--primary-color);
        }

        .file-list {
            margin-top: 10px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .file-item {
            background: var(--secondary-color);
            padding: 8px 12px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
        }

        .file-item .remove-file {
            color: var(--danger-color);
            cursor: pointer;
        }

        .attachment-preview {
            margin-top: 10px;
            padding: 10px;
            background: var(--secondary-color);
            border-radius: 8px;
        }

        .attachment-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px;
            border-bottom: 1px solid var(--border-color);
        }

        .attachment-item:last-child {
            border-bottom: none;
        }

        .attachment-icon {
            color: var(--primary-color);
        }

        .attachment-name {
            flex-grow: 1;
        }

        .attachment-size {
            color: var(--light-text);
            font-size: 12px;
        }

        .attachment-download {
            color: var(--primary-color);
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Messages <?php if ($notify_count > 0): ?><span class="notification-badge"><?php echo $notify_count; ?></span><?php endif; ?></h1>
            <div>
                <a href="dashboard.php" class="back-btn">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
                <button class="compose-btn" onclick="showComposeForm()">
                    <i class="fas fa-pen"></i> Compose
                </button>
            </div>
        </div>

        <div class="tabs">
            <a href="?tab=inbox" class="tab <?php echo $current_tab === 'inbox' ? 'active' : ''; ?>">
                <i class="fas fa-inbox"></i> Inbox
            </a>
            <a href="?tab=sent" class="tab <?php echo $current_tab === 'sent' ? 'active' : ''; ?>">
                <i class="fas fa-paper-plane"></i> Sent
            </a>
            <a href="?tab=drafts" class="tab <?php echo $current_tab === 'drafts' ? 'active' : ''; ?>">
                <i class="fas fa-save"></i> Drafts
            </a>
        </div>

        <div class="message-list">
            <?php while ($message = $messages_result->fetch_assoc()): ?>
                <div class="message-item <?php echo !$message['is_read'] ? 'unread' : ''; ?> <?php echo $message['is_resolved'] ? 'resolved' : ''; ?>"
                     data-message-id="<?php echo $message['id']; ?>"
                     onclick="viewMessage(<?php echo $message['id']; ?>, '<?php echo $current_tab; ?>')">
                    <div class="message-sender">
                        <?php 
                        if ($current_tab === 'sent') {
                            echo "To: " . htmlspecialchars($message['receiver_name']);
                        } else {
                            echo "From: " . htmlspecialchars($message['sender_first_name'] . ' ' . $message['sender_last_name']);
                        }
                        ?>
                    </div>
                    <div class="message-subject">
                        <span class="message-type type-<?php echo htmlspecialchars($message['message_type']); ?>">
                            <?php echo ucfirst(htmlspecialchars($message['message_type'])); ?>
                        </span>
                        <?php 
                        echo htmlspecialchars($message['subject']);
                        if ($message['is_group']) echo ' <span class="badge badge-info">Group Message</span>';
                        ?>
                    </div>
                    <div class="message-date">
                        <?php echo date('M d, Y h:i A', strtotime($message['created_at'])); ?>
                    </div>
                </div>
            <?php endwhile; ?>
        </div>

        <?php if (isset($_GET['view'])): ?>
        <div class="message-thread">
            <?php if ($current_tab === 'drafts'): ?>
            <!-- Draft Message Display -->
            <div class="message-thread-item">
                <div class="message-header">
                    <div>
                        <strong>Draft Message</strong>
                    </div>
                    <div>
                        <?php echo date('M d, Y h:i A', strtotime($current_message['created_at'])); ?>
                    </div>
                </div>
                <div class="message-subject">
                    <span class="message-type type-<?php echo htmlspecialchars($current_message['message_type']); ?>">
                        <?php echo ucfirst(htmlspecialchars($current_message['message_type'])); ?>
                    </span>
                    <strong><?php echo htmlspecialchars($current_message['subject']); ?></strong>
                </div>
                <div class="message-content">
                    <?php echo nl2br(htmlspecialchars($current_message['message'])); ?>
                </div>
                <div class="button-group" style="padding: 20px;">
                    <button type="button" class="send-btn" onclick="editDraft(<?php echo $current_message['id']; ?>)">
                        <i class="fas fa-edit"></i> Edit Draft
                    </button>
                    <button type="button" class="cancel-btn" onclick="window.location.href='inbox.php?tab=drafts'">
                        <i class="fas fa-arrow-left"></i> Back to Drafts
                    </button>
                </div>
            </div>
            <?php else: ?>
            <!-- Regular Message Thread Display -->
            <?php while ($thread_message = $thread_result->fetch_assoc()): ?>
                <div class="message-thread-item">
                    <div class="message-header">
                        <div>
                            <strong>From:</strong> <?php echo htmlspecialchars($thread_message['sender_first_name'] . ' ' . $thread_message['sender_last_name']); ?>
                            <strong>To:</strong> <?php echo htmlspecialchars($thread_message['receiver_name']); ?>
                        </div>
                        <div>
                            <?php echo date('M d, Y h:i A', strtotime($thread_message['created_at'])); ?>
                        </div>
                    </div>
                    <div class="message-subject">
                        <span class="message-type type-<?php echo htmlspecialchars($thread_message['message_type']); ?>">
                            <?php echo ucfirst(htmlspecialchars($thread_message['message_type'])); ?>
                        </span>
                        <strong><?php echo htmlspecialchars($thread_message['subject']); ?></strong>
                    </div>
                    <div class="message-content">
                        <?php echo nl2br(htmlspecialchars($thread_message['message'])); ?>
                    </div>
                    <?php
                    // Fetch and display attachments
                    $attachment_query = "SELECT * FROM message_attachments WHERE message_id = ?";
                    $attachment_stmt = $conn->prepare($attachment_query);
                    $attachment_stmt->bind_param("i", $thread_message['id']);
                    $attachment_stmt->execute();
                    $attachments = $attachment_stmt->get_result();
                    $attachment_stmt->close();

                    if ($attachments->num_rows > 0): ?>
                    <div class="attachment-preview">
                        <div style="margin-bottom: 10px;"><strong><i class="fas fa-paperclip"></i> Attachments:</strong></div>
                        <?php while ($attachment = $attachments->fetch_assoc()): ?>
                        <div class="attachment-item">
                            <i class="fas fa-file attachment-icon"></i>
                            <span class="attachment-name"><?php echo htmlspecialchars($attachment['file_name']); ?></span>
                            <span class="attachment-size"><?php echo number_format($attachment['file_size'] / 1024, 2); ?> KB</span>
                            <a href="<?php echo htmlspecialchars($attachment['file_path']); ?>" 
                               class="attachment-download" 
                               download="<?php echo htmlspecialchars($attachment['file_name']); ?>">
                                <i class="fas fa-download"></i> Download
                            </a>
                        </div>
                        <?php endwhile; ?>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endwhile; ?>

            <?php if ($current_tab === 'inbox'): ?>
            <!-- Reply Form - Only shown in inbox -->
            <div class="reply-form">
                <h3>Reply</h3>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="parent_id" value="<?php echo $current_message['id']; ?>">
                    <div class="form-group">
                        <label>Subject</label>
                        <input type="text" name="subject" value="Re: <?php echo htmlspecialchars($current_message['subject']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Message Type</label>
                        <select name="message_type" required>
                            <option value="concern" <?php echo $current_message['message_type'] === 'concern' ? 'selected' : ''; ?>>Concern</option>
                            <option value="reminder" <?php echo $current_message['message_type'] === 'reminder' ? 'selected' : ''; ?>>Reminder</option>
                            <option value="casual" <?php echo $current_message['message_type'] === 'casual' ? 'selected' : ''; ?>>Casual</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Attachments</label>
                        <input type="file" name="attachments[]" multiple class="file-input">
                        <div class="file-list"></div>
                    </div>
                    <?php if ($current_message['is_group']): ?>
                        <input type="hidden" name="is_group" value="1">
                        <input type="hidden" name="recipients[]" value="<?php echo htmlspecialchars($current_message['sender_id']); ?>">
                    <?php else: ?>
                        <input type="hidden" name="receiver_id" value="<?php echo $current_message['sender_id']; ?>">
                    <?php endif; ?>
                    <div class="form-group">
                        <label>Message</label>
                        <textarea name="message" required></textarea>
                    </div>
                    <div class="button-group">
                        <button type="submit" name="send_message" class="send-btn">Send Reply</button>
                        <button type="button" class="cancel-btn" onclick="window.location.href='inbox.php?tab=<?php echo $current_tab; ?>'">Cancel</button>
                    </div>
                </form>
            </div>
            <?php else: ?>
            <!-- Back button for sent view -->
            <div class="button-group" style="padding: 20px;">
                <button type="button" class="cancel-btn" onclick="window.location.href='inbox.php?tab=<?php echo $current_tab; ?>'">
                    <i class="fas fa-arrow-left"></i> Back to <?php echo ucfirst($current_tab); ?>
                </button>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div id="composeForm" class="compose-form" style="display: none;">
            <h2>New Message</h2>
            <form method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label>Subject</label>
                    <input type="text" name="subject" required>
                </div>
                <div class="form-group">
                    <label>Message Type</label>
                    <select name="message_type" required>
                        <option value="concern">Concern</option>
                        <option value="reminder">Reminder</option>
                        <option value="casual">Casual</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Attachments</label>
                    <input type="file" name="attachments[]" multiple class="file-input">
                    <div class="file-list"></div>
                </div>
                <?php if ($role === 'admin'): ?>
                <div class="form-group">
                    <label>Message Type</label>
                    <select onchange="toggleRecipientSelector(this.value)">
                        <option value="single">Single Recipient</option>
                        <option value="group">Group Message</option>
                    </select>
                </div>
                <div id="singleRecipient" class="form-group">
                    <label>To</label>
                    <select name="receiver_id">
                        <?php 
                        $staff_result->data_seek(0);
                        while ($staff = $staff_result->fetch_assoc()): 
                        ?>
                            <option value="<?php echo $staff['id']; ?>">
                                <?php echo htmlspecialchars($staff['first_name'] . ' ' . $staff['last_name']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div id="groupRecipients" class="recipient-selector">
                    <label>Select Recipients</label>
                    <input type="hidden" name="is_group" id="is_group" value="0">
                    <div class="recipient-list">
                        <?php 
                        $staff_result->data_seek(0);
                        while ($staff = $staff_result->fetch_assoc()): 
                        ?>
                            <div class="recipient-item">
                                <input type="checkbox" name="recipients[]" value="<?php echo $staff['id']; ?>">
                                <?php echo htmlspecialchars($staff['first_name'] . ' ' . $staff['last_name']); ?>
                            </div>
                        <?php endwhile; ?>
                    </div>
                </div>
                <?php endif; ?>
                <div class="form-group">
                    <label>Message</label>
                    <textarea name="message" required></textarea>
                </div>
                <div class="button-group">
                    <button type="submit" name="send_message" class="send-btn">Send</button>
                    <button type="submit" name="save_draft" class="save-btn">Save as Draft</button>
                    <button type="button" class="cancel-btn" onclick="hideComposeForm()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function showComposeForm() {
        document.getElementById('composeForm').style.display = 'block';
    }

    function hideComposeForm() {
        document.getElementById('composeForm').style.display = 'none';
    }

    function toggleRecipientSelector(type) {
        const singleRecipient = document.getElementById('singleRecipient');
        const groupRecipients = document.getElementById('groupRecipients');
        const isGroupInput = document.getElementById('is_group');
        
        if (type === 'group') {
            singleRecipient.style.display = 'none';
            groupRecipients.style.display = 'block';
            isGroupInput.value = '1';
        } else {
            singleRecipient.style.display = 'block';
            groupRecipients.style.display = 'none';
            isGroupInput.value = '0';
        }
    }

    function editDraft(draftId) {
        // Show compose form
        showComposeForm();
        
        // Get the draft message details
        const subject = '<?php echo isset($current_message) ? addslashes($current_message['subject']) : ''; ?>';
        const message = '<?php echo isset($current_message) ? addslashes($current_message['message']) : ''; ?>';
        const messageType = '<?php echo isset($current_message) ? $current_message['message_type'] : ''; ?>';
        
        // Fill the compose form with draft details
        document.querySelector('#composeForm input[name="subject"]').value = subject;
        document.querySelector('#composeForm textarea[name="message"]').value = message;
        document.querySelector('#composeForm select[name="message_type"]').value = messageType;
        
        // Scroll to the compose form
        document.getElementById('composeForm').scrollIntoView({ behavior: 'smooth' });
    }

    document.querySelectorAll('.file-input').forEach(input => {
        input.addEventListener('change', function() {
            const fileList = this.closest('.form-group').querySelector('.file-list');
            fileList.innerHTML = '';
            
            Array.from(this.files).forEach(file => {
                const fileItem = document.createElement('div');
                fileItem.className = 'file-item';
                fileItem.innerHTML = `
                    <i class="fas fa-file"></i>
                    <span>${file.name}</span>
                    <i class="fas fa-times remove-file"></i>
                `;
                fileList.appendChild(fileItem);
            });
        });
    });

    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('remove-file')) {
            const fileItem = e.target.closest('.file-item');
            const fileInput = fileItem.closest('.form-group').querySelector('.file-input');
            fileItem.remove();
            fileInput.value = ''; // Reset file input
        }
    });

    function viewMessage(messageId, tab) {
        if (messageId && tab) {
            window.location.href = `inbox.php?tab=${tab}&view=${messageId}`;
        }
    }

    // Add click event listeners to message items
    document.addEventListener('DOMContentLoaded', function() {
        const messageItems = document.querySelectorAll('.message-item');
        messageItems.forEach(item => {
            item.addEventListener('click', function() {
                const messageId = this.getAttribute('data-message-id');
                const currentTab = '<?php echo $current_tab; ?>';
                if (messageId) {
                    viewMessage(messageId, currentTab);
                }
            });
        });
    });
    </script>
</body>
</html>