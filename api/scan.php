<?php
require_once '../config/database.php';
require_once '../config/config.php';
require_once __DIR__ . '/../config/attendance-scan.php';

$database = new Database();
$db = $database->getConnection();
autoMarkMissingCheckOut($db);

$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['rfid_code']) || empty($input['rfid_code'])) {
    jsonResponse(false, 'กรุณาระบุรหัส RFID', null, 400);
}

$rfidCode = $input['rfid_code'];

try {
    $query = "SELECT * FROM teachers WHERE rfid_code = :rfid_code AND status = 'active'";
    $stmt = $db->prepare($query);
    $stmt->execute([':rfid_code' => $rfidCode]);
    $teacher = $stmt->fetch();

    if (!$teacher) {
        jsonResponse(false, 'ไม่พบรายชื่อในระบบ', null, 200);
    }

    processAttendanceScan($db, $teacher);

} catch(Exception $e) {
    error_log("Scan error: " . $e->getMessage());
    jsonResponse(false, 'เกิดข้อผิดพลาดในการบันทึกข้อมูล', null, 500);
}
?>
