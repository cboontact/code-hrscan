<?php
date_default_timezone_set('Asia/Bangkok');

function getAppConfigValue($key, $default = null)
{
    $value = getenv($key);
    if ($value === false || $value === '') {
        $value = $_SERVER[$key] ?? null;
    }

    return ($value === null || $value === '') ? $default : $value;
}

function buildBaseUrl()
{
    $configuredBaseUrl = getAppConfigValue('APP_BASE_URL');
    if ($configuredBaseUrl) {
        return rtrim($configuredBaseUrl, '/') . '/';
    }

    $https = $_SERVER['HTTPS'] ?? '';
    $isSecure = (!empty($https) && strtolower((string) $https) !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? null) === '443')
        || (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
    $scheme = $isSecure ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    $basePath = str_replace('\\', '/', dirname(dirname($scriptName)));

    if ($basePath === '/' || $basePath === '\\' || $basePath === '.') {
        $basePath = '';
    }

    return $scheme . '://' . $host . rtrim($basePath, '/') . '/';
}

function stringStartsWith($haystack, $needle)
{
    $haystack = (string) $haystack;
    $needle = (string) $needle;

    if ($needle === '') {
        return true;
    }

    return strncmp($haystack, $needle, strlen($needle)) === 0;
}

function stringEndsWith($haystack, $needle)
{
    $haystack = (string) $haystack;
    $needle = (string) $needle;

    if ($needle === '') {
        return true;
    }

    $length = strlen($needle);
    if ($length > strlen($haystack)) {
        return false;
    }

    return substr($haystack, -$length) === $needle;
}

define('BASE_URL', buildBaseUrl());
define('UPLOAD_PATH', __DIR__ . '/../uploads/');
define('UPLOAD_URL', BASE_URL . 'uploads/');
define('APPROVED_LATE_REMARK', 'ขออนุญาตมาสาย');
define('LATE_NO_CHECK_OUT_REMARK', 'มาสายและไม่สแกนออก');
define('NO_CHECK_IN_BUT_CHECKED_OUT_REMARK', 'ไม่สแกนเข้า แต่สแกนออก');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit();
}

function jsonResponse($success, $message, $data = null, $code = 200)
{
    http_response_code($code);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data,
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit();
}

function getClientIpAddress()
{
    $candidates = [
        $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null,
        $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
        $_SERVER['HTTP_CLIENT_IP'] ?? null,
        $_SERVER['REMOTE_ADDR'] ?? null
    ];

    foreach ($candidates as $ip) {
        if (empty($ip)) {
            continue;
        }

        // X-Forwarded-For อาจมีหลายค่า คั่นด้วย comma
        $firstIp = trim(explode(',', $ip)[0]);
        if (filter_var($firstIp, FILTER_VALIDATE_IP)) {
            return $firstIp;
        }
    }

    return '0.0.0.0';
}

function normalizeAdminRole($role)
{
    $normalized = strtolower(trim((string) $role));
    if ($normalized === '') {
        return null;
    }

    $normalized = str_replace([' ', '-'], '_', $normalized);
    return $normalized;
}

function getAdminRole()
{
    return normalizeAdminRole($_SESSION['admin_role'] ?? null);
}

function isSuperAdmin()
{
    return getAdminRole() === 'super_admin';
}

function canEditRfid()
{
    return in_array(getAdminRole(), ['super_admin', 'admin'], true);
}

function maskRfidValue($value)
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return null;
    }

    $length = max(4, strlen($raw));
    return str_repeat('•', $length);
}

function sanitizeTeacherRfidData($teacher)
{
    if (!is_array($teacher)) {
        return $teacher;
    }

    if (isSuperAdmin()) {
        $teacher['rfid_masked'] = maskRfidValue($teacher['rfid_code'] ?? '');
        return $teacher;
    }

    if (getAdminRole() === 'admin') {
        $teacher['rfid_masked'] = maskRfidValue($teacher['rfid_code'] ?? '');
        $teacher['rfid_code'] = null;
        return $teacher;
    }

    $teacher['rfid_masked'] = null;
    if (array_key_exists('rfid_code', $teacher)) {
        $teacher['rfid_code'] = null;
    }
    return $teacher;
}

