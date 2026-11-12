<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/attendance-scan.php';

header_remove('Access-Control-Allow-Origin');
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    jsonResponse(false, 'กรุณาเปิดผ่านลิงก์ส่วนตัว', null, 405);
}

$input = json_decode(file_get_contents('php://input'), true);
$token = is_array($input) ? ($input['token'] ?? null) : null;
$action = is_array($input) ? ($input['action'] ?? 'status') : null;
if (!is_string($token) || !preg_match('/\A[a-f0-9]{64}\z/', $token)) {
    jsonResponse(false, 'ลิงก์ไม่ถูกต้องหรือถูกยกเลิกแล้ว', null, 403);
}
if (!in_array($action, ['status', 'check_in', 'check_out'], true)) {
    jsonResponse(false, 'คำสั่งไม่ถูกต้อง', null, 400);
}

try {
    $db = (new Database())->getConnection();
    $stmt = $db->prepare("SELECT t.*
                          FROM personal_attendance_links l
                          INNER JOIN teachers t ON t.id = l.teacher_id
                          WHERE l.token_hash = :token_hash
                            AND l.is_active = 1 AND t.status = 'active'");
    $stmt->execute([':token_hash' => hash('sha256', $token)]);
    $teacher = $stmt->fetch();
    if (!$teacher) {
        jsonResponse(false, 'ลิงก์ไม่ถูกต้องหรือถูกยกเลิกแล้ว', null, 403);
    }

    if ($action === 'status') {
        $today = date('Y-m-d');
        $rules = getAttendanceTimeRules($db, $today, $teacher['id']);
        $stmt = $db->prepare('SELECT check_in_time, check_out_time, status, remark
                              FROM attendance_records
                              WHERE teacher_id = :teacher_id AND attendance_date = :attendance_date');
        $stmt->execute([':teacher_id' => $teacher['id'], ':attendance_date' => $today]);
        $record = $stmt->fetch() ?: null;
        jsonResponse(true, 'ดึงข้อมูลสำเร็จ', [
            'name' => trim($teacher['first_name'] . ' ' . $teacher['last_name']),
            'department' => $teacher['department'],
            'date' => $today,
            'record' => $record,
            'status_text' => $record
                ? resolveAttendanceDisplayStatus($record, $rules['check_in_late'])['text']
                : 'ยังไม่ลงเวลา',
            'rules' => [
                'check_in_start' => $rules['check_in_start'],
                'check_in_late' => $rules['check_in_late'],
                'check_out_start' => $rules['check_out_start'],
                'check_out_end' => $rules['check_out_end']
            ]
        ]);
    }

    processAttendanceScan($db, $teacher, $action);
} catch (Exception $e) {
    error_log('Personal attendance error: ' . $e->getMessage());
    jsonResponse(false, 'ไม่สามารถติดต่อระบบได้ กรุณาลองใหม่', null, 500);
}
