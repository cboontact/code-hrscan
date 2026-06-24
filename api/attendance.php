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

// อัพเดตสถานะไม่สแกนออกอัตโนมัติก่อนใช้งานข้อมูล
autoMarkMissingCheckOut($db);

if ($action === 'get' && isset($_GET['id'])) {
    try {
        $query = "SELECT a.*, 
                         t.id AS teacher_id,
                         CONCAT(t.first_name, ' ', t.last_name) as teacher_name,
                         t.department,
                         t.citizen_id
                  FROM attendance_records a
                  INNER JOIN teachers t ON a.teacher_id = t.id
                  WHERE a.id = :id";
        
        $stmt = $db->prepare($query);
        $stmt->execute([':id' => $_GET['id']]);
        $record = $stmt->fetch();
        
        if (!$record) {
            jsonResponse(false, 'ไม่พบข้อมูลการลงเวลา', null, 404);
        }

        $timeRules = getAttendanceTimeRules($db, $record['attendance_date'] ?? date('Y-m-d'));
        $displayStatus = resolveAttendanceDisplayStatus($record, $timeRules['check_in_late']);
        
        jsonResponse(true, 'ดึงข้อมูลสำเร็จ', [
            'record' => [
                'id' => $record['id'],
                'attendance_date' => $record['attendance_date'],
                'check_in_time' => $record['check_in_time'],
                'check_out_time' => $record['check_out_time'],
                'status' => $displayStatus['code'],
                'base_status' => $displayStatus['base_status'],
                'status_text' => $displayStatus['text'],
                'remark' => $record['remark'] ?? null,
                'teacher' => [
                    'id' => $record['teacher_id'] ?? null,
                    'name' => $record['teacher_name'],
                    'department' => $record['department'],
                    'citizen_id' => $record['citizen_id']
                ]
            ]
        ]);
    } catch (Exception $e) {
        error_log("Get attendance error: " . $e->getMessage());
        jsonResponse(false, 'เกิดข้อผิดพลาดในการดึงข้อมูล', null, 500);
    }
}

