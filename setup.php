<?php
/**
 * COMPREHENSIVE DATABASE SETUP
 * Consolidates all tables, columns, and initial data into a single file.
 */
require_once 'config.php';

// Helper function to add columns if they don't exist
function addColumn($pdo, $table, $column, $definition) {
    try {
        $pdo->exec("ALTER TABLE $table ADD COLUMN $column $definition");
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

try {
    echo "<h2>LMS Database Setup</h2>";
    echo "Initializing tables...<br>";

    // 1. Users Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        email VARCHAR(100) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        role ENUM('admin', 'student', 'sub_admin') DEFAULT 'student',
        status ENUM('active', 'blocked', 'archived') DEFAULT 'active',
        login_attempts INT DEFAULT 0,
        last_attempt_time DATETIME NULL,
        last_access DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    // Ensure all columns exist for existing installations
    addColumn($pdo, 'users', 'status', "ENUM('active', 'blocked', 'archived') DEFAULT 'active' AFTER role");
    addColumn($pdo, 'users', 'login_attempts', "INT DEFAULT 0 AFTER status");
    addColumn($pdo, 'users', 'last_attempt_time', "DATETIME NULL AFTER login_attempts");
    addColumn($pdo, 'users', 'last_access', "DATETIME NULL AFTER last_attempt_time");
    addColumn($pdo, 'users', 'login_count', "INT DEFAULT 0 AFTER last_access");
    addColumn($pdo, 'users', 'desktop_session_id', "VARCHAR(255) NULL AFTER login_count");
    addColumn($pdo, 'users', 'mobile_session_id', "VARCHAR(255) NULL AFTER desktop_session_id");
    addColumn($pdo, 'users', 'permissions', "TEXT NULL AFTER mobile_session_id");
    addColumn($pdo, 'users', 'access_start_time', "TIME NULL AFTER permissions");
    addColumn($pdo, 'users', 'access_end_time', "TIME NULL AFTER access_start_time");
    try {
        $pdo->exec("ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'student', 'sub_admin') DEFAULT 'student'");
    } catch (PDOException $e) {}
    echo "✓ Table 'users' updated with permissions and time-based access.<br>";

    // 2. Courses Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS courses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        description TEXT,
        image_url VARCHAR(255),
        status TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    addColumn($pdo, 'courses', 'status', "TINYINT(1) DEFAULT 1 AFTER image_url");
    addColumn($pdo, 'courses', 'is_paid', "TINYINT(1) DEFAULT 0 AFTER status");
    addColumn($pdo, 'courses', 'price', "DECIMAL(10,2) DEFAULT 0.00 AFTER is_paid");
    echo "✓ Table 'courses' ready with pricing support.<br>";

    // 3. Batches Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS batches (
        id INT AUTO_INCREMENT PRIMARY KEY,
        course_id INT NOT NULL,
        name VARCHAR(100) NOT NULL,
        start_date DATE,
        schedule VARCHAR(255),
        status ENUM('active', 'closed') DEFAULT 'active',
        close_message TEXT NULL,
        FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
    )");
    addColumn($pdo, 'batches', 'status', "ENUM('active', 'closed') DEFAULT 'active'");
    addColumn($pdo, 'batches', 'close_message', "TEXT NULL");
    echo "✓ Table 'batches' ready.<br>";

    // 4. Student-Batch Pivot
    $pdo->exec("CREATE TABLE IF NOT EXISTS student_batches (
        student_id INT NOT NULL,
        batch_id INT NOT NULL,
        PRIMARY KEY (student_id, batch_id),
        FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (batch_id) REFERENCES batches(id) ON DELETE CASCADE
    )");
    echo "✓ Table 'student_batches' ready.<br>";

    // 5. Batch-Courses Pivot (Added in v9)
    $pdo->exec("CREATE TABLE IF NOT EXISTS batch_courses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        batch_id INT NOT NULL,
        course_id INT NOT NULL,
        FOREIGN KEY (batch_id) REFERENCES batches(id) ON DELETE CASCADE,
        FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
    )");
    echo "✓ Table 'batch_courses' ready.<br>";

    // 6. Lessons Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS lessons (
        id INT AUTO_INCREMENT PRIMARY KEY,
        batch_id INT NOT NULL,
        course_id INT NULL,
        title VARCHAR(255) NOT NULL,
        content TEXT,
        video_url VARCHAR(255),
        video_file VARCHAR(255),
        image_url VARCHAR(255),
        is_hidden TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (batch_id) REFERENCES batches(id) ON DELETE CASCADE,
        FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE SET NULL
    )");
    addColumn($pdo, 'lessons', 'video_file', "VARCHAR(255) AFTER video_url");
    addColumn($pdo, 'lessons', 'image_url', "VARCHAR(255) AFTER video_file");
    addColumn($pdo, 'lessons', 'course_id', "INT NULL AFTER batch_id");
    addColumn($pdo, 'lessons', 'is_hidden', "TINYINT(1) DEFAULT 0");
    addColumn($pdo, 'lessons', 'is_optional', "TINYINT(1) DEFAULT 0 AFTER is_hidden");
    addColumn($pdo, 'lessons', 'exam_id', "INT NULL AFTER course_id");
    addColumn($pdo, 'lessons', 'publish_date', "DATETIME NULL AFTER is_optional");
    echo "✓ Table 'lessons' ready with schedule and optional support.<br>";

    // 7. Resources Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS resources (
        id INT AUTO_INCREMENT PRIMARY KEY,
        lesson_id INT NOT NULL,
        title VARCHAR(255) NOT NULL,
        file_url VARCHAR(255),
        type ENUM('pdf', 'link', 'image', 'other') DEFAULT 'other',
        FOREIGN KEY (lesson_id) REFERENCES lessons(id) ON DELETE CASCADE
    )");
    echo "✓ Table 'resources' ready.<br>";

    // 8. Notifications Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        title VARCHAR(255) NOT NULL,
        message TEXT NOT NULL,
        link VARCHAR(255) NULL,
        is_read TINYINT(1) DEFAULT 0,
        attachment_url VARCHAR(255),
        attachment_type ENUM('image', 'video', 'file'),
        target_role ENUM('all', 'student', 'admin') DEFAULT 'all',
        batch_id INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (batch_id) REFERENCES batches(id) ON DELETE SET NULL
    )");
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS notification_reads (
        notification_id INT NOT NULL,
        user_id INT NOT NULL,
        read_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (notification_id, user_id),
        FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");

    addColumn($pdo, 'notifications', 'user_id', "INT NULL AFTER id");
    addColumn($pdo, 'notifications', 'link', "VARCHAR(255) NULL AFTER message");
    addColumn($pdo, 'notifications', 'is_read', "TINYINT(1) DEFAULT 0 AFTER link");
    echo "✓ Table 'notifications' and 'notification_reads' ready.<br>";

    // 9. Schedules Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS schedules (
        id INT AUTO_INCREMENT PRIMARY KEY,
        batch_id INT NOT NULL,
        title VARCHAR(255) NOT NULL,
        meeting_link VARCHAR(500) NOT NULL,
        start_time DATETIME NOT NULL,
        end_time DATETIME NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (batch_id) REFERENCES batches(id) ON DELETE CASCADE
    )");
    echo "✓ Table 'schedules' ready.<br>";

    // 10. Events Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS events (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        description TEXT,
        image_url VARCHAR(255),
        video_url VARCHAR(255),
        event_date DATETIME NOT NULL,
        event_end_date DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    addColumn($pdo, 'events', 'event_end_date', "DATETIME NULL AFTER event_date");
    echo "✓ Table 'events' ready.<br>";

    // 11. Settings Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        setting_key VARCHAR(50) PRIMARY KEY,
        setting_value TEXT
    )");
    $pdo->exec("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('maintenance_mode', '0')");
    $pdo->exec("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('locked_pages', '[]')");
    echo "✓ Table 'settings' ready.<br>";

    // 12. Exams & Related Tables
    $pdo->exec("CREATE TABLE IF NOT EXISTS exams (
        id INT AUTO_INCREMENT PRIMARY KEY,
        batch_id INT NULL,
        title VARCHAR(255) NOT NULL,
        instructions TEXT,
        is_published TINYINT(1) DEFAULT 0,
        start_time DATETIME NOT NULL,
        publish_date DATETIME NULL,
        duration_minutes INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (batch_id) REFERENCES batches(id) ON DELETE SET NULL
    )");
    addColumn($pdo, 'exams', 'is_published', "TINYINT(1) DEFAULT 0 AFTER instructions");
    addColumn($pdo, 'exams', 'publish_date', "DATETIME NULL AFTER start_time");
    addColumn($pdo, 'exams', 'max_attempts', "INT DEFAULT 1 AFTER duration_minutes");
    addColumn($pdo, 'exams', 'course_id', "INT NULL AFTER batch_id");
    addColumn($pdo, 'exams', 'is_certificate_exam', "TINYINT(1) DEFAULT 0 AFTER is_published");
    addColumn($pdo, 'exams', 'is_private', "TINYINT(1) DEFAULT 0 AFTER is_certificate_exam");
    echo "✓ Table 'exams' updated with certification support.<br>";

    $pdo->exec("CREATE TABLE IF NOT EXISTS exam_batches (
        exam_id INT NOT NULL,
        batch_id INT NOT NULL,
        PRIMARY KEY (exam_id, batch_id),
        FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE,
        FOREIGN KEY (batch_id) REFERENCES batches(id) ON DELETE CASCADE
    )");
    echo "✓ Table 'exams' and 'exam_batches' ready.<br>";

    // Migrate existing batch_id from exams to exam_batches
    try {
        $stmt = $pdo->query("SELECT id, batch_id FROM exams WHERE batch_id IS NOT NULL AND batch_id > 0");
        while ($row = $stmt->fetch()) {
            $pdo->prepare("INSERT IGNORE INTO exam_batches (exam_id, batch_id) VALUES (?, ?)")->execute([$row['id'], $row['batch_id']]);
        }
    } catch (PDOException $e) {}
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS exam_questions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        exam_id INT NOT NULL,
        question_text TEXT NOT NULL,
        question_type ENUM('mcq', 'descriptive') DEFAULT 'mcq',
        marks INT DEFAULT 1,
        option_a VARCHAR(255) NULL,
        option_b VARCHAR(255) NULL,
        option_c VARCHAR(255) NULL,
        option_d VARCHAR(255) NULL,
        correct_option ENUM('a', 'b', 'c', 'd') NULL,
        FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE
    )");
    addColumn($pdo, 'exam_questions', 'question_type', "ENUM('mcq', 'descriptive') DEFAULT 'mcq' AFTER question_text");
    addColumn($pdo, 'exam_questions', 'marks', "INT DEFAULT 1 AFTER question_type");
    // Allow nulls for descriptive
    try { $pdo->exec("ALTER TABLE exam_questions MODIFY COLUMN option_a VARCHAR(255) NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE exam_questions MODIFY COLUMN option_b VARCHAR(255) NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE exam_questions MODIFY COLUMN option_c VARCHAR(255) NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE exam_questions MODIFY COLUMN option_d VARCHAR(255) NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE exam_questions MODIFY COLUMN correct_option ENUM('a', 'b', 'c', 'd') NULL"); } catch (PDOException $e) {}

    $pdo->exec("CREATE TABLE IF NOT EXISTS student_exams (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        exam_id INT NOT NULL,
        start_time DATETIME DEFAULT NULL,
        submit_time DATETIME DEFAULT NULL,
        score DECIMAL(10,2) DEFAULT NULL,
        total_marks INT DEFAULT 0,
        status ENUM('not_started', 'ongoing', 'submitted', 'blocked') DEFAULT 'not_started',
        is_result_published TINYINT(1) DEFAULT 0,
        violations_count INT DEFAULT 0,
        device_fingerprint VARCHAR(255) DEFAULT NULL,
        FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE
    )");
    addColumn($pdo, 'student_exams', 'score', "DECIMAL(10,2) NULL AFTER submit_time");
    addColumn($pdo, 'student_exams', 'total_marks', "INT DEFAULT 0 AFTER score");
    addColumn($pdo, 'student_exams', 'is_result_published', "TINYINT(1) DEFAULT 0 AFTER status");
    addColumn($pdo, 'student_exams', 'violations_count', "INT DEFAULT 0 AFTER is_result_published");
    addColumn($pdo, 'student_exams', 'device_fingerprint', "VARCHAR(255) NULL AFTER violations_count");

    $pdo->exec("CREATE TABLE IF NOT EXISTS student_answers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_exam_id INT NOT NULL,
        question_id INT NOT NULL,
        selected_option ENUM('a', 'b', 'c', 'd') DEFAULT NULL,
        answer_text TEXT NULL,
        marks_awarded DECIMAL(10,2) DEFAULT 0,
        FOREIGN KEY (student_exam_id) REFERENCES student_exams(id) ON DELETE CASCADE,
        FOREIGN KEY (question_id) REFERENCES exam_questions(id) ON DELETE CASCADE
    )");
    addColumn($pdo, 'student_answers', 'answer_text', "TEXT NULL AFTER selected_option");
    addColumn($pdo, 'student_answers', 'marks_awarded', "DECIMAL(10,2) DEFAULT 0 AFTER answer_text");
    echo "✓ Exam tables updated for descriptive questions & grading.<br>";

    // 13. Projects & Submissions (Enhanced)
    $pdo->exec("CREATE TABLE IF NOT EXISTS projects (
        id INT AUTO_INCREMENT PRIMARY KEY,
        batch_id INT NULL,
        title VARCHAR(255) NOT NULL,
        description TEXT,
        image_url VARCHAR(255) NULL,
        video_url VARCHAR(255) NULL,
        external_links TEXT NULL,
        submission_types VARCHAR(100) DEFAULT 'file',
        allowed_types VARCHAR(255) DEFAULT 'pdf,zip,doc,docx,jpg,png,txt,py,java,cpp,c,html,css,js,php',
        max_size_mb INT DEFAULT 5,
        start_date DATETIME NOT NULL,
        end_date DATETIME NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (batch_id) REFERENCES batches(id) ON DELETE CASCADE
    )");
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS project_batches (
        project_id INT NOT NULL,
        batch_id INT NOT NULL,
        PRIMARY KEY (project_id, batch_id),
        FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
        FOREIGN KEY (batch_id) REFERENCES batches(id) ON DELETE CASCADE
    )");
    echo "✓ Table 'project_batches' ready.<br>";
    addColumn($pdo, 'projects', 'image_url', "VARCHAR(255) NULL AFTER description");
    addColumn($pdo, 'projects', 'video_url', "VARCHAR(255) NULL AFTER image_url");
    addColumn($pdo, 'projects', 'external_links', "TEXT NULL AFTER video_url");
    addColumn($pdo, 'projects', 'submission_types', "VARCHAR(100) DEFAULT 'file' AFTER external_links");

    $pdo->exec("CREATE TABLE IF NOT EXISTS project_submissions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        project_id INT NOT NULL,
        student_id INT NOT NULL,
        submission_text LONGTEXT NULL,
        submission_links TEXT NULL,
        feedback TEXT,
        score INT DEFAULT NULL,
        submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
        FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
        UNIQUE KEY unique_submission (project_id, student_id)
    )");
    addColumn($pdo, 'project_submissions', 'submission_text', "LONGTEXT NULL AFTER student_id");
    addColumn($pdo, 'project_submissions', 'submission_links', "TEXT NULL AFTER submission_text");

    $pdo->exec("CREATE TABLE IF NOT EXISTS project_files (
        id INT AUTO_INCREMENT PRIMARY KEY,
        submission_id INT NOT NULL,
        file_url VARCHAR(255) NOT NULL,
        file_name VARCHAR(255) NOT NULL,
        FOREIGN KEY (submission_id) REFERENCES project_submissions(id) ON DELETE CASCADE
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS vouchers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(50) NOT NULL UNIQUE,
        course_id INT NULL,
        is_used TINYINT(1) DEFAULT 0,
        student_id INT NULL,
        used_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE SET NULL,
        FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE SET NULL
    )");
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS voucher_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        course_id INT NULL,
        phone_number VARCHAR(20) NOT NULL,
        status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE SET NULL
    )");
    addColumn($pdo, 'voucher_requests', 'course_id', "INT NULL AFTER student_id");

    $pdo->exec("CREATE TABLE IF NOT EXISTS student_courses (
        student_id INT NOT NULL,
        course_id INT NOT NULL,
        access_type ENUM('free', 'voucher') DEFAULT 'free',
        voucher_id INT NULL,
        enrolled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (student_id, course_id),
        FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
        FOREIGN KEY (voucher_id) REFERENCES vouchers(id) ON DELETE SET NULL
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS password_resets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(255) NOT NULL,
        token VARCHAR(255) NOT NULL,
        expires_at DATETIME NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS lesson_status (
        student_id INT NOT NULL,
        lesson_id INT NOT NULL,
        status ENUM('completed', 'in_progress') DEFAULT 'completed',
        completed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (student_id, lesson_id),
        FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (lesson_id) REFERENCES lessons(id) ON DELETE CASCADE
    )");
    echo "✓ Table 'lesson_status' ready for progress tracking.<br>";

    $pdo->exec("CREATE TABLE IF NOT EXISTS tickets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        category ENUM('LMS Issue', 'Student Issue', 'Course Material', 'Exam/Result', 'Other') DEFAULT 'Other',
        description TEXT NOT NULL,
        attachment_url VARCHAR(255) NULL,
        status ENUM('open', 'in_progress', 'solved' ,'closed') DEFAULT 'open',
        admin_reply TEXT NULL,
        replied_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
    )");

    echo "✓ Voucher, Course Access, and Ticket tables ready.<br>";

    // 14. Magic Links Table (New)
    $pdo->exec("CREATE TABLE IF NOT EXISTS magic_links (
        id INT AUTO_INCREMENT PRIMARY KEY,
        token VARCHAR(100) NOT NULL UNIQUE,
        user_id INT NULL,
        action VARCHAR(50) NOT NULL, -- 'login', 'reset_password', 'enroll', 'exam_access'
        data TEXT NULL, -- JSON formatted parameters (course_ids, voucher_id, exam_id)
        is_used TINYINT(1) DEFAULT 0,
        expires_at DATETIME NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");
    echo "✓ Table 'magic_links' created.<br>";

    // Update Exams for Certificates and Private Access
    addColumn($pdo, 'exams', 'is_certificate_exam', "TINYINT(1) DEFAULT 0 AFTER is_published");
    addColumn($pdo, 'exams', 'is_private', "TINYINT(1) DEFAULT 0 AFTER is_certificate_exam");
    addColumn($pdo, 'exams', 'course_id', "INT NULL AFTER is_private");
    try {
        $pdo->exec("ALTER TABLE exams ADD CONSTRAINT fk_exam_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE SET NULL");
    } catch (PDOException $e) {}
    
    // 15. Student Private Exam Access (Magic link results)
    $pdo->exec("CREATE TABLE IF NOT EXISTS student_private_exams (
        student_id INT NOT NULL,
        exam_id INT NOT NULL,
        granted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (student_id, exam_id),
        FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE
    )");
    echo "✓ Table 'student_private_exams' created.<br>";

    // Folders
    $folders = ['uploads', 'uploads/videos', 'uploads/images', 'uploads/resources', 'uploads/projects', 'uploads/tickets'];
    foreach ($folders as $folder) {
        if (!file_exists($folder)) {
            mkdir($folder, 0777, true);
        }
    }
    echo "✓ Upload folders ready.<br>";

    // --- MIGRATIONS FOR EXISTING DATA ---
    
    // 1. Migrate existing course_id from batches to batch_courses (Legacy support)
    try {
        $stmt = $pdo->query("SELECT id, course_id FROM batches WHERE course_id IS NOT NULL AND course_id > 0");
        $batchesCount = 0;
        while ($b = $stmt->fetch()) {
            $check = $pdo->prepare("SELECT COUNT(*) FROM batch_courses WHERE batch_id = ? AND course_id = ?");
            $check->execute([$b['id'], $b['course_id']]);
            if ($check->fetchColumn() == 0) {
                $pdo->prepare("INSERT INTO batch_courses (batch_id, course_id) VALUES (?, ?)")->execute([$b['id'], $b['course_id']]);
                $batchesCount++;
            }
        }
        if ($batchesCount > 0) echo "✓ Migrated $batchesCount batch-course assignments.<br>";
    } catch (PDOException $e) {}

    // 2. Backfill lessons' course_id from batches if possible
    try {
        $updatedRows = $pdo->exec("UPDATE lessons l JOIN batches b ON l.batch_id = b.id SET l.course_id = b.course_id WHERE l.course_id IS NULL AND b.course_id IS NOT NULL");
        if ($updatedRows > 0) echo "✓ Backfilled $updatedRows lesson-course assignments.<br>";
    } catch (PDOException $e) {}

    // --- END MIGRATIONS ---

    // Default Admin User
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
    $stmt->execute(['admin@lms.com']);
    if ($stmt->fetchColumn() == 0) {
        $password = password_hash('admin123', PASSWORD_DEFAULT);
        $pdo->exec("INSERT INTO users (name, email, password, role) VALUES ('Admin', 'admin@lms.com', '$password', 'admin')");
        echo "✓ Default admin account created (admin@lms.com / admin123).<br>";
    }

    echo "<h3>Setup Complete!</h3>";
    echo "<p>You can now delete old schema update files if everything is working correctly.</p>";

} catch (PDOException $e) {
    die("<h3 style='color:red;'>CRITICAL ERROR: Could not setup database.</h3>" . $e->getMessage());
}
?>
