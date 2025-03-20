<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$role = $_SESSION['role'];

// Connect to database
$host = "localhost"; 
$dbname = "accountant"; 
$db_username = "root"; 
$db_password = ""; 

$conn = new mysqli($host, $db_username, $db_password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Fetch tasks based on role
if ($role === 'admin') {
    // Fetch all tasks for admin
    $tasks_query = "SELECT t.*, 
                    u1.first_name as creator_first_name, u1.last_name as creator_last_name,
                    u2.first_name as assigned_first_name, u2.last_name as assigned_last_name,
                    GROUP_CONCAT(DISTINCT ta.file_name) as attachment_names
                    FROM tasks t
                    JOIN users u1 ON t.created_by = u1.id
                    JOIN users u2 ON t.assigned_to = u2.id
                    LEFT JOIN task_attachments ta ON t.id = ta.task_id
                    GROUP BY t.id
                    ORDER BY t.created_at DESC";
} else {
    // Fetch tasks assigned to staff member
    $tasks_query = "SELECT t.*, 
                    u.first_name as creator_first_name, u.last_name as creator_last_name,
                    GROUP_CONCAT(DISTINCT ta.file_name) as attachment_names
                    FROM tasks t
                    JOIN users u ON t.created_by = u.id
                    LEFT JOIN task_attachments ta ON t.id = ta.task_id
                    WHERE t.assigned_to = ?
                    GROUP BY t.id
                    ORDER BY t.created_at DESC";
    $stmt = $conn->prepare($tasks_query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $tasks_result = $stmt->get_result();
    $stmt->close();
}

if ($role === 'admin') {
    $tasks_result = $conn->query($tasks_query);
}

// Get task counts for the calendar
// Modified SQL query to include overdue status
$task_counts_query = "SELECT 
    DATE(due_date) as date,
    CASE 
        WHEN status = 'pending' AND due_date < NOW() THEN 'overdue'
        ELSE status
    END as calculated_status,
    COUNT(*) as count
FROM tasks
WHERE created_by = ? OR assigned_to = ?
GROUP BY DATE(due_date), calculated_status";
$count_stmt = $conn->prepare($task_counts_query);
$count_stmt->bind_param("ii", $user_id, $user_id);
$count_stmt->execute();
$task_counts = $count_stmt->get_result();
$count_stmt->close();

// Prepare task counts for calendar
// Fix the calendar data preparation
$calendar_data = [];
while ($row = $task_counts->fetch_assoc()) {
    $date = $row['date'];
    $status = $row['calculated_status'];
    $count = $row['count'];
    
    if (!isset($calendar_data[$date])) {
        $calendar_data[$date] = [
            'pending' => 0,
            'overdue' => 0,
            'completed' => 0
        ];
    }
    
    $calendar_data[$date][$status] = $count;
}

$completed_tasks_query = "SELECT t.*, 
    u1.first_name AS creator_fname, 
    u2.first_name AS assignee_fname
    FROM tasks t
    JOIN users u1 ON t.created_by = u1.id
    JOIN users u2 ON t.assigned_to = u2.id
    WHERE t.status = 'completed'
    ORDER BY t.updated_at DESC";

$completed_tasks = $conn->query($completed_tasks_query);
// Get unread notifications count
$notifications_query = "SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND `read` = 0";
$notif_stmt = $conn->prepare($notifications_query);
$notif_stmt->bind_param("i", $user_id);
$notif_stmt->execute();
$notifications_count = $notif_stmt->get_result()->fetch_assoc()['count'];
$notif_stmt->close();


?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js"></script>
    <style>
        :root {
            --primary-color: #2c3e50;
            --secondary-color: #3498db;
            --success-color: #2ecc71;
            --warning-color: #f1c40f;
            --danger-color: #e74c3c;
            --light-bg: #f8f9fa;
            --dark-text: #2c3e50;
            --light-text: #666;
            --border-color: #e0e0e0;
            --shadow-sm: 0 2px 10px rgba(0, 0, 0, 0.1);
            --shadow-md: 0 4px 20px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 5px 30px rgba(0, 0, 0, 0.2);
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 0;
            background: url('image.png') no-repeat center center fixed;
            background-size: cover;
            color: var(--dark-text);
            line-height: 1.6;
        }

        .dashboard-container {
            display: flex;
            height: 100vh;
            margin: 20px;
            gap: 25px;
            max-width: 1600px;
            margin: 20px auto;
        }

        .sidebar {
            width: 280px;
            background: rgba(255, 255, 255, 0.98);
            padding: 30px;
            border-radius: 20px;
            box-shadow: var(--shadow-md);
            backdrop-filter: blur(10px);
            display: flex;
            flex-direction: column;
        }

        .sidebar h2 {
            color: var(--primary-color);
            font-size: 24px;
            margin-bottom: 30px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--border-color);
        }

        .main-content {
            flex: 1;
            padding: 20px;
            overflow-y: auto;
        }

        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            background: rgba(255, 255, 255, 0.98);
            padding: 25px;
            border-radius: 20px;
            box-shadow: var(--shadow-md);
            backdrop-filter: blur(10px);
        }

        .top-bar h2 {
            color: var(--primary-color);
            margin: 0;
            font-size: 28px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .icons {
            display: flex;
            gap: 20px;
        }

        .icons i {
            font-size: 22px;
            cursor: pointer;
            color: var(--primary-color);
            transition: all 0.3s ease;
            position: relative;
            padding: 10px;
            border-radius: 50%;
            background: var(--light-bg);
        }

        .icons i:hover {
            color: var(--secondary-color);
            transform: translateY(-2px);
            background: white;
            box-shadow: var(--shadow-sm);
        }

        .action-button {
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--primary-color);
            color: white;
            padding: 16px;
            border-radius: 15px;
            font-size: 16px;
            cursor: pointer;
            width: 100%;
            margin-bottom: 15px;
            text-decoration: none;
            transition: all 0.3s ease;
            border: none;
            box-shadow: var(--shadow-sm);
            gap: 12px;
        }

        .action-button:hover {
            background: var(--secondary-color);
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .action-button i {
            font-size: 18px;
        }

        .task-item {
            background: white;
            padding: 25px;
            margin-bottom: 25px;
            border-radius: 15px;
            box-shadow: var(--shadow-sm);
            transition: all 0.3s ease;
            border: 1px solid var(--border-color);
        }

        .task-item:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-md);
        }

        .task-item h5 {
            margin: 0 0 20px 0;
            color: var(--primary-color);
            font-size: 20px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .task-meta {
            background: var(--light-bg);
            padding: 20px;
            border-radius: 12px;
            margin: 20px 0;
            border-left: 4px solid var(--secondary-color);
        }

        .task-meta p {
            margin: 10px 0;
            color: var(--light-text);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border-radius: 25px;
            font-size: 14px;
            font-weight: 500;
            margin-top: 15px;
        }

        .status-pending {
            background: #fff3cd;
            color: #856404;
        }

        .status-completed {
            background: #d4edda;
            color: #155724;
        }

        .status-approved {
            background: #cce5ff;
            color: #004085;
        }

        .status-rejected {
            background: #f8d7da;
            color: #721c24;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            z-index: 1000;
            backdrop-filter: blur(5px);
        }

        .modal-content {
            position: relative;
            background: white;
            margin: 3% auto;
            padding: 35px;
            width: 95%;
            max-width: 1200px;
            border-radius: 20px;
            box-shadow: var(--shadow-lg);
        }

        .close-modal {
            position: absolute;
            right: 30px;
            top: 20px;
            font-size: 32px;
            cursor: pointer;
            color: var(--light-text);
            transition: all 0.3s ease;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }

        .close-modal:hover {
            color: var(--primary-color);
            background: var(--light-bg);
        }

        .task-columns {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 30px;
            margin-top: 30px;
        }

        .task-column {
            background: var(--light-bg);
            padding: 25px;
            border-radius: 15px;
            box-shadow: var(--shadow-sm);
        }

        .task-column h4 {
            margin: 0 0 25px 0;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--border-color);
            color: var(--primary-color);
            font-size: 22px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: var(--danger-color);
            color: white;
            border-radius: 50%;
            padding: 4px 8px;
            font-size: 12px;
            font-weight: bold;
            box-shadow: var(--shadow-sm);
            min-width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .notification-item {
            padding: 15px 20px;
            border-bottom: 1px solid var(--border-color);
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: flex-start;
            gap: 15px;
            position: relative;
        }

        .notification-item:last-child {
            border-bottom: none;
        }

        .notification-item.unread {
            background: #e3f2fd;
        }

        .notification-item.unread::before {
            content: '';
            position: absolute;
            left: 0;
            top: 50%;
            transform: translateY(-50%);
            width: 4px;
            height: 70%;
            background: var(--secondary-color);
            border-radius: 0 4px 4px 0;
        }

        .notification-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--secondary-color);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 18px;
        }

        .notification-content {
            flex: 1;
        }

        .notification-item .message {
            margin: 0;
            color: var(--dark-text);
            font-size: 15px;
            line-height: 1.5;
            margin-bottom: 5px;
        }

        .notification-item .timestamp {
            font-size: 12px;
            color: var(--light-text);
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .notification-item .timestamp i {
            font-size: 12px;
        }

        .notification-item:hover {
            background: var(--light-bg);
            transform: translateX(5px);
        }

        #notificationsList {
            max-height: 500px;
            overflow-y: auto;
            padding: 10px;
        }

        #notificationsList::-webkit-scrollbar {
            width: 6px;
        }

        #notificationsList::-webkit-scrollbar-track {
            background: var(--light-bg);
            border-radius: 3px;
        }

        #notificationsList::-webkit-scrollbar-thumb {
            background: var(--secondary-color);
            border-radius: 3px;
        }

        #notificationsList::-webkit-scrollbar-thumb:hover {
            background: var(--primary-color);
        }

        .notification-empty {
            padding: 40px 20px;
            text-align: center;
            color: var(--light-text);
        }

        .notification-empty i {
            font-size: 48px;
            color: var(--secondary-color);
            margin-bottom: 15px;
        }

        .notification-empty p {
            margin: 0;
            font-size: 16px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 12px 24px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            font-size: 15px;
        }

        .btn-review {
            background: var(--success-color);
            color: white;
        }

        .btn-edit {
            background: var(--secondary-color);
            color: white;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .attachments {
            margin: 20px 0;
            padding: 20px;
            background: var(--light-bg);
            border-radius: 12px;
            border-left: 4px solid var(--secondary-color);
        }

        .attachments h6 {
            margin: 0 0 15px 0;
            color: var(--primary-color);
            font-size: 16px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .attachment-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .attachment-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            background: white;
            border-radius: 8px;
            margin-bottom: 10px;
            transition: all 0.3s ease;
        }

        .attachment-item:hover {
            background: var(--light-bg);
            transform: translateX(5px);
        }

        .attachment-item i {
            color: var(--secondary-color);
            font-size: 16px;
        }

        .attachment-item a {
            color: var(--dark-text);
            text-decoration: none;
            font-size: 14px;
            flex: 1;
        }

        .attachment-item a:hover {
            color: var(--secondary-color);
        }

        .submission-info {
            margin-top: 25px;
            padding: 25px;
            background: var(--light-bg);
            border-radius: 12px;
            border-left: 4px solid var(--success-color);
        }

        .submission-info h6 {
            margin: 0 0 20px 0;
            color: var(--primary-color);
            font-size: 18px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .task-actions {
            margin-top: 25px;
            display: flex;
            gap: 20px;
        }

        #calendar {
            background: white;
            padding: 25px;
            border-radius: 20px;
            box-shadow: var(--shadow-md);
        }

        .fc-event {
            border-radius: 8px;
            padding: 6px 10px;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none !important;
        }

        .fc-event:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .fc-toolbar-title {
            font-size: 22px !important;
            font-weight: 600 !important;
            color: var(--primary-color) !important;
        }

        .fc-button {
            background: var(--primary-color) !important;
            border: none !important;
            padding: 10px 20px !important;
            border-radius: 8px !important;
            transition: all 0.3s ease !important;
            font-weight: 500 !important;
        }

        .fc-button:hover {
            background: var(--secondary-color) !important;
            transform: translateY(-2px);
        }

        .fc-button-active {
            background: var(--secondary-color) !important;
        }

        .fc-day-today {
            background: #e3f2fd !important;
        }

        .fc-highlight {
            background: #e3f2fd !important;
        }

        /* Loading State */
        .loading {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            color: var(--light-text);
            font-size: 16px;
            gap: 10px;
        }

        .loading i {
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--light-text);
        }

        .empty-state i {
            font-size: 48px;
            margin-bottom: 15px;
            color: var(--secondary-color);
        }

        .empty-state p {
            font-size: 16px;
            margin: 0;
        }

        /* Responsive Design */
        @media (max-width: 1200px) {
            .dashboard-container {
                margin: 10px;
            }

            .sidebar {
                width: 250px;
            }
        }

        @media (max-width: 992px) {
            .dashboard-container {
                flex-direction: column;
            }

            .sidebar {
                width: 100%;
                margin-bottom: 20px;
            }

            .main-content {
                width: 100%;
            }

            .task-columns {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .top-bar {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }

            .icons {
                margin-top: 10px;
            }

            .modal-content {
                margin: 5% auto;
                padding: 20px;
            }
        }

        .notification {
            cursor: pointer;
            padding: 10px;
            margin: 5px 0;
            border-radius: 4px;
            transition: background-color 0.2s;
        }

        .notification:hover {
            background-color: #f5f5f5;
        }

        .notification.unread {
            background-color: #e8f4fd;
            border-left: 3px solid #2196F3;
        }

        .notification.read {
            background-color: #fff;
            border-left: 3px solid #ddd;
        }

        .notification-message {
            margin-bottom: 5px;
        }

        .notification-time {
            font-size: 0.8em;
            color: #666;
        }
        /* Add to your CSS */
.task-container {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.task-columns {
    grid-template-columns: repeat(3, minmax(300px, 1fr));
    gap: 15px;
    align-items: start;
}

.task-column {
    background: white;
    padding: 20px;
    border-radius: 10px;
    box-shadow: var(--shadow-sm);
    min-height: 400px;
}

.task-column h4 {
    font-size: 18px;
    padding-bottom: 10px;
    margin-bottom: 15px;
    color: var(--primary-color);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.task-list-container {
    background: #fff;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    padding: 10px;
    min-height: 300px;
}

.missing-task {
    background: #fff3cd;
    border-left: 4px solid #ffc107;
}
/* Overdue tasks styling */
.missing-task {
    border-left: 4px solid #e74c3c;
    background: #fee;
    animation: pulse-warning 1.5s infinite;
}

@keyframes pulse-warning {
    0% { transform: scale(1); }
    50% { transform: scale(1.02); }
    100% { transform: scale(1); }
}

.task-column h4 span.count {
    background: var(--light-bg);
    padding: 3px 10px;
    border-radius: 15px;
    font-size: 0.9em;
}
.fc-event.overdue {
    background: #ff0000 !important;
    border-color: #cc0000 !important;
}
.task-item {
    cursor: pointer;
    transition: background-color 0.3s;
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 10px;
    background: #fff;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}

.task-item:hover {
    background-color: #f8f9fa;
}

.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.5);
}

.modal-content {
    background: white;
    margin: 5% auto;
    padding: 25px;
    width: 60%;
    border-radius: 8px;
}

.task-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
}

.submit-btn {
    background: #4CAF50;
    color: white;
    padding: 12px 24px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-size: 16px;
}

.attachments {
    color: #666;
    font-size: 0.9em;
}
    </style>
</head>
<body>

<div class="dashboard-container">
    <div class="sidebar">
        <h2>Menu</h2>
        <?php if ($role === 'admin'): ?>
            <a href="manage_staff.php" class="action-button">
                <i class="fas fa-users"></i> Manage Staff
            </a>
            <a href="manage_tasks.php" class="action-button">
                <i class="fas fa-tasks"></i> Manage Tasks
            </a>
        <?php else: ?>
            <a href="staff_tasks.php" class="action-button">
                <i class="fas fa-tasks"></i> My Tasks
            </a>
        <?php endif; ?>
        <a href="inbox.php" class="action-button">
            <i class="fas fa-envelope"></i> Inbox
        </a>
        <a href="logout.php" class="action-button">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </div>

    <div class="main-content">
        <div class="top-bar">
            <h2>Welcome, <?php echo htmlspecialchars($username); ?>!</h2>
            <div class="icons">
                <i class="fas fa-bell" onclick="showNotification()">
                    <?php if ($notifications_count > 0): ?>
                        <span class="notification-badge"><?php echo $notifications_count; ?></span>
                    <?php endif; ?>
                </i>
                <i class="fas fa-cog" onclick="goToSettings()"></i>
            </div>
        </div>

        <?php if ($role === 'admin'): ?>
            <div id="calendar"></div>
        <?php else: ?>
            <div class="task-status-container">
                <div class="task-status">
                    <div class="task-box">
                        <h3><i class="fas fa-check-circle"></i> Completed Tasks</h3>
                        <ul class="task-list">
                            <?php 
                            $tasks_result->data_seek(0);
                            while ($task = $tasks_result->fetch_assoc()): 
                                if ($task['status'] === 'completed'): ?>
                                    <li class="task-item">
                                        <h4><?php echo htmlspecialchars($task['title']); ?></h4>
                                        <p><?php echo htmlspecialchars($task['description']); ?></p>
                                        <p>Due: <?php echo htmlspecialchars($task['due_date']); ?></p>
                                        <span class="status-badge status-completed">Completed</span>
                                        <?php if ($task['attachment_names']): ?>
                                            <div class="attachments">
                                                <i class="fas fa-paperclip"></i>
                                                <?php echo htmlspecialchars($task['attachment_names']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </li>
                                <?php endif; ?>
                            <?php endwhile; ?>
                        </ul>
                    </div>
                    <div class="task-box">
    <h3><i class="fas fa-clock"></i> Active Tasks</h3>
    <ul class="task-list">
        <?php 
        $tasks_result->data_seek(0);
        while ($task = $tasks_result->fetch_assoc()): 
            if ($task['status'] === 'pending'): ?>
                <li class="task-item" data-task-id="<?php echo $task['id']; ?>" 
                    onclick="showTaskModal(
                        '<?php echo addslashes($task['title']); ?>',
                        '<?php echo addslashes($task['description']); ?>',
                        '<?php echo $task['due_date']; ?>',
                        '<?php echo $task['id']; ?>'
                    )">
                    <div class="task-header">
                        <h4><?php echo htmlspecialchars($task['title']); ?></h4>
                        <span class="status-badge status-pending">Pending</span>
                    </div>
                    <p class="task-desc"><?php echo htmlspecialchars($task['description']); ?></p>
                    <div class="task-footer">
                        <span class="due-date"><i class="fas fa-calendar-alt"></i> <?php echo htmlspecialchars($task['due_date']); ?></span>
                        <?php if ($task['attachment_names']): ?>
                            <div class="attachments">
                                <i class="fas fa-paperclip"></i>
                                <?php echo htmlspecialchars($task['attachment_names']); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endif; ?>
        <?php endwhile; ?>
    </ul>
</div>

<!-- Task Submission Modal -->
<div id="taskModal" class="modal">
    <div class="modal-content">
        <span class="close">&times;</span>
        <h3 id="modalTitle"></h3>
        <div class="task-details">
            <p id="modalDescription"></p>
            <p><strong>Due Date:</strong> <span id="modalDueDate"></span></p>
        </div>
        
        <form id="taskSubmissionForm" action="submit_task.php" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="task_id" id="modalTaskId">
            
            <div class="form-group">
                <label for="comments">Comments:</label>
                <textarea name="comments" id="comments" rows="4"></textarea>
            </div>
            
            <div class="form-group">
                <label for="attachments">Upload Files:</label>
                <input type="file" name="attachments[]" id="attachments" multiple>
            </div>
            
            <button type="submit" class="submit-btn">
                <i class="fas fa-check-circle"></i> Mark as Complete
            </button>
        </form>
    </div>
</div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Task Details Modal -->
<div id="taskModal" class="modal">
    <div class="modal-content">
        <span class="close-modal">&times;</span>
        <h3>Tasks for <span id="modalDate"></span></h3>
        <div class="task-container">
            <div class="task-columns">
                <div class="task-column">
                    <h4>Pending Tasks (<span id="pendingCount">0</span>)</h4>
                    <div id="pendingTasks" class="task-list-container"></div>
                </div>
                <div class="task-column">
                    <h4>Completed Tasks (<span id="completedCount">0</span>)</h4>
                    <div id="completedTasks" class="task-list-container"></div>
                </div>
                <div class="task-column">
                    <h4>Overdue Tasks (<span id="missingCount">0</span>)</h4>
                    <div id="missingTasks" class="task-list-container"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Notifications Modal -->
<div id="notificationModal" class="modal notification-modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-bell"></i> Notifications</h3>
            <span class="close-modal">&times;</span>
        </div>
        <div class="modal-body">
            <div id="notificationsList"></div>
        </div>
    </div>
</div>

<script>
// Replace the existing calendar initialization with this
document.addEventListener('DOMContentLoaded', function() {
    var calendarEl = document.getElementById('calendar');
    if (calendarEl) {
        var calendar = new FullCalendar.Calendar(calendarEl, {
            initialView: 'dayGridMonth',
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: 'dayGridMonth,timeGridWeek,timeGridDay'
            },
            events: [
                <?php foreach ($calendar_data as $date => $counts): ?>
                {
                    title: `Pending: ${<?= $counts['pending'] ?>} | Overdue: ${<?= $counts['overdue'] ?>} | Completed: ${<?= $counts['completed'] ?>}`,
                    start: '<?= $date ?>',
                    color: <?= $counts['overdue'] > 0 ? "'#ff0000'" : "'#2ecc71'" ?>,
                    textColor: 'white'
                },
                <?php endforeach; ?>
            ],
            eventDidMount: function(info) {
                // Add hover effect
                info.el.style.cursor = 'pointer';
            },
            eventClick: function(info) {
                showTaskDetails(info.event.startStr);
            },
            dateClick: function(info) {
                showTaskDetails(info.dateStr);
            }
        });
        calendar.render();
    }
});
function showTaskDetails(date) {
    const modal = document.getElementById('taskModal');
    const now = new Date(); // Current date with time
    
    fetch(`get_tasks.php?date=${date}`)
    .then(response => response.json())
    .then(data => {
        // Clear existing content and counts
        ['pendingTasks', 'completedTasks', 'missingTasks'].forEach(id => {
            document.getElementById(id).innerHTML = '';
        });
        
        let pendingCount = 0, completedCount = 0, missingCount = 0;

        data.forEach(task => {
            const taskDueDate = new Date(task.due_date);
            const isOverdue = task.status === 'pending' && taskDueDate < now;
            
            const taskHtml = `
                <div class="task-item ${isOverdue ? 'missing-task' : ''}">
                    <h5>${task.title}</h5>
                    <div class="task-meta">
                        <p>Due: ${taskDueDate.toLocaleString()}</p>
                        <p>Status: <span class="status-badge status-${task.status}">${task.status}</span></p>
                    </div>
                    ${task.description ? `<p>${task.description}</p>` : ''}
                    <div class="task-actions">
                        <a href="edit_task.php?id=${task.id}" class="btn btn-edit">
                            <i class="fas fa-edit"></i> Edit
                        </a>
                    </div>
                </div>
            `;

            if (task.status === 'completed') {
                document.getElementById('completedTasks').innerHTML += taskHtml;
                completedCount++;
            } else if (isOverdue) {
                document.getElementById('missingTasks').innerHTML += taskHtml;
                missingCount++;
            } else {
                document.getElementById('pendingTasks').innerHTML += taskHtml;
                pendingCount++;
            }
        });

        // Update counts
        document.getElementById('pendingCount').textContent = pendingCount;
        document.getElementById('completedCount').textContent = completedCount;
        document.getElementById('missingCount').textContent = missingCount;
        
        modal.style.display = 'block';
    });
}
// Close modal when clicking the X or outside the modal
// Close modal when clicking the X or outside the modal
document.querySelector('.close-modal').onclick = function() {
    document.getElementById('taskModal').style.display = 'none';
}
window.onclick = function(event) {
    var modal = document.getElementById('taskModal');
    if (event.target == modal) {
        modal.style.display = 'none';
    }
}