if ($action === 'list') {
    $canViewRfid = isSuperAdmin();
    $date = $_GET['date'] ?? date('Y-m-d');
    $search = $_GET['search'] ?? '';
    $department = $_GET['department'] ?? '';
    $status = $_GET['status'] ?? '';
    $sort = $_GET['sort'] ?? '';
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
    $offset = ($page - 1) * $limit;

    try {
        $timeRules = getAttendanceTimeRules($db, $date);
        $effectiveStatusSql = "CASE
            WHEN a.check_in_time IS NOT NULL 
                 AND TIME(a.check_in_time) > :late_threshold
                 AND COALESCE(TRIM(a.remark), '') <> :approved_late_remark
                THEN 'late'
            WHEN a.check_in_time IS NOT NULL THEN 'present'
            WHEN a.status IS NULL THEN 'absent'
            ELSE a.status
        END";

        // เงื่อนไขสำหรับ WHERE
        $whereConditions = ["t.status = 'active'"];
        $params = [];

        if (!empty($search)) {
            $searchValue = "%{$search}%";
            $searchConditions = [
                "t.first_name LIKE :search_first_name",
                "t.last_name LIKE :search_last_name",
                "t.citizen_id LIKE :search_citizen_id"
            ];
            if ($canViewRfid) {
                $searchConditions[] = "t.rfid_code LIKE :search_rfid_code";
                $params[':search_rfid_code'] = $searchValue;
            }
            $whereConditions[] = '(' . implode(' OR ', $searchConditions) . ')';
            $params[':search_first_name'] = $searchValue;
            $params[':search_last_name'] = $searchValue;
            $params[':search_citizen_id'] = $searchValue;
        }

        if (!empty($department)) {
            $whereConditions[] = "t.department = :department";
            $params[':department'] = $department;
        }

        if (!empty($status)) {
            if ($status === 'absent') {
                // กรณีเลือกดู "ยังไม่ลงเวลา"
                $whereConditions[] = "(a.id IS NULL OR (a.attendance_date = :date AND a.id IS NULL))";
                $params[':date'] = $date;
            } elseif ($status === 'incomplete') {
                $whereConditions[] = "a.status = :status";
                $whereConditions[] = "a.attendance_date = :date";
                $params[':status'] = $status;
                $params[':date'] = $date;
            } else {
                $whereConditions[] = "{$effectiveStatusSql} = :status";
                $whereConditions[] = "a.attendance_date = :date";
                $params[':approved_late_remark'] = APPROVED_LATE_REMARK;
                $params[':late_threshold'] = $timeRules['check_in_late'];
                $params[':status'] = $status;
                $params[':date'] = $date;
            }
        }

        $whereClause = implode(' AND ', $whereConditions);

        // นับจำนวนทั้งหมด
        $countQuery = "SELECT COUNT(*) as total 
                       FROM teachers t
                       LEFT JOIN attendance_records a ON t.id = a.teacher_id AND a.attendance_date = :count_date
                       WHERE {$whereClause}";
        $countParams = array_merge($params, [':count_date' => $date]);
        $countStmt = $db->prepare($countQuery);
        $countStmt->execute($countParams);
        $totalRecords = $countStmt->fetch()['total'];

        $orderBySql = "ORDER BY 
                    CASE 
                        WHEN a.check_in_time IS NOT NULL THEN 0 
                        ELSE 1 
                    END,
                    a.check_in_time ASC,
                    t.first_name ASC";

        if ($sort === 'recent') {
            $orderBySql = "ORDER BY
                    CASE
                        WHEN a.id IS NULL THEN 1
                        ELSE 0
                    END,
                    CASE
                        WHEN a.check_out_time IS NOT NULL THEN a.check_out_time
                        ELSE a.check_in_time
                    END DESC,
                    a.id DESC,
                    t.first_name ASC";
        }

        // ดึงข้อมูล
        $query = "SELECT t.id as teacher_id,
                         t.first_name, 
                         t.last_name, 
                         t.department, 
                         t.photo, 
                         t.citizen_id,
                         a.id,
                         a.check_in_time,
                         a.check_out_time,
                         a.attendance_date,
                         a.status,
                         a.remark
                  FROM teachers t
                  LEFT JOIN attendance_records a ON t.id = a.teacher_id AND a.attendance_date = :query_date
                  WHERE {$whereClause}
                  {$orderBySql}
                  LIMIT :limit OFFSET :offset";
        
        $queryParams = array_merge($params, [':query_date' => $date]);
        $stmt = $db->prepare($query);
        foreach ($queryParams as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        $records = $stmt->fetchAll();

        $formattedRecords = array_map(function($record) use ($date, $timeRules) {
            $displayStatus = resolveAttendanceDisplayStatus($record, $timeRules['check_in_late']);

            return [
                'id' => $record['id'], // attendance record id (อาจเป็น null ถ้ายังไม่ลงเวลา)
                'teacher' => [
                    'id' => $record['teacher_id'],
                    'name' => $record['first_name'] . ' ' . $record['last_name'],
                    'department' => $record['department'],
                    'photo' => $record['photo'],
                    'citizen_id' => $record['citizen_id']
                ],
                'check_in_time' => $record['check_in_time'],
                'check_out_time' => $record['check_out_time'],
                'attendance_date' => $record['attendance_date'] ?? $date,
                'status' => $displayStatus['code'],
                'base_status' => $displayStatus['base_status'],
                'status_text' => $displayStatus['text'],
                'remark' => $record['remark']
            ];
        }, $records);

        jsonResponse(true, 'ดึงข้อมูลสำเร็จ', [
            'records' => $formattedRecords,
            'pagination' => [
                'total' => (int)$totalRecords,
                'page' => $page,
                'limit' => $limit,
                'total_pages' => ceil($totalRecords / $limit)
            ]
        ]);

    } catch(Exception $e) {
        error_log("List attendance error: " . $e->getMessage());
        jsonResponse(false, 'เกิดข้อผิดพลาดในการดึงข้อมูล', null, 500);
    }
}

if ($action === 'stats') {
    $date = $_GET['date'] ?? date('Y-m-d');

    try {
        $timeRules = getAttendanceTimeRules($db, $date);
        $statsQuery = "SELECT status, remark, check_in_time, check_out_time
                       FROM attendance_records
                       WHERE attendance_date = :date";
        $statsStmt = $db->prepare($statsQuery);
        $statsStmt->execute([':date' => $date]);
        $records = $statsStmt->fetchAll();

        $stats = [
            'total' => count($records),
            'present' => 0,
            'late' => 0,
            'absent' => 0,
            'incomplete' => 0,
            'remark_count' => 0
        ];

        foreach ($records as $record) {
            if (trim((string) ($record['remark'] ?? '')) !== '') {
                $stats['remark_count']++;
            }

            $category = resolveAttendanceSummaryCategory($record, $timeRules['check_in_late']);
            if ($category === 'present') {
                $stats['present']++;
            } elseif ($category === 'late') {
                $stats['late']++;
            } elseif ($category === 'absent') {
                $stats['absent']++;
            }

            if (($record['status'] ?? '') === 'incomplete') {
                $stats['incomplete']++;
            }
        }

        $totalTeachersQuery = "SELECT COUNT(*) as total FROM teachers WHERE status = 'active'";
        $totalTeachersStmt = $db->query($totalTeachersQuery);
        $totalTeachers = $totalTeachersStmt->fetch()['total'];

        jsonResponse(true, 'ดึงสถิติสำเร็จ', [
            'date' => $date,
            'total_teachers' => (int)$totalTeachers,
            'checked_in' => (int)$stats['total'],
            'present' => (int)$stats['present'],
            'late' => (int)$stats['late'],
            'absent' => (int)$stats['absent'],
            'incomplete' => (int)$stats['incomplete'],
            'remark_count' => (int)$stats['remark_count'],
            'not_checked_in' => (int)$totalTeachers - (int)$stats['total']
        ]);

    } catch(Exception $e) {
        error_log("Stats error: " . $e->getMessage());
        jsonResponse(false, 'เกิดข้อผิดพลาดในการดึงสถิติ', null, 500);
    }
}

if ($action === 'departments') {
    try {
        $query = "SELECT DISTINCT TRIM(department) AS department
                  FROM teachers
                  WHERE department IS NOT NULL
                    AND TRIM(department) <> ''
                  ORDER BY department";
        $stmt = $db->query($query);
        $departments = $stmt->fetchAll(PDO::FETCH_COLUMN);

        jsonResponse(true, 'ดึงข้อมูลกลุ่มสาระ / แผนกสำเร็จ', [
            'departments' => $departments
        ]);

    } catch(Exception $e) {
        error_log("Departments error: " . $e->getMessage());
        jsonResponse(false, 'เกิดข้อผิดพลาดในการดึงข้อมูล', null, 500);
    }
}

jsonResponse(false, 'Invalid action', null, 400);
?>
