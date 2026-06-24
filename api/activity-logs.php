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

try {
    $whereConditions = ["action IN ('CHECK_IN', 'CHECK_OUT')"];
    $params = [];

    if ($search !== '') {
        $whereConditions[] = "(
            action LIKE :search
            OR description LIKE :search
            OR ip_address LIKE :search
            OR user_agent LIKE :search
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

    $countStmt = $db->prepare("SELECT COUNT(*) FROM activity_logs WHERE {$whereClause}");
    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value);
    }
    $countStmt->execute();
    $total = (int)$countStmt->fetchColumn();

    $stmt = $db->prepare("SELECT
                            id,
                            user_id,
                            action,
                            description,
                            ip_address,
                            user_agent,
                            created_at
                          FROM activity_logs
                          WHERE {$whereClause}
                          ORDER BY id DESC
                          LIMIT :limit OFFSET :offset");
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $actionsStmt = $db->query("SELECT DISTINCT action FROM activity_logs WHERE action IN ('CHECK_IN', 'CHECK_OUT') ORDER BY action ASC");
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
    error_log('Activity logs error: ' . $e->getMessage());
    if (stripos($e->getMessage(), "doesn't exist") !== false || stripos($e->getMessage(), '1146') !== false) {
        jsonResponse(false, 'ยังไม่พบตาราง activity_logs', null, 400);
    }
    jsonResponse(false, 'เกิดข้อผิดพลาดในการดึงข้อมูล log การสแกน', null, 500);
}
?>
