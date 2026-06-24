<?php
session_start();
require_once '../config/database.php';
require_once '../config/config.php';

if (!isset($_SESSION['admin_id'])) {
    jsonResponse(false, 'กรุณาเข้าสู่ระบบ', null, 401);
}

$database = new Database();
$db = $database->getConnection();
$action = $_GET['action'] ?? '';

function requireSuperAdmin()
{
    if (!isSuperAdmin()) {
        jsonResponse(false, 'เฉพาะผู้ดูแลระบบหลักเท่านั้น', null, 403);
    }
}

function isValidDateYmd($value) {
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return false;
    }
    $parts = explode('-', $value);
    return checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0]);
}

function normalizeToGregorianDate($value) {
    $raw = trim((string)$value);
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $matches)) {
        return $raw;
    }

    $year = (int)$matches[1];
    $month = (int)$matches[2];
    $day = (int)$matches[3];

    // ถ้ารับค่าปี พ.ศ. ให้แปลงเป็น ค.ศ. ก่อนเก็บ
    if ($year >= 2400) {
        $year -= 543;
    }

    if (!checkdate($month, $day, $year)) {
        return $raw;
    }

    return sprintf('%04d-%02d-%02d', $year, $month, $day);
}

function ensureAcademicCalendarsTable($db) {
    $query = "CREATE TABLE IF NOT EXISTS academic_calendars (
                id INT AUTO_INCREMENT PRIMARY KEY,
                academic_year_be SMALLINT NOT NULL,
                semester_1_start DATE NOT NULL,
                semester_1_end DATE NOT NULL,
                semester_2_start DATE NOT NULL,
                semester_2_end DATE NOT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_academic_year_be (academic_year_be),
                INDEX idx_is_active (is_active)
              ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $db->exec($query);
}

function normalizeAcademicCalendarsToGregorian($db) {
    $update = "UPDATE academic_calendars
               SET semester_1_start = CASE WHEN YEAR(semester_1_start) >= 2400 THEN DATE_SUB(semester_1_start, INTERVAL 543 YEAR) ELSE semester_1_start END,
                   semester_1_end = CASE WHEN YEAR(semester_1_end) >= 2400 THEN DATE_SUB(semester_1_end, INTERVAL 543 YEAR) ELSE semester_1_end END,
                   semester_2_start = CASE WHEN YEAR(semester_2_start) >= 2400 THEN DATE_SUB(semester_2_start, INTERVAL 543 YEAR) ELSE semester_2_start END,
                   semester_2_end = CASE WHEN YEAR(semester_2_end) >= 2400 THEN DATE_SUB(semester_2_end, INTERVAL 543 YEAR) ELSE semester_2_end END
               WHERE YEAR(semester_1_start) >= 2400
                  OR YEAR(semester_1_end) >= 2400
                  OR YEAR(semester_2_start) >= 2400
                  OR YEAR(semester_2_end) >= 2400";
    $db->exec($update);
}

