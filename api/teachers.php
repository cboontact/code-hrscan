<?php
session_start();
require_once '../config/database.php';
require_once '../config/config.php';

if (!isset($_SESSION['admin_id'])) {
    jsonResponse(false, 'กรุณาเข้าสู่ระบบ', null, 401);
}

$database = new Database();
$db = $database->getConnection();
ensureTeacherIdentityColumn($db);

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

if ($action === 'list') {
    $canViewRfid = isSuperAdmin();
    $search = $_GET['search'] ?? '';
    $department = $_GET['department'] ?? '';
    $status = $_GET['status'] ?? '';
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
    $offset = ($page - 1) * $limit;

    try {
        $whereConditions = [];
        $params = [];

        if (!empty($search)) {
            $searchValue = "%{$search}%";
            $searchConditions = [
                "first_name LIKE :search_first_name",
                "last_name LIKE :search_last_name",
                "citizen_id LIKE :search_citizen_id"
            ];
            if ($canViewRfid) {
                $searchConditions[] = "rfid_code LIKE :search_rfid_code";
                $params[':search_rfid_code'] = $searchValue;
            }
            $whereConditions[] = '(' . implode(' OR ', $searchConditions) . ')';
            $params[':search_first_name'] = $searchValue;
            $params[':search_last_name'] = $searchValue;
            $params[':search_citizen_id'] = $searchValue;
        }

        if (!empty($department)) {
            $whereConditions[] = "department = :department";
            $params[':department'] = $department;
        }

        if (!empty($status)) {
            $whereConditions[] = "status = :status";
            $params[':status'] = $status;
        }

        $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

        $countQuery = "SELECT COUNT(*) as total FROM teachers {$whereClause}";
        $countStmt = $db->prepare($countQuery);
        $countStmt->execute($params);
        $totalRecords = $countStmt->fetch()['total'];

        $query = "SELECT * FROM teachers {$whereClause} ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
        $stmt = $db->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        $teachers = $stmt->fetchAll();
        $teachers = array_map('sanitizeTeacherRfidData', $teachers);

        jsonResponse(true, 'ดึงข้อมูลสำเร็จ', [
            'teachers' => $teachers,
            'pagination' => [
                'total' => (int)$totalRecords,
                'page' => $page,
                'limit' => $limit,
                'total_pages' => ceil($totalRecords / $limit)
            ]
        ]);

    } catch(Exception $e) {
        error_log("List teachers error: " . $e->getMessage());
        jsonResponse(false, 'เกิดข้อผิดพลาดในการดึงข้อมูล', null, 500);
    }
}

if ($action === 'get' && isset($_GET['id'])) {
    try {
        $query = "SELECT * FROM teachers WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->execute([':id' => $_GET['id']]);
        $teacher = $stmt->fetch();

        if (!$teacher) {
            jsonResponse(false, 'ไม่พบข้อมูลครู', null, 404);
        }

        jsonResponse(true, 'ดึงข้อมูลสำเร็จ', ['teacher' => sanitizeTeacherRfidData($teacher)]);

    } catch(Exception $e) {
        error_log("Get teacher error: " . $e->getMessage());
        jsonResponse(false, 'เกิดข้อผิดพลาดในการดึงข้อมูล', null, 500);
    }
}