function sanitizeAdminLogDetailsForViewer($details)
{
    if (!is_array($details)) {
        return $details;
    }

    if (isSuperAdmin()) {
        return $details;
    }

    if (getAdminRole() === 'admin' && !empty($details['rfid_code'])) {
        $details['rfid_code'] = maskRfidValue($details['rfid_code']);
        return $details;
    }

    unset($details['rfid_code']);
    return $details;
}

function ensureAdminLogsTable($db)
{
    static $initialized = false;
    if ($initialized) {
        return;
    }

    try {
        $query = "CREATE TABLE IF NOT EXISTS admin_logs (
                    id BIGINT AUTO_INCREMENT PRIMARY KEY,
                    admin_id INT NOT NULL,
                    admin_username VARCHAR(100) DEFAULT NULL,
                    admin_name VARCHAR(150) DEFAULT NULL,
                    action VARCHAR(120) NOT NULL,
                    description TEXT DEFAULT NULL,
                    endpoint VARCHAR(255) DEFAULT NULL,
                    request_method VARCHAR(10) DEFAULT NULL,
                    ip_address VARCHAR(45) DEFAULT NULL,
                    user_agent TEXT DEFAULT NULL,
                    details_json LONGTEXT DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_admin_id (admin_id),
                    INDEX idx_action (action),
                    INDEX idx_ip (ip_address),
                    INDEX idx_created_at (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $db->exec($query);
    } catch (Exception $e) {
        error_log("Ensure admin_logs table error: " . $e->getMessage());
    }

    $initialized = true;
}

function logAdminAction(
    $db,
    $action,
    $description = '',
    $details = null,
    $adminId = null,
    $adminUsername = null,
    $adminName = null,
    $ipAddress = null,
    $userAgent = null
) {
    try {
        if ($adminId === null && isset($_SESSION['admin_id'])) {
            $adminId = (int) $_SESSION['admin_id'];
        }

        if (empty($adminId)) {
            return;
        }

        $endpoint = $_SERVER['REQUEST_URI'] ?? ($_SERVER['SCRIPT_NAME'] ?? null);
        $method = $_SERVER['REQUEST_METHOD'] ?? null;
        $ip = $ipAddress ?: getClientIpAddress();
        $ua = $userAgent ?? ($_SERVER['HTTP_USER_AGENT'] ?? null);
        $adminUsername = $adminUsername ?? ($_SESSION['admin_username'] ?? null);
        $adminName = $adminName ?? ($_SESSION['admin_name'] ?? null);

        $detailsJson = null;
        if ($details !== null) {
            if (!is_array($details) && !is_object($details)) {
                $details = ['value' => $details];
            }
            $encoded = json_encode($details, JSON_UNESCAPED_UNICODE);
            $detailsJson = ($encoded === false) ? null : $encoded;
        }

        $query = "INSERT INTO admin_logs (
                    admin_id,
                    admin_username,
                    admin_name,
                    action,
                    description,
                    endpoint,
                    request_method,
                    ip_address,
                    user_agent,
                    details_json
                  ) VALUES (
                    :admin_id,
                    :admin_username,
                    :admin_name,
                    :action,
                    :description,
                    :endpoint,
                    :request_method,
                    :ip_address,
                    :user_agent,
                    :details_json
                  )";
        $stmt = $db->prepare($query);
        $stmt->execute([
            ':admin_id' => $adminId,
            ':admin_username' => $adminUsername,
            ':admin_name' => $adminName,
            ':action' => $action,
            ':description' => $description,
            ':endpoint' => $endpoint,
            ':request_method' => $method,
            ':ip_address' => $ip,
            ':user_agent' => $ua,
            ':details_json' => $detailsJson
        ]);
    } catch (Exception $e) {
        error_log("Log admin action error: " . $e->getMessage());
    }
}

