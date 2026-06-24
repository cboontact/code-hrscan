<?php
session_start();
require_once '../config/database.php';
require_once '../config/config.php';

if (!isset($_SESSION['admin_id'])) {
    jsonResponse(false, 'กรุณาเข้าสู่ระบบ', null, 401);
}

$database = new Database();
$db = $database->getConnection();

// อัพเดตสถานะไม่สแกนออกอัตโนมัติก่อนสรุปรายงาน
autoMarkMissingCheckOut($db);

try {
    // รับช่วงเวลาจาก query string (ถ้าไม่มีให้ใช้เดือนปัจจุบัน)
    $startDate = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
    $endDate = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t');

    error_log("Summary report request start_date={$startDate}, end_date={$endDate}, admin_id=" . ($_SESSION['admin_id'] ?? 'unknown'));

    // ตรวจสอบรูปแบบวันที่
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
        jsonResponse(false, 'รูปแบบวันที่ไม่ถูกต้อง', null, 400);
    }

    if ($startDate > $endDate) {
        jsonResponse(false, 'วันที่เริ่มต้นต้องไม่มากกว่าวันที่สิ้นสุด', null, 400);
    }
    
    // ดึงข้อมูลสรุปของแต่ละคน
    $query = "SELECT 
                t.id,
                t.first_name,
                t.last_name,
                t.department,
                t.photo,
                COUNT(ar.id) as total_records
              FROM teachers t
              LEFT JOIN attendance_records ar ON t.id = ar.teacher_id 
                  AND ar.attendance_date BETWEEN :start_date AND :end_date
              WHERE t.status = 'active'
              GROUP BY t.id, t.first_name, t.last_name, t.department, t.photo
              ORDER BY 
                CASE 
                    WHEN TRIM(t.first_name) = 'นางสาววัลภมาภรค์' THEN 0
                    WHEN CONCAT(TRIM(t.first_name), ' ', TRIM(t.last_name)) LIKE 'นางสาววัลภมาภรค์%' THEN 0
                    WHEN t.department LIKE '%ผู้บริหาร%' THEN 1
                    ELSE 2
                END,
                t.department ASC,
                t.first_name ASC,
                t.last_name ASC";
    
    $stmt = $db->prepare($query);
    $stmt->execute([
        ':start_date' => $startDate,
        ':end_date' => $endDate
    ]);
    $records = $stmt->fetchAll();

    // เตรียมโครงข้อมูลนับและรายละเอียดรายวัน
    $summaryMap = [];
    foreach ($records as $record) {
        $teacherId = (int)$record['id'];
        $summaryMap[$teacherId] = [
            'present_count' => 0,
            'late_count' => 0,
            'leave_count' => 0,
            'absent_count' => 0,
            'other_count' => 0,
            'details' => [
                'present' => [],
                'late' => [],
                'leave' => [],
                'absent' => [],
                'other' => []
            ]
        ];
    }

    // ดึงข้อมูลรายวันของแต่ละคนในช่วงเวลา
    $detailQuery = "SELECT 
                        teacher_id,
                        attendance_date,
                        status,
                        remark,
                        check_in_time,
                        check_out_time
                    FROM attendance_records
                    WHERE attendance_date BETWEEN :start_date AND :end_date
                    ORDER BY attendance_date ASC";
    $detailStmt = $db->prepare($detailQuery);
    $detailStmt->execute([
        ':start_date' => $startDate,
        ':end_date' => $endDate
    ]);
    $detailRows = $detailStmt->fetchAll();

    foreach ($detailRows as $row) {
        $teacherId = (int)$row['teacher_id'];
        if (!isset($summaryMap[$teacherId])) {
            continue;
        }

        $timeRules = getAttendanceTimeRules($db, $row['attendance_date']);
        $displayStatus = resolveAttendanceDisplayStatus($row, $timeRules['check_in_late']);
        $remark = trim((string)($row['remark'] ?? ''));
        $status = $row['status'] ?? '';
        $category = resolveAttendanceSummaryCategory($row, $timeRules['check_in_late']);

        $countKey = $category . '_count';
        $summaryMap[$teacherId][$countKey]++;
        $summaryMap[$teacherId]['details'][$category][] = [
            'attendance_date' => $row['attendance_date'],
            'status' => $status,
            'status_detail' => $displayStatus['code'],
            'status_text' => $displayStatus['text'],
            'remark' => $remark,
            'check_in_time' => $row['check_in_time'],
            'check_out_time' => $row['check_out_time']
        ];
    }

    // ใส่ข้อมูลนับและรายละเอียดกลับเข้า records
    foreach ($records as &$record) {
        $teacherId = (int)$record['id'];
        $record['present_count'] = $summaryMap[$teacherId]['present_count'];
        $record['late_count'] = $summaryMap[$teacherId]['late_count'];
        $record['leave_count'] = $summaryMap[$teacherId]['leave_count'];
        $record['absent_count'] = $summaryMap[$teacherId]['absent_count'];
        $record['other_count'] = $summaryMap[$teacherId]['other_count'];
        $record['details'] = $summaryMap[$teacherId]['details'];
        $record['total_records'] = $record['present_count'] + $record['late_count'] + $record['leave_count'] + $record['absent_count'] + $record['other_count'];
    }
    unset($record);
    
    // คำนวณจำนวนวันทำงานในช่วงเวลาที่เลือก
    $workDaysQuery = "SELECT COUNT(DISTINCT attendance_date) as work_days 
                      FROM attendance_records 
                      WHERE attendance_date BETWEEN :start_date AND :end_date";
    $workDaysStmt = $db->prepare($workDaysQuery);
    $workDaysStmt->execute([
        ':start_date' => $startDate,
        ':end_date' => $endDate
    ]);
    $workDaysResult = $workDaysStmt->fetch();
    $workDays = $workDaysResult['work_days'] ?: 0;

    error_log("Summary report success records=" . count($records) . ", detail_rows=" . count($detailRows) . ", work_days={$workDays}");
    
    jsonResponse(true, 'ดึงข้อมูลสำเร็จ', [
        'records' => $records,
        'count' => count($records),
        'start_date' => $startDate,
        'end_date' => $endDate,
        'work_days' => $workDays
    ]);

} catch(Exception $e) {
    error_log("Summary report error: " . $e->getMessage());
    jsonResponse(false, 'เกิดข้อผิดพลาดในการดึงข้อมูล', null, 500);
}
?>
