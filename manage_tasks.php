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

// Handle task creation
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['create_task'])) {
    $title = $conn->real_escape_string($_POST['title']);
    $description = $conn->real_escape_string($_POST['description']);
    $due_date = $conn->real_escape_string($_POST['due_date']);
    $assigned_to = isset($_POST['assigned_to']) ? $_POST['assigned_to'][0] : null; // Take first selected staff
    
    if ($assigned_to) {
        // Create task
        $create_task = $conn->prepare("INSERT INTO tasks (title, description, created_by, assigned_to, due_date, status) VALUES (?, ?, ?, ?, ?, 'pending')");
        $create_task->bind_param("ssiis", $title, $description, $_SESSION['user_id'], $assigned_to, $due_date);
        
        if ($create_task->execute()) {
            $task_id = $conn->insert_id;
            
            // Handle file uploads
            if (!empty($_FILES['attachments']['name'][0])) {
                $upload_dir = "uploads/";
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                foreach ($_FILES['attachments']['tmp_name'] as $key => $tmp_name) {
                    $file_name = $_FILES['attachments']['name'][$key];
                    $file_size = $_FILES['attachments']['size'][$key];
                    $file_tmp = $_FILES['attachments']['tmp_name'][$key];
                    $file_type = $_FILES['attachments']['type'][$key];
                    
                    // Generate unique filename
                    $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                    $unique_file_name = uniqid() . '.' . $file_ext;
                    $upload_path = $upload_dir . $unique_file_name;
                    
                    if (move_uploaded_file($file_tmp, $upload_path)) {
                        // Save file information to database
                        $stmt = $conn->prepare("INSERT INTO task_attachments (task_id, file_name, file_path, uploaded_by) VALUES (?, ?, ?, ?)");
                        $stmt->bind_param("issi", $task_id, $file_name, $unique_file_name, $_SESSION['user_id']);
                        $stmt->execute();
                        $stmt->close();
                    }
                }
            }
            
            $_SESSION['success_message'] = "Task created successfully.";
        } else {
            $_SESSION['error_message'] = "Error creating task: " . $conn->error;
        }
    } else {
        $_SESSION['error_message'] = "Please select a staff member to assign the task to.";
    }
    
    header("Location: manage_tasks.php");
    exit();
}

// Handle task deletion
if (isset($_POST['delete_task'])) {
    $task_id = (int)$_POST['task_id'];
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Delete task attachments
        $delete_attachments = $conn->prepare("DELETE FROM task_attachments WHERE task_id = ?");
        $delete_attachments->bind_param("i", $task_id);
        $delete_attachments->execute();
        
        // Delete task submissions
        $delete_submissions = $conn->prepare("DELETE FROM task_submissions WHERE task_id = ?");
        $delete_submissions->bind_param("i", $task_id);
        $delete_submissions->execute();
        
        // Delete task
        $delete_task = $conn->prepare("DELETE FROM tasks WHERE id = ?");
        $delete_task->bind_param("i", $task_id);
        $delete_task->execute();
        
        $conn->commit();
        $_SESSION['success_message'] = "Task deleted successfully.";
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error_message'] = "Error deleting task: " . $e->getMessage();
    }
    
    header("Location: manage_tasks.php");
    exit();
}