function logActivity($db, $userId, $action, $description, $ipAddress, $userAgent, $details = null)
{
    try {
        $query = "INSERT INTO activity_logs (user_id, action, description, ip_address, user_agent) 
                  VALUES (:user_id, :action, :description, :ip_address, :user_agent)";
        $stmt = $db->prepare($query);
        $stmt->execute([
            ':user_id' => $userId,
            ':action' => $action,
            ':description' => $description,
            ':ip_address' => $ipAddress,
            ':user_agent' => $userAgent
        ]);

        // สะสม log สำหรับระบบแอดมินในตารางเฉพาะ
        if (!empty($userId)) {
            logAdminAction(
                $db,
                $action,
                $description,
                $details,
                (int) $userId,
                $_SESSION['admin_username'] ?? null,
                $_SESSION['admin_name'] ?? null,
                $ipAddress,
                $userAgent
            );
        }
    } catch (Exception $e) {
        error_log("Log activity error: " . $e->getMessage());
    }
}

function normalizeTimeValue($value, $default = null)
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return $default;
    }

    if (preg_match('/^\d{2}:\d{2}$/', $raw)) {
        $raw .= ':00';
    }

    if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $raw)) {
        return $default;
    }

    [$hour, $minute, $second] = array_map('intval', explode(':', $raw));
    if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59 || $second < 0 || $second > 59) {
        return $default;
    }

    return sprintf('%02d:%02d:%02d', $hour, $minute, $second);
}

function normalizeIdentityValue($value)
{
    $normalized = trim((string) $value);
    if ($normalized === '') {
        return '';
    }

    return preg_replace('/\s+/', '', $normalized);
}

function validateIdentityValue($value)
{
    if ($value === '') {
        return 'กรุณากรอกเลขประจำตัวหรือ Passport';
    }

    $length = strlen($value);
    if ($length < 4 || $length > 50) {
        return 'เลขประจำตัวหรือ Passport ต้องยาว 4-50 ตัวอักษร';
    }

    if (!preg_match('/^[A-Za-z0-9\-\/]+$/', $value)) {
        return 'เลขประจำตัวหรือ Passport ใช้ได้เฉพาะตัวอักษรภาษาอังกฤษ ตัวเลข - และ /';
    }

    return null;
}

function normalizeDateValue($value)
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return null;
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
        return null;
    }

    [$year, $month, $day] = array_map('intval', explode('-', $raw));
    if (!checkdate($month, $day, $year)) {
        return null;
    }

    return sprintf('%04d-%02d-%02d', $year, $month, $day);
}

function isApprovedLateRemark($remark)
{
    return trim((string) $remark) === APPROVED_LATE_REMARK;
}

function resolveAttendanceCheckInStatus($checkInDateTime, $lateThreshold, $remark = '')
{
    $checkInTimeOnly = normalizeTimeValue(
        preg_match('/\d{2}:\d{2}(:\d{2})?$/', (string) $checkInDateTime, $matches) ? $matches[0] : $checkInDateTime
    );

    if (!$checkInTimeOnly) {
        return 'present';
    }

    if ($checkInTimeOnly > $lateThreshold) {
        return isApprovedLateRemark($remark) ? 'present' : 'late';
    }

    return 'present';
}

function splitAttendanceRemarkParts($remark)
{
    $raw = trim((string) $remark);
    if ($raw === '') {
        return [];
    }

    $parts = preg_split('/\s*\|\s*/u', $raw);
    $parts = array_map('trim', $parts ?: []);
    $parts = array_filter($parts, static function ($part) {
        return $part !== '';
    });

    return array_values(array_unique($parts));
}

function joinAttendanceRemarkParts(array $parts)
{
    $parts = array_values(array_filter(array_map('trim', $parts), static function ($part) {
        return $part !== '';
    }));

    return empty($parts) ? null : implode(' | ', array_unique($parts));
}

function addAttendanceRemarkTag($remark, $tag)
{
    $parts = splitAttendanceRemarkParts($remark);
    if (!in_array($tag, $parts, true)) {
        $parts[] = $tag;
    }

    return joinAttendanceRemarkParts($parts);
}

function removeAttendanceRemarkTags($remark, array $tags)
{
    $parts = splitAttendanceRemarkParts($remark);
    $parts = array_values(array_filter($parts, static function ($part) use ($tags) {
        return !in_array($part, $tags, true);
    }));

    return joinAttendanceRemarkParts($parts);
}

