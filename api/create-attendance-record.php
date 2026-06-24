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

if (!isset($input['teacher_id']) || !isset($input['attendance_date']) || !isset($input['check_in_time'])) {
    jsonResponse(false, 'ข้อมูลไม่ครบถ้วน', null, 400);
}

try {
    $database = new Database();
    $db = $database->getConnection();
    
    $teacherQuery = "SELECT id, first_name, last_name, rfid_code, citizen_id
                     FROM teachers
                     WHERE id = :teacher_id";
    $teacherStmt = $db->prepare($teacherQuery);
    $teacherStmt->execute([':teacher_id' => $input['teacher_id']]);
    $teacher = $teacherStmt->fetch();

    if (!$teacher) {
        jsonResponse(false, 'ไม่พบข้อมูลครูที่ต้องการเพิ่มลงเวลา', null, 404);
    }

    // ตรวจสอบว่ามีข้อมูลลงเวลาในวันนี้แล้วหรือไม่
    $checkQuery = "SELECT id FROM attendance_records 
                   WHERE teacher_id = :teacher_id AND attendance_date = :attendance_date";
    $checkStmt = $db->prepare($checkQuery);
    $checkStmt->execute([
        ':teacher_id' => $input['teacher_id'],
        ':attendance_date' => $input['attendance_date']
    ]);
    
    if ($checkStmt->fetch()) {
        jsonResponse(false, 'มีข้อมูลลงเวลาในวันนี้แล้ว กรุณาใช้ฟังก์ชันแก้ไข', null, 400);
    }
    
    $attendanceDate = $input['attendance_date'];
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
    $status = resolveAttendanceCheckInStatus($checkInDateTime, $timeRules['check_in_late']);
    $remark = null;
    if ($checkOutDateTime === null && $status === 'late') {
        $remark = addAttendanceRemarkTag($remark, LATE_NO_CHECK_OUT_REMARK);
    }
    
    // ถ้ายังไม่ลงเวลากลับ ให้คงสถานะตามเวลาเข้าไว้ก่อน
    // สถานะ "ไม่สแกนออก" จะถูกระบบตั้งอัตโนมัติตามเวลาที่กำหนด
    
    // เพิ่มข้อมูลลงเวลาใหม่
    $insertQuery = "INSERT INTO attendance_records 
                    (teacher_id, rfid_code, attendance_date, check_in_time, check_out_time, remark, status, created_at, updated_at)
                    VALUES 
                    (:teacher_id, :rfid_code, :attendance_date, :check_in_time, :check_out_time, :remark, :status, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)";
    
    $insertStmt = $db->prepare($insertQuery);
    $insertStmt->execute([
        ':teacher_id' => $input['teacher_id'],
        ':rfid_code' => (string)($teacher['rfid_code'] ?? ''),
        ':attendance_date' => $attendanceDate,
        ':check_in_time' => $checkInDateTime,
        ':check_out_time' => $checkOutDateTime,
        ':remark' => $remark,
        ':status' => $status
    ]);
    
    $teacherName = trim(($teacher['first_name'] ?? '') . ' ' . ($teacher['last_name'] ?? ''));
    logAdminAction(
        $db,
        'CREATE_ATTENDANCE_RECORD',
        "เพิ่มข้อมูลลงเวลาของ {$teacherName} วันที่ {$attendanceDate}",
        [
            'attendance_record_id' => (int)$db->lastInsertId(),
            'teacher_id' => (int)$teacher['id'],
            'teacher_name' => $teacherName,
            'citizen_id' => $teacher['citizen_id'] ?? null,
            'rfid_code' => $teacher['rfid_code'] ?? null,
            'attendance_date' => $attendanceDate,
            'check_in_time' => $checkInDateTime,
            'check_out_time' => $checkOutDateTime,
            'remark' => $remark,
            'status' => $status
        ],
        (int)$_SESSION['admin_id'],
        $_SESSION['admin_username'] ?? null,
        $_SESSION['admin_name'] ?? null,
        getClientIpAddress(),
        $_SERVER['HTTP_USER_AGENT'] ?? ''
    );
    
    jsonResponse(true, 'เพิ่มข้อมูลลงเวลาสำเร็จ', null);
    
} catch (Exception $e) {
    error_log("Create attendance record error: " . $e->getMessage());
    jsonResponse(false, 'เกิดข้อผิดพลาดในการเพิ่มข้อมูล: ' . $e->getMessage(), null, 500);
}
?>
