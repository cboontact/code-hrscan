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

$database = new Database();
$db = $database->getConnection();

$messages = [];
$errors = [];

function columnExists($db, $tableName, $columnName) {
    $sql = "SELECT COUNT(*) AS total
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
              AND COLUMN_NAME = :column_name";
    $stmt = $db->prepare($sql);
    $stmt->execute([
        ':table_name' => $tableName,
        ':column_name' => $columnName
    ]);
    return (int)$stmt->fetchColumn() > 0;
}

try {
    $db->beginTransaction();

    $columnsToAdd = [
        'position' => "ALTER TABLE teachers ADD COLUMN position VARCHAR(120) NULL AFTER department",
        'personnel_type' => "ALTER TABLE teachers ADD COLUMN personnel_type VARCHAR(120) NULL AFTER position",
        'gender' => "ALTER TABLE teachers ADD COLUMN gender VARCHAR(20) NULL AFTER personnel_type",
        'blood_type' => "ALTER TABLE teachers ADD COLUMN blood_type VARCHAR(20) NULL AFTER gender"
    ];

    foreach ($columnsToAdd as $columnName => $sql) {
        if (columnExists($db, 'teachers', $columnName)) {
            $messages[] = "คอลัมน์ teachers.{$columnName} มีอยู่แล้ว";
            continue;
        }

        $db->exec($sql);
        $messages[] = "เพิ่มคอลัมน์ teachers.{$columnName} สำเร็จ";
    }

    $db->exec("ALTER TABLE teachers MODIFY status ENUM('active','inactive') DEFAULT 'inactive'");
    $messages[] = "ตั้งค่าเริ่มต้น teachers.status = 'inactive' เรียบร้อย";
    $db->exec("ALTER TABLE teachers MODIFY blood_type VARCHAR(20) NULL");
    $messages[] = "ปรับขนาด teachers.blood_type เป็น VARCHAR(20) เรียบร้อย";

    $db->commit();
    $success = true;
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    $success = false;
    $errors[] = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>อัปเดตโครงสร้างฐานข้อมูล</title>
    <link rel="icon" type="image/png" href="../assets/images/logo.png">
    <link rel="shortcut icon" type="image/png" href="../assets/images/logo.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body class="bg-gray-100">
    <main class="max-w-3xl mx-auto p-6">
        <div class="bg-white rounded-xl shadow-lg p-6">
            <h1 class="text-2xl font-bold text-gray-800 mb-4">
                <i class="fas fa-database text-blue-600"></i> อัปเดตโครงสร้างฐานข้อมูล
            </h1>

            <?php if ($success): ?>
                <div class="mb-4 px-4 py-3 rounded-lg bg-green-100 text-green-800">
                    <i class="fas fa-circle-check"></i> อัปเดตโครงสร้างเสร็จเรียบร้อย
                </div>
            <?php else: ?>
                <div class="mb-4 px-4 py-3 rounded-lg bg-red-100 text-red-800">
                    <i class="fas fa-circle-exclamation"></i> อัปเดตไม่สำเร็จ
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
                <a href="run-db-migration.php" class="px-4 py-2 bg-gray-200 text-gray-800 rounded-lg hover:bg-gray-300">
                    <i class="fas fa-rotate-right"></i> รันอีกครั้ง
                </a>
            </div>
        </div>
    </main>
</body>
</html>
