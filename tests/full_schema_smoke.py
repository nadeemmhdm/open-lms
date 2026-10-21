#!/usr/bin/env python3
"""Schema/workflow smoke test for Open LMS SQLite migrations 001-006.
Uses Python sqlite3 because this build environment's PHP CLI has PDO core but no PDO DB driver.
"""
from pathlib import Path
import glob, json, os, sqlite3, tempfile

ROOT = Path(__file__).resolve().parents[1]
fd, db_path = tempfile.mkstemp(prefix="open-lms-smoke-", suffix=".sqlite")
os.close(fd)
conn = sqlite3.connect(db_path)
conn.row_factory = sqlite3.Row
conn.execute("PRAGMA foreign_keys=ON")

try:
    migrations = sorted(ROOT.glob("database/migrations/*_sqlite.sql"))
    assert len(migrations) >= 6, "expected phase migrations 001-006"
    for migration in migrations:
        conn.executescript(migration.read_text(encoding="utf-8"))
        version = migration.name.replace("_sqlite.sql", "")
        try:
            conn.execute("INSERT INTO schema_migrations(version,applied_at) VALUES (?,datetime('now'))", (version,))
        except sqlite3.IntegrityError:
            pass
    conn.commit()

    now = "2026-10-21 12:00:00"
    # Roles / users
    for role in ("super_admin", "administrator", "teacher", "student", "hod", "examiner", "parent"):
        conn.execute("INSERT INTO roles(name,label,is_system) VALUES (?,?,1)", (role, role.replace('_',' ').title()))
    users = [
        ("Admin", "admin@example.test", "admin"),
        ("Teacher", "teacher@example.test", "teacher"),
        ("Student", "student@example.test", "student"),
        ("Parent", "parent@example.test", "parent"),
    ]
    user_ids = {}
    for name,email,key in users:
        cur=conn.execute("INSERT INTO users(name,email,password_hash,status,created_at,updated_at) VALUES (?,?,?,'active',?,?)",(name,email,'hash',now,now))
        user_ids[key]=cur.lastrowid
        role = "administrator" if key=="admin" else key
        rid=conn.execute("SELECT id FROM roles WHERE name=?",(role,)).fetchone()[0]
        conn.execute("INSERT INTO user_roles(user_id,role_id) VALUES (?,?)",(cur.lastrowid,rid))

    # Academic / Phase 2
    ay=conn.execute("INSERT INTO academic_years(name,starts_on,ends_on,is_active) VALUES ('2026-27','2026-06-01','2027-05-31',1)").lastrowid
    dep=conn.execute("INSERT INTO departments(name,code) VALUES ('Computer Science','CS')").lastrowid
    cls=conn.execute("INSERT INTO classes(name,department_id) VALUES ('Year 1',?)",(dep,)).lastrowid
    sec=conn.execute("INSERT INTO sections(class_id,name) VALUES (?,'A')",(cls,)).lastrowid
    batch=conn.execute("INSERT INTO batches(name,academic_year_id,class_id,section_id) VALUES ('CS-2026',?,?,?)",(ay,cls,sec)).lastrowid
    conn.execute("INSERT INTO batch_members(batch_id,user_id,assigned_at) VALUES (?,?,?)",(batch,user_ids['student'],now))
    subj=conn.execute("INSERT INTO subjects(name,code,status,created_at,updated_at) VALUES ('Programming','CS101','active',?,?)",(now,now)).lastrowid
    course=conn.execute("INSERT INTO courses(subject_id,title,teacher_id,status,created_at,updated_at) VALUES (?, 'PHP Basics', ?, 'published', ?, ?)",(subj,user_ids['teacher'],now,now)).lastrowid
    conn.execute("INSERT INTO course_batches(course_id,batch_id,assigned_at) VALUES (?,?,?)",(course,batch,now))
    csec=conn.execute("INSERT INTO course_sections(course_id,title,sort_order) VALUES (?,'Module 1',1)",(course,)).lastrowid
    lesson1=conn.execute("INSERT INTO lessons(course_section_id,title,sort_order,status,created_at,updated_at) VALUES (?,'Intro',1,'published',?,?)",(csec,now,now)).lastrowid
    lesson2=conn.execute("INSERT INTO lessons(course_section_id,title,sort_order,status,created_at,updated_at) VALUES (?,'Variables',2,'published',?,?)",(csec,now,now)).lastrowid
    conn.execute("INSERT INTO lesson_prerequisites(lesson_id,prerequisite_lesson_id) VALUES (?,?)",(lesson2,lesson1))
    conn.execute("INSERT INTO lesson_progress(user_id,lesson_id,progress_percent,completed_at) VALUES (?,?,100,?)",(user_ids['student'],lesson1,now))
    conn.execute("INSERT INTO lesson_progress(user_id,lesson_id,progress_percent,completed_at) VALUES (?,?,100,?)",(user_ids['student'],lesson2,now))
    conn.execute("INSERT INTO course_completions(user_id,course_id,completed_at) VALUES (?,?,?)",(user_ids['student'],course,now))

    # Phase 3 attendance + correction history
    att=conn.execute("INSERT INTO attendance_sessions(course_id,batch_id,session_date,period_label,marked_by,created_at,academic_year_id) VALUES (?,?, '2026-10-21','P1',?,?,?)",(course,batch,user_ids['teacher'],now,ay)).lastrowid
    conn.execute("INSERT INTO attendance_records(session_id,student_id,status,note) VALUES (?,?,'present','')",(att,user_ids['student']))
    try:
        conn.execute("INSERT INTO attendance_records(session_id,student_id,status,note) VALUES (?,?,'absent','')",(att,user_ids['student']))
        raise AssertionError("duplicate attendance record was accepted")
    except sqlite3.IntegrityError:
        pass
    conn.execute("INSERT INTO attendance_corrections(session_id,student_id,old_status,new_status,reason,corrected_by,created_at) VALUES (?,?, 'present','late','Verified late arrival',?,?)",(att,user_ids['student'],user_ids['teacher'],now))
    conn.execute("UPDATE attendance_records SET status='late' WHERE session_id=? AND student_id=?",(att,user_ids['student']))

    # Question bank / quiz
    bank=conn.execute("INSERT INTO question_banks(course_id,name,created_by,created_at) VALUES (?,'Core bank',?,?)",(course,user_ids['teacher'],now)).lastrowid
    q=conn.execute("INSERT INTO questions(question_bank_id,type,prompt,marks,created_at,updated_at,explanation) VALUES (?,'mcq','PHP files usually use which extension?',1,?,?, 'PHP source uses .php')",(bank,now,now)).lastrowid
    wrong=conn.execute("INSERT INTO question_options(question_id,option_text,is_correct,sort_order) VALUES (?,'html',0,1)",(q,)).lastrowid
    right=conn.execute("INSERT INTO question_options(question_id,option_text,is_correct,sort_order) VALUES (?,'php',1,2)",(q,)).lastrowid
    quiz=conn.execute("INSERT INTO quizzes(course_id,title,duration_minutes,total_marks,pass_marks,attempt_limit,status,created_at,assessment_type,negative_marking,randomize_questions,shuffle_options,review_policy,result_publication,updated_at) VALUES (?,'Quiz 1',10,1,1,2,'published',?,'quiz',0,1,1,'after_submit','immediate',?)",(course,now,now)).lastrowid
    conn.execute("INSERT INTO quiz_questions(quiz_id,question_id,sort_order) VALUES (?,?,1)",(quiz,q))

    # Phase 4 frozen attempt / answer / events / grade
    attempt=conn.execute("INSERT INTO exam_attempts(quiz_id,user_id,started_at,expires_at,status,attempt_no,last_activity_at,grading_status) VALUES (?,?,?,'2026-10-21 12:10:00','in_progress',1,?,'pending')",(quiz,user_ids['student'],now,now)).lastrowid
    conn.execute("INSERT INTO attempt_questions(attempt_id,question_id,sort_order,option_order_json,max_marks) VALUES (?,?,1,?,1)",(attempt,q,json.dumps([wrong,right])))
    conn.execute("INSERT INTO exam_answers(attempt_id,question_id,selected_options_json,awarded_marks,saved_at,is_correct) VALUES (?,?,?,1,?,1)",(attempt,q,json.dumps([right]),now))
    conn.execute("INSERT INTO exam_events(attempt_id,event_type,metadata_json,created_at) VALUES (?,'window_blur','{}',?)",(attempt,now))
    conn.execute("UPDATE exam_attempts SET status='submitted',submitted_at=?,score=1,grading_status='graded',results_published_at=? WHERE id=?",(now,now,attempt))
    conn.execute("INSERT INTO grades(user_id,course_id,item_type,item_id,marks,max_marks,graded_at) VALUES (?,?,'quiz',?,1,1,?)",(user_ids['student'],course,quiz,now))
    try:
        conn.execute("INSERT INTO exam_attempts(quiz_id,user_id,started_at,expires_at,status,attempt_no) VALUES (?,?,?,'2026-10-21 12:20:00','in_progress',1)",(quiz,user_ids['student'],now))
        raise AssertionError("duplicate attempt number accepted")
    except sqlite3.IntegrityError:
        pass

    # Phase 5 group project / versioned submission / private file metadata / grade
    project=conn.execute("INSERT INTO projects(course_id,title,project_type,starts_at,deadline_at,max_upload_bytes,status,created_at,allowed_extensions,allow_multiple_files,resubmission_allowed,late_policy,max_marks,updated_at) VALUES (?,'Team project','group',?,'2026-10-31 23:59:00',1048576,'published',?,'pdf,zip',1,1,'allow',10,?)",(course,now,now,now)).lastrowid
    grp=conn.execute("INSERT INTO project_groups(project_id,name,created_by,created_at) VALUES (?,'Group A',?,?)",(project,user_ids['teacher'],now)).lastrowid
    conn.execute("INSERT INTO project_group_members(group_id,user_id) VALUES (?,?)",(grp,user_ids['student']))
    sub=conn.execute("INSERT INTO project_submissions(project_id,user_id,version_no,status,submitted_at,group_id,is_late) VALUES (?,?,1,'submitted',?,?,0)",(project,user_ids['student'],now,grp)).lastrowid
    conn.execute("INSERT INTO submission_files(submission_id,original_name,stored_name,mime_type,size_bytes,created_at) VALUES (?,'report.pdf','projects/1/abc.dat','application/pdf',100,?)",(sub,now))
    conn.execute("INSERT INTO project_submission_events(submission_id,actor_user_id,event_type,details,created_at) VALUES (?,?,'submitted','Version 1',?)",(sub,user_ids['student'],now))
    conn.execute("UPDATE project_submissions SET status='graded',marks=9,feedback='Good',graded_by=?,graded_at=? WHERE id=?",(user_ids['teacher'],now,sub))

    # Phase 6 operational modules
    conn.execute("INSERT INTO notifications(user_id,title,body,created_at,type) VALUES (?,'Welcome','Hello',?,'general')",(user_ids['student'],now))
    conn.execute("INSERT INTO announcements(title,body,audience,course_id,created_by,published_at,created_at) VALUES ('Notice','Class update','course',?,?,?,?)",(course,user_ids['teacher'],now,now))
    conn.execute("INSERT INTO certificates(user_id,course_id,certificate_no,issued_by,issued_at,verification_token) VALUES (?,?, 'OLMS-TEST-1',?,?,'token-1')",(user_ids['student'],course,user_ids['teacher'],now))
    conn.execute("INSERT INTO timetable_entries(course_id,batch_id,weekday,starts_at,ends_at,room_label,created_by) VALUES (?,?,1,'09:00','10:00','Lab',?)",(course,batch,user_ids['teacher']))
    conn.execute("INSERT INTO live_classes(course_id,title,join_url,starts_at,created_by) VALUES (?,'Live 1','https://example.test/live',?,?)",(course,now,user_ids['teacher']))
    conn.execute("INSERT INTO parent_student_links(parent_user_id,student_user_id,created_at) VALUES (?,?,?)",(user_ids['parent'],user_ids['student'],now))
    invoice=conn.execute("INSERT INTO fee_invoices(user_id,title,amount,due_on,status,created_at) VALUES (?,'Tuition',100,'2026-11-01','unpaid',?)",(user_ids['student'],now)).lastrowid
    conn.execute("INSERT INTO fee_payments(invoice_id,amount,reference,paid_at,recorded_by) VALUES (?,50,'REF1',?,?)",(invoice,now,user_ids['admin']))
    conn.execute("INSERT INTO password_reset_tokens(user_id,token_hash,expires_at,created_at) VALUES (?,'hash-token','2026-10-21 12:30:00',?)",(user_ids['student'],now))
    conn.execute("INSERT INTO backup_history(file_name,driver,size_bytes,created_by,created_at) VALUES ('backup.olmsbak','sqlite',123,?,?)",(user_ids['admin'],now))
    conn.commit()

    # Critical invariants
    assert conn.execute("SELECT COUNT(*) FROM course_completions WHERE user_id=? AND course_id=?",(user_ids['student'],course)).fetchone()[0] == 1
    assert conn.execute("SELECT status FROM attendance_records WHERE session_id=? AND student_id=?",(att,user_ids['student'])).fetchone()[0] == 'late'
    assert conn.execute("SELECT score FROM exam_attempts WHERE id=?",(attempt,)).fetchone()[0] == 1
    assert conn.execute("SELECT COUNT(*) FROM project_group_members WHERE group_id=?",(grp,)).fetchone()[0] == 1
    assert conn.execute("SELECT COUNT(*) FROM parent_student_links").fetchone()[0] == 1
    assert conn.execute("SELECT COUNT(*) FROM certificates WHERE verification_token='token-1'").fetchone()[0] == 1
    flags = {r['feature_key']: r['enabled'] for r in conn.execute("SELECT feature_key,enabled FROM feature_settings WHERE feature_key IN ('announcements','search','backup_restore')")}
    assert flags == {'announcements':1,'backup_restore':1,'search':1}, flags
    # Queries used by filtered attendance and parent-portal summaries must remain portable on SQLite.
    report = conn.execute("SELECT u.name,c.title,COUNT(*) total,SUM(CASE WHEN r.status='present' THEN 1 ELSE 0 END) present,SUM(CASE WHEN r.status='late' THEN 1 ELSE 0 END) late FROM attendance_records r JOIN attendance_sessions a ON a.id=r.session_id JOIN courses c ON c.id=a.course_id JOIN users u ON u.id=r.student_id WHERE a.academic_year_id=? AND a.session_date BETWEEN ? AND ? GROUP BY u.id,u.name,c.id,c.title",(ay,'2026-10-01','2026-10-31')).fetchall()
    assert len(report)==1 and report[0]['late']==1
    parent_att = conn.execute("SELECT COUNT(*) total,SUM(CASE WHEN ar.status='present' THEN 1 ELSE 0 END) present,SUM(CASE WHEN ar.status='late' THEN 1 ELSE 0 END) late FROM attendance_records ar WHERE ar.student_id=?",(user_ids['student'],)).fetchone()
    assert parent_att['total']==1 and parent_att['late']==1
    parent_progress = conn.execute("SELECT c.id,c.title,(SELECT COUNT(*) FROM lessons l JOIN course_sections cs2 ON cs2.id=l.course_section_id WHERE cs2.course_id=c.id AND l.status='published') lesson_count,(SELECT COUNT(*) FROM lesson_progress lp JOIN lessons l2 ON l2.id=lp.lesson_id JOIN course_sections cs3 ON cs3.id=l2.course_section_id WHERE cs3.course_id=c.id AND lp.user_id=? AND lp.completed_at IS NOT NULL) completed_lessons,EXISTS(SELECT 1 FROM course_completions cc WHERE cc.course_id=c.id AND cc.user_id=?) completed FROM courses c WHERE EXISTS(SELECT 1 FROM course_batches cb JOIN batch_members bm ON bm.batch_id=cb.batch_id WHERE cb.course_id=c.id AND bm.user_id=?)",(user_ids['student'],user_ids['student'],user_ids['student'])).fetchall()
    assert len(parent_progress)==1 and parent_progress[0]['completed_lessons']==2 and parent_progress[0]['completed']==1
    assert conn.execute("PRAGMA foreign_key_check").fetchall() == [], "foreign key integrity failed"
    assert conn.execute("SELECT COUNT(*) FROM sqlite_master WHERE type='table'").fetchone()[0] >= 59

    print("PASS: migrations 001-006, academic access relations, filtered attendance/parent summaries, quiz/exam constraints, projects, gradebook, notifications, certificates, timetable, parent links, fees, reset-token and backup-history schema.")
finally:
    conn.close()
    try: os.unlink(db_path)
    except FileNotFoundError: pass
