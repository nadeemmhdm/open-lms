<?php
$path_to_root = '../';
require_once $path_to_root . 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'sub_admin')) {
    redirect('../index.php');
}

$project_id = $_GET['id'] ?? redirect('projects.php');

// Fetch Project Info
$stmt = $pdo->prepare("SELECT p.*, b.name as batch_name FROM projects p JOIN batches b ON p.batch_id = b.id WHERE p.id = ?");
$stmt->execute([$project_id]);
$project = $stmt->fetch();

if (!$project) redirect('projects.php');

$message = '';

// Handle Grading
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['grade_submission'])) {
    $sub_id = $_POST['submission_id'];
    $score = $_POST['score'];
    $feedback = trim($_POST['feedback']);

    try {
        $stmt = $pdo->prepare("UPDATE project_submissions SET score = ?, feedback = ? WHERE id = ?");
        $stmt->execute([$score, $feedback, $sub_id]);
        $message = "<div class='badge badge-success' style='display:block; padding: 12px; margin-bottom: 20px;'>Grade updated!</div>";
    } catch (PDOException $e) {
        $message = "<div class='badge badge-danger'>Error updating grade.</div>";
    }
}

// Handle Delete Submission
if (isset($_GET['delete_submission'])) {
    $sub_id = $_GET['delete_submission'];
    
    try {
        $pdo->beginTransaction();
        
        // 1. Fetch files and delete from storage
        $f_stmt = $pdo->prepare("SELECT file_url FROM project_files WHERE submission_id = ?");
        $f_stmt->execute([$sub_id]);
        $files = $f_stmt->fetchAll();
        foreach($files as $f) {
            @unlink($path_to_root . "uploads/projects/" . $f['file_url']);
        }
        
        // 2. Delete from DB (on Cascade handles project_files)
        $pdo->prepare("DELETE FROM project_submissions WHERE id = ?")->execute([$sub_id]);
        
        $pdo->commit();
        $message = "<div class='badge badge-success' style='display:block; padding: 12px; margin-bottom: 20px;'>Submission deleted successfully. Student can now resubmit.</div>";
    } catch (Exception $e) {
        $pdo->rollBack();
        $message = "<div class='badge badge-danger'>Error: " . $e->getMessage() . "</div>";
    }
}

// Fetch Submissions
$stmt = $pdo->prepare("SELECT ps.*, u.name as student_name, u.email as student_email 
                      FROM project_submissions ps 
                      JOIN users u ON ps.student_id = u.id 
                      WHERE ps.project_id = ? 
                      ORDER BY ps.submitted_at DESC");
$stmt->execute([$project_id]);
$submissions = $stmt->fetchAll();

include $path_to_root . 'includes/header.php';
include $path_to_root . 'includes/sidebar.php';
?>

<div style="margin-bottom: 30px;">
    <a href="projects.php" class="text-muted" style="text-decoration: none; font-size: 0.9rem; display: inline-flex; align-items: center; gap: 8px;">
        <i class="fas fa-arrow-left"></i> Back
    </a>
    <h2 style="margin-top: 10px;">Submissions: <?= htmlspecialchars($project['title']) ?></h2>
    <span class="badge badge-warning"><?= htmlspecialchars($project['batch_name']) ?></span>
</div>

<?php echo $message; ?>

<div class="table-container">
    <table>
        <thead>
            <tr>
                <th>Student</th>
                <th>Content Preview</th>
                <th>Submitted</th>
                <th>Score</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($submissions)): ?>
                <tr><td colspan="5" style="text-align: center; padding: 40px; color: #94a3b8;">No submissions yet.</td></tr>
            <?php endif; ?>

            <?php foreach ($submissions as $sub): 
                $f_stmt = $pdo->prepare("SELECT * FROM project_files WHERE submission_id = ?");
                $f_stmt->execute([$sub['id']]);
                $files = $f_stmt->fetchAll();
            ?>
            <tr>
                <td>
                    <div style="font-weight: 700; color: var(--dark);"><?= htmlspecialchars($sub['student_name']) ?></div>
                    <div style="font-size: 0.8rem; color: #64748b;"><?= htmlspecialchars($sub['student_email']) ?></div>
                </td>
                <td>
                    <div style="display: flex; flex-wrap: wrap; gap: 8px; align-items: center; max-width: 300px;">
                        <?php if($sub['submission_text']): ?>
                            <span class='badge' style='background: #f1f5f9; color: #475569;'>Text</span> 
                        <?php endif; ?>
                        <?php if($sub['submission_links']): ?>
                             <span class='badge' style='background: #f1f5f9; color: #475569;'>Links</span> 
                        <?php endif; ?>
                        
                        <?php foreach($files as $f): ?>
                             <a href="<?= $path_to_root ?>uploads/projects/<?= $f['file_url'] ?>" target="_blank" class="badge" style="background: var(--blue-50); color: var(--primary); text-decoration: none; border: 1px solid var(--blue-100);" title="<?= htmlspecialchars($f['file_name']) ?>">
                                <i class="fas fa-paperclip"></i> File
                             </a>
                        <?php endforeach; ?>
                    </div>
                </td>
                <td><small><?= date('d M, h:i A', strtotime($sub['submitted_at'])) ?></small></td>
                <td>
                    <?php if ($sub['score'] !== null): ?>
                        <div style="font-weight: 800; color: var(--success); font-size: 1.1rem;"><?= $sub['score'] ?>%</div>
                    <?php else: ?>
                        <span style="color: #ef4444; font-size: 0.8rem; font-weight: 700;">NOT GRADED</span>
                    <?php endif; ?>
                </td>
                <td>
                    <div style="display: flex; gap: 8px;">
                        <button onclick='openGradeModal(<?= htmlspecialchars(json_encode($sub), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($files), ENT_QUOTES) ?>)' class="btn btn-sm btn-primary">
                            <i class="fas fa-eye"></i> Grade
                        </button>
                        <a href="?id=<?= $project_id ?>&delete_submission=<?= $sub['id'] ?>" onclick="return confirm('WARNING: Are you sure you want to delete this submission? All student files will be permanently erased. Student can then submit again.')" class="btn btn-sm btn-danger" style="background: #fee2e2; color: #ef4444; border: 1px solid #fecaca;"><i class="fas fa-trash"></i></a>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Detailed View & Grading Modal -->
