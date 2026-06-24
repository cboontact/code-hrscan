<?php
require_once '../config/database.php';
require_once '../config/config.php';

header('Content-Type: text/html; charset=utf-8');

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-t');
$search = trim($_GET['search'] ?? '');
$department = trim($_GET['department'] ?? '');

$database = new Database();
$db = $database->getConnection();

$query = "SELECT 
            t.id,
            t.first_name,
            t.last_name,
            t.department,
            t.position
          FROM teachers t
          WHERE t.status = 'active'";

$params = [];
if ($search !== '') {
    $query .= " AND (
        CONCAT(t.first_name, ' ', t.last_name) LIKE :search
        OR t.first_name LIKE :search
        OR t.last_name LIKE :search
        OR t.position LIKE :search
        OR t.department LIKE :search
    )";
    $params[':search'] = '%' . $search . '%';
}

if ($department !== '') {
    $query .= " AND t.department = :department";
    $params[':department'] = $department;
}

$query .= "
          ORDER BY
            CASE
                WHEN t.position IS NULL OR TRIM(t.position) = '' THEN 1
                ELSE 0
            END,
            t.position ASC,
            t.department ASC,
            t.first_name ASC,
            t.last_name ASC";
$stmt = $db->prepare($query);
$stmt->execute($params);
$teachers = $stmt->fetchAll();

$summaryMap = [];
foreach ($teachers as $teacher) {
    $teacherId = (int)$teacher['id'];
    $summaryMap[$teacherId] = [
        'present_count' => 0,
        'late_count' => 0,
        'leave_count' => 0,
        'absent_count' => 0,
        'other_count' => 0,
        'remarks' => []
    ];
}

$detailQuery = "SELECT teacher_id, attendance_date, status, remark, check_in_time, check_out_time
                FROM attendance_records
                WHERE attendance_date BETWEEN :start_date AND :end_date";
$detailStmt = $db->prepare($detailQuery);
$detailStmt->execute([
    ':start_date' => $startDate,
    ':end_date' => $endDate
]);
$detailRows = $detailStmt->fetchAll();

foreach ($detailRows as $row) {
    $teacherId = (int)$row['teacher_id'];
    if (!isset($summaryMap[$teacherId])) {
        continue;
    }

    $remark = trim((string)($row['remark'] ?? ''));
    if ($remark !== '') {
        $summaryMap[$teacherId]['remarks'][] = $remark;
    }
    $timeRules = getAttendanceTimeRules($db, $row['attendance_date']);
    $category = resolveAttendanceSummaryCategory($row, $timeRules['check_in_late']);

    if ($category === 'present') {
        $summaryMap[$teacherId]['present_count']++;
    } elseif ($category === 'late') {
        $summaryMap[$teacherId]['late_count']++;
    } elseif ($category === 'leave') {
        $summaryMap[$teacherId]['leave_count']++;
    } elseif ($category === 'absent') {
        $summaryMap[$teacherId]['absent_count']++;
    } else {
        $summaryMap[$teacherId]['other_count']++;
    }
}

$rows = [];
foreach ($teachers as $teacher) {
    $teacherId = (int)$teacher['id'];
    $counts = $summaryMap[$teacherId];
    $total = $counts['present_count'] + $counts['late_count'] + $counts['leave_count'] + $counts['absent_count'] + $counts['other_count'];
    $remarks = array_values(array_unique(array_filter($counts['remarks'])));
    $rows[] = array_merge($teacher, $counts, [
        'total' => $total,
        'remark_summary' => implode(', ', $remarks)
    ]);
}

$thaiMonths = [
    '01' => 'ม.ค.', '02' => 'ก.พ.', '03' => 'มี.ค.', '04' => 'เม.ย.',
    '05' => 'พ.ค.', '06' => 'มิ.ย.', '07' => 'ก.ค.', '08' => 'ส.ค.',
    '09' => 'ก.ย.', '10' => 'ต.ค.', '11' => 'พ.ย.', '12' => 'ธ.ค.'
];

$formatThaiDate = function($date) use ($thaiMonths) {
    $parts = explode('-', $date);
    if (count($parts) !== 3) return $date;
    $day = (int)$parts[2];
    $month = $thaiMonths[$parts[1]] ?? $parts[1];
    $year = (int)$parts[0] + 543;
    return "{$day} {$month} {$year}";
};

