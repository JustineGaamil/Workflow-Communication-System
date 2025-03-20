<?php
// Load configuration FIRST
require_once __DIR__ . '/config.php';

// Then start session
session_start();

// Check if user is logged in and is staff
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'staff') {
    header("Location: login.php");
    exit();
}

try {
    // Create single database connection
    $conn = new mysqli($host, $db_username, $db_password, $dbname);
    
    if ($conn->connect_error) {
        throw new Exception("Database connection failed: " . $conn->connect_error);
    }

    if ($_SERVER["REQUEST_METHOD"] === "POST") {
        $task_id = (int)$_POST['task_id'];
        $user_id = $_SESSION['user_id'];
        $message = $conn->real_escape_string($_POST['message']);
        
        // Verify task assignment with error handling
        $check_query = "SELECT assigned_to, created_by FROM tasks WHERE id = ? AND assigned_to = ? AND `status` = 'pending'";
        $check_stmt = $conn->prepare($check_query);
        
        if ($check_stmt === false) {
            throw new Exception("SQL prepare error: " . $conn->error);
        }
        
        if (!$check_stmt->bind_param("ii", $task_id, $user_id)) {
            throw new Exception("Bind error: " . $check_stmt->error);
        }
        
        if (!$check_stmt->execute()) {
            throw new Exception("Execute error: " . $check_stmt->error);
        }
        
        $result = $check_stmt->get_result();
        $check_stmt->close();
        
        if ($result->num_rows === 0) {
            $_SESSION['error_message'] = "Invalid task or you don't have permission to submit this task.";
            header("Location: staff_tasks.php");
            exit();
        }
        
        $task = $result->fetch_assoc();
        
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Handle file upload
            if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = 'uploads/';
                
                // Create uploads directory if it doesn't exist
                if (!file_exists($upload_dir) && !mkdir($upload_dir, 0777, true)) {
                    throw new Exception("Failed to create upload directory");
                }
                
                $file_info = pathinfo($_FILES['attachment']['name']);
                $safe_filename = preg_replace('/[^a-zA-Z0-9-_\.]/', '', $file_info['filename']);
                $unique_filename = $safe_filename . '_' . uniqid() . '.' . $file_info['extension'];
                $upload_path = $upload_dir . $unique_filename;
                
                if (move_uploaded_file($_FILES['attachment']['tmp_name'], $upload_path)) {
                    // Update task status
                    $update_query = "UPDATE tasks SET status = 'completed', completion_message = ?, completed_at = NOW() WHERE id = ?";
                    $update_stmt = $conn->prepare($update_query);
                    
                    if (!$update_stmt) {
                        throw new Exception("Prepare error: " . $conn->error);
                    }
                    
                    if (!$update_stmt->bind_param("si", $message, $task_id)) {
                        throw new Exception("Bind error: " . $update_stmt->error);
                    }
                    
                    if (!$update_stmt->execute()) {
                        throw new Exception("Execute error: " . $update_stmt->error);
                    }
                    $update_stmt->close();
                    
                    // Insert attachment
                    $attachment_query = "INSERT INTO task_attachments (task_id, file_name, file_path) VALUES (?, ?, ?)";
                    $attachment_stmt = $conn->prepare($attachment_query);
                    
                    if (!$attachment_stmt) {
                        throw new Exception("Prepare error: " . $conn->error);
                    }
                    
                    $original_name = $_FILES['attachment']['name'];
                    if (!$attachment_stmt->bind_param("iss", $task_id, $original_name, $upload_path)) {
                        throw new Exception("Bind error: " . $attachment_stmt->error);
                    }
                    
                    if (!$attachment_stmt->execute()) {
                        throw new Exception("Execute error: " . $attachment_stmt->error);
                    }
                    $attachment_stmt->close();
                    
                    // Create notification
                    $notification_query = "INSERT INTO notifications (user_id, message, type, task_id, created_at) VALUES (?, ?, 'task_submitted', ?, NOW())";
                    $notification_stmt = $conn->prepare($notification_query);
                    $notification_message = "Task #$task_id has been submitted with an attachment.";
                    
                    if (!$notification_stmt) {
                        throw new Exception("Prepare error: " . $conn->error);
                    }
                    
                    if (!$notification_stmt->bind_param("isi", $task['created_by'], $notification_message, $task_id)) {
                        throw new Exception("Bind error: " . $notification_stmt->error);
                    }
                    
                    if (!$notification_stmt->execute()) {
                        throw new Exception("Execute error: " . $notification_stmt->error);
                    }
                    $notification_stmt->close();
                    
                    $conn->commit();
                    $_SESSION['success_message'] = "Task submitted successfully with attachment.";
                } else {
                    throw new Exception("Failed to move uploaded file");
                }
            } else {
                throw new Exception("File upload error: " . ($_FILES['attachment']['error'] ?? 'No file uploaded'));
            }
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error_message'] = "Error submitting task: " . $e->getMessage();
        }
    } else {
        $_SESSION['error_message'] = "Invalid request method.";
    }

    // Change the redirect at the end of the file
header("Location: dashboard.php");  // Instead of staff_tasks.php
exit();

} catch (Exception $e) {
    error_log("System Error: " . $e->getMessage());
    $_SESSION['error_message'] = "A system error occurred. Please try again later.";
    header("Location: staff_tasks.php");
    exit();
}
?>

<!-- HTML remains the same -->


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Submit Task</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .task-info {
            margin-bottom: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 4px;
        }
        .attachments {
            margin: 20px 0;
        }
        .attachment-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 4px;
            margin-bottom: 5px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        textarea {
            width: 100%;
            min-height: 150px;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            resize: vertical;
        }
        .btn-submit {
            background: #28a745;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        .btn-submit:hover {
            background: #218838;
        }
        .error {
            color: #dc3545;
            margin-bottom: 15px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>Submit Task</h2>

        <!-- Add this after the <h2> tag in the HTML section -->
<?php if (isset($_SESSION['error_message'])): ?>
    <div class="alert alert-danger">
        <?= $_SESSION['error_message']; ?>
        <?php unset($_SESSION['error_message']); ?>
    </div>
<?php endif; ?>

<?php if (isset($_SESSION['success_message'])): ?>
    <div class="alert alert-success">
        <?= $_SESSION['success_message']; ?>
        <?php unset($_SESSION['success_message']); ?>
    </div>
<?php endif; ?>
        
        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="error"><?php echo $_SESSION['error_message']; ?></div>
        <?php endif; ?>
        
        <div class="task-info">
            <h3><?php echo htmlspecialchars($task['title']); ?></h3>
            <p><?php echo htmlspecialchars($task['description']); ?></p>
            <p>Due: <?php echo htmlspecialchars($task['due_date']); ?></p>
            
            <?php if (!empty($attachments)): ?>
                <div class="attachments">
                    <h4>Task Attachments:</h4>
                    <?php foreach ($attachments as $attachment): ?>
                        <div class="attachment-item">
                            <i class="fas fa-paperclip"></i>
                            <a href="<?php echo htmlspecialchars($attachment['file_path']); ?>" target="_blank">
                                <?php echo htmlspecialchars($attachment['file_name']); ?>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="task_id" value="<?php echo $task_id; ?>">
            
            <div class="form-group">
                <label for="message">Message:</label>
                <textarea name="message" id="message" required></textarea>
            </div>
            
            <div class="form-group">
                <label for="attachment">Attach File:</label>
                <input type="file" name="attachment" id="attachment">
            </div>
            
            <button type="submit" class="btn-submit">Submit Task</button>
        </form>
    </div>
</body>
</html> 