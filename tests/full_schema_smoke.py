#!/usr/bin/env python3
"""Portable SQLite smoke test for Open LMS migrations 001-013."""
from pathlib import Path
import os, sqlite3, tempfile

ROOT = Path(__file__).resolve().parents[1]
fd, path = tempfile.mkstemp(prefix='open-lms-', suffix='.sqlite')
os.close(fd)
db = sqlite3.connect(path)
db.execute('PRAGMA foreign_keys=ON')
try:
    migrations = sorted(ROOT.glob('database/migrations/*_sqlite.sql'))
    assert [p.name[:3] for p in migrations] == ['001','002','003','004','005','006','007','008','009','010','011','012','013']
    for migration in migrations:
        db.executescript(migration.read_text(encoding='utf-8'))
    db.commit()

    tables = {r[0] for r in db.execute("SELECT name FROM sqlite_master WHERE type='table'")}
    required = {
        'users','roles','subjects','courses','lessons','lesson_progress',
        'attendance_sessions','attendance_records','attendance_corrections',
        'question_banks','questions','quizzes','quiz_questions','grades',
        'exam_attempts','exam_answers','exam_events','attempt_questions',
        'projects','project_submissions','project_groups','project_group_members',
        'submission_files','notifications','announcements','announcement_media','certificates',
        'timetable_entries','live_classes','parent_student_links','fee_invoices',
        'password_reset_tokens','backup_history','mfa_credentials','feature_settings',
        'registration_invites','registration_invite_history','registration_invite_contexts','registration_invite_courses','doubts','doubt_replies',
        'auth_sessions','user_login_history','payment_transactions','support_tickets','support_replies','password_reset_requests'
    }
    missing = sorted(required - tables)
    assert not missing, f'missing tables: {missing}'

    # On a fresh install, 007 removes Phase-6 feature defaults so the installer seed can run without duplicate keys.
    assert db.execute("SELECT COUNT(*) FROM feature_settings WHERE feature_key IN ('announcements','search','backup_restore')").fetchone()[0] == 0

    # Duplicate-sensitive records must be rejected.
    db.execute("INSERT INTO users(name,email,password_hash,status,created_at,updated_at) VALUES('A','a@test','x','active',datetime('now'),datetime('now'))")
    uid = db.execute("SELECT id FROM users WHERE email='a@test'").fetchone()[0]
    try:
        db.execute("INSERT INTO users(name,email,password_hash,status,created_at,updated_at) VALUES('B','a@test','x','active',datetime('now'),datetime('now'))")
        raise AssertionError('duplicate email accepted')
    except sqlite3.IntegrityError:
        pass

    # MFA is one credential row per user; reset tokens and backups are available.
    db.execute("INSERT INTO mfa_credentials(user_id,encrypted_secret,recovery_codes_json,enabled_at,updated_at) VALUES(?, 'enc','[]',datetime('now'),datetime('now'))", (uid,))
    try:
        db.execute("INSERT INTO mfa_credentials(user_id,encrypted_secret,recovery_codes_json,enabled_at,updated_at) VALUES(?, 'enc2','[]',datetime('now'),datetime('now'))", (uid,))
        raise AssertionError('duplicate MFA credential accepted')
    except sqlite3.IntegrityError:
        pass


    # Public registration links use 6-8 digit IDs and remain unique across active/history tables.
    db.execute("INSERT INTO roles(name,label,is_system) VALUES('student','Student',1)")
    db.execute("INSERT INTO registration_invites(public_id,token_hash,role_name,max_uses,used_count,expires_at,created_by,created_at) VALUES(123456,'hash1','student',20,0,datetime('now','+1 day'),?,datetime('now'))", (uid,))
    db.execute("INSERT INTO academic_years(name,is_active) VALUES('2026-27',1)")
    year_id = db.execute("SELECT id FROM academic_years WHERE name='2026-27'").fetchone()[0]
    db.execute("INSERT INTO batches(name,academic_year_id) VALUES('Batch A',?)", (year_id,))
    batch_id = db.execute("SELECT id FROM batches WHERE name='Batch A'").fetchone()[0]
    db.execute("INSERT INTO subjects(name,code,status,created_at,updated_at) VALUES('Test Subject','TST','active',datetime('now'),datetime('now'))")
    subject_id = db.execute("SELECT id FROM subjects WHERE code='TST'").fetchone()[0]
    db.execute("INSERT INTO courses(subject_id,title,status,created_at,updated_at) VALUES(?,'Test Course','published',datetime('now'),datetime('now'))", (subject_id,))
    course_id = db.execute("SELECT id FROM courses WHERE title='Test Course'").fetchone()[0]
    db.execute("INSERT INTO registration_invite_contexts(public_id,academic_year_id,batch_id,created_at) VALUES(123456,?,?,datetime('now'))", (year_id,batch_id))
    db.execute("INSERT INTO registration_invite_courses(public_id,course_id) VALUES(123456,?)", (course_id,))
    try:
        db.execute("INSERT INTO registration_invites(public_id,token_hash,role_name,max_uses,used_count,expires_at,created_by,created_at) VALUES(123456,'hash2','student',1,0,datetime('now','+1 day'),?,datetime('now'))", (uid,))
        raise AssertionError('duplicate invite public id accepted')
    except sqlite3.IntegrityError:
        pass
    db.execute("INSERT INTO registration_invite_history(public_id,token_hash,role_name,max_uses,used_count,final_state,expires_at,created_by,created_at,archived_at) VALUES(654321,'hash3','student',1,1,'used',datetime('now'),?,datetime('now'),datetime('now'))", (uid,))
    assert db.execute("SELECT COUNT(*) FROM registration_invites WHERE public_id BETWEEN 100000 AND 99999999").fetchone()[0] == 1
    db.execute("INSERT INTO doubts(public_id,course_id,student_id,title,body,status,created_at,updated_at) VALUES(765432,?,?, 'Need help','Explain this topic','open',datetime('now'),datetime('now'))", (course_id,uid))
    doubt_id = db.execute("SELECT id FROM doubts WHERE public_id=765432").fetchone()[0]
    db.execute("INSERT INTO doubt_replies(doubt_id,user_id,body,created_at) VALUES(?,?, 'Reply',datetime('now'))", (doubt_id,uid))
    assert db.execute("SELECT COUNT(*) FROM doubts WHERE public_id BETWEEN 100000 AND 99999999").fetchone()[0] == 1

    # Session history, support tickets and payments introduced by 010.
    db.execute("INSERT INTO auth_sessions(user_id,token_hash,remember_me,ip_address,user_agent,device_label,created_at,last_seen_at,expires_at) VALUES(?, 'sesshash',1,'127.0.0.1','UA','Test Device',datetime('now'),datetime('now'),datetime('now','+1 day'))", (uid,))
    db.execute("INSERT INTO user_login_history(user_id,ip_address,user_agent,device_label,remember_me,login_at,session_expires_at) VALUES(?, '127.0.0.1','UA','Test Device',1,datetime('now'),datetime('now','+1 day'))", (uid,))
    db.execute("INSERT INTO support_tickets(public_id,user_id,subject,category,priority,status,created_at,updated_at) VALUES(876543,?,'Help','technical','normal','open',datetime('now'),datetime('now'))", (uid,))
    support_id=db.execute("SELECT id FROM support_tickets WHERE public_id=876543").fetchone()[0]
    db.execute("INSERT INTO support_replies(ticket_id,sender_user_id,body,created_at) VALUES(?,?, 'Issue details',datetime('now'))", (support_id,uid))
    db.execute("INSERT INTO fee_invoices(user_id,title,amount,due_on,status,created_at,currency,notes,updated_at) VALUES(?,'Tuition',1000,NULL,'unpaid',datetime('now'),'INR',NULL,datetime('now'))", (uid,))
    invoice_id=db.execute("SELECT id FROM fee_invoices WHERE title='Tuition'").fetchone()[0]
    db.execute("INSERT INTO payment_transactions(invoice_id,user_id,provider,status,amount,currency,provider_order_id,created_at,updated_at) VALUES(?,?,'cash','pending',1000,'INR','cash-test',datetime('now'),datetime('now'))", (invoice_id,uid))
    assert db.execute("SELECT COUNT(*) FROM feature_settings WHERE feature_key='support' AND enabled=1").fetchone()[0] == 1
    assert db.execute("SELECT setting_value FROM app_settings WHERE setting_key='app_timezone'").fetchone()[0] == 'UTC'

    db.execute("INSERT INTO password_reset_requests(email_hash,ip_address,mail_sent,requested_at) VALUES('abc','127.0.0.1',0,datetime('now'))")
    assert db.execute("SELECT COUNT(*) FROM password_reset_requests WHERE ip_address='127.0.0.1'").fetchone()[0] == 1


    # Stable content/media additions introduced by 012.
    lesson_cols = {r[1] for r in db.execute("PRAGMA table_info('lessons')")}
    announcement_cols = {r[1] for r in db.execute("PRAGMA table_info('announcements')")}
    assert 'content_format' in lesson_cols
    assert {'image_url','video_url'} <= announcement_cols

    db.execute("INSERT INTO password_reset_tokens(user_id,token_hash,expires_at,created_at) VALUES(?, 'hash',datetime('now','+1 hour'),datetime('now'))", (uid,))
    db.execute("INSERT INTO backup_history(file_name,driver,size_bytes,created_by,created_at) VALUES('test.olmsbak','sqlite',1,?,datetime('now'))", (uid,))
    assert db.execute('PRAGMA foreign_key_check').fetchall() == []
    assert len(tables) >= 72
    print('PASS: migrations 001-013, required LMS tables, unique constraints and foreign-key integrity.')
finally:
    db.close()
    try: os.unlink(path)
    except FileNotFoundError: pass
