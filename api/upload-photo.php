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
    // รับข้อมูล Base64 จาก JavaScript
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['photo']) || empty($input['photo'])) {
        jsonResponse(false, 'ไม่พบข้อมูลรูปภาพ', null, 400);
    }
    
    $base64Data = $input['photo'];
    
    // แยก header และ data
    if (preg_match('/^data:image\/(\w+);base64,/', $base64Data, $matches)) {
        $imageType = $matches[1];
        $base64Data = substr($base64Data, strpos($base64Data, ',') + 1);
    } else {
        jsonResponse(false, 'รูปแบบข้อมูลไม่ถูกต้อง', null, 400);
    }
    
    // Decode Base64
    $imageData = base64_decode($base64Data);
    
    if ($imageData === false) {
        jsonResponse(false, 'ไม่สามารถแปลงข้อมูลรูปภาพได้', null, 400);
    }
    
    // สร้างรูปภาพจาก string
    $image = imagecreatefromstring($imageData);
    
    if ($image === false) {
        jsonResponse(false, 'ไม่สามารถสร้างรูปภาพได้', null, 400);
    }
    
    // ปรับขนาดและบีบอัดรูปภาพ
    $width = imagesx($image);
    $height = imagesy($image);
    
    // กำหนดขนาดสูงสุด (ลดจาก 1920 เป็น 800 เพื่อความเร็ว)
    $maxDimension = 800;
    
    if ($width > $maxDimension || $height > $maxDimension) {
        if ($width > $height) {
            $newWidth = $maxDimension;
            $newHeight = (int)(($height / $width) * $maxDimension);
        } else {
            $newHeight = $maxDimension;
            $newWidth = (int)(($width / $height) * $maxDimension);
        }
    } else {
        $newWidth = $width;
        $newHeight = $height;
    }
    
    // สร้างรูปใหม่ที่ปรับขนาดแล้ว
    $resizedImage = imagecreatetruecolor($newWidth, $newHeight);
    imagecopyresampled($resizedImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
    
    // สร้างชื่อไฟล์ที่ไม่ซ้ำ
    $fileName = uniqid('teacher_', true) . '.jpg';
    $uploadDir = rtrim(UPLOAD_PATH, '/\\') . '/teachers/';
    $filePath = $uploadDir . $fileName;

    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            throw new RuntimeException('ไม่สามารถสร้างโฟลเดอร์จัดเก็บรูปภาพได้');
        }
    }
    
    // บันทึกรูปภาพด้วยคุณภาพที่ปรับให้ได้ขนาดไม่เกิน 200KB (ลดจาก 1MB)
    $quality = 75; // ลดจาก 90 เป็น 75 เพื่อความเร็ว
    $maxFileSize = 200 * 1024; // 200KB (ลดจาก 1MB)
    
    do {
        imagejpeg($resizedImage, $filePath, $quality);
        $fileSize = filesize($filePath);
        
        if ($fileSize > $maxFileSize && $quality > 10) {
            $quality -= 10;
        } else {
            break;
        }
    } while ($quality > 10);
    
    // ล้างหน่วยความจำ
    imagedestroy($image);
    imagedestroy($resizedImage);
    
    // ตรวจสอบขนาดไฟล์สุดท้าย
    $finalSize = filesize($filePath);
    $finalSizeKB = round($finalSize / 1024, 2);
    
    // ส่งกลับ path ของรูปภาพ
    logAdminAction(
        $db,
        'UPLOAD_TEACHER_PHOTO',
        'อัพโหลดรูปภาพครู',
        [
            'photo_path' => 'uploads/teachers/' . $fileName,
            'file_size_kb' => $finalSizeKB,
            'dimensions' => $newWidth . 'x' . $newHeight
        ]
    );

    jsonResponse(true, 'อัพโหลดรูปภาพสำเร็จ', [
        'photo_path' => 'uploads/teachers/' . $fileName,
        'file_size' => $finalSizeKB . ' KB',
        'dimensions' => $newWidth . 'x' . $newHeight
    ]);
    
} catch (Exception $e) {
    error_log("Upload photo error: " . $e->getMessage());
    jsonResponse(false, 'เกิดข้อผิดพลาดในการอัพโหลดรูปภาพ: ' . $e->getMessage(), null, 500);
}
?>