function resolveAttendanceDisplayStatus($record, $lateThreshold)
{
    $checkInTime = $record['check_in_time'] ?? null;
    $checkOutTime = $record['check_out_time'] ?? null;
    $remark = trim((string) ($record['remark'] ?? ''));
    $storedStatus = (string) ($record['status'] ?? '');

    if (!empty($checkInTime)) {
        $checkInStatus = resolveAttendanceCheckInStatus($checkInTime, $lateThreshold, $remark);
        $isLate = $checkInStatus === 'late';

        if (!empty($checkOutTime)) {
            return [
                'code' => $isLate ? 'late_checked_out' : 'on_time_checked_out',
                'text' => $isLate ? 'มาสาย กลับตรงเวลา' : 'มาตรงเวลา กลับตรงเวลา',
                'base_status' => $checkInStatus
            ];
        }

        if ($storedStatus !== 'incomplete') {
            return [
                'code' => $isLate ? 'late' : 'present',
                'text' => $isLate ? 'มาสาย' : 'มาตรงเวลา',
                'base_status' => $checkInStatus
            ];
        }

        return [
            'code' => $isLate ? 'late_no_check_out' : 'on_time_no_check_out',
            'text' => $isLate ? 'มาสาย ไม่สแกนออก' : 'มาตรงเวลา ไม่สแกนออก',
            'base_status' => $storedStatus === 'incomplete' ? 'incomplete' : $checkInStatus
        ];
    }

    if (empty($checkInTime) && !empty($checkOutTime)) {
        return [
            'code' => 'no_check_in_but_checked_out',
            'text' => 'ไม่สแกนเข้า แต่สแกนออก',
            'base_status' => 'absent'
        ];
    }

    if ($storedStatus === 'absent') {
        return [
            'code' => 'absent',
            'text' => $remark !== '' ? $remark : 'ยังไม่ลงเวลา',
            'base_status' => 'absent'
        ];
    }

    return [
        'code' => 'absent',
        'text' => $remark !== '' ? $remark : 'ยังไม่ลงเวลา',
        'base_status' => $storedStatus !== '' ? $storedStatus : 'absent'
    ];
}

function resolveAttendanceSummaryCategory($record, $lateThreshold)
{
    $displayStatus = resolveAttendanceDisplayStatus($record, $lateThreshold);
    $statusCode = $displayStatus['code'];
    $remark = trim((string) ($record['remark'] ?? ''));

    $manualRemarkCategory = null;
    if ($remark !== ''
        && !isApprovedLateRemark($remark)
        && $remark !== LATE_NO_CHECK_OUT_REMARK
    ) {
        $manualRemarkCategory = strpos($remark, 'ลา') !== false ? 'leave' : 'other';
    }

    if (in_array($statusCode, ['present', 'on_time_checked_out', 'on_time_no_check_out'], true)) {
        if ($manualRemarkCategory !== null) {
            return $manualRemarkCategory;
        }
        return 'present';
    }

    if (in_array($statusCode, ['late', 'late_checked_out', 'late_no_check_out'], true)) {
        if ($manualRemarkCategory !== null) {
            return $manualRemarkCategory;
        }
        return 'late';
    }

    if ($statusCode === 'absent') {
        if ($remark === '') {
            return 'absent';
        }

        return strpos($remark, 'ลา') !== false ? 'leave' : 'other';
    }

    return 'other';
}

function ensureTeacherIdentityColumn($db)
{
    static $initialized = false;
    if ($initialized) {
        return;
    }

    try {
        $stmt = $db->query("SHOW COLUMNS FROM teachers LIKE 'citizen_id'");
        $column = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;

        if ($column && isset($column['Type'])) {
            $type = strtolower((string) $column['Type']);
            if (preg_match('/varchar\((\d+)\)/', $type, $matches) && (int) $matches[1] < 50) {
                $db->exec("ALTER TABLE teachers MODIFY citizen_id VARCHAR(50) NOT NULL");
            }
        }
    } catch (Exception $e) {
        error_log("Ensure teacher identity column error: " . $e->getMessage());
    }

    try {
        $stmt = $db->query("SHOW COLUMNS FROM teachers LIKE 'blood_type'");
        $column = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
        if (!$column) {
            $db->exec("ALTER TABLE teachers ADD COLUMN blood_type VARCHAR(20) NULL AFTER gender");
        } elseif (isset($column['Type'])) {
            $type = strtolower((string) $column['Type']);
            if (preg_match('/varchar\((\d+)\)/', $type, $matches) && (int) $matches[1] < 20) {
                $db->exec("ALTER TABLE teachers MODIFY blood_type VARCHAR(20) NULL");
            }
        }
    } catch (Exception $e) {
        error_log("Ensure teacher blood type column error: " . $e->getMessage());
    }

    try {
        $stmt = $db->query("SHOW COLUMNS FROM teachers LIKE 'birth_date'");
        $column = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
        if (!$column) {
            $db->exec("ALTER TABLE teachers ADD COLUMN birth_date DATE NULL AFTER personnel_type");
        }
    } catch (Exception $e) {
        error_log("Ensure teacher birth date column error: " . $e->getMessage());
    }

    $initialized = true;
}