if ($action === 'create' && $method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $input['citizen_id'] = normalizeIdentityValue($input['citizen_id'] ?? '');
    $input['rfid_code'] = trim((string)($input['rfid_code'] ?? ''));
    $input['first_name'] = trim((string)($input['first_name'] ?? ''));
    $input['last_name'] = trim((string)($input['last_name'] ?? ''));
    $input['department'] = trim((string)($input['department'] ?? ''));
    $input['birth_date'] = normalizeDateValue($input['birth_date'] ?? null);

    if ($input['rfid_code'] === '' || $input['citizen_id'] === '' ||
        $input['first_name'] === '' || $input['last_name'] === '' ||
        $input['department'] === '') {
        jsonResponse(false, 'กรุณากรอกข้อมูลให้ครบถ้วน', null, 400);
    }

    $identityValidationError = validateIdentityValue($input['citizen_id']);
    if ($identityValidationError !== null) {
        jsonResponse(false, $identityValidationError, null, 400);
    }

    try {
        $checkQuery = "SELECT id FROM teachers WHERE rfid_code = :rfid_code OR citizen_id = :citizen_id";
        $checkStmt = $db->prepare($checkQuery);
        $checkStmt->execute([
            ':rfid_code' => $input['rfid_code'],
            ':citizen_id' => $input['citizen_id']
        ]);

        if ($checkStmt->fetch()) {
            jsonResponse(false, 'รหัส RFID หรือเลขประจำตัว/Passport นี้มีในระบบแล้ว', null, 400);
        }

        $query = "INSERT INTO teachers (rfid_code, citizen_id, first_name, last_name, department, position, personnel_type, birth_date, gender, blood_type, email, phone, photo, status) 
                  VALUES (:rfid_code, :citizen_id, :first_name, :last_name, :department, :position, :personnel_type, :birth_date, :gender, :blood_type, :email, :phone, :photo, :status)";
        $stmt = $db->prepare($query);
        $stmt->execute([
            ':rfid_code' => $input['rfid_code'],
            ':citizen_id' => $input['citizen_id'],
            ':first_name' => $input['first_name'],
            ':last_name' => $input['last_name'],
            ':department' => $input['department'],
            ':position' => $input['position'] ?? null,
            ':personnel_type' => $input['personnel_type'] ?? null,
            ':birth_date' => $input['birth_date'],
            ':gender' => $input['gender'] ?? null,
            ':blood_type' => $input['blood_type'] ?? null,
            ':email' => $input['email'] ?? null,
            ':phone' => $input['phone'] ?? null,
            ':photo' => $input['photo'] ?? null,
            ':status' => $input['status'] ?? 'inactive'
        ]);

        $teacherId = $db->lastInsertId();

        logAdminAction(
            $db,
            'CREATE_TEACHER',
            "เพิ่มครู {$input['first_name']} {$input['last_name']}",
            [
                'teacher_id' => (int)$teacherId,
                'teacher_name' => trim(($input['first_name'] ?? '') . ' ' . ($input['last_name'] ?? '')),
                'citizen_id' => $input['citizen_id'] ?? null,
                'department' => $input['department'] ?? null,
                'status' => $input['status'] ?? 'inactive'
            ],
            (int)$_SESSION['admin_id'],
            $_SESSION['admin_username'] ?? null,
            $_SESSION['admin_name'] ?? null,
            getClientIpAddress(),
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        );

        jsonResponse(true, 'เพิ่มข้อมูลครูสำเร็จ', ['teacher_id' => $teacherId], 201);

    } catch(Exception $e) {
        error_log("Create teacher error: " . $e->getMessage());
        jsonResponse(false, 'เกิดข้อผิดพลาดในการเพิ่มข้อมูล', null, 500);
    }
}