// Handle attachment deletion
if (isset($_POST['delete_attachment'])) {
    $attachment_id = (int)$_POST['delete_attachment'];
    
    // Get the file name first
    $stmt = $conn->prepare("SELECT file_name FROM task_attachments WHERE id = ?");
    $stmt->bind_param("i", $attachment_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $attachment = $result->fetch_assoc();
    $stmt->close();
    
    if ($attachment) {
        // Delete the physical file
        $file_path = "uploads/" . $attachment['file_name'];
        if (file_exists($file_path)) {
            unlink($file_path);
        }
        
        // Delete the database record
        $stmt = $conn->prepare("DELETE FROM task_attachments WHERE id = ?");
        $stmt->bind_param("i", $attachment_id);
        $stmt->execute();
        $stmt->close();
    }
    
    header("Location: edit_task.php?id=" . $_POST['task_id']);
    exit();
}

// Handle task editing
if (isset($_POST['edit_task'])) {
    $task_id = (int)$_POST['task_id'];
    $title = $_POST['title'];
    $description = $_POST['description'];
    $assigned_to = $_POST['assigned_to'][0]; // Get first selected staff
    $due_date = $_POST['due_date'];
    
    // Update task details
    $stmt = $conn->prepare("UPDATE tasks SET title = ?, description = ?, assigned_to = ?, due_date = ? WHERE id = ?");
    $stmt->bind_param("ssisi", $title, $description, $assigned_to, $due_date, $task_id);
    $stmt->execute();
    $stmt->close();
    
    // Handle new file attachments
    if (isset($_FILES['attachments']) && !empty($_FILES['attachments']['name'][0])) {
        // Create uploads directory if it doesn't exist
        if (!file_exists('uploads')) {
            mkdir('uploads', 0777, true);
        }
        
        // Process each uploaded file
        foreach ($_FILES['attachments']['tmp_name'] as $key => $tmp_name) {
            if ($_FILES['attachments']['error'][$key] === 0) {
                $file_name = uniqid() . '_' . $_FILES['attachments']['name'][$key];
                $file_path = 'uploads/' . $file_name;
                
                if (move_uploaded_file($tmp_name, $file_path)) {
                    // Save attachment record in database
                    $stmt = $conn->prepare("INSERT INTO task_attachments (task_id, file_name) VALUES (?, ?)");
                    $stmt->bind_param("is", $task_id, $file_name);
                    $stmt->execute();
                    $stmt->close();
                }
            }
        }
    }
    
    header("Location: manage_tasks.php");
    exit();
}

// Fetch all staff members for the dropdown
$staff_result = $conn->query("SELECT id, first_name, last_name FROM users WHERE role = 'staff' ORDER BY last_name, first_name");

// Fetch all tasks with staff names and attachments
$tasks_query = "SELECT t.*, 
                u1.first_name as creator_first_name, u1.last_name as creator_last_name,
                u2.first_name as assigned_first_name, u2.last_name as assigned_last_name,
                GROUP_CONCAT(ta.file_name) as attachments
                FROM tasks t
                JOIN users u1 ON t.created_by = u1.id
                JOIN users u2 ON t.assigned_to = u2.id
                LEFT JOIN task_attachments ta ON t.id = ta.task_id
                GROUP BY t.id
                ORDER BY t.created_at DESC";
$tasks_result = $conn->query($tasks_query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Tasks</title>
    <link rel="stylesheet" href="styles.css">
    <style>
    .container {
        display: flex;
        height: 100vh;
        max-width: 100%;
        margin: 0;
        padding: 20px;
        gap: 30px;
        background: #f8fafc;
    }

    .creation-panel {
        flex: 0 0 400px;
        padding: 25px;
        background: white;
        border-radius: 12px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        height: calc(100vh - 40px);
        overflow-y: auto;
    }

    .tasks-panel {
        flex: 1;
        padding: 25px;
        background: white;
        border-radius: 12px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        height: calc(100vh - 40px);
        overflow-y: auto;
    }

    .compact-form {
        display: grid;
        grid-template-columns: 1fr;
        gap: 15px;
    }

    .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 15px;
    }

    .staff-select-container {
        height: 120px;
        overflow-y: auto;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        padding: 8px;
    }

    .staff-select {
        width: 100%;
        border: none;
        background: none;
    }

    .task-card {
        padding: 16px;
        margin-bottom: 12px;
        background: white;
        border: 1px solid #f1f5f9;
        border-radius: 8px;
        transition: transform 0.1s ease;
    }

    .management-grid {
        display: grid;
        grid-template-columns: 1fr 1.5fr;
        gap: 40px;
        margin-top: 30px;
    }

    .creation-section {
        padding: 25px;
        background: #f9fafb;
        border-radius: 10px;
    }

    .tasks-section {
        padding: 25px;
        background: #fff;
        border-radius: 10px;
        max-height: 75vh;
        overflow-y: auto;
    }

    .form-group {
        margin-bottom: 20px;
    }

    .form-label {
        display: block;
        margin-bottom: 8px;
        font-weight: 500;
        color: #374151;
    }

    .form-input {
        width: 100%;
        padding: 10px 12px;
        border: 1px solid #e5e7eb;
        border-radius: 6px;
        background: #fff;
        transition: border-color 0.2s;
    }

    .form-input:focus {
        outline: none;
        border-color: #3b82f6;
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    }

    .staff-select {
        height: 150px;
        padding: 8px;
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 6px;
    }

    .staff-select option {
        padding: 8px;
        margin: 2px 0;
        border-radius: 4px;
    }

    .staff-select option:hover {
        background: #f3f4f6;
    }

    .task-card {
        padding: 20px;
        margin-bottom: 15px;
        background: #fff;
        border-radius: 8px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.04);
        transition: transform 0.1s ease;
    }

    .task-card:hover {
        transform: translateY(-2px);
    }

    .task-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 12px;
    }

    .task-title {
        font-size: 1.1rem;
        font-weight: 600;
        color: #1f2937;
    }

    .status-pill {
        padding: 4px 10px;
        border-radius: 16px;
        font-size: 0.8rem;
        font-weight: 500;
    }

    .status-pending {
        background: #ffedd5;
        color: #9a3412;
    }

    .status-completed {
        background: #dcfce7;
        color: #166534;
    }

    .deadline-text {
        color: #dc2626;
        font-size: 0.9rem;
        font-weight: 500;
    }

    .attachment-badge {
        display: inline-flex;
        align-items: center;
        padding: 4px 8px;
        margin: 4px;
        background: #f3f4f6;
        border-radius: 6px;
        font-size: 0.85rem;
    }

    .action-buttons {
        display: flex;
        gap: 10px;
        margin-top: 15px;
    }

    .btn {
        padding: 8px 16px;
        border: none;
        border-radius: 6px;
        font-weight: 500;
        cursor: pointer;
        transition: opacity 0.2s;
    }

    .btn-primary {
        background: #3b82f6;
        color: white;
    }

    .btn-edit {
        background: #10b981;
        color: white;
    }

    .btn-delete {
        background: #ef4444;
        color: white;
    }

    .btn:hover {
        opacity: 0.9;
    }

    .scroll-note {
        color: #6b7280;
        font-size: 0.9rem;
        text-align: center;
        padding: 10px;
        border-top: 1px solid #e5e7eb;
        margin-top: 20px;
    }

    @media (max-width: 768px) {
        .management-grid {
            grid-template-columns: 1fr;
        }
        
        .tasks-section {
            max-height: none;
        }
    }
    {
    margin: 15px 0;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        padding: 15px;
    }

    .search-container {
        position: relative;
        margin-bottom: 10px;
    }

    .search-input {
        width: 100%;
        padding: 8px 35px 8px 12px;
        border: 1px solid #cbd5e1;
        border-radius: 6px;
    }

    .search-icon {
        position: absolute;
        right: 10px;
        top: 50%;
        transform: translateY(-50%);
        color: #64748b;
    }

    .staff-list {
        max-height: 200px;
        overflow-y: auto;
        margin: 10px 0;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        padding: 5px;
        display: none;
    }

    .staff-item {
        padding: 8px 12px;
        cursor: pointer;
        border-radius: 4px;
    }

    .staff-item:hover {
        background: #f1f5f9;
    }

    .selected-staff {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 10px;
    }

    .staff-tag {
        background: #e2e8f0;
        padding: 4px 8px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .remove-tag {
        cursor: pointer;
        color: #64748b;
    }

    .group-buttons {
        display: flex;
        gap: 8px;
        margin-top: 10px;
        flex-wrap: wrap;
    }

    .group-btn {
        padding: 6px 12px;
        border: 1px solid #cbd5e1;
        border-radius: 20px;
        background: #f8fafc;
        cursor: pointer;
        transition: all 0.2s;
    }

    .group-btn:hover {
        background: #e2e8f0;
    }

    .hidden-assignees {
        display: none;
    }
    .assignment-container {
        margin: 15px 0;
        position: relative;
    }

    .search-group-wrapper {
        display: flex;
        gap: 10px;
        margin-bottom: 10px;
    }

    .search-input {
        flex: 1;
        padding: 8px 12px;
        border: 1px solid #ddd;
        border-radius: 4px;
    }

    .group-toggle {
        padding: 8px 15px;
        background:rgb(138, 162, 186);
        border: 1px solid #ddd;
        border-radius: 4px;
        cursor: pointer;
        transition: all 0.2s;
        width: 100px;
    }

    .group-toggle.active {
        background: #007bff;
        color: white;
        border-color: #007bff;
    }

    .staff-results {
        position: absolute;
        width: 100%;
        max-height: 200px;
        overflow-y: auto;
        background: white;
        border: 1px solid #ddd;
        border-radius: 4px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        z-index: 100;
        display: none;
    }

    .staff-item {
        padding: 8px 12px;
        cursor: pointer;
        transition: background 0.2s;
    }

    .staff-item:hover {
        background: #f8f9fa;
    }

    .selected-staff {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 10px;
    }

    .staff-tag {
        background: #e9ecef;
        padding: 4px 8px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .remove-tag {
        cursor: pointer;
        color: #6c757d;
    }

    .hidden-assignees {
        display: none;
    }
    </style>
</head>
<body>
<div class="container">
            <!-- Creation Panel -->
        <div class="creation-panel">
            <h2>Create New Task</h2>
            <form method="POST" enctype="multipart/form-data">
                <div class="form-row">
                    <div>
                        <label>Title</label>
                        <input type="text" name="title" class="form-input" required>
                    </div>
                    <div>
                        <label>Due Date</label>
                        <input type="datetime-local" name="due_date" class="form-input" required>
                    </div>
                </div>

                <div>
                    <label>Description</label>
                    <textarea name="description" class="form-input" rows="3" required></textarea>
                </div>

                <div class="assignment-container">
                    <label>Assign To</label>
                    <div class="search-group-wrapper">
                        <input type="text" 
                               class="search-input" 
                               placeholder="Search staff..."
                               id="staffSearch">
                        <button type="button" 
                                class="group-toggle" 
                                id="groupToggle">Group</button>
                    </div>

                    <div class="staff-results" id="staffResults">
                        <?php 
                        $staff_result->data_seek(0);
                        while ($staff = $staff_result->fetch_assoc()): 
                        ?>
                            <div class="staff-item" 
                                 data-id="<?= $staff['id'] ?>"
                                 data-name="<?= htmlspecialchars($staff['first_name'].' '.$staff['last_name']) ?>">
                                <?= htmlspecialchars($staff['first_name'].' '.$staff['last_name']) ?>
                            </div>
                        <?php endwhile; ?>
                    </div>

                    <div class="selected-staff" id="selectedStaff"></div>
                    <input type="hidden" name="assigned_to" id="assignedTo">
                </div>




                <div>
                    <label>Attachments (optional)</label>
                    <input type="file" name="attachments[]" multiple class="form-input">
                </div>

                <button type="submit" name="create_task" class="btn-primary">Create Task</button>
            </form>
        </div>

        <div class="tasks-panel">
            <h2 style="margin-bottom: 25px; font-size: 1.4rem;">Active Tasks</h2>
            <div class="task-list">

            <!-- Task List -->
            <div class="tasks-section">
                <h2 style="font-size: 1.3rem; color: #1f2937; margin-bottom: 20px;">Active Tasks</h2>
                
                <div class="task-list">
                    <?php while ($task = $tasks_result->fetch_assoc()): ?>
                    <div class="task-card">
                        <div class="task-header">
                            <div class="task-title"><?= htmlspecialchars($task['title']) ?></div>
                            <div class="status-pill status-<?= strtolower($task['status']) ?>">
                                <?= ucfirst($task['status']) ?>
                            </div>
                        </div>

                        <div style="margin-bottom: 12px;">
                            <span class="deadline-text">
                                Due: <?= date('M j, Y \a\t g:i a', strtotime($task['due_date'])) ?>
                            </span>
                        </div>

                        <div style="color: #4b5563; font-size: 0.95rem; line-height: 1.5;">
                            <?= nl2br(htmlspecialchars($task['description'])) ?>
                        </div>

                        <?php if ($task['attachments']): ?>
                        <div style="margin-top: 15px;">
                            <div style="font-size: 0.9rem; color: #6b7280; margin-bottom: 8px;">
                                Attachments:
                            </div>
                            <div style="display: flex; flex-wrap: wrap; gap: 6px;">
                                <?php foreach (explode(',', $task['attachments']) as $attachment): ?>
                                <span class="attachment-badge">
                                    <?= htmlspecialchars($attachment) ?>
                                </span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="action-buttons">
                            <a href="edit_task.php?id=<?= $task['id'] ?>" class="btn btn-edit">Edit</a>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
                                <button type="submit" name="delete_task" class="btn btn-delete"
                                        onclick="return confirm('Are you sure you want to delete this task?')">
                                    Delete
                                </button>
                            </form>
                        </div>
                    </div>
                    <?php endwhile; ?>
                </div>

                <div class="scroll-note">
                    <?= $tasks_result->num_rows ?> tasks found • Scroll to view more
                </div>
            </div>
        </div>
    </section>
    <div style="text-align: center; margin-top: 20px;">
            <a href="dashboard.php" class="button">Back to Dashboard</a>
        </div>

    <script>
    let isGroupMode = false;
    const selectedStaff = new Set();

    // Toggle group mode
    document.getElementById('groupToggle').addEventListener('click', function() {
        isGroupMode = !isGroupMode;
        this.classList.toggle('active', isGroupMode);
        selectedStaff.clear();
        updateSelectedDisplay();
        document.getElementById('staffSearch').value = '';
        document.getElementById('staffResults').style.display = 'none';
    });

    // Search functionality
    document.getElementById('staffSearch').addEventListener('input', function(e) {
        const searchTerm = e.target.value.toLowerCase();
        const staffItems = document.querySelectorAll('.staff-item');
        const staffResults = document.getElementById('staffResults');

        staffResults.style.display = searchTerm ? 'block' : 'none';
        
        staffItems.forEach(item => {
            const name = item.dataset.name.toLowerCase();
            item.style.display = name.includes(searchTerm) ? 'block' : 'none';
        });
    });

    // Staff selection
    document.getElementById('staffResults').addEventListener('click', function(e) {
        if (e.target.classList.contains('staff-item')) {
            const staffId = e.target.dataset.id;
            const staffName = e.target.dataset.name;

            if (isGroupMode) {
                // Group mode: toggle selection
                if (selectedStaff.has(staffId)) {
                    selectedStaff.delete(staffId);
                } else {
                    selectedStaff.add(staffId);
                }
            } else {
                // Single mode: replace selection
                selectedStaff.clear();
                selectedStaff.add(staffId);
            }

            updateSelectedDisplay();
            updateHiddenInput();
            document.getElementById('staffSearch').value = '';
            document.getElementById('staffResults').style.display = 'none';
        }
    });

    // Update selected display
    function updateSelectedDisplay() {
        const container = document.getElementById('selectedStaff');
        container.innerHTML = '';

        selectedStaff.forEach(id => {
            const staffItem = document.querySelector(`.staff-item[data-id="${id}"]`);
            if (staffItem) {
                const name = staffItem.dataset.name;
                container.innerHTML += `
                    <div class="staff-tag">
                        <span>${name}</span>
                        ${isGroupMode ? `<span class="remove-tag" data-id="${id}">×</span>` : ''}
                    </div>
                `;
            }
        });

        // Add remove functionality in group mode
        if (isGroupMode) {
            document.querySelectorAll('.remove-tag').forEach(tag => {
                tag.addEventListener('click', function() {
                    selectedStaff.delete(this.dataset.id);
                    updateSelectedDisplay();
                    updateHiddenInput();
                });
            });
        }
    }

    // Update hidden input
    function updateHiddenInput() {
        document.getElementById('assignedTo').value = Array.from(selectedStaff).join(',');
    }
    </script>
</body>
</html>