$thaiStartDate = $formatThaiDate($startDate);
$thaiEndDate = $formatThaiDate($endDate);
$departmentLabel = $department !== '' ? $department : 'ทั้งหมด';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รายงานสรุปการมา-สาย-ลา-ขาด-อื่น ๆ</title>
    <link rel="icon" type="image/png" href="../assets/images/logo.png">
    <link rel="shortcut icon" type="image/png" href="../assets/images/logo.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        @media print {
            @page { size: A4; margin: 1cm; }
            .no-print { display: none; }
            body { font-size: 14px; }
            table { margin-top: 8px; }
            thead { display: table-header-group; }
            tfoot { display: table-footer-group; }
            th, td { font-size: 13px; }
            tr,
            img {
                page-break-inside: avoid;
                break-inside: avoid;
            }
            .header,
            .logo-wrap {
                page-break-inside: avoid;
                break-inside: avoid;
            }

            body.is-windows { font-size: 15px; }
            body.is-windows th,
            body.is-windows td { font-size: 14px; }
        }
        body { font-family: 'Sarabun', 'TH SarabunPSK', 'Leelawadee UI', 'Tahoma', sans-serif; margin: 0; padding: 20px; font-size: 15px; }
        .header { text-align: center; margin-bottom: 16px; }
        .header h1 { margin: 0 0 6px 0; font-size: 24px; }
        .header p { margin: 0; font-size: 16px; }
        body.is-windows .header h1 { font-size: 25px; }
        body.is-windows .header p { font-size: 16px; }
        .logo-wrap { text-align: center; margin-bottom: 10px; }
        .logo-wrap img { height: 90px; object-fit: contain; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; table-layout: fixed; }
        th, td {
            border: 1px solid #000;
            padding: 6px 5px;
            vertical-align: top;
            overflow-wrap: anywhere;
            word-break: break-word;
            white-space: normal;
            font-size: 14px;
        }
        th { background: #f3f4f6; text-align: center; }
        .left { text-align: left; }
        .center { text-align: center; }
        .col-no { width: 6%; }
        .col-name { width: 17%; }
        .col-position { width: 14%; }
        .col-count { width: 7%; }
        .col-total { width: 8%; }
        .print-btn {
            position: fixed; top: 16px; right: 16px; border: 0; border-radius: 8px;
            padding: 10px 14px; background: #16a34a; color: #fff; font-size: 15px; cursor: pointer;
        }
        .footer { margin-top: 16px; text-align: right; font-size: 12px; }
    </style>
</head>
<body>
    <button class="print-btn no-print" onclick="window.print()">
        <i class="fas fa-print"></i> พิมพ์รายงาน
    </button>

    <div class="logo-wrap">
        <img src="../assets/images/logo.png" alt="โลโก้โรงเรียน">
    </div>

    <div class="header">
        <h1>รายงานสรุปข้อมูลการมา-สาย-ลา-ขาด-อื่น ๆ</h1>
        <p>ช่วงวันที่ <?php echo htmlspecialchars($thaiStartDate); ?> ถึง <?php echo htmlspecialchars($thaiEndDate); ?></p>
        <p>กลุ่มสาระ / แผนก: <?php echo htmlspecialchars($departmentLabel); ?></p>
        <?php if ($search !== ''): ?>
            <p>คำค้นหา: <?php echo htmlspecialchars($search); ?></p>
        <?php endif; ?>
    </div>

    <table>
        <colgroup>
            <col class="col-no">
            <col class="col-name">
            <col class="col-position">
            <col class="col-count">
            <col class="col-count">
            <col class="col-count">
            <col class="col-count">
            <col class="col-count">
            <col class="col-total">
        </colgroup>
        <thead>
            <tr>
                <th>ลำดับ</th>
                <th>ชื่อ-นามสกุล</th>
                <th>ตำแหน่ง</th>
                <th>มาตรงเวลา</th>
                <th>มาสาย</th>
                <th>ลา</th>
                <th>ขาด</th>
                <th>อื่น ๆ</th>
                <th>รวม</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="9" class="center">ไม่พบข้อมูล</td></tr>
            <?php else: ?>
                <?php $i = 1; foreach ($rows as $row): ?>
                    <tr>
                        <td class="center"><?php echo $i++; ?></td>
                        <td class="left"><?php echo htmlspecialchars($row['first_name'] . ' ' . $row['last_name']); ?></td>
                        <td class="center"><?php echo htmlspecialchars(trim((string)($row['position'] ?? '')) !== '' ? $row['position'] : '-'); ?></td>
                        <td class="center"><?php echo (int)$row['present_count']; ?></td>
                        <td class="center"><?php echo (int)$row['late_count']; ?></td>
                        <td class="center"><?php echo (int)$row['leave_count']; ?></td>
                        <td class="center"><?php echo (int)$row['absent_count']; ?></td>
                        <td class="center"><?php echo (int)$row['other_count']; ?></td>
                        <td class="center"><?php echo (int)$row['total']; ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="footer">
        ออกรายงานเมื่อ: <?php echo date('d/m/Y H:i'); ?>
    </div>
<script>
    if (/Windows/i.test(navigator.userAgent)) {
        document.body.classList.add('is-windows');
    }
</script>
</body>
</html>
