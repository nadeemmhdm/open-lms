<?php
namespace OpenLMS\Controllers;

use OpenLMS\Core\Auth;
use OpenLMS\Core\Csrf;
use OpenLMS\Core\Database;
use OpenLMS\Policies\CoursePolicy;

trait AttendanceHelpersTrait
{
    private static function course(int $id):?array{$st=Database::connection()->prepare("SELECT c.*,s.status subject_status FROM courses c JOIN subjects s ON s.id=c.subject_id WHERE c.id=?");$st->execute([$id]);return $st->fetch()?:null;}
    private static function manageableCourses(array $u):array{$pdo=Database::connection();if(Auth::hasAnyRole(['super_admin','administrator','hod']))return $pdo->query("SELECT c.*,s.status subject_status FROM courses c JOIN subjects s ON s.id=c.subject_id WHERE c.status<>'archived' ORDER BY c.title")->fetchAll();$st=$pdo->prepare("SELECT c.*,s.status subject_status FROM courses c JOIN subjects s ON s.id=c.subject_id WHERE c.teacher_id=? AND c.status<>'archived' ORDER BY c.title");$st->execute([(int)$u['id']]);return $st->fetchAll();}
    private static function manageableSessions(array $u):array{$pdo=Database::connection();$where=Auth::hasAnyRole(['super_admin','administrator','hod'])?'1=1':'c.teacher_id=?';$st=$pdo->prepare("SELECT a.*,c.title course_title,b.name batch_name,(SELECT COUNT(*) FROM attendance_records ar WHERE ar.session_id=a.id) record_count FROM attendance_sessions a JOIN courses c ON c.id=a.course_id LEFT JOIN batches b ON b.id=a.batch_id WHERE $where ORDER BY a.session_date DESC,a.id DESC LIMIT 50");$st->execute($where==='1=1'?[]:[(int)$u['id']]);return $st->fetchAll();}
    private static function studentsForSession(array $s):array{$pdo=Database::connection();if(!empty($s['batch_id'])){$st=$pdo->prepare("SELECT DISTINCT u.id,u.name,u.email FROM batch_members bm JOIN users u ON u.id=bm.user_id WHERE bm.batch_id=? AND u.status='active' ORDER BY u.name");$st->execute([(int)$s['batch_id']]);}else{$st=$pdo->prepare("SELECT DISTINCT u.id,u.name,u.email FROM enrollments e JOIN users u ON u.id=e.user_id WHERE e.course_id=? AND e.status='active' AND u.status='active' ORDER BY u.name");$st->execute([(int)$s['course_id']]);}return $st->fetchAll();}
    private static function batchAllowed(int $course,int $batch):bool{$st=Database::connection()->prepare('SELECT COUNT(*) FROM course_batches WHERE course_id=? AND batch_id=?');$st->execute([$course,$batch]);return (int)$st->fetchColumn()>0;}
    private static function batchesForCourses(array $courses):array{if(!$courses)return[];$ids=array_map(fn($c)=>(int)$c['id'],$courses);$marks=implode(',',array_fill(0,count($ids),'?'));$st=Database::connection()->prepare("SELECT DISTINCT b.id,b.name FROM course_batches cb JOIN batches b ON b.id=cb.batch_id WHERE cb.course_id IN ($marks) ORDER BY b.name");$st->execute($ids);return $st->fetchAll();}
    private static function reportRows(array $user): array
    {
        $clauses=[];$args=[];
        if(in_array('teacher',$user['roles'],true)&&!Auth::hasAnyRole(['administrator','super_admin','hod'])){$clauses[]='c.teacher_id=?';$args[]=(int)$user['id'];}
        self::appendReportFilters($clauses,$args);$where=$clauses?implode(' AND ',$clauses):'1=1';
        $sql="SELECT u.id student_id,u.name student_name,c.id course_id,c.title course_title,COUNT(*) total,SUM(CASE WHEN r.status='present' THEN 1 ELSE 0 END) present,SUM(CASE WHEN r.status='late' THEN 1 ELSE 0 END) late,SUM(CASE WHEN r.status='absent' THEN 1 ELSE 0 END) absent,SUM(CASE WHEN r.status='excused' THEN 1 ELSE 0 END) excused FROM attendance_records r JOIN attendance_sessions a ON a.id=r.session_id JOIN courses c ON c.id=a.course_id JOIN users u ON u.id=r.student_id WHERE $where GROUP BY u.id,u.name,c.id,c.title ORDER BY c.title,u.name";
        $st=Database::connection()->prepare($sql);$st->execute($args);return $st->fetchAll();
    }
    private static function appendReportFilters(array &$clauses,array &$args): void
    {
        $map=['date_from'=>['a.session_date >= ?',false],'date_to'=>['a.session_date <= ?',false],'academic_year_id'=>['a.academic_year_id = ?',true],'semester_id'=>['a.semester_id = ?',true],'course_id'=>['a.course_id = ?',true],'batch_id'=>['a.batch_id = ?',true]];
        foreach($map as $key=>[$sql,$numeric]){$v=$_GET[$key]??'';if($v==='')continue;if($numeric){$v=(int)$v;if($v<1)continue;}elseif(!preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)$v))continue;$clauses[]=$sql;$args[]=$v;}
    }
    private static function idExists(string $table,int $id): bool
    {
        if(!in_array($table,['academic_years','semesters'],true))return false;$st=Database::connection()->prepare('SELECT COUNT(*) FROM '.$table.' WHERE id=?');$st->execute([$id]);return (int)$st->fetchColumn()>0;
    }
    private static function friendly(\Throwable $e):string{return $e instanceof \RuntimeException?$e->getMessage():'Unable to save attendance.';}
}