<div id="gradeModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 10000; align-items: center; justify-content: center; padding: 20px; backdrop-filter: blur(4px);">
    <div class="white-card shadow-lg" style="width: 100%; max-width: 900px; max-height: 90vh; overflow-y: auto; padding: 0;">
        <!-- Header -->
        <div style="padding: 25px; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="margin:0;">Grade Student Work</h3>
            <button onclick="closeGradeModal()" class="btn" style="padding: 5px 10px;"><i class="fas fa-times"></i></button>
        </div>

        <div class="responsive-grading-grid">
            <!-- SUBMISSION CONTENT -->
            <div class="submission-content-pane">
                <h4 id="grade_student_name" style="margin-bottom: 20px; color: var(--primary);"></h4>
                
                <div id="content_text" style="display:none; margin-bottom: 25px;">
                    <label style="font-size: 0.8rem; font-weight: 700; color: #94a3b8; display: block; margin-bottom: 8px;">SUBMITTED TEXT / CODE</label>
                    <pre id="text_preview" class="pretty-scroll" style="background: #0f172a; color: #34d399; padding: 20px; border-radius: 12px; font-family: 'Courier New', monospace; font-size: 0.9rem; white-space: pre-wrap; overflow-x: auto; max-height: 400px;"></pre>
                </div>

                <div id="content_links" style="display:none; margin-bottom: 25px;">
                    <label style="font-size: 0.8rem; font-weight: 700; color: #94a3b8; display: block; margin-bottom: 8px;">SUBMITTED LINKS</label>
                    <div id="links_preview" style="display: flex; flex-direction: column; gap: 8px;"></div>
                </div>

                <div id="content_files" style="display:none; margin-bottom: 25px;">
                    <label style="font-size: 0.8rem; font-weight: 700; color: #94a3b8; display: block; margin-bottom: 8px;">SUBMITTED FILES</label>
                    <div id="files_preview" style="display: grid; grid-template-columns: 1fr; gap: 10px;"></div>
                </div>
            </div>

            <!-- GRADING PANEL -->
            <div class="grading-form-pane">
                <form method="POST">
                    <input type="hidden" name="submission_id" id="submission_id">
                    
                    <div class="form-group">
                        <label>Score (Percentage)</label>
                        <input type="number" min="0" max="100" name="score" id="score" class="form-control" style="font-size: 1.5rem; font-weight: 800; text-align: center; color: var(--primary);" required>
                    </div>

                    <div class="form-group" style="margin-top: 25px;">
                        <label>Instructor Feedback</label>
                        <textarea name="feedback" id="feedback" class="form-control" rows="8" placeholder="Well done! Here are some thoughts..."></textarea>
                    </div>

                    <button type="submit" name="grade_submission" class="btn btn-primary" style="margin-top: 30px; width: 100%; padding: 15px;">
                        <i class="fas fa-check-circle"></i> Save Grade & Feedback
                    </button>
                    <p style="font-size: 0.8rem; color: #64748b; text-align: center; margin-top: 15px;">Student will be notified once you save.</p>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function openGradeModal(sub, files) {
    document.getElementById('submission_id').value = sub.id;
    document.getElementById('grade_student_name').innerText = sub.student_name;
    document.getElementById('score').value = sub.score || '';
    document.getElementById('feedback').value = sub.feedback || '';

    // Preview TEXT
    if(sub.submission_text) {
        document.getElementById('content_text').style.display = 'block';
        document.getElementById('text_preview').innerText = sub.submission_text;
    } else {
        document.getElementById('content_text').style.display = 'none';
    }

    // Preview LINKS
    if(sub.submission_links) {
        document.getElementById('content_links').style.display = 'block';
        const link_container = document.getElementById('links_preview');
        link_container.innerHTML = '';
        sub.submission_links.split('\n').forEach(l => {
            const parts = l.split('|');
            if(parts.length == 2) {
                const a = document.createElement('a');
                a.href = parts[1].trim();
                a.target = '_blank';
                a.className = 'btn btn-sm btn-white';
                a.style = 'text-align: left; background: #fff; border: 1px solid #e2e8f0; color: #1e293b;';
                a.innerHTML = `<i class="fas fa-external-link-alt text-primary"></i> ${parts[0].trim()}`;
                link_container.appendChild(a);
            }
        });
    } else {
        document.getElementById('content_links').style.display = 'none';
    }

    // Preview FILES
    if(files && files.length > 0) {
        document.getElementById('content_files').style.display = 'block';
        const file_container = document.getElementById('files_preview');
        file_container.innerHTML = '';
        files.forEach(f => {
            const div = document.createElement('div');
            div.style = 'padding: 10px; border: 1px solid #e2e8f0; border-radius: 8px; background: #fff; display: flex; justify-content: space-between; align-items: center;';
            div.innerHTML = `
                <div style='font-size: 0.85rem; font-weight: 600;'><i class='far fa-file-code text-primary'></i> ${f.file_name}</div>
                <a href='${path_to_root}uploads/projects/${f.file_url}' target='_blank' class='btn btn-xs btn-primary'><i class='fas fa-download'></i></a>
            `;
            file_container.appendChild(div);
        });
    } else {
        document.getElementById('content_files').style.display = 'none';
    }

    document.getElementById('gradeModal').style.display = 'flex';
}

