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

// Handle staff deletion
if (isset($_POST['delete_staff'])) {
    $staff_id = (int)$_POST['staff_id'];
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // First reassign or delete all related records
        
        // Update tasks
        $reassign_tasks = $conn->prepare("UPDATE tasks SET assigned_to = ? WHERE assigned_to = ?");
        $admin_id = 1; // Assuming ID 1 is admin
        $reassign_tasks->bind_param("ii", $admin_id, $staff_id);
        $reassign_tasks->execute();
        $reassign_tasks->close();
        
        // Delete notifications
        $delete_notifications = $conn->prepare("DELETE FROM notifications WHERE user_id = ? OR sender_id = ?");
        $delete_notifications->bind_param("ii", $staff_id, $staff_id);
        $delete_notifications->execute();
        $delete_notifications->close();
        
        // Delete messages
        $delete_messages = $conn->prepare("DELETE FROM messages WHERE sender_id = ? OR receiver_id = ?");
        $delete_messages->bind_param("ii", $staff_id, $staff_id);
        $delete_messages->execute();
        $delete_messages->close();
        
        // Delete task submissions
        $delete_submissions = $conn->prepare("DELETE FROM task_submissions WHERE submitted_by = ? OR reviewed_by = ?");
        $delete_submissions->bind_param("ii", $staff_id, $staff_id);
        $delete_submissions->execute();
        $delete_submissions->close();
        
        // Finally delete the user
        $delete_user = $conn->prepare("DELETE FROM users WHERE id = ? AND role = 'staff'");
        $delete_user->bind_param("i", $staff_id);
        $delete_user->execute();
        $delete_user->close();
        
        // If everything is successful, commit the transaction
        $conn->commit();
        
        $_SESSION['success_message'] = "Staff member deleted successfully.";
    } catch (Exception $e) {
        // If there's an error, rollback the changes
        $conn->rollback();
        $_SESSION['error_message'] = "Could not delete staff member: " . $e->getMessage();
    }
    
    header("Location: manage_staff.php");
    exit();
}
    // Handle search functionality
// Handle AJAX search request
if (isset($_GET['ajax_search'])) {
    $search = trim($_GET['search'] ?? '');
    
    $query = "SELECT * FROM users 
            WHERE role = 'staff' 
            AND (first_name LIKE ? 
                OR last_name LIKE ? 
                OR username LIKE ?) 
            ORDER BY last_name, first_name";
    
    $stmt = $conn->prepare($query);
    $search_term = "%$search%";
    $stmt->bind_param("sss", $search_term, $search_term, $search_term);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $staffs = [];
    while ($staff = $result->fetch_assoc()) {
        $staffs[] = $staff;
    }
    
    header('Content-Type: application/json');
    echo json_encode([
        'html' => generateStaffList($staffs),
        'count' => count($staffs)
    ]);
    exit();
}

function generateStaffList($staffs) {
    ob_start(); ?>
    <ul class="employee-list">
        <?php foreach ($staffs as $staff): ?>
        <li class="employee-item">
            <div>
                <?= htmlspecialchars($staff['first_name'] . ' ' . $staff['last_name']) ?>
            </div>
            <div class="employee-actions">
                <a href="edit_staff.php?id=<?= $staff['id'] ?>" class="edit-btn">Edit</a>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="staff_id" value="<?= $staff['id'] ?>">
                    <button type="submit" name="delete_staff" class="delete-btn" 
                            onclick="return confirm('Are you sure?')">Delete</button>
                </form>
            </div>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php
    return ob_get_clean();
}

// Initial page load
$result = $conn->query("SELECT * FROM users WHERE role = 'staff' ORDER BY last_name, first_name");
$all_staff = $result->fetch_all(MYSQLI_ASSOC);
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Staff</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        .container {
            max-width: 800px;
            margin: 20px auto;
            padding: 20px;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }

        .header-section {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .search-add {
            display: flex;
            gap: 10px;
        }

        .search-input {
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            width: 200px;
        }

        .button {
            padding: 8px 16px;
            background: #007bff;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
        }

        .button:hover {
            background: #0056b3;
        }

        .employee-list {
            list-style: none;
            padding: 0;
        }

        .employee-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px;
            border: 1px solid #eee;
            margin-bottom: 10px;
            border-radius: 4px;
        }

        .employee-actions {
            display: flex;
            gap: 10px;
        }

        .edit-btn {
            padding: 5px 10px;
            background: #28a745;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }

        .delete-btn {
            padding: 5px 10px;
            background: #dc3545;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }

        .count-badge {
            background: #6c757d;
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.9em;
        }
        .search-form {
            display: flex;
            gap: 10px;
        }
        search-input {
            transition: width 0.3s ease;
        }
        .loading {
            display: none;
            color: #666;
        }
        .staff-list-container {
            min-height: 400px; /* Set minimum height based on your content */
            position: relative;
        }
        
        .employee-list {
            transition: opacity 0.2s ease;
        }
        
        .loading-overlay {
            display: none;
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.8);
            align-items: center;
            justify-content: center;
        }
        
        .search-input {
            width: 200px; /* Fixed width */
        }
    </style>
</head>
<body>
<section class="container">
        <div class="header-section">
            <h2>Manage Staff</h2>
            <div class="search-add">
                <input type="text" 
                       class="search-input" 
                       id="searchInput" 
                       placeholder="Search employee..."
                       autocomplete="off">
                <div class="loading-overlay" id="loadingOverlay">
                    <div class="loading">Searching...</div>
                </div>
                <a href="register.php" class="button">Add <span class="count-badge" id="countBadge"><?= count($all_staff) ?></span></a>
            </div>
        </div>

        <div class="staff-list-container">
            <div id="staffListContainer">
                <?= generateStaffList($all_staff) ?>
            </div>
        </div>
        
        <div style="text-align: center; margin-top: 20px;">
            <a href="dashboard.php" class="button">Back to Dashboard</a>
        </div>
    </section>
    <script>
    // Modified JavaScript
    const searchInput = document.getElementById('searchInput');
    const staffListContainer = document.getElementById('staffListContainer');
    const countBadge = document.getElementById('countBadge');
    const loadingOverlay = document.getElementById('loadingOverlay');
    
    let searchTimeout;
    
    function handleSearch(searchTerm) {
        loadingOverlay.style.display = 'flex';
        
        fetch(`manage_staff.php?ajax_search=1&search=${encodeURIComponent(searchTerm)}`)
            .then(response => response.json())
            .then(data => {
                staffListContainer.innerHTML = data.html;
                countBadge.textContent = data.count;
                loadingOverlay.style.display = 'none';
            })
            .catch(error => {
                console.error('Error:', error);
                loadingOverlay.style.display = 'none';
            });
    }
    
    searchInput.addEventListener('input', function(e) {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            handleSearch(e.target.value);
        }, 300);
    });
    </script>

        <ul class="employee-list">
            <?php while ($staff = $result->fetch_assoc()): ?>
            <li class="employee-item">
                <div>
                    <?php echo htmlspecialchars($staff['first_name'] . ' ' . $staff['last_name']); ?>
                </div>
                <div class="employee-actions">
                    <a href="edit_staff.php?id=<?php echo $staff['id']; ?>" class="edit-btn">Edit</a>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="staff_id" value="<?php echo $staff['id']; ?>">
                        <button type="submit" name="delete_staff" class="delete-btn" onclick="return confirm('Are you sure?')">Delete</button>
                    </form>
                </div>
            </li>
            <?php endwhile; ?>
        </ul>
        
        
    </section>
</body>
</html>