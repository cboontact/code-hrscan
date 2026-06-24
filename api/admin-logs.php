<?php
session_start();
require_once '../config/database.php';
require_once '../config/config.php';

if (!isset($_SESSION['admin_id'])) {
    jsonResponse(false, 'กรุณาเข้าสู่ระบบ', null, 401);
}

$database = new Database();
$db = $database->getConnection();
$action = $_GET['action'] ?? 'list';

if ($action !== 'list') {
    jsonResponse(false, 'Invalid action', null, 400);
}

$search = trim($_GET['search'] ?? '');
$logAction = trim($_GET['log_action'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = max(1, min(100, (int)($_GET['limit'] ?? 20)));
$offset = ($page - 1) * $limit;

function decodeDetails($raw) {
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function enrichLogDetails($db, $log) {
    $details = decodeDetails($log['details_json'] ?? '');
    $action = (string)($log['action'] ?? '');
    $description = (string)($log['description'] ?? '');

    if (!isset($details['teacher_name']) && preg_match('/ID:\s*(\d+)/u', $description, $m)) {
        $recordId = (int)$m[1];

        if (in_array($action, ['UPDATE_ATTENDANCE_TIME', 'UPDATE_ATTENDANCE_REMARK'], true)) {
            $stmt = $db->prepare("SELECT a.id,
                                         a.attendance_date,
                                         a.check_in_time,
                                         a.check_out_time,
                                         a.remark,
                                         t.id AS teacher_id,
                                         t.first_name,
                                         t.last_name
                                  FROM attendance_records a
                                  INNER JOIN teachers t ON a.teacher_id = t.id
                                  WHERE a.id = :id
                                  LIMIT 1");
            $stmt->execute([':id' => $recordId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $details['attendance_record_id'] = (int)$row['id'];
                $details['teacher_id'] = (int)$row['teacher_id'];
                $details['teacher_name'] = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
                $details['attendance_date'] = $row['attendance_date'];
                $details['check_in_time'] = $row['check_in_time'];
                $details['check_out_time'] = $row['check_out_time'];
                $details['remark'] = $row['remark'];
            }
        } elseif ($action === 'UPDATE_TEACHER') {
            $stmt = $db->prepare("SELECT id, first_name, last_name, citizen_id, rfid_code
                                  FROM teachers
                                  WHERE id = :id
                                  LIMIT 1");
            $stmt->execute([':id' => $recordId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $details['teacher_id'] = (int)$row['id'];
                $details['teacher_name'] = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
                $details['citizen_id'] = $row['citizen_id'] ?? null;
                $details['rfid_code'] = $row['rfid_code'] ?? null;
            }
        }
    }

    if (!isset($details['teacher_name']) && preg_match('/Teacher ID:\s*(\d+)/u', $description, $m)) {
        $teacherId = (int)$m[1];
        $stmt = $db->prepare("SELECT first_name, last_name, citizen_id, rfid_code
                              FROM teachers
                              WHERE id = :id
                              LIMIT 1");
        $stmt->execute([':id' => $teacherId]);
        $teacher = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($teacher) {
            $details['teacher_id'] = $teacherId;
            $details['teacher_name'] = trim(($teacher['first_name'] ?? '') . ' ' . ($teacher['last_name'] ?? ''));
            $details['citizen_id'] = $teacher['citizen_id'] ?? null;
            $details['rfid_code'] = $teacher['rfid_code'] ?? null;
        }
    }

    $details = sanitizeAdminLogDetailsForViewer($details);

    if (!empty($details)) {
        $log['details_json'] = json_encode($details, JSON_UNESCAPED_UNICODE);
    } else {
        $log['details_json'] = null;
    }

    return $log;
}

try {
    $whereConditions = ['1=1'];
    $params = [];

    if ($search !== '') {
        $whereConditions[] = "(
            admin_username LIKE :search
            OR admin_name LIKE :search
            OR action LIKE :search
            OR description LIKE :search
            OR ip_address LIKE :search
            OR endpoint LIKE :search
            OR details_json LIKE :search
        )";
        $params[':search'] = '%' . $search . '%';
    }

    if ($logAction !== '') {
        $whereConditions[] = "action = :log_action";
        $params[':log_action'] = $logAction;
    }

    if ($dateFrom !== '') {
        $whereConditions[] = "DATE(created_at) >= :date_from";
        $params[':date_from'] = $dateFrom;
    }

    if ($dateTo !== '') {
        $whereConditions[] = "DATE(created_at) <= :date_to";
        $params[':date_to'] = $dateTo;
    }

    $whereClause = implode(' AND ', $whereConditions);

    $countQuery = "SELECT COUNT(*) AS total FROM admin_logs WHERE {$whereClause}";
    $countStmt = $db->prepare($countQuery);
    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value);
    }
    $countStmt->execute();
    $total = (int)$countStmt->fetchColumn();

    $query = "SELECT
                id,
                admin_id,
                admin_username,
                admin_name,
                action,
                description,
                endpoint,
                request_method,
                ip_address,
                user_agent,
                details_json,
                created_at
              FROM admin_logs
              WHERE {$whereClause}
              ORDER BY id DESC
              LIMIT :limit OFFSET :offset";
    $stmt = $db->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $records = array_map(function($log) use ($db) {
        return enrichLogDetails($db, $log);
    }, $records);

    $actionsStmt = $db->query("SELECT DISTINCT action FROM admin_logs ORDER BY action ASC");
    $actions = $actionsStmt->fetchAll(PDO::FETCH_COLUMN);

    jsonResponse(true, 'ดึงข้อมูลสำเร็จ', [
        'records' => $records,
        'actions' => $actions,
        'pagination' => [
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'total_pages' => $total > 0 ? (int)ceil($total / $limit) : 1
        ]
    ]);
} catch (Exception $e) {
    error_log('Admin logs error: ' . $e->getMessage());
    if (stripos($e->getMessage(), "doesn't exist") !== false || stripos($e->getMessage(), '1146') !== false) {
        jsonResponse(false, 'ยังไม่พบตาราง admin_logs กรุณารัน migration ก่อน', null, 400);
    }
    jsonResponse(false, 'เกิดข้อผิดพลาดในการดึงข้อมูล log', null, 500);
}
?>