function normalizeWeekdayList($weekdays)
{
    if (is_string($weekdays)) {
        $weekdays = explode(',', $weekdays);
    }

    if (!is_array($weekdays)) {
        return '1,2,3,4,5,6,7';
    }

    $normalized = [];
    foreach ($weekdays as $weekday) {
        $day = (int) $weekday;
        if ($day >= 1 && $day <= 7) {
            $normalized[$day] = true;
        }
    }

    if (empty($normalized)) {
        return '1,2,3,4,5,6,7';
    }

    $days = array_keys($normalized);
    sort($days);
    return implode(',', $days);
}

function parseWeekdayList($weekdays)
{
    $normalized = normalizeWeekdayList($weekdays);
    return array_map('intval', explode(',', $normalized));
}

function attendanceRuleAppliesToDate(array $rule, $attendanceDate, $dayOfWeek)
{
    $startDate = trim((string) ($rule['start_date'] ?? ''));
    $endDate = trim((string) ($rule['end_date'] ?? ''));

    if ($startDate !== '' && $attendanceDate < $startDate) {
        return false;
    }
    if ($endDate !== '' && $attendanceDate > $endDate) {
        return false;
    }

    $weekdays = parseWeekdayList($rule['weekdays'] ?? '');
    return in_array((int) $dayOfWeek, $weekdays, true);
}