function showNotification() {
    var modal = document.getElementById('notificationModal');
    modal.style.display = 'block';
    
    // Fetch notifications
    fetch('get_notifications.php')
        .then(response => response.json())
        .then(data => {
            var notificationsList = document.getElementById('notificationsList');
            notificationsList.innerHTML = '';
            
            if (data.length === 0) {
                notificationsList.innerHTML = `
                    <div class="notification-empty">
                        <i class="fas fa-bell-slash"></i>
                        <p>No notifications yet</p>
                    </div>
                `;
                return;
            }
            
            data.forEach(notification => {
                var icon = getNotificationIcon(notification.type);
                var notificationHtml = `
                    <div class="notification-item ${notification.is_read ? '' : 'unread'}" 
                         onclick="handleNotification(${notification.id}, '${notification.type}', ${notification.task_id || 'null'}, ${notification.submission_id || 'null'})">
                        <div class="notification-avatar">
                            <i class="${icon}"></i>
                        </div>
                        <div class="notification-content">
                            <div class="message">${notification.message}</div>
                            <div class="timestamp">
                                <i class="far fa-clock"></i>
                                ${new Date(notification.created_at).toLocaleString()}
                            </div>
                        </div>
                    </div>
                `;
                notificationsList.innerHTML += notificationHtml;
            });
        });
}

