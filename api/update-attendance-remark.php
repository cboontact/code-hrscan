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

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // กรณีมี id = แก้ไขหมายเหตุของ record ที่มีอยู่
    if (!empty($input['id'])) {
        // ตรวจสอบว่ามีข้อมูลหรือไม่
        $checkQuery = "SELECT a.id,
                              a.teacher_id,
                              a.attendance_date,
                              a.status,
                              a.check_in_time,
                              a.remark AS old_remark,
                              t.first_name,
                              t.last_name
                       FROM attendance_records a
                       INNER JOIN teachers t ON a.teacher_id = t.id
                       WHERE a.id = :id";
        $checkStmt = $db->prepare($checkQuery);
        $checkStmt->execute([':id' => $input['id']]);
        $checkRecord = $checkStmt->fetch();

        if (!$checkRecord) {
            jsonResponse(false, 'ไม่พบข้อมูลการลงเวลา', null, 404);
        }
        
        // อัพเดทหมายเหตุ
        $timeRules = getAttendanceTimeRules($db, $checkRecord['attendance_date']);
        $nextRemark = removeAttendanceRemarkTags($input['remark'] ?? null, [LATE_NO_CHECK_OUT_REMARK]);
        $nextStatus = $checkRecord['status'];
        if (!empty($checkRecord['check_in_time']) && in_array($checkRecord['status'], ['present', 'late'], true)) {
            $nextStatus = resolveAttendanceCheckInStatus(
                $checkRecord['check_in_time'],
                $timeRules['check_in_late'],
                $nextRemark ?? ''
            );
        }
        if (!empty($checkRecord['check_in_time']) && empty($checkRecord['check_out_time']) && $nextStatus === 'late') {
            $nextRemark = addAttendanceRemarkTag($nextRemark, LATE_NO_CHECK_OUT_REMARK);
        }

        $updateQuery = "UPDATE attendance_records 
                        SET remark = :remark,
                            status = :status,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE id = :id";
        
        $updateStmt = $db->prepare($updateQuery);
        $updateStmt->execute([
            ':remark' => $nextRemark,
            ':status' => $nextStatus,
            ':id' => $input['id']
        ]);
        
        $teacherName = trim(($checkRecord['first_name'] ?? '') . ' ' . ($checkRecord['last_name'] ?? ''));
        logAdminAction(
            $db,
            'UPDATE_ATTENDANCE_REMARK',
            "แก้ไขหมายเหตุของ {$teacherName} วันที่ {$checkRecord['attendance_date']}",
            [
                'attendance_record_id' => (int)$checkRecord['id'],
                'teacher_id' => (int)$checkRecord['teacher_id'],
                'teacher_name' => $teacherName,
                'attendance_date' => $checkRecord['attendance_date'],
                'old_remark' => $checkRecord['old_remark'],
                'new_remark' => $nextRemark,
                'old_status' => $checkRecord['status'],
                'new_status' => $nextStatus
            ],
            (int)$_SESSION['admin_id'],
            $_SESSION['admin_username'] ?? null,
            $_SESSION['admin_name'] ?? null,
            getClientIpAddress(),
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        );
        
        jsonResponse(true, 'บันทึกหมายเหตุสำเร็จ', null);
    } 
    // กรณีไม่มี id = เพิ่มหมายเหตุสำหรับคนที่ยังไม่ลงเวลา (สร้าง record ใหม่)
    else {
        if (!isset($input['teacher_id']) || !isset($input['attendance_date'])) {
            jsonResponse(false, 'ข้อมูลไม่ครบถ้วน (ต้องมี teacher_id และ attendance_date)', null, 400);
        }
        $nextStatus = 'absent';
        
        // ตรวจสอบว่ามี record อยู่แล้วหรือไม่
        $checkQuery = "SELECT id, status, check_in_time, remark FROM attendance_records 
                       WHERE teacher_id = :teacher_id AND attendance_date = :attendance_date";
        $checkStmt = $db->prepare($checkQuery);
        $checkStmt->execute([
            ':teacher_id' => $input['teacher_id'],
            ':attendance_date' => $input['attendance_date']
        ]);
        
        $existingRecord = $checkStmt->fetch();
        $teacherQuery = "SELECT id, first_name, last_name FROM teachers WHERE id = :teacher_id";
        $teacherStmt = $db->prepare($teacherQuery);
        $teacherStmt->execute([':teacher_id' => $input['teacher_id']]);
        $teacher = $teacherStmt->fetch();
        $teacherName = trim(($teacher['first_name'] ?? '') . ' ' . ($teacher['last_name'] ?? ''));
        $nextRemark = removeAttendanceRemarkTags($input['remark'] ?? null, [LATE_NO_CHECK_OUT_REMARK]);
        
        if ($existingRecord) {
            // ถ้ามี record แล้ว ให้อัพเดทหมายเหตุ
            $timeRules = getAttendanceTimeRules($db, $input['attendance_date']);
            $nextStatus = $existingRecord['status'];
            if (!empty($existingRecord['check_in_time']) && in_array($existingRecord['status'], ['present', 'late'], true)) {
                $nextStatus = resolveAttendanceCheckInStatus(
                    $existingRecord['check_in_time'],
                    $timeRules['check_in_late'],
                    $nextRemark ?? ''
                );
            }
            if (!empty($existingRecord['check_in_time']) && empty($existingRecord['check_out_time']) && $nextStatus === 'late') {
                $nextRemark = addAttendanceRemarkTag($nextRemark, LATE_NO_CHECK_OUT_REMARK);
            }

            $updateQuery = "UPDATE attendance_records 
                            SET remark = :remark,
                                status = :status,
                                updated_at = CURRENT_TIMESTAMP
                            WHERE id = :id";
            
            $updateStmt = $db->prepare($updateQuery);
            $updateStmt->execute([
                ':remark' => $nextRemark,
                ':status' => $nextStatus,
                ':id' => $existingRecord['id']
            ]);
        } else {
            // ถ้ายังไม่มี record ให้สร้างใหม่ (เฉพาะหมายเหตุ ไม่มีเวลา)
            $insertQuery = "INSERT INTO attendance_records 
                            (teacher_id, attendance_date, remark, status, rfid_code, created_at, updated_at)
                            VALUES 
                            (:teacher_id, :attendance_date, :remark, 'absent', '', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)";
            
            $insertStmt = $db->prepare($insertQuery);
            $insertStmt->execute([
                ':teacher_id' => $input['teacher_id'],
                ':attendance_date' => $input['attendance_date'],
                ':remark' => $nextRemark
            ]);
        }
        
        logAdminAction(
            $db,
            'UPDATE_ATTENDANCE_REMARK',
            "บันทึกหมายเหตุของ {$teacherName} วันที่ {$input['attendance_date']}",
            [
                'attendance_record_id' => (int)($existingRecord['id'] ?? $db->lastInsertId()),
                'teacher_id' => (int)$input['teacher_id'],
                'teacher_name' => $teacherName,
                'attendance_date' => $input['attendance_date'],
                'new_remark' => $nextRemark,
                'old_status' => $existingRecord['status'] ?? null,
                'new_status' => $nextStatus ?? 'absent'
            ],
            (int)$_SESSION['admin_id'],
            $_SESSION['admin_username'] ?? null,
            $_SESSION['admin_name'] ?? null,
            getClientIpAddress(),
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        );
        
        jsonResponse(true, 'บันทึกหมายเหตุสำเร็จ', null);
    }
    
} catch (Exception $e) {
    error_log("Update attendance remark error: " . $e->getMessage());
    jsonResponse(false, 'เกิดข้อผิดพลาดในการบันทึกหมายเหตุ: ' . $e->getMessage(), null, 500);
}
?>
