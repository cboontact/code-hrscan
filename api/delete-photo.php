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

// ใช้สำหรับบันทึก admin log
$database = new Database();
$db = $database->getConnection();

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['photo_path']) || empty($input['photo_path'])) {
        jsonResponse(false, 'ไม่พบ path ของรูปภาพ', null, 400);
    }
    
    $photoPath = $input['photo_path'];
    
    // ตรวจสอบว่า path เริ่มต้นด้วย uploads/teachers/
    if (!stringStartsWith($photoPath, 'uploads/teachers/')) {
        jsonResponse(false, 'Path ไม่ถูกต้อง', null, 400);
    }
    
    // สร้าง full path
    $relativePath = preg_replace('#^uploads/#', '', $photoPath);
    $fullPath = rtrim(UPLOAD_PATH, '/\\') . '/' . ltrim($relativePath, '/\\');
    
    // ตรวจสอบว่าไฟล์มีอยู่จริง
    if (file_exists($fullPath)) {
        // ลบไฟล์
        if (unlink($fullPath)) {
            logAdminAction(
                $db,
                'DELETE_TEACHER_PHOTO',
                'ลบรูปภาพครู',
                ['photo_path' => $photoPath]
            );
            jsonResponse(true, 'ลบรูปภาพสำเร็จ', null);
        } else {
            jsonResponse(false, 'ไม่สามารถลบรูปภาพได้', null, 500);
        }
    } else {
        // ไฟล์ไม่มีอยู่ ถือว่าสำเร็จ
        logAdminAction(
            $db,
            'DELETE_TEACHER_PHOTO',
            'ลบรูปภาพครู (ไม่พบไฟล์)',
            ['photo_path' => $photoPath]
        );
        jsonResponse(true, 'ไฟล์ไม่มีอยู่ในระบบ', null);
    }
    
} catch (Exception $e) {
    error_log("Delete photo error: " . $e->getMessage());
    jsonResponse(false, 'เกิดข้อผิดพลาดในการลบรูปภาพ', null, 500);
}
?>
