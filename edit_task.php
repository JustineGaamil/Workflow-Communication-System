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

// Get task ID from URL
$task_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Fetch task details
$task_query = "SELECT t.*, u2.id as assigned_to
               FROM tasks t
               JOIN users u2 ON t.assigned_to = u2.id
               WHERE t.id = ?";
$stmt = $conn->prepare($task_query);
$stmt->bind_param("i", $task_id);
$stmt->execute();
$task = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$task) {
    header("Location: manage_tasks.php");
    exit();
}

// Fetch existing attachments
$attachments_query = "SELECT * FROM task_attachments WHERE task_id = ?";
$stmt = $conn->prepare($attachments_query);
$stmt->bind_param("i", $task_id);
$stmt->execute();
$attachments = $stmt->get_result();
$stmt->close();

// Fetch all staff members for the dropdown
$staff_result = $conn->query("SELECT id, first_name, last_name FROM users WHERE role = 'staff' ORDER BY last_name, first_name");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Task</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        .container {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
        }
        .form-group input[type="text"],
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        .form-group textarea {
            height: 150px;
        }
        .button-group {
            margin-top: 20px;
            display: flex;
            gap: 10px;
        }
        .button {
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }
        .save-btn {
            background: #4CAF50;
            color: white;
        }
        .cancel-btn {
            background: #f44336;
            color: white;
        }
        .attachments-list {
            margin-top: 10px;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 4px;
        }
        .attachment-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 5px;
            margin: 5px 0;
            background: white;
            border-radius: 4px;
        }
        .delete-attachment {
            color: #dc3545;
            cursor: pointer;
            padding: 2px 8px;
            border: none;
            background: none;
            font-size: 1.2em;
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>Edit Task</h2>
        
        <form method="POST" action="manage_tasks.php" enctype="multipart/form-data">
            <input type="hidden" name="task_id" value="<?php echo $task_id; ?>">
            
            <div class="form-group">
                <label>Title</label>
                <input type="text" name="title" value="<?php echo htmlspecialchars($task['title']); ?>" required>
            </div>
            
            <div class="form-group">
                <label>Description</label>
                <textarea name="description" required><?php echo htmlspecialchars($task['description']); ?></textarea>
            </div>
            
            <div class="form-group">
                <label>Assign To</label>
                <select name="assigned_to[]" required>
                    <?php while ($staff = $staff_result->fetch_assoc()): ?>
                        <option value="<?php echo $staff['id']; ?>" <?php echo $staff['id'] == $task['assigned_to'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($staff['first_name'] . ' ' . $staff['last_name']); ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label>Due Date</label>
                <input type="datetime-local" name="due_date" 
                       value="<?php echo date('Y-m-d\TH:i', strtotime($task['due_date'])); ?>" required>
            </div>

            <div class="form-group">
                <label>Add New Attachments</label>
                <input type="file" name="attachments[]" multiple>
                <small>You can select multiple files</small>
            </div>

            <?php if ($attachments->num_rows > 0): ?>
            <div class="attachments-list">
                <label>Current Attachments</label>
                <?php while ($attachment = $attachments->fetch_assoc()): ?>
                    <div class="attachment-item">
                        <span><?php echo htmlspecialchars($attachment['file_name']); ?></span>
                        <button type="button" class="delete-attachment" 
                                onclick="deleteAttachment(<?php echo $attachment['id']; ?>)">&times;</button>
                    </div>
                <?php endwhile; ?>
            </div>
            <?php endif; ?>
            
            <div class="button-group">
                <button type="submit" name="edit_task" class="button save-btn">Save Changes</button>
                <a href="manage_tasks.php" class="button cancel-btn">Cancel</a>
            </div>
        </form>
    </div>

    <script>
    function deleteAttachment(attachmentId) {
        if (confirm('Are you sure you want to delete this attachment?')) {
            // Create a form to submit the deletion request
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'manage_tasks.php';
            
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'delete_attachment';
            input.value = attachmentId;
            
            form.appendChild(input);
            document.body.appendChild(form);
            form.submit();
        }
    }
    </script>
</body>
</html> 