function getNotificationIcon(type) {
    switch(type) {
        case 'task_approved':
            return 'fas fa-check-circle';
        case 'task_rejected':
            return 'fas fa-times-circle';
        case 'task_submitted':
            return 'fas fa-file-alt';
        case 'task_assigned':
            return 'fas fa-user-plus';
        default:
            return 'fas fa-bell';
    }
}

function handleNotification(notificationId, type, taskId, submissionId) {
    // Mark notification as read
    fetch('mark_notification_read.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ notification_id: notificationId })
    });
    
    // Handle different notification types
    switch(type) {
        case 'task_approved':
        case 'task_rejected':
            // Refresh the page to update task status
            window.location.reload();
            break;
        case 'task_submitted':
            // Show task details in modal
            showTaskDetails(new Date());
            break;
        // Add other notification type handlers as needed
    }
}

// Update notification count
function updateNotificationCount() {
    fetch('get_notifications.php')
        .then(response => response.json())
        .then(data => {
            var unreadCount = data.filter(n => !n.is_read).length;
            var badge = document.querySelector('.notification-badge');
            if (unreadCount > 0) {
                if (!badge) {
                    badge = document.createElement('span');
                    badge.className = 'notification-badge';
                    document.querySelector('.fa-bell').appendChild(badge);
                }
                badge.textContent = unreadCount;
            } else if (badge) {
                badge.remove();
            }
        });
}

