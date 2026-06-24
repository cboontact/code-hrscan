<?php
require_once '../config/database.php';
require_once '../config/config.php';

$database = new Database();
$db = $database->getConnection();
autoMarkMissingCheckOut($db);

try {
    // ดึงรายการสแกนล่าสุด 10 รายการของวันนี้
    $today = date('Y-m-d');
    
    $query = "SELECT 
                ar.id,
                ar.check_in_time,
                ar.check_out_time,
                ar.status,
                ar.attendance_date,
                t.first_name,
                t.last_name,
                t.department,
                t.photo
              FROM attendance_records ar
              JOIN teachers t ON ar.teacher_id = t.id
              WHERE ar.attendance_date = :today
              ORDER BY 
                CASE 
                    WHEN ar.check_out_time IS NOT NULL THEN ar.check_out_time
                    ELSE ar.check_in_time
                END DESC
              LIMIT 3";
    
    $stmt = $db->prepare($query);
    $stmt->execute([':today' => $today]);
    $records = $stmt->fetchAll();

    $timeRules = getAttendanceTimeRules($db, $today);
    $records = array_map(function ($record) use ($timeRules) {
        $displayStatus = resolveAttendanceDisplayStatus($record, $timeRules['check_in_late']);
        $record['status'] = $displayStatus['code'];
        $record['base_status'] = $displayStatus['base_status'];
        $record['status_text'] = $displayStatus['text'];
        return $record;
    }, $records);
    
    jsonResponse(true, 'ดึงข้อมูลสำเร็จ', [
        'records' => $records,
        'count' => count($records)
    ]);

} catch(Exception $e) {
    error_log("Recent scans error: " . $e->getMessage());
    jsonResponse(false, 'เกิดข้อผิดพลาดในการดึงข้อมูล', null, 500);
}
?>