if ($action === 'update' && $method === 'POST' && isset($_GET['id'])) {
    $input = json_decode(file_get_contents('php://input'), true);
    $canManageRfid = canEditRfid();
    if (isset($input['citizen_id'])) {
        $input['citizen_id'] = normalizeIdentityValue($input['citizen_id']);
        $identityValidationError = validateIdentityValue($input['citizen_id']);
        if ($identityValidationError !== null) {
            jsonResponse(false, $identityValidationError, null, 400);
        }
    }
    if (isset($input['rfid_code'])) {
        $input['rfid_code'] = trim((string)$input['rfid_code']);
        if (!$canManageRfid) {
            jsonResponse(false, 'เฉพาะ admin และ super admin เท่านั้นที่แก้ไข RFID ได้', null, 403);
        }
    }
    if (isset($input['first_name'])) {
        $input['first_name'] = trim((string)$input['first_name']);
    }
    if (isset($input['last_name'])) {
        $input['last_name'] = trim((string)$input['last_name']);
    }
    if (isset($input['department'])) {
        $input['department'] = trim((string)$input['department']);
    }
    if (array_key_exists('birth_date', $input)) {
        $input['birth_date'] = normalizeDateValue($input['birth_date']);
    }

    try {
        $checkQuery = "SELECT id FROM teachers WHERE id = :id";
        $checkStmt = $db->prepare($checkQuery);
        $checkStmt->execute([':id' => $_GET['id']]);

        if (!$checkStmt->fetch()) {
            jsonResponse(false, 'ไม่พบข้อมูลครู', null, 404);
        }

        // ตรวจสอบ RFID ซ้ำ
        if (isset($input['rfid_code'])) {
            $checkRfidQuery = "SELECT id FROM teachers WHERE rfid_code = :rfid_code AND id != :id";
            $checkRfidStmt = $db->prepare($checkRfidQuery);
            $checkRfidStmt->execute([
                ':rfid_code' => $input['rfid_code'],
                ':id' => $_GET['id']
            ]);
            if ($checkRfidStmt->fetch()) {
                jsonResponse(false, 'รหัส RFID นี้มีในระบบแล้ว', null, 400);
            }
        }

        // ตรวจสอบเลขบัตรประชาชนซ้ำ
        if (isset($input['citizen_id'])) {
            $checkCitizenQuery = "SELECT id FROM teachers WHERE citizen_id = :citizen_id AND id != :id";
            $checkCitizenStmt = $db->prepare($checkCitizenQuery);
            $checkCitizenStmt->execute([
                ':citizen_id' => $input['citizen_id'],
                ':id' => $_GET['id']
            ]);
            if ($checkCitizenStmt->fetch()) {
                jsonResponse(false, 'เลขประจำตัว/Passport นี้มีในระบบแล้ว', null, 400);
            }
        }

        $updateFields = [];
        $params = [':id' => $_GET['id']];

        if (isset($input['rfid_code'])) {
            $updateFields[] = "rfid_code = :rfid_code";
            $params[':rfid_code'] = $input['rfid_code'];
        }
        if (isset($input['citizen_id'])) {
            $updateFields[] = "citizen_id = :citizen_id";
            $params[':citizen_id'] = $input['citizen_id'];
        }
        if (isset($input['first_name'])) {
            $updateFields[] = "first_name = :first_name";
            $params[':first_name'] = $input['first_name'];
        }
        if (isset($input['last_name'])) {
            $updateFields[] = "last_name = :last_name";
            $params[':last_name'] = $input['last_name'];
        }
        if (isset($input['department'])) {
            $updateFields[] = "department = :department";
            $params[':department'] = $input['department'];
        }
        if (isset($input['position'])) {
            $updateFields[] = "position = :position";
            $params[':position'] = $input['position'];
        }
        if (isset($input['personnel_type'])) {
            $updateFields[] = "personnel_type = :personnel_type";
            $params[':personnel_type'] = $input['personnel_type'];
        }
        if (array_key_exists('birth_date', $input)) {
            $updateFields[] = "birth_date = :birth_date";
            $params[':birth_date'] = $input['birth_date'];
        }
        if (isset($input['gender'])) {
            $updateFields[] = "gender = :gender";
            $params[':gender'] = $input['gender'];
        }
        if (array_key_exists('blood_type', $input)) {
            $updateFields[] = "blood_type = :blood_type";
            $params[':blood_type'] = $input['blood_type'] !== '' ? $input['blood_type'] : null;
        }
        if (isset($input['email'])) {
            $updateFields[] = "email = :email";
            $params[':email'] = $input['email'];
        }
        if (isset($input['phone'])) {
            $updateFields[] = "phone = :phone";
            $params[':phone'] = $input['phone'];
        }
        if (isset($input['photo'])) {
            // ลบไฟล์เก่าเฉพาะเมื่อมีการเปลี่ยน path รูปจริง หรือผู้ใช้ตั้งใจลบรูป
            $oldPhotoQuery = "SELECT photo FROM teachers WHERE id = :id";
            $oldPhotoStmt = $db->prepare($oldPhotoQuery);
            $oldPhotoStmt->execute([':id' => $_GET['id']]);
            $oldPhoto = $oldPhotoStmt->fetchColumn();
            $newPhoto = $input['photo'];

            if ($oldPhoto && $oldPhoto !== $newPhoto && stringStartsWith($oldPhoto, 'uploads/teachers/')) {
                $oldRelativePath = preg_replace('#^uploads/#', '', $oldPhoto);
                $oldPhotoPath = rtrim(UPLOAD_PATH, '/\\') . '/' . ltrim($oldRelativePath, '/\\');
                if (file_exists($oldPhotoPath)) {
                    @unlink($oldPhotoPath);
                }
            }

            $updateFields[] = "photo = :photo";
            $params[':photo'] = $newPhoto;
        }
        if (isset($input['status'])) {
            $updateFields[] = "status = :status";
            $params[':status'] = $input['status'];
        }

        if (empty($updateFields)) {
            jsonResponse(false, 'ไม่มีข้อมูลที่ต้องการอัพเดท', null, 400);
        }

        $query = "UPDATE teachers SET " . implode(', ', $updateFields) . " WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->execute($params);

        logAdminAction(
            $db,
            'UPDATE_TEACHER',
            "แก้ไขข้อมูลครู ID: {$_GET['id']}",
            [
                'teacher_id' => (int)$_GET['id'],
                'updated_fields' => array_map(function ($field) {
                    return trim((string) preg_replace('/\s*=\s*:.+$/', '', (string) $field));
                }, $updateFields)
            ],
            (int)$_SESSION['admin_id'],
            $_SESSION['admin_username'] ?? null,
            $_SESSION['admin_name'] ?? null,
            getClientIpAddress(),
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        );

        jsonResponse(true, 'อัพเดทข้อมูลสำเร็จ', null);

    } catch(Exception $e) {
        error_log("Update teacher error: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        jsonResponse(false, 'เกิดข้อผิดพลาดในการอัพเดทข้อมูล: ' . $e->getMessage(), null, 500);
    }
}

if ($action === 'delete' && $method === 'POST' && isset($_GET['id'])) {
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        $deleteCode = trim((string)($input['delete_code'] ?? ''));
        if ($deleteCode !== '50052001') {
            logAdminAction(
                $db,
                'DELETE_TEACHER_DENIED',
                "พยายามลบครูไม่สำเร็จ (รหัสยืนยันไม่ถูกต้อง) ID: {$_GET['id']}",
                [
                    'teacher_id' => (int)$_GET['id']
                ],
                (int)$_SESSION['admin_id'],
                $_SESSION['admin_username'] ?? null,
                $_SESSION['admin_name'] ?? null,
                getClientIpAddress(),
                $_SERVER['HTTP_USER_AGENT'] ?? ''
            );
            jsonResponse(false, 'รหัสยืนยันไม่ถูกต้อง', null, 403);
        }

        $checkQuery = "SELECT first_name, last_name, photo FROM teachers WHERE id = :id";
        $checkStmt = $db->prepare($checkQuery);
        $checkStmt->execute([':id' => $_GET['id']]);
        $teacher = $checkStmt->fetch();

        if (!$teacher) {
            jsonResponse(false, 'ไม่พบข้อมูลครู', null, 404);
        }

        // ลบรูปภาพถ้ามี
        if ($teacher['photo'] && stringStartsWith($teacher['photo'], 'uploads/teachers/')) {
            $relativePath = preg_replace('#^uploads/#', '', $teacher['photo']);
            $photoPath = rtrim(UPLOAD_PATH, '/\\') . '/' . ltrim($relativePath, '/\\');
            if (file_exists($photoPath)) {
                @unlink($photoPath);
            }
        }

        $query = "DELETE FROM teachers WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->execute([':id' => $_GET['id']]);

        logAdminAction(
            $db,
            'DELETE_TEACHER',
            "ลบครู {$teacher['first_name']} {$teacher['last_name']}",
            [
                'teacher_id' => (int)$_GET['id'],
                'teacher_name' => trim(($teacher['first_name'] ?? '') . ' ' . ($teacher['last_name'] ?? ''))
            ],
            (int)$_SESSION['admin_id'],
            $_SESSION['admin_username'] ?? null,
            $_SESSION['admin_name'] ?? null,
            getClientIpAddress(),
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        );

        jsonResponse(true, 'ลบข้อมูลสำเร็จ', null);

    } catch(Exception $e) {
        error_log("Delete teacher error: " . $e->getMessage());
        jsonResponse(false, 'เกิดข้อผิดพลาดในการลบข้อมูล', null, 500);
    }
}

jsonResponse(false, 'Invalid action', null, 400);
?>