// Update notification count every minute
setInterval(updateNotificationCount, 60000);

function goToSettings() {
    window.location.href = "user_management.php";
}

function showNotificationContext(notificationId, context) {
    // Create and show modal with notification context
    const modal = document.createElement('div');
    modal.className = 'modal';
    modal.innerHTML = `
        <div class="modal-content">
            <span class="close">&times;</span>
            <h3>Notification Details</h3>
            <pre>${context}</pre>
        </div>
    `;
    document.body.appendChild(modal);

    // Add styles if not already present
    if (!document.getElementById('modal-styles')) {
        const styles = document.createElement('style');
        styles.id = 'modal-styles';
        styles.textContent = `
            .modal {
                display: block;
                position: fixed;
                z-index: 1000;
                left: 0;
                top: 0;
                width: 100%;
                height: 100%;
                background-color: rgba(0,0,0,0.4);
            }
            .modal-content {
                background-color: #fefefe;
                margin: 15% auto;
                padding: 20px;
                border: 1px solid #888;
                width: 80%;
                max-width: 600px;
                border-radius: 5px;
            }
            .close {
                color: #aaa;
                float: right;
                font-size: 28px;
                font-weight: bold;
                cursor: pointer;
            }
            .close:hover {
                color: black;
            }
            pre {
                white-space: pre-wrap;
                word-wrap: break-word;
                background: #f5f5f5;
                padding: 10px;
                border-radius: 4px;
            }
        `;
        document.head.appendChild(styles);
    }

    // Close button functionality
    const closeBtn = modal.querySelector('.close');
    closeBtn.onclick = function() {
        modal.remove();
    }

    // Close when clicking outside
    window.onclick = function(event) {
        if (event.target == modal) {
            modal.remove();
        }
    }

    // Mark notification as read
    fetch('mark_notification_read.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ notification_id: notificationId })
    });
}

