<?php

/** บันทึกเวลาจากบัตรหรือปุ่มส่วนตัว โดยใช้กฎและสถานะชุดเดียวกัน */
function processAttendanceScan($db, array $teacher, $action = 'auto')
{
    $rfidCode = $teacher['rfid_code'];
    $today = date('Y-m-d');
    $currentTime = date('Y-m-d H:i:s');
    $currentTimeOnly = date('H:i:s');
    $timeRules = getAttendanceTimeRules($db, $today, $teacher['id']);
    $checkInStart = $timeRules['check_in_start'];
    $checkInOnTime = $timeRules['check_in_late'];
    $checkOutStart = $timeRules['check_out_start'];
    $checkOutEnd = $timeRules['check_out_end'];

    $checkQuery = "SELECT * FROM attendance_records
                   WHERE teacher_id = :teacher_id AND attendance_date = :attendance_date";
    $checkStmt = $db->prepare($checkQuery);
    $checkStmt->execute([
        ':teacher_id' => $teacher['id'],
        ':attendance_date' => $today
    ]);
    $existingRecord = $checkStmt->fetch();

    if ($action === 'check_in' && !empty($existingRecord['check_in_time'])) {
        jsonResponse(true, 'บันทึกเวลาเข้าของวันนี้แล้ว', [
            'type' => 'already_checked_in',
            'check_in_time' => $existingRecord['check_in_time'],
            'check_out_time' => $existingRecord['check_out_time']
        ]);
    }

    // ปุ่มเข้าและออกต้องไม่สลับหน้าที่กันตามเวลาที่กด
    if ($action === 'check_out' && $currentTimeOnly < $checkOutStart) {
        jsonResponse(false, 'ยังไม่ถึงเวลาลงเวลาออก (เริ่ม ' . substr($checkOutStart, 0, 5) . ' น.)', null, 400);
    }
    if ($action !== 'auto' && $currentTimeOnly > $checkOutEnd && empty($existingRecord['check_out_time'])) {
        jsonResponse(false, 'เลยเวลาลงเวลาของวันนี้แล้ว', null, 400);
    }

    // รายการที่มีเพียงหมายเหตุยังไม่ถือว่าเคยสแกนเข้า-ออก
    if (!$existingRecord || (empty($existingRecord['check_in_time']) && empty($existingRecord['check_out_time']))) {
        // ถ้าสแกนครั้งแรกหลังเวลาออก ให้บันทึกเป็นสแกนออกโดยไม่มีเวลาเข้า
        if ($action !== 'check_in' && $currentTimeOnly >= $checkOutStart && $currentTimeOnly <= $checkOutEnd) {
            $remark = addAttendanceRemarkTag($existingRecord['remark'] ?? null, NO_CHECK_IN_BUT_CHECKED_OUT_REMARK);
            if ($existingRecord) {
                $saveQuery = "UPDATE attendance_records
                                SET rfid_code = :rfid_code,
                                    check_out_time = :check_out_time,
                                    remark = :remark,
                                    status = 'absent',
                                    updated_at = CURRENT_TIMESTAMP
                                WHERE id = :id";
                $params = [':id' => $existingRecord['id']];
            } else {
                $saveQuery = "INSERT INTO attendance_records
                            (teacher_id, rfid_code, check_out_time, attendance_date, remark, status)
                            VALUES (:teacher_id, :rfid_code, :check_out_time, :attendance_date, :remark, 'absent')";
                $params = [':teacher_id' => $teacher['id'], ':attendance_date' => $today];
            }
            $saveStmt = $db->prepare($saveQuery);
            $saveStmt->execute(array_merge($params, [
                ':rfid_code' => $rfidCode,
                ':check_out_time' => $currentTime,
                ':remark' => $remark
            ]));

            $displayStatus = resolveAttendanceDisplayStatus([
                'check_in_time' => null,
                'check_out_time' => $currentTime,
                'remark' => $remark,
                'status' => 'absent'
            ], $checkInOnTime);

            logPersonalAttendanceScan($db, $teacher, $action, 'CHECK_OUT');

            // logActivity($db, null, 'CHECK_OUT',
            //            "ครู {$teacher['first_name']} {$teacher['last_name']} ลงเวลากลับ (ไม่มีการสแกนเข้า)",
            //            $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);

            jsonResponse(true, 'บันทึกเวลากลับสำเร็จ', [
                'type' => 'check_out',
                'teacher' => [
                    'id' => $teacher['id'],
                    'name' => $teacher['first_name'] . ' ' . $teacher['last_name'],
                    'department' => $teacher['department'],
                    'photo' => $teacher['photo']
                ],
                'check_in_time' => null,
                'check_out_time' => $currentTime,
                'remark' => $remark,
                'status' => $displayStatus['code'],
                'base_status' => $displayStatus['base_status'],
                'status_text' => $displayStatus['text']
            ]);
        }

        // สแกนเข้า - ตรวจสอบว่าอยู่ในช่วงเวลาที่กำหนด
        if ($currentTimeOnly < $checkInStart) {
            jsonResponse(false, 'ยังไม่ถึงเวลาสแกนเข้างาน (เริ่มสแกนได้ตั้งแต่ ' . substr($checkInStart, 0, 5) . ' น.)', null, 400);
        }

        // กำหนดสถานะ: มาตรงเวลา หรือ มาสาย ตามกฎของวันนั้น
        $remark = $existingRecord['remark'] ?? null;
        $status = resolveAttendanceCheckInStatus($currentTime, $checkInOnTime, $remark ?? '');

        if ($existingRecord) {
            $saveQuery = "UPDATE attendance_records
                            SET rfid_code = :rfid_code,
                                check_in_time = :check_in_time,
                                status = :status,
                                updated_at = CURRENT_TIMESTAMP
                            WHERE id = :id";
            $params = [':id' => $existingRecord['id']];
        } else {
            $saveQuery = "INSERT INTO attendance_records
                        (teacher_id, rfid_code, check_in_time, attendance_date, status)
                        VALUES (:teacher_id, :rfid_code, :check_in_time, :attendance_date, :status)";
            $params = [':teacher_id' => $teacher['id'], ':attendance_date' => $today];
        }
        $saveStmt = $db->prepare($saveQuery);
        $saveStmt->execute(array_merge($params, [
            ':rfid_code' => $rfidCode,
            ':check_in_time' => $currentTime,
            ':status' => $status
        ]));
        $displayStatus = resolveAttendanceDisplayStatus([
            'check_in_time' => $currentTime,
            'check_out_time' => null,
            'remark' => $remark,
            'status' => $status
        ], $checkInOnTime);

        logPersonalAttendanceScan($db, $teacher, $action, 'CHECK_IN');

        // logActivity($db, null, 'CHECK_IN',
        //            "ครู {$teacher['first_name']} {$teacher['last_name']} ลงเวลามา",
        //            $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);

        jsonResponse(true, 'บันทึกเวลามาสำเร็จ', [
            'type' => 'check_in',
            'teacher' => [
                'id' => $teacher['id'],
                'name' => $teacher['first_name'] . ' ' . $teacher['last_name'],
                'department' => $teacher['department'],
                'photo' => $teacher['photo']
            ],
            'check_in_time' => $currentTime,
            'status' => $displayStatus['code'],
            'base_status' => $displayStatus['base_status'],
            'status_text' => $displayStatus['text']
        ]);
    } else {
        // มีการสแกนเข้าแล้ว - ตรวจสอบว่าสแกนออกแล้วหรือยัง
        if ($existingRecord['check_out_time']) {
            jsonResponse(true, 'คุณได้สแกนบัตรครบแล้ววันนี้ (เข้า-ออก)', [
                'type' => 'already_completed',
                'teacher' => [
                    'name' => $teacher['first_name'] . ' ' . $teacher['last_name'],
                    'department' => $teacher['department']
                ],
                'check_in_time' => $existingRecord['check_in_time'],
                'check_out_time' => $existingRecord['check_out_time']
            ]);
        }

        // สแกนออก - ตรวจสอบว่าอยู่ในช่วงเวลาที่กำหนด
        if ($currentTimeOnly < $checkOutStart) {
            $allowedTimeText = substr($checkOutStart, 0, 5) . ' น.';
            jsonResponse(true, 'ยังไม่ถึงเวลาสแกนออก<br>(สแกนออกได้ตั้งแต่ ' . $allowedTimeText . ')', [
                'type' => 'too_early',
                'teacher' => [
                    'name' => $teacher['first_name'] . ' ' . $teacher['last_name'],
                    'department' => $teacher['department']
                ],
                'check_in_time' => $existingRecord['check_in_time'],
                'allowed_time' => $allowedTimeText
            ]);
        }

        if ($currentTimeOnly > $checkOutEnd) {
            jsonResponse(true, 'เลยเวลาสแกนออกแล้ว (สแกนออกได้ถึง ' . substr($checkOutEnd, 0, 5) . ' น.)', [
                'type' => 'too_late',
                'teacher' => [
                    'name' => $teacher['first_name'] . ' ' . $teacher['last_name'],
                    'department' => $teacher['department']
                ],
                'check_in_time' => $existingRecord['check_in_time']
            ]);
        }

        $normalizedStatus = !empty($existingRecord['check_in_time'])
            ? resolveAttendanceCheckInStatus(
                $existingRecord['check_in_time'],
                $checkInOnTime,
                $existingRecord['remark'] ?? ''
            )
            : 'absent';
        $remark = removeAttendanceRemarkTags($existingRecord['remark'] ?? '', [LATE_NO_CHECK_OUT_REMARK]);
        if (empty($existingRecord['check_in_time'])) {
            $remark = addAttendanceRemarkTag($remark, NO_CHECK_IN_BUT_CHECKED_OUT_REMARK);
        }

        $updateQuery = "UPDATE attendance_records
                        SET check_out_time = :check_out_time,
                            remark = :remark,
                            status = :status,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE id = :id";
        $updateStmt = $db->prepare($updateQuery);
        $updateStmt->execute([
            ':check_out_time' => $currentTime,
            ':remark' => $remark,
            ':status' => $normalizedStatus,
            ':id' => $existingRecord['id']
        ]);
        $displayStatus = resolveAttendanceDisplayStatus([
            'check_in_time' => $existingRecord['check_in_time'],
            'check_out_time' => $currentTime,
            'remark' => $remark,
            'status' => $normalizedStatus
        ], $checkInOnTime);

        logPersonalAttendanceScan($db, $teacher, $action, 'CHECK_OUT');

        // logActivity($db, null, 'CHECK_OUT',
        //            "ครู {$teacher['first_name']} {$teacher['last_name']} ลงเวลากลับ",
        //            $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);

        jsonResponse(true, 'บันทึกเวลากลับสำเร็จ', [
            'type' => 'check_out',
            'teacher' => [
                'id' => $teacher['id'],
                'name' => $teacher['first_name'] . ' ' . $teacher['last_name'],
                'department' => $teacher['department'],
                'photo' => $teacher['photo']
            ],
            'check_in_time' => $existingRecord['check_in_time'],
            'check_out_time' => $currentTime,
            'remark' => $remark,
            'status' => $displayStatus['code'],
            'base_status' => $displayStatus['base_status'],
            'status_text' => $displayStatus['text']
        ]);
    }

}

function logPersonalAttendanceScan($db, array $teacher, $requestedAction, $logAction)
{
    if ($requestedAction === 'auto') {
        return;
    }
    $name = trim($teacher['first_name'] . ' ' . $teacher['last_name']);
    logActivity(
        $db,
        null,
        $logAction,
        $name . ($logAction === 'CHECK_IN' ? ' ลงเวลาเข้า' : ' ลงเวลาออก') . ' ผ่านลิงก์ส่วนตัว',
        getClientIpAddress(),
        $_SERVER['HTTP_USER_AGENT'] ?? ''
    );
}