function closeGradeModal() {
    document.getElementById('gradeModal').style.display = 'none';
}

const path_to_root = "<?= $path_to_root ?>";
</script>

<style>
.responsive-grading-grid {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-start;
}
.submission-content-pane {
    flex: 1;
    min-width: 300px;
    padding: 30px;
    border-right: 1px solid #f1f5f9;
    background: #fafafa;
    min-height: 100%;
}
.grading-form-pane {
    flex: 0 0 350px;
    padding: 30px;
    position: sticky;
    top: 0;
}
.pretty-scroll::-webkit-scrollbar {
    height: 6px;
    width: 6px;
}
.pretty-scroll::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 10px;
}
.pretty-scroll:hover::-webkit-scrollbar-thumb {
    background: #94a3b8;
}
#text_preview {
    max-width: 100%;
    overflow-x: auto;
    background: #0f172a;
    color: #34d399;
    padding: 20px;
    border-radius: 12px;
    font-family: 'Courier New', monospace;
    font-size: 0.85rem;
    line-height: 1.6;
    border: 1px solid #1e293b;
}
@media (max-width: 900px) {
    .submission-content-pane {
        border-right: none;
        border-bottom: 1px solid #f1f5f9;
        flex: 1 1 100%;
    }
    .grading-form-pane {
        flex: 1 1 100%;
        position: static;
    }
}
</style>

<?php include $path_to_root . 'includes/footer.php'; ?>