// Update the loadNotifications function
function loadNotifications() {
    fetch('get_notifications.php')
        .then(response => response.json())
        .then(notifications => {
            const container = document.getElementById('notificationsList');
            container.innerHTML = '';
            
            notifications.forEach(notification => {
                const div = document.createElement('div');
                div.className = `notification ${notification.is_read ? 'read' : 'unread'}`;
                div.onclick = () => showNotificationContext(notification.id, notification.context);
                div.innerHTML = `
                    <div class="notification-item">
                        <div class="notification-avatar">
                            <i class="${getNotificationIcon(notification.type)}"></i>
                        </div>
                        <div class="notification-content">
                            <div class="message">${notification.message}</div>
                            <div class="timestamp">
                                <i class="far fa-clock"></i>
                                ${new Date(notification.created_at).toLocaleString()}
                            </div>
                        </div>
                    </div>
                `;
                container.appendChild(div);
            });
            
            // Update notification count
            const unreadCount = notifications.filter(n => !n.is_read).length;
            document.getElementById('notification-count').textContent = unreadCount;
        });
}

// Call loadNotifications initially and set up periodic refresh
loadNotifications();
setInterval(loadNotifications, 30000); // Refresh every 30 seconds

function showTaskModal(title, description, dueDate, taskId) {
    const modal = document.getElementById('taskModal');
    document.getElementById('modalTitle').textContent = title;
    document.getElementById('modalDescription').textContent = description;
    document.getElementById('modalDueDate').textContent = dueDate;
    document.getElementById('modalTaskId').value = taskId;
    modal.style.display = 'block';
}

// Close modal
document.querySelector('.close').onclick = function() {
    document.getElementById('taskModal').style.display = 'none';
}

// Handle form submission
document.getElementById('taskSubmissionForm').onsubmit = async function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    
    try {
        const response = await fetch('submit_task.php', {
            method: 'POST',
            body: formData
        });
        
        if (response.ok) {
            alert('Task submitted successfully!');
            location.reload();
        } else {
            alert('Error submitting task');
        }
    } catch (error) {
        console.error('Error:', error);
        alert('An error occurred while submitting the task');
    }
};
</script>

</body>
</html>