function ensureAttendanceTimeRulesTable($db)
{
    static $initialized = false;
    if ($initialized) {
        return;
    }

    try {
        $db->exec("CREATE TABLE IF NOT EXISTS attendance_time_rules (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    rule_name VARCHAR(150) NOT NULL,
                    note TEXT DEFAULT NULL,
                    start_date DATE DEFAULT NULL,
                    end_date DATE DEFAULT NULL,
                    weekdays VARCHAR(20) NOT NULL DEFAULT '1,2,3,4,5,6,7',
                    check_in_start TIME NOT NULL,
                    check_in_late TIME NOT NULL,
                    check_out_start TIME NOT NULL,
                    check_out_end TIME NOT NULL,
                    priority INT NOT NULL DEFAULT 100,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_rule_active (is_active),
                    INDEX idx_rule_dates (start_date, end_date),
                    INDEX idx_rule_priority (priority)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Exception $e) {
        error_log("Ensure attendance_time_rules table error: " . $e->getMessage());
    }

    try {
        $countStmt = $db->query("SELECT COUNT(*) AS total FROM attendance_time_rules");
        $count = (int) ($countStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

        if ($count === 0) {
            $legacyRules = getAttendanceTimeRulesFromSystemSettings($db);
            $insert = $db->prepare("INSERT INTO attendance_time_rules (
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
                                        1
                                    )");

            $insert->execute([
                ':rule_name' => 'เวลาปกติ',
                ':note' => 'กฎหลักของระบบ',
                ':start_date' => null,
                ':end_date' => null,
                ':weekdays' => '1,2,3,4,5,6,7',
                ':check_in_start' => $legacyRules['check_in_start'],
                ':check_in_late' => $legacyRules['check_in_late'],
                ':check_out_start' => $legacyRules['check_out_start_weekday'],
                ':check_out_end' => $legacyRules['check_out_end'],
                ':priority' => 100
            ]);

            if ($legacyRules['check_out_start_friday'] !== $legacyRules['check_out_start_weekday']) {
                $insert->execute([
                    ':rule_name' => 'เวลาวันศุกร์',
                    ':note' => 'ใช้เฉพาะวันศุกร์',
                    ':start_date' => null,
                    ':end_date' => null,
                    ':weekdays' => '5',
                    ':check_in_start' => $legacyRules['check_in_start'],
                    ':check_in_late' => $legacyRules['check_in_late'],
                    ':check_out_start' => $legacyRules['check_out_start_friday'],
                    ':check_out_end' => $legacyRules['check_out_end'],
                    ':priority' => 200
                ]);
            }
        }
    } catch (Exception $e) {
        error_log("Migrate attendance_time_rules data error: " . $e->getMessage());
    }

    $initialized = true;
}

function getAttendanceTimeRulesFromSystemSettings($db = null)
{
    $rules = [
        'check_in_start' => '03:00:00',
        'check_in_late' => '08:00:00',
        'check_out_start_weekday' => '16:30:00',
        'check_out_start_friday' => '16:00:00',
        'check_out_end' => '23:59:59'
    ];

    if (!$db) {
        return $rules;
    }

    try {
        $stmt = $db->query("SELECT setting_key, setting_value FROM system_settings");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $key = $row['setting_key'] ?? '';
            $value = $row['setting_value'] ?? '';
            if ($key === 'check_in_start') {
                $rules['check_in_start'] = normalizeTimeValue($value, $rules['check_in_start']);
            } elseif ($key === 'check_in_late') {
                $rules['check_in_late'] = normalizeTimeValue($value, $rules['check_in_late']);
            } elseif ($key === 'check_out_start') {
                $rules['check_out_start_weekday'] = normalizeTimeValue($value, $rules['check_out_start_weekday']);
            } elseif ($key === 'check_out_start_friday') {
                $rules['check_out_start_friday'] = normalizeTimeValue($value, $rules['check_out_start_friday']);
            } elseif ($key === 'check_out_end') {
                $rules['check_out_end'] = normalizeTimeValue($value, $rules['check_out_end']);
            }
        }
    } catch (Exception $e) {
        error_log("Load system_settings time rules error: " . $e->getMessage());
    }

    return $rules;
}

function getAttendanceTimeRuleRows($db)
{
    static $cachedRows = null;

    if ($cachedRows !== null) {
        return $cachedRows;
    }

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
                               is_active
                        FROM attendance_time_rules
                        WHERE is_active = 1
                        ORDER BY
                            CASE WHEN start_date IS NULL AND end_date IS NULL THEN 0 ELSE 1 END DESC,
                            priority DESC,
                            CASE
                                WHEN start_date IS NOT NULL AND end_date IS NOT NULL THEN DATEDIFF(end_date, start_date)
                                ELSE 999999
                            END ASC,
                            id DESC");

    $cachedRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return $cachedRows;
}

function getAttendanceTimeRules($db = null, $attendanceDate = null)
{
    static $resolvedRulesCache = [];

    $dateBase = $attendanceDate ?: date('Y-m-d');
    if (isset($resolvedRulesCache[$dateBase])) {
        return $resolvedRulesCache[$dateBase];
    }

    $timestamp = strtotime($dateBase);
    if ($timestamp === false) {
        $timestamp = time();
    }
    $dayOfWeek = (int) date('N', $timestamp); // 1=Mon ... 7=Sun

    $fallback = [
        'check_in_start' => '03:00:00',
        'check_in_late' => '08:00:00',
        'check_out_start' => ($dayOfWeek === 5) ? '16:00:00' : '16:30:00',
        'check_out_end' => '23:59:59',
        'rule_id' => null,
        'rule_name' => 'ค่ามาตรฐาน',
        'rule_note' => null,
        'priority' => 0,
        'weekdays' => normalizeWeekdayList([$dayOfWeek]),
        'start_date' => null,
        'end_date' => null
    ];

    $rules = $fallback;

    if ($db) {
        try {
            $rows = getAttendanceTimeRuleRows($db);
            foreach ($rows as $row) {
                if (!attendanceRuleAppliesToDate($row, $dateBase, $dayOfWeek)) {
                    continue;
                }

                $rules = [
                    'check_in_start' => normalizeTimeValue($row['check_in_start'], $fallback['check_in_start']),
                    'check_in_late' => normalizeTimeValue($row['check_in_late'], $fallback['check_in_late']),
                    'check_out_start' => normalizeTimeValue($row['check_out_start'], $fallback['check_out_start']),
                    'check_out_end' => normalizeTimeValue($row['check_out_end'], $fallback['check_out_end']),
                    'rule_id' => (int) ($row['id'] ?? 0),
                    'rule_name' => $row['rule_name'] ?? $fallback['rule_name'],
                    'rule_note' => $row['note'] ?? null,
                    'priority' => (int) ($row['priority'] ?? 0),
                    'weekdays' => normalizeWeekdayList($row['weekdays'] ?? ''),
                    'start_date' => $row['start_date'] ?? null,
                    'end_date' => $row['end_date'] ?? null
                ];
                break;
            }
        } catch (Exception $e) {
            error_log("Resolve attendance_time_rules error: " . $e->getMessage());
        }
    }

    $rules['day_of_week'] = $dayOfWeek;

    $resolvedRulesCache[$dateBase] = $rules;

    return $rules;
}

function autoMarkMissingCheckOut($db)
{
    try {
        $today = date('Y-m-d');
        $currentTime = date('H:i:s');
        $repairQuery = "SELECT id, attendance_date, check_in_time, check_out_time, status, remark
                        FROM attendance_records
                        WHERE (
                                (status = 'incomplete' AND check_out_time IS NOT NULL)
                             OR (check_in_time IS NOT NULL AND check_out_time IS NULL AND status IN ('present', 'late'))
                             OR (check_in_time IS NOT NULL AND check_out_time IS NOT NULL AND status IN ('present', 'late') AND remark LIKE '%มาสายและไม่สแกนออก%')
                              )
                          AND attendance_date <= :today";
        $repairStmt = $db->prepare($repairQuery);
        $repairStmt->execute([':today' => $today]);
        $records = $repairStmt->fetchAll(PDO::FETCH_ASSOC);

        $updateStmt = $db->prepare("UPDATE attendance_records
                                    SET status = :status,
                                        remark = :remark,
                                        updated_at = CURRENT_TIMESTAMP
                                    WHERE id = :id");

        foreach ($records as $record) {
            $attendanceDate = $record['attendance_date'] ?? $today;
            $timeRules = getAttendanceTimeRules($db, $attendanceDate);
            $baseStatus = resolveAttendanceCheckInStatus(
                $record['check_in_time'] ?? null,
                $timeRules['check_in_late'],
                $record['remark'] ?? ''
            );
            $remark = removeAttendanceRemarkTags($record['remark'] ?? '', [LATE_NO_CHECK_OUT_REMARK]);
            $nextStatus = $baseStatus;

            if (!empty($record['check_in_time']) && empty($record['check_out_time'])) {
                $checkOutStart = $timeRules['check_out_start'];
                $shouldMarkIncomplete = ($attendanceDate < $today)
                    || ($attendanceDate === $today && $currentTime >= $checkOutStart);

                if ($shouldMarkIncomplete) {
                    $nextStatus = 'incomplete';
                    if ($baseStatus === 'late') {
                        $remark = addAttendanceRemarkTag($remark, LATE_NO_CHECK_OUT_REMARK);
                    }
                }
            } elseif (!empty($record['check_in_time']) && !empty($record['check_out_time'])) {
                // ถ้าสแกนออกแล้ว ให้ลบ LATE_NO_CHECK_OUT_REMARK ออกและใช้ status ตามเวลาเข้า
                $remark = removeAttendanceRemarkTags($remark, [LATE_NO_CHECK_OUT_REMARK]);
                $nextStatus = $baseStatus;
            }

            $currentStatus = (string) ($record['status'] ?? '');
            $currentRemark = trim((string) ($record['remark'] ?? ''));
            $nextRemark = trim((string) $remark);

            if ($nextStatus === $currentStatus && $nextRemark === $currentRemark) {
                continue;
            }

            $updateStmt->execute([
                ':id' => (int) $record['id'],
                ':status' => $nextStatus,
                ':remark' => $remark
            ]);
        }
    } catch (Exception $e) {
        error_log("Auto mark missing check-out error: " . $e->getMessage());
    }
}

if (!file_exists(UPLOAD_PATH)) {
    mkdir(UPLOAD_PATH, 0755, true);
}
?>
