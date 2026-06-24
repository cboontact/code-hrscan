<?php
session_start();
require_once '../config/database.php';
require_once '../config/config.php';

$database = new Database();
$db = $database->getConnection();

$action = $_GET['action'] ?? '';

if ($action === 'login') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['username']) || !isset($input['password'])) {
        jsonResponse(false, 'กรุณากรอกข้อมูลให้ครบถ้วน', null, 400);
    }

    try {
        $query = "SELECT * FROM admin_users WHERE username = :username AND status = 'active'";
        $stmt = $db->prepare($query);
        $stmt->execute([':username' => $input['username']]);
        $user = $stmt->fetch();

        $isValidPassword = false;

        if ($user) {
            // รองรับทั้งรหัสผ่านแบบ hash และแบบ plain text (legacy)
            if (password_verify($input['password'], $user['password'])) {
                $isValidPassword = true;
            } elseif (hash_equals((string)$user['password'], (string)$input['password'])) {
                $isValidPassword = true;

                // อัปเกรดรหัสผ่านเดิมเป็น hash ทันทีเมื่อเข้าสู่ระบบได้
                $rehashQuery = "UPDATE admin_users SET password = :password WHERE id = :id";
                $rehashStmt = $db->prepare($rehashQuery);
                $rehashStmt->execute([
                    ':password' => password_hash($input['password'], PASSWORD_DEFAULT),
                    ':id' => $user['id']
                ]);
            }
        }

        if (!$user || !$isValidPassword) {
            jsonResponse(false, 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง', null, 401);
        }

        $updateQuery = "UPDATE admin_users SET last_login = NOW() WHERE id = :id";
        $updateStmt = $db->prepare($updateQuery);
        $updateStmt->execute([':id' => $user['id']]);

        $_SESSION['admin_id'] = $user['id'];
        $_SESSION['admin_username'] = $user['username'];
        $normalizedRole = normalizeAdminRole($user['role'] ?? null);

        $_SESSION['admin_role'] = $normalizedRole;
        $_SESSION['admin_name'] = $user['full_name'];

        logAdminAction(
            $db,
            'ADMIN_LOGIN',
            "ผู้ใช้ {$user['username']} เข้าสู่ระบบ",
            [
                'admin_id' => (int)$user['id'],
                'admin_username' => $user['username'],
                'admin_role' => $normalizedRole
            ],
            (int)$user['id'],
            $user['username'],
            $user['full_name'],
            getClientIpAddress(),
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        );

        jsonResponse(true, 'เข้าสู่ระบบสำเร็จ', [
            'user' => [
                'id' => $user['id'],
                'username' => $user['username'],
                'full_name' => $user['full_name'],
                'role' => $normalizedRole
            ]
        ]);

    } catch(Exception $e) {
        error_log("Login error: " . $e->getMessage());
        jsonResponse(false, 'เกิดข้อผิดพลาดในการเข้าสู่ระบบ', null, 500);
    }
}

if ($action === 'logout') {
    if (isset($_SESSION['admin_id'])) {
        logAdminAction(
            $db,
            'ADMIN_LOGOUT',
            "ผู้ใช้ {$_SESSION['admin_username']} ออกจากระบบ",
            null,
            (int)$_SESSION['admin_id'],
            $_SESSION['admin_username'] ?? null,
            $_SESSION['admin_name'] ?? null,
            getClientIpAddress(),
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        );
    }
    
    session_destroy();
    jsonResponse(true, 'ออกจากระบบสำเร็จ', null);
}

if ($action === 'check') {
    if (!isset($_SESSION['admin_id'])) {
        jsonResponse(false, 'ไม่ได้เข้าสู่ระบบ', null, 401);
    }

    jsonResponse(true, 'เข้าสู่ระบบแล้ว', [
        'user' => [
            'id' => $_SESSION['admin_id'],
            'username' => $_SESSION['admin_username'],
            'full_name' => $_SESSION['admin_name'],
            'role' => normalizeAdminRole($_SESSION['admin_role'] ?? null)
        ]
    ]);
}

jsonResponse(false, 'Invalid action', null, 400);
?>
