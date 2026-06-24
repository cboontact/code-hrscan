<?php
session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if (!isset($_SESSION['admin_id'])) {
    header('Location: index.php');
    exit();
}

require_once '../config/database.php';

function stringStartsWithCompat($haystack, $needle) {
    $haystack = (string) $haystack;
    $needle = (string) $needle;
    if ($needle === '') {
        return true;
    }

    return strncmp($haystack, $needle, strlen($needle)) === 0;
}

function stringEndsWithCompat($haystack, $needle) {
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

$database = new Database();
$db = $database->getConnection();

$messages = [];
$errors = [];
$executed = 0;

function ensurePersonnelColumns($db, &$messages) {
    $db->exec("ALTER TABLE teachers
               ADD COLUMN IF NOT EXISTS position VARCHAR(120) NULL AFTER department,
               ADD COLUMN IF NOT EXISTS personnel_type VARCHAR(120) NULL AFTER position,
               ADD COLUMN IF NOT EXISTS gender VARCHAR(20) NULL AFTER personnel_type,
               ADD COLUMN IF NOT EXISTS blood_type VARCHAR(20) NULL AFTER gender");
    $db->exec("ALTER TABLE teachers MODIFY blood_type VARCHAR(20) NULL");
    $messages[] = 'ตรวจสอบ/เพิ่มคอลัมน์ position, personnel_type, gender, blood_type เรียบร้อย';
}

function loadPersonnelStatements($sqlFilePath) {
    if (!file_exists($sqlFilePath)) {
        throw new Exception('ไม่พบไฟล์ฐานข้อมูลสำหรับนำเข้า');
    }

    $sql = file_get_contents($sqlFilePath);
    if ($sql === false) {
        throw new Exception('ไม่สามารถอ่านไฟล์ฐานข้อมูลได้');
    }

    $marker = '-- APPENDED DATASET: teachers import (151 rows)';
    $markerPos = strpos($sql, $marker);
    if ($markerPos === false) {
        throw new Exception('ไม่พบชุดข้อมูลบุคลากรในไฟล์ SQL');
    }

    $tail = substr($sql, $markerPos);
    $lines = preg_split("/\\r\\n|\\r|\\n/", $tail);
    $statements = [];
    $buffer = '';

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || stringStartsWithCompat($trimmed, '--')) {
            continue;
        }

        $buffer .= $line . "\n";
        if (stringEndsWithCompat(rtrim($line), ';')) {
            $stmt = trim($buffer);
            $buffer = '';
            if (stripos($stmt, 'INSERT INTO teachers') === 0) {
                $statements[] = $stmt;
            }
        }
    }

    return $statements;
}

try {
    $db->beginTransaction();

    ensurePersonnelColumns($db, $messages);

    $sqlFilePath = __DIR__ . '/../database/jomthong_attendance.sql';
    $statements = loadPersonnelStatements($sqlFilePath);

    foreach ($statements as $statement) {
        // บังคับสถานะเริ่มต้นเป็นไม่ใช้งาน เพื่อทยอยตรวจสอบและเปิดใช้งานทีละคน
        $statement = str_replace("'active') ON DUPLICATE KEY UPDATE", "'inactive') ON DUPLICATE KEY UPDATE", $statement);
        $statement = str_replace("status='active'", "status='inactive'", $statement);
        $db->exec($statement);
        $executed++;
    }

    $db->commit();
    $messages[] = "นำเข้าข้อมูลบุคลากรสำเร็จ {$executed} รายการ (รูปแบบ upsert)";
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    $errors[] = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>นำเข้าข้อมูลบุคลากร</title>
    <link rel="icon" type="image/png" href="../assets/images/logo.png">
    <link rel="shortcut icon" type="image/png" href="../assets/images/logo.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body class="bg-gray-100">
    <main class="max-w-3xl mx-auto p-6">
        <div class="bg-white rounded-xl shadow-lg p-6">
            <h1 class="text-2xl font-bold text-gray-800 mb-4">
                <i class="fas fa-file-import text-blue-600"></i> นำเข้าข้อมูลบุคลากร
            </h1>

            <?php if (empty($errors)): ?>
                <div class="mb-4 px-4 py-3 rounded-lg bg-green-100 text-green-800">
                    <i class="fas fa-circle-check"></i> ดำเนินการสำเร็จ
                </div>
            <?php else: ?>
                <div class="mb-4 px-4 py-3 rounded-lg bg-red-100 text-red-800">
                    <i class="fas fa-circle-exclamation"></i> เกิดข้อผิดพลาด
                </div>
            <?php endif; ?>

            <ul class="space-y-2 text-sm text-gray-700">
                <?php foreach ($messages as $message): ?>
                    <li class="px-3 py-2 rounded bg-gray-50 border border-gray-200">
                        <i class="fas fa-check text-green-600 mr-1"></i><?php echo htmlspecialchars($message); ?>
                    </li>
                <?php endforeach; ?>
                <?php foreach ($errors as $error): ?>
                    <li class="px-3 py-2 rounded bg-red-50 border border-red-200 text-red-700">
                        <i class="fas fa-xmark text-red-600 mr-1"></i><?php echo htmlspecialchars($error); ?>
                    </li>
                <?php endforeach; ?>
            </ul>

            <div class="mt-6 flex gap-3">
                <a href="index.php" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                    <i class="fas fa-arrow-left"></i> กลับหน้าแอดมิน
                </a>
                <a href="import-personnel.php" class="px-4 py-2 bg-gray-200 text-gray-800 rounded-lg hover:bg-gray-300">
                    <i class="fas fa-rotate-right"></i> รันอีกครั้ง
                </a>
            </div>
        </div>
    </main>
</body>
</html>
