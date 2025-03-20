USE account;

-- Drop foreign key constraint
ALTER TABLE tasks DROP FOREIGN KEY tasks_ibfk_2;

-- Drop assigned_to column
ALTER TABLE tasks DROP COLUMN assigned_to;

-- Create task_assignments table
CREATE TABLE task_assignments (
    id int(11) NOT NULL AUTO_INCREMENT,
    task_id int(11) NOT NULL,
    user_id int(11) NOT NULL,
    assigned_at timestamp NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (id),
    KEY task_id (task_id),
    KEY user_id (user_id),
    CONSTRAINT task_assignments_ibfk_1 FOREIGN KEY (task_id) REFERENCES tasks (id) ON DELETE CASCADE,
    CONSTRAINT task_assignments_ibfk_2 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci; 