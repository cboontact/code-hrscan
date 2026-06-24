<?php
session_start();
require_once '../config/database.php';
require_once '../config/config.php';

// ตรวจสอบ session admin
if (!isset($_SESSION['admin_id'])) {
    jsonResponse(false, 'กรุณาเข้าสู่ระบบ', null, 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Method not allowed', null, 405);
}

$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['id']) || !isset($input['check_in_time'])) {
    jsonResponse(false, 'ข้อมูลไม่ครบถ้วน', null, 400);
}

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // ตรวจสอบว่ามีข้อมูลหรือไม่
    $checkQuery = "SELECT a.id,
                          a.attendance_date,
                          a.remark,
                          a.check_in_time AS old_check_in_time,
                          a.check_out_time AS old_check_out_time,
                          t.id AS teacher_id,
                          t.first_name,
                          t.last_name
                   FROM attendance_records a
                   INNER JOIN teachers t ON a.teacher_id = t.id
                   WHERE a.id = :id";
    $checkStmt = $db->prepare($checkQuery);
    $checkStmt->execute([':id' => $input['id']]);
    $record = $checkStmt->fetch();
    
    if (!$record) {
        jsonResponse(false, 'ไม่พบข้อมูลการลงเวลา', null, 404);
    }
    
    $attendanceDate = $record['attendance_date'];
    $timeRules = getAttendanceTimeRules($db, $attendanceDate);

    $checkInTimeOnly = normalizeTimeValue($input['check_in_time']);
    if (!$checkInTimeOnly) {
        jsonResponse(false, 'รูปแบบเวลาเข้าไม่ถูกต้อง', null, 400);
    }

    $checkInDateTime = $attendanceDate . ' ' . $checkInTimeOnly;
    $checkOutDateTime = null;
    
    if (!empty($input['check_out_time'])) {
        $checkOutTimeOnly = normalizeTimeValue($input['check_out_time']);
        if (!$checkOutTimeOnly) {
            jsonResponse(false, 'รูปแบบเวลาออกไม่ถูกต้อง', null, 400);
        }
        $checkOutDateTime = $attendanceDate . ' ' . $checkOutTimeOnly;
    }
    
    // กำหนดสถานะตามเวลาเข้า
    $status = resolveAttendanceCheckInStatus(
        $checkInDateTime,
        $timeRules['check_in_late'],
        $record['remark'] ?? ''
    );
    $remark = removeAttendanceRemarkTags($record['remark'] ?? '', [LATE_NO_CHECK_OUT_REMARK]);
    if ($checkOutDateTime === null && $status === 'late') {
        $remark = addAttendanceRemarkTag($remark, LATE_NO_CHECK_OUT_REMARK);
    }

    // ถ้ายังไม่ลงเวลาออก ให้คงสถานะตามเวลาเข้าไว้ก่อน
    // สถานะ "ไม่สแกนออก" จะถูกระบบตั้งอัตโนมัติตามเวลาที่กำหนด
    
    // อัพเดทข้อมูล
    $updateQuery = "UPDATE attendance_records 
                    SET check_in_time = :check_in_time,
                        check_out_time = :check_out_time,
                        remark = :remark,
                        status = :status,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = :id";
    
    $updateStmt = $db->prepare($updateQuery);
    $updateStmt->execute([
        ':check_in_time' => $checkInDateTime,
        ':check_out_time' => $checkOutDateTime,
        ':remark' => $remark,
        ':status' => $status,
        ':id' => $input['id']
    ]);
    
    $teacherName = trim(($record['first_name'] ?? '') . ' ' . ($record['last_name'] ?? ''));
    logAdminAction(
        $db,
        'UPDATE_ATTENDANCE_TIME',
        "แก้ไขเวลาเข้า-ออกของ {$teacherName} วันที่ {$attendanceDate}",
        [
            'attendance_record_id' => (int)$record['id'],
            'teacher_id' => (int)$record['teacher_id'],
            'teacher_name' => $teacherName,
            'attendance_date' => $attendanceDate,
            'old_check_in_time' => $record['old_check_in_time'],
            'old_check_out_time' => $record['old_check_out_time'],
            'new_check_in_time' => $checkInDateTime,
            'new_check_out_time' => $checkOutDateTime,
            'new_remark' => $remark,
            'new_status' => $status
        ],
        (int)$_SESSION['admin_id'],
        $_SESSION['admin_username'] ?? null,
        $_SESSION['admin_name'] ?? null,
        getClientIpAddress(),
        $_SERVER['HTTP_USER_AGENT'] ?? ''
    );
    
    jsonResponse(true, 'อัพเดทเวลาสำเร็จ', null);
    
} catch (Exception $e) {
    error_log("Update attendance time error: " . $e->getMessage());
    jsonResponse(false, 'เกิดข้อผิดพลาดในการอัพเดทเวลา: ' . $e->getMessage(), null, 500);
}
?>