function getSettingValue($db, $key) {
    $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = :key LIMIT 1");
    $stmt->execute([':key' => $key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? $row['setting_value'] : null;
}

function toYmdFromLegacy($value, $fallbackYear) {
    if (!$value) return null;

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return $value;
    }

    if (preg_match('/^(\d{2})-(\d{2})$/', $value, $matches)) {
        $month = (int)$matches[1];
        $day = (int)$matches[2];
        if (checkdate($month, $day, (int)$fallbackYear)) {
            return sprintf('%04d-%02d-%02d', (int)$fallbackYear, $month, $day);
        }
    }

    return null;
}

function migrateLegacySettingsToAcademicCalendar($db) {
    $countStmt = $db->query("SELECT COUNT(*) AS total FROM academic_calendars");
    $count = (int)($countStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
    if ($count > 0) {
        return;
    }

    $yearCe = (int)(getSettingValue($db, 'current_academic_year') ?: date('Y'));
    if ($yearCe < 1900 || $yearCe > 2600) {
        $yearCe = (int)date('Y');
    }
    $yearBe = $yearCe + 543;

    $s1Start = toYmdFromLegacy(getSettingValue($db, 'semester_1_start'), $yearCe) ?: sprintf('%04d-05-01', $yearCe);
    $s1End = toYmdFromLegacy(getSettingValue($db, 'semester_1_end'), $yearCe) ?: sprintf('%04d-10-31', $yearCe);
    $s2Start = toYmdFromLegacy(getSettingValue($db, 'semester_2_start'), $yearCe) ?: sprintf('%04d-11-01', $yearCe);
    $s2EndRaw = getSettingValue($db, 'semester_2_end');

    // รองรับข้อมูลเก่าแบบ MM-DD ของภาคเรียนที่ 2 ที่อาจข้ามปี
    $s2End = null;
    if (is_string($s2EndRaw) && preg_match('/^(\d{2})-(\d{2})$/', $s2EndRaw, $m)) {
        $month = (int)$m[1];
        $day = (int)$m[2];
        $s2StartMonth = (int)date('m', strtotime($s2Start));
        $endYear = ($month < $s2StartMonth) ? $yearCe + 1 : $yearCe;
        if (checkdate($month, $day, $endYear)) {
            $s2End = sprintf('%04d-%02d-%02d', $endYear, $month, $day);
        }
    }
    if ($s2End === null) {
        $s2End = toYmdFromLegacy($s2EndRaw, $yearCe) ?: sprintf('%04d-03-31', $yearCe + 1);
    }

    $insert = $db->prepare("INSERT INTO academic_calendars (
                            academic_year_be,
                            semester_1_start,
                            semester_1_end,
                            semester_2_start,
                            semester_2_end,
                            is_active
                          ) VALUES (
                            :academic_year_be,
                            :semester_1_start,
                            :semester_1_end,
                            :semester_2_start,
                            :semester_2_end,
                            1
                          )");
    $insert->execute([
        ':academic_year_be' => $yearBe,
        ':semester_1_start' => $s1Start,
        ':semester_1_end' => $s1End,
        ':semester_2_start' => $s2Start,
        ':semester_2_end' => $s2End
    ]);
}

function fetchAcademicCalendars($db) {
    $stmt = $db->query("SELECT id,
                               academic_year_be,
                               semester_1_start,
                               semester_1_end,
                               semester_2_start,
                               semester_2_end,
                               is_active
                        FROM academic_calendars
                        ORDER BY academic_year_be DESC, id DESC");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function syncLegacySettingsFromActiveCalendar($db) {
    $stmt = $db->query("SELECT * FROM academic_calendars WHERE is_active = 1 ORDER BY id DESC LIMIT 1");
    $active = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$active) {
        return null;
    }

    $legacyCeYear = (int)$active['academic_year_be'] - 543;

    $upsert = $db->prepare("INSERT INTO settings (setting_key, setting_value, description)
                            VALUES (:key, :value, :description)
                            ON DUPLICATE KEY UPDATE
                                setting_value = VALUES(setting_value),
                                description = VALUES(description)");

    $legacyRows = [
        ['current_academic_year', (string)$legacyCeYear, 'ปีการศึกษาปัจจุบัน (ค.ศ.)'],
        ['semester_1_start', $active['semester_1_start'], 'วันเริ่มต้นภาคเรียนที่ 1'],
        ['semester_1_end', $active['semester_1_end'], 'วันสิ้นสุดภาคเรียนที่ 1'],
        ['semester_2_start', $active['semester_2_start'], 'วันเริ่มต้นภาคเรียนที่ 2'],
        ['semester_2_end', $active['semester_2_end'], 'วันสิ้นสุดภาคเรียนที่ 2']
    ];

    foreach ($legacyRows as [$key, $value, $description]) {
        $upsert->execute([
            ':key' => $key,
            ':value' => $value,
            ':description' => $description
        ]);
    }

    return $active;
}

function validateCalendarPayload($payload) {
    $year = isset($payload['academic_year_be']) ? (int)$payload['academic_year_be'] : 0;
    $s1Start = normalizeToGregorianDate($payload['semester_1_start'] ?? '');
    $s1End = normalizeToGregorianDate($payload['semester_1_end'] ?? '');
    $s2Start = normalizeToGregorianDate($payload['semester_2_start'] ?? '');
    $s2End = normalizeToGregorianDate($payload['semester_2_end'] ?? '');

    if ($year < 2400 || $year > 3000) {
        throw new Exception('ปีการศึกษา (พ.ศ.) ไม่ถูกต้อง');
    }
    if (!isValidDateYmd($s1Start) || !isValidDateYmd($s1End) || !isValidDateYmd($s2Start) || !isValidDateYmd($s2End)) {
        throw new Exception('รูปแบบวันที่ไม่ถูกต้อง');
    }
    if ($s1Start > $s1End) {
        throw new Exception('ภาคเรียนที่ 1: วันเริ่มต้นต้องไม่มากกว่าวันสิ้นสุด');
    }
    if ($s2Start > $s2End) {
        throw new Exception('ภาคเรียนที่ 2: วันเริ่มต้นต้องไม่มากกว่าวันสิ้นสุด');
    }

    return [
        'academic_year_be' => $year,
        'semester_1_start' => $s1Start,
        'semester_1_end' => $s1End,
        'semester_2_start' => $s2Start,
        'semester_2_end' => $s2End
    ];
}

function validateTimeRulePayload($payload)
{
    $ruleName = trim((string)($payload['rule_name'] ?? ''));
    $note = trim((string)($payload['note'] ?? ''));
    $startDateRaw = trim((string)($payload['start_date'] ?? ''));
    $endDateRaw = trim((string)($payload['end_date'] ?? ''));
    $weekdaysRaw = $payload['weekdays'] ?? [];
    $priority = isset($payload['priority']) ? (int)$payload['priority'] : 100;

    if ($ruleName === '') {
        throw new Exception('กรุณาระบุชื่อกฎเวลา');
    }

    $startDate = $startDateRaw !== '' ? normalizeToGregorianDate($startDateRaw) : null;
    $endDate = $endDateRaw !== '' ? normalizeToGregorianDate($endDateRaw) : null;

    if ($startDate !== null && !isValidDateYmd($startDate)) {
        throw new Exception('วันเริ่มต้นของช่วงวันที่ไม่ถูกต้อง');
    }
    if ($endDate !== null && !isValidDateYmd($endDate)) {
        throw new Exception('วันสิ้นสุดของช่วงวันที่ไม่ถูกต้อง');
    }
    if (($startDate === null) xor ($endDate === null)) {
        throw new Exception('ถ้าระบุช่วงวันที่ ต้องกรอกทั้งวันเริ่มต้นและวันสิ้นสุด');
    }
    if ($startDate !== null && $endDate !== null && $startDate > $endDate) {
        throw new Exception('วันเริ่มต้นต้องไม่มากกว่าวันสิ้นสุด');
    }

    $weekdays = normalizeWeekdayList($weekdaysRaw);

    $checkInStart = normalizeTimeValue($payload['check_in_start'] ?? '');
    $checkInLate = normalizeTimeValue($payload['check_in_late'] ?? '');
    $checkOutStart = normalizeTimeValue($payload['check_out_start'] ?? '');
    $checkOutEnd = normalizeTimeValue($payload['check_out_end'] ?? '');

    if (!$checkInStart || !$checkInLate || !$checkOutStart || !$checkOutEnd) {
        throw new Exception('กรุณากรอกเวลาเข้า-ออกให้ครบถ้วน');
    }
    if ($checkInStart > $checkInLate) {
        throw new Exception('เวลาเริ่มสแกนเข้าต้องไม่มากกว่าเวลาเริ่มมาสาย');
    }
    if ($checkOutStart > $checkOutEnd) {
        throw new Exception('เวลาเริ่มสแกนออกต้องไม่มากกว่าเวลาสิ้นสุดสแกนออก');
    }

    if ($priority < 0 || $priority > 9999) {
        throw new Exception('ลำดับความสำคัญต้องอยู่ระหว่าง 0 ถึง 9999');
    }

    return [
        'rule_name' => $ruleName,
        'note' => $note !== '' ? $note : null,
        'start_date' => $startDate,
        'end_date' => $endDate,
        'weekdays' => $weekdays,
        'check_in_start' => $checkInStart,
        'check_in_late' => $checkInLate,
        'check_out_start' => $checkOutStart,
        'check_out_end' => $checkOutEnd,
        'priority' => $priority,
        'is_active' => isset($payload['is_active']) ? ((int)$payload['is_active'] === 1 ? 1 : 0) : 1
    ];
}

function fetchAttendanceTimeRulesForSettings($db)
{
    ensureAttendanceTimeRulesTable($db);

    $stmt = $db->query("SELECT id,
                               rule_name,
                               note,
                               start_date,
                               end_date,
                               weekdays,
                               check_in_start,
                               check_in_late,
                               check_out_start,
                               check_out_end,
                               priority,
                               is_active,
                               created_at,
                               updated_at
                        FROM attendance_time_rules
                        ORDER BY
                            CASE WHEN start_date IS NULL AND end_date IS NULL THEN 0 ELSE 1 END DESC,
                            priority DESC,
                            CASE
                                WHEN start_date IS NOT NULL AND end_date IS NOT NULL THEN DATEDIFF(end_date, start_date)
                                ELSE 999999
                            END ASC,
                            id DESC");

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

try {
    ensureAcademicCalendarsTable($db);
    normalizeAcademicCalendarsToGregorian($db);
    migrateLegacySettingsToAcademicCalendar($db);
    ensureAttendanceTimeRulesTable($db);

    switch ($action) {
        case 'get':
            $calendars = fetchAcademicCalendars($db);
            $active = null;
            foreach ($calendars as $row) {
                if ((int)$row['is_active'] === 1) {
                    $active = $row;
                    break;
                }
            }

            $legacyStmt = $db->query("SELECT setting_key, setting_value, description FROM settings");
            $legacyRows = $legacyStmt->fetchAll(PDO::FETCH_ASSOC);
            $legacySettings = [];
            foreach ($legacyRows as $row) {
                $legacySettings[$row['setting_key']] = [
                    'value' => $row['setting_value'],
                    'description' => $row['description']
                ];
            }

            jsonResponse(true, 'ดึงข้อมูลสำเร็จ', [
                'calendars' => $calendars,
                'active_calendar' => $active,
                'legacy_settings' => $legacySettings,
                'time_rules' => fetchAttendanceTimeRulesForSettings($db),
                'effective_time_rule_today' => getAttendanceTimeRules($db, date('Y-m-d'))
            ]);
            break;

        case 'save_time_rule':
            requireSuperAdmin();

            $input = json_decode(file_get_contents('php://input'), true);
            if (!is_array($input)) {
                jsonResponse(false, 'ข้อมูลไม่ถูกต้อง', null, 400);
            }

            $payload = validateTimeRulePayload($input);
            $ruleId = isset($input['id']) ? (int)$input['id'] : 0;

            if ($ruleId > 0) {
                $check = $db->prepare("SELECT id FROM attendance_time_rules WHERE id = :id");
                $check->execute([':id' => $ruleId]);
                if (!$check->fetch(PDO::FETCH_ASSOC)) {
                    throw new Exception('ไม่พบกฎเวลาที่ต้องการแก้ไข');
                }

                $stmt = $db->prepare("UPDATE attendance_time_rules
                                      SET rule_name = :rule_name,
                                          note = :note,
                                          start_date = :start_date,
                                          end_date = :end_date,
                                          weekdays = :weekdays,
                                          check_in_start = :check_in_start,
                                          check_in_late = :check_in_late,
                                          check_out_start = :check_out_start,
                                          check_out_end = :check_out_end,
                                          priority = :priority,
                                          is_active = :is_active
                                      WHERE id = :id");
                $stmt->execute([
                    ':id' => $ruleId,
                    ':rule_name' => $payload['rule_name'],
                    ':note' => $payload['note'],
                    ':start_date' => $payload['start_date'],
                    ':end_date' => $payload['end_date'],
                    ':weekdays' => $payload['weekdays'],
                    ':check_in_start' => $payload['check_in_start'],
                    ':check_in_late' => $payload['check_in_late'],
                    ':check_out_start' => $payload['check_out_start'],
                    ':check_out_end' => $payload['check_out_end'],
                    ':priority' => $payload['priority'],
                    ':is_active' => $payload['is_active']
                ]);
                $message = 'บันทึกการแก้ไขกฎเวลาเรียบร้อย';
                $logAction = 'UPDATE_ATTENDANCE_TIME_RULE';
            } else {
                $stmt = $db->prepare("INSERT INTO attendance_time_rules (
                                        rule_name,
                                        note,
                                        start_date,
                                        end_date,
                                        weekdays,
                                        check_in_start,
                                        check_in_late,
                                        check_out_start,
                                        check_out_end,
                                        priority,
                                        is_active
                                      ) VALUES (
                                        :rule_name,
                                        :note,
                                        :start_date,
                                        :end_date,
                                        :weekdays,
                                        :check_in_start,
                                        :check_in_late,
                                        :check_out_start,
                                        :check_out_end,
                                        :priority,
                                        :is_active
                                      )");
                $stmt->execute([
                    ':rule_name' => $payload['rule_name'],
                    ':note' => $payload['note'],
                    ':start_date' => $payload['start_date'],
                    ':end_date' => $payload['end_date'],
                    ':weekdays' => $payload['weekdays'],
                    ':check_in_start' => $payload['check_in_start'],
                    ':check_in_late' => $payload['check_in_late'],
                    ':check_out_start' => $payload['check_out_start'],
                    ':check_out_end' => $payload['check_out_end'],
                    ':priority' => $payload['priority'],
                    ':is_active' => $payload['is_active']
                ]);
                $ruleId = (int)$db->lastInsertId();
                $message = 'เพิ่มกฎเวลาเรียบร้อย';
                $logAction = 'CREATE_ATTENDANCE_TIME_RULE';
            }

            logAdminAction(
                $db,
                $logAction,
                $message,
                [
                    'rule_id' => $ruleId,
                    'rule_name' => $payload['rule_name'],
                    'start_date' => $payload['start_date'],
                    'end_date' => $payload['end_date'],
                    'weekdays' => $payload['weekdays'],
                    'priority' => $payload['priority']
                ]
            );

            jsonResponse(true, $message);
            break;

        case 'delete_time_rule':
            requireSuperAdmin();

            $input = json_decode(file_get_contents('php://input'), true);
            $ruleId = isset($input['id']) ? (int)$input['id'] : 0;
            if ($ruleId <= 0) {
                jsonResponse(false, 'กรุณาระบุกฎเวลาที่ต้องการลบ', null, 400);
            }

            $check = $db->prepare("SELECT id, rule_name FROM attendance_time_rules WHERE id = :id");
            $check->execute([':id' => $ruleId]);
            $rule = $check->fetch(PDO::FETCH_ASSOC);
            if (!$rule) {
                jsonResponse(false, 'ไม่พบกฎเวลาที่ต้องการลบ', null, 404);
            }

            $delete = $db->prepare("DELETE FROM attendance_time_rules WHERE id = :id");
            $delete->execute([':id' => $ruleId]);

            logAdminAction(
                $db,
                'DELETE_ATTENDANCE_TIME_RULE',
                'ลบกฎเวลาเรียบร้อย',
                [
                    'rule_id' => $ruleId,
                    'rule_name' => $rule['rule_name']
                ]
            );

            jsonResponse(true, 'ลบกฎเวลาเรียบร้อย');
            break;

        case 'save_calendar':
            requireSuperAdmin();

            $input = json_decode(file_get_contents('php://input'), true);
            if (!is_array($input)) {
                jsonResponse(false, 'ข้อมูลไม่ถูกต้อง', null, 400);
            }

            $payload = validateCalendarPayload($input);
            $calendarId = isset($input['id']) ? (int)$input['id'] : 0;

            $db->beginTransaction();
            if ($calendarId > 0) {
                $check = $db->prepare("SELECT id FROM academic_calendars WHERE id = :id");
                $check->execute([':id' => $calendarId]);
                if (!$check->fetch(PDO::FETCH_ASSOC)) {
                    throw new Exception('ไม่พบปีการศึกษาที่ต้องการแก้ไข');
                }

                $update = $db->prepare("UPDATE academic_calendars
                                        SET academic_year_be = :academic_year_be,
                                            semester_1_start = :semester_1_start,
                                            semester_1_end = :semester_1_end,
                                            semester_2_start = :semester_2_start,
                                            semester_2_end = :semester_2_end
                                        WHERE id = :id");
                $update->execute([
                    ':id' => $calendarId,
                    ':academic_year_be' => $payload['academic_year_be'],
                    ':semester_1_start' => $payload['semester_1_start'],
                    ':semester_1_end' => $payload['semester_1_end'],
                    ':semester_2_start' => $payload['semester_2_start'],
                    ':semester_2_end' => $payload['semester_2_end']
                ]);
                $message = 'บันทึกการแก้ไขปีการศึกษาสำเร็จ';
                $logAction = 'UPDATE_ACADEMIC_CALENDAR';
            } else {
                $insert = $db->prepare("INSERT INTO academic_calendars (
                                        academic_year_be,
                                        semester_1_start,
                                        semester_1_end,
                                        semester_2_start,
                                        semester_2_end,
                                        is_active
                                      ) VALUES (
                                        :academic_year_be,
                                        :semester_1_start,
                                        :semester_1_end,
                                        :semester_2_start,
                                        :semester_2_end,
                                        0
                                      )");
                $insert->execute([
                    ':academic_year_be' => $payload['academic_year_be'],
                    ':semester_1_start' => $payload['semester_1_start'],
                    ':semester_1_end' => $payload['semester_1_end'],
                    ':semester_2_start' => $payload['semester_2_start'],
                    ':semester_2_end' => $payload['semester_2_end']
                ]);
                $calendarId = (int)$db->lastInsertId();
                $message = 'เพิ่มปีการศึกษาสำเร็จ';
                $logAction = 'CREATE_ACADEMIC_CALENDAR';
            }

            // ถ้ายังไม่มี active ให้ set ตัวแรกเป็น active
            $activeCountStmt = $db->query("SELECT COUNT(*) AS total FROM academic_calendars WHERE is_active = 1");
            $activeCount = (int)($activeCountStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
            if ($activeCount === 0 && $calendarId > 0) {
                $db->exec("UPDATE academic_calendars SET is_active = 0");
                $activateStmt = $db->prepare("UPDATE academic_calendars SET is_active = 1 WHERE id = :id");
                $activateStmt->execute([':id' => $calendarId]);
                syncLegacySettingsFromActiveCalendar($db);
            }

            $db->commit();

            logAdminAction(
                $db,
                $logAction,
                $message,
                [
                    'calendar_id' => $calendarId,
                    'academic_year_be' => $payload['academic_year_be']
                ]
            );

            jsonResponse(true, $message);
            break;

        case 'set_active_calendar':
            requireSuperAdmin();

            $input = json_decode(file_get_contents('php://input'), true);
            $calendarId = isset($input['id']) ? (int)$input['id'] : 0;
            if ($calendarId <= 0) {
                jsonResponse(false, 'กรุณาระบุปีการศึกษาที่ต้องการใช้งาน', null, 400);
            }

            $db->beginTransaction();
            $check = $db->prepare("SELECT id, academic_year_be FROM academic_calendars WHERE id = :id");
            $check->execute([':id' => $calendarId]);
            $calendar = $check->fetch(PDO::FETCH_ASSOC);
            if (!$calendar) {
                throw new Exception('ไม่พบปีการศึกษาที่ต้องการใช้งาน');
            }

            $db->exec("UPDATE academic_calendars SET is_active = 0");
            $activate = $db->prepare("UPDATE academic_calendars SET is_active = 1 WHERE id = :id");
            $activate->execute([':id' => $calendarId]);

            syncLegacySettingsFromActiveCalendar($db);
            $db->commit();

            logAdminAction(
                $db,
                'ACTIVATE_ACADEMIC_CALENDAR',
                'เปลี่ยนปีการศึกษาที่ใช้งาน',
                [
                    'calendar_id' => $calendarId,
                    'academic_year_be' => (int)$calendar['academic_year_be']
                ]
            );

            jsonResponse(true, 'เปลี่ยนปีการศึกษาที่ใช้งานสำเร็จ');
            break;

        // รองรับ API เก่าที่เคยใช้ update
        case 'update':
            requireSuperAdmin();

            $input = json_decode(file_get_contents('php://input'), true);
            if (!isset($input['settings']) || !is_array($input['settings'])) {
                jsonResponse(false, 'ข้อมูลไม่ถูกต้อง', null, 400);
            }

            $legacy = $input['settings'];
            $yearCe = isset($legacy['current_academic_year']) ? (int)$legacy['current_academic_year'] : 0;
            $payload = [
                'academic_year_be' => $yearCe > 0 ? $yearCe + 543 : 0,
                'semester_1_start' => $legacy['semester_1_start'] ?? '',
                'semester_1_end' => $legacy['semester_1_end'] ?? '',
                'semester_2_start' => $legacy['semester_2_start'] ?? '',
                'semester_2_end' => $legacy['semester_2_end'] ?? ''
            ];

            $payload = validateCalendarPayload($payload);

            $activeStmt = $db->query("SELECT id FROM academic_calendars WHERE is_active = 1 ORDER BY id DESC LIMIT 1");
            $active = $activeStmt->fetch(PDO::FETCH_ASSOC);
            $calendarId = (int)($active['id'] ?? 0);

            $db->beginTransaction();
            if ($calendarId > 0) {
                $update = $db->prepare("UPDATE academic_calendars
                                        SET academic_year_be = :academic_year_be,
                                            semester_1_start = :semester_1_start,
                                            semester_1_end = :semester_1_end,
                                            semester_2_start = :semester_2_start,
                                            semester_2_end = :semester_2_end
                                        WHERE id = :id");
                $update->execute([
                    ':id' => $calendarId,
                    ':academic_year_be' => $payload['academic_year_be'],
                    ':semester_1_start' => $payload['semester_1_start'],
                    ':semester_1_end' => $payload['semester_1_end'],
                    ':semester_2_start' => $payload['semester_2_start'],
                    ':semester_2_end' => $payload['semester_2_end']
                ]);
            } else {
                $insert = $db->prepare("INSERT INTO academic_calendars (
                                        academic_year_be,
                                        semester_1_start,
                                        semester_1_end,
                                        semester_2_start,
                                        semester_2_end,
                                        is_active
                                      ) VALUES (
                                        :academic_year_be,
                                        :semester_1_start,
                                        :semester_1_end,
                                        :semester_2_start,
                                        :semester_2_end,
                                        1
                                      )");
                $insert->execute([
                    ':academic_year_be' => $payload['academic_year_be'],
                    ':semester_1_start' => $payload['semester_1_start'],
                    ':semester_1_end' => $payload['semester_1_end'],
                    ':semester_2_start' => $payload['semester_2_start'],
                    ':semester_2_end' => $payload['semester_2_end']
                ]);
            }

            syncLegacySettingsFromActiveCalendar($db);
            $db->commit();

            logAdminAction(
                $db,
                'UPDATE_SETTINGS',
                'บันทึกการตั้งค่าระบบ (โหมดเดิม)',
                ['updated_keys' => array_keys($legacy)]
            );

            jsonResponse(true, 'บันทึกการตั้งค่าสำเร็จ');
            break;

        default:
            jsonResponse(false, 'Invalid action', null, 400);
    }
} catch (PDOException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    if ((int)$e->errorInfo[1] === 1062) {
        jsonResponse(false, 'ปีการศึกษานี้มีอยู่แล้ว', null, 400);
    }

    error_log("Settings PDO error: " . $e->getMessage());
    jsonResponse(false, 'เกิดข้อผิดพลาดในการบันทึกข้อมูล', null, 500);
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Settings error: " . $e->getMessage());
    jsonResponse(false, 'เกิดข้อผิดพลาด: ' . $e->getMessage(), null, 500);
}
?>
