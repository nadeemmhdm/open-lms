#!/usr/bin/env python3
"""Portable SQLite smoke test for Open LMS migrations 001-008."""
from pathlib import Path
import os, sqlite3, tempfile

ROOT = Path(__file__).resolve().parents[1]
fd, path = tempfile.mkstemp(prefix='open-lms-', suffix='.sqlite')
os.close(fd)
db = sqlite3.connect(path)
db.execute('PRAGMA foreign_keys=ON')
try:
    migrations = sorted(ROOT.glob('database/migrations/*_sqlite.sql'))
    assert [p.name[:3] for p in migrations] == ['001','002','003','004','005','006','007','008']
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
        'submission_files','notifications','announcements','certificates',
        'timetable_entries','live_classes','parent_student_links','fee_invoices',
        'password_reset_tokens','backup_history','mfa_credentials','feature_settings',
        'registration_invites','registration_invite_history'
    }
    missing = sorted(required - tables)
    assert not missing, f'missing tables: {missing}'

    assert db.execute("SELECT COUNT(*) FROM feature_settings WHERE feature_key IN ('announcements','search','backup_restore')").fetchone()[0] == 0

    db.execute("INSERT INTO users(name,email,password_hash,status,created_at,updated_at) VALUES('A','a@test','x','active',datetime('now'),datetime('now'))")
    uid = db.execute("SELECT id FROM users WHERE email='a@test'").fetchone()[0]
    try:
        db.execute("INSERT INTO users(name,email,password_hash,status,created_at,updated_at) VALUES('B','a@test','x','active',datetime('now'),datetime('now'))")
        raise AssertionError('duplicate email accepted')
    except sqlite3.IntegrityError:
        pass

    db.execute("INSERT INTO mfa_credentials(user_id,encrypted_secret,recovery_codes_json,enabled_at,updated_at) VALUES(?, 'enc','[]',datetime('now'),datetime('now'))", (uid,))
    try:
        db.execute("INSERT INTO mfa_credentials(user_id,encrypted_secret,recovery_codes_json,enabled_at,updated_at) VALUES(?, 'enc2','[]',datetime('now'),datetime('now'))", (uid,))
        raise AssertionError('duplicate MFA credential accepted')
    except sqlite3.IntegrityError:
        pass

    db.execute("INSERT INTO roles(name,label,is_system) VALUES('student','Student',1)")
    db.execute("INSERT INTO registration_invites(public_id,token_hash,role_name,max_uses,used_count,expires_at,created_by,created_at) VALUES(123456,'hash1','student',20,0,datetime('now','+1 day'),?,datetime('now'))", (uid,))
    try:
        db.execute("INSERT INTO registration_invites(public_id,token_hash,role_name,max_uses,used_count,expires_at,created_by,created_at) VALUES(123456,'hash2','student',1,0,datetime('now','+1 day'),?,datetime('now'))", (uid,))
        raise AssertionError('duplicate invite public id accepted')
    except sqlite3.IntegrityError:
        pass
    db.execute("INSERT INTO registration_invite_history(public_id,token_hash,role_name,max_uses,used_count,final_state,expires_at,created_by,created_at,archived_at) VALUES(654321,'hash3','student',1,1,'used',datetime('now'),?,datetime('now'),datetime('now'))", (uid,))
    assert db.execute("SELECT COUNT(*) FROM registration_invites WHERE public_id BETWEEN 100000 AND 99999999").fetchone()[0] == 1

    db.execute("INSERT INTO password_reset_tokens(user_id,token_hash,expires_at,created_at) VALUES(?, 'hash',datetime('now','+1 hour'),datetime('now'))", (uid,))
    db.execute("INSERT INTO backup_history(file_name,driver,size_bytes,created_by,created_at) VALUES('test.olmsbak','sqlite',1,?,datetime('now'))", (uid,))
    assert db.execute('PRAGMA foreign_key_check').fetchall() == []
    assert len(tables) >= 61
    print('PASS: migrations 001-008, required LMS tables, unique constraints and foreign-key integrity.')
finally:
    db.close()
    try: os.unlink(path)
    except FileNotFoundError: pass
