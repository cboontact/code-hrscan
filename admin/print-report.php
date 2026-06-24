<?php
require_once '../config/database.php';
require_once '../config/config.php';

header('Content-Type: text/html; charset=utf-8');

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

function getPrintRemarkText($record) {
    $remark = trim((string)($record['remark'] ?? ''));
    $status = trim((string)($record['status'] ?? ''));
    $hasCheckIn = !empty($record['check_in_time']);
    $hasCheckOut = !empty($record['check_out_time']);

    if (isApprovedLateRemark($remark)) {
        if ($hasCheckIn && $hasCheckOut) {
            return $remark;
        }

        $checkInText = $hasCheckIn ? 'สแกนเข้าแล้ว' : 'ยังไม่สแกนเข้า';
        $checkOutText = $hasCheckOut ? 'สแกนออกแล้ว' : 'ยังไม่สแกนออก';

        return $remark . ' (' . $checkInText . ', ' . $checkOutText . ')';
    }

    if (!$hasCheckIn && !$hasCheckOut) {
        return $remark !== '' ? $remark : 'ไม่ลงเวลา';
    }

    if ($status === 'late' && !isApprovedLateRemark($remark)) {
        return $remark !== '' ? $remark : 'มาสาย';
    }

    if (!$hasCheckIn && $hasCheckOut) {
        return $remark !== '' ? $remark : 'ไม่สแกนเข้า แต่สแกนออก';
    }

    if ($status === 'incomplete' && $hasCheckIn && !$hasCheckOut) {
        return $remark !== '' ? $remark : 'ไม่สแกนออก';
    }

    return $remark !== '' ? $remark : '-';
}

$date = $_GET['date'] ?? date('Y-m-d');

$database = new Database();
$db = $database->getConnection();
autoMarkMissingCheckOut($db);
$timeRules = getAttendanceTimeRules($db, $date);

// ดึงข้อมูลครูทั้งหมดพร้อม attendance records
$query = "SELECT t.id as teacher_id,
                 t.first_name,
                 t.last_name,
                 t.department,
                 t.position,
                 t.citizen_id,
                 a.id,
                 a.check_in_time,
                 a.check_out_time,
                 a.status,
                 a.remark
          FROM teachers t
          LEFT JOIN attendance_records a ON t.id = a.teacher_id AND a.attendance_date = :date
          WHERE t.status = 'active'
          ORDER BY 
            CASE 
                WHEN a.check_in_time IS NOT NULL THEN 0 
                ELSE 1 
            END,
            a.check_in_time ASC,
            t.first_name ASC";

$stmt = $db->prepare($query);
$stmt->execute([':date' => $date]);
$allRecords = $stmt->fetchAll();

// แยกข้อมูลออกเป็น 3 กลุ่ม
$presentRecords = []; // คนที่ลงเวลา (ไม่มีหมายเหตุ)
$remarkRecords = []; // คนที่มีหมายเหตุ (ลา)
$absentRecords = []; // คนที่ไม่ลงเวลาและไม่มีหมายเหตุ
$remarkStats = []; // สถิติแยกตามประเภทหมายเหตุ

foreach ($allRecords as $record) {
    $category = resolveAttendanceSummaryCategory($record, $timeRules['check_in_late']);

    if ($category === 'present' || $category === 'late') {
        $presentRecords[] = $record;
    } elseif ($category === 'leave' || $category === 'other') {
        $remarkRecords[] = $record;

        $remarkType = trim((string) ($record['remark'] ?? ''));
        if ($remarkType === '') {
            $remarkType = getPrintRemarkText($record);
        }
        if (!isset($remarkStats[$remarkType])) {
            $remarkStats[$remarkType] = 0;
        }
        $remarkStats[$remarkType]++;
    } else {
        $absentRecords[] = $record;
    }
}

// เรียงข้อมูลตามตำแหน่ง
usort($remarkRecords, function($a, $b) {
    return strcmp((string)($a['position'] ?? ''), (string)($b['position'] ?? ''));
});

usort($absentRecords, function($a, $b) {
    return strcmp((string)($a['position'] ?? ''), (string)($b['position'] ?? ''));
});

// นับสถิติ
$totalTeachers = count($allRecords);
$presentCount = count($presentRecords);
$remarkCount = count($remarkRecords);
$absentCount = count($absentRecords);

// แปลงวันที่เป็นภาษาไทย
$thaiMonths = [
    '01' => 'มกราคม', '02' => 'กุมภาพันธ์', '03' => 'มีนาคม',
    '04' => 'เมษายน', '05' => 'พฤษภาคม', '06' => 'มิถุนายน',
    '07' => 'กรกฎาคม', '08' => 'สิงหาคม', '09' => 'กันยายน',
    '10' => 'ตุลาคม', '11' => 'พฤศจิกายน', '12' => 'ธันวาคม'
];

$dateParts = explode('-', $date);
$day = (int)$dateParts[2];
$month = $thaiMonths[$dateParts[1]];
$year = (int)$dateParts[0] + 543;
$thaiDate = "$day $month $year";
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รายงานการลงเวลาประจำวัน - <?php echo $thaiDate; ?></title>
    <link rel="icon" type="image/png" href="../assets/images/logo.png">
    <link rel="shortcut icon" type="image/png" href="../assets/images/logo.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        @media print {
            @page {
                size: A4;
                margin: 1cm;
            }
            .no-print {
                display: none;
            }
            body {
                font-size: 14px;
                padding: 0;
            }
            table {
                table-layout: fixed;
                margin-top: 8px;
            }
            thead {
                display: table-header-group;
            }
            tfoot {
                display: table-footer-group;
            }
            table th,
            table td {
                padding: 4px 3px;
                font-size: 13px;
            }
            tr,
            img,
            h3 {
                page-break-inside: avoid;
                break-inside: avoid;
            }
            h3 {
                page-break-after: avoid;
                break-after: avoid;
                margin-top: 0;
            }
            .header,
            .summary,
            .signature {
                page-break-inside: avoid;
                break-inside: avoid;
            }
            .col-no { width: 7%; }
            .col-name { width: 37%; }
            .col-position { width: 19%; }
            .col-time { width: 7%; }
            .col-remark { width: 23%; }
            .col-remark-only { width: 37%; }
            .time-cell {
                white-space: nowrap;
                font-size: 12px;
                letter-spacing: -0.1px;
            }

            body.is-windows {
                font-size: 15px;
            }

            body.is-windows table th,
            body.is-windows table td {
                font-size: 14px;
            }

            body.is-windows .time-cell {
                font-size: 13px;
            }
        }
        
        body {
            font-family: 'Sarabun', 'TH SarabunPSK', 'Leelawadee UI', 'Tahoma', sans-serif;
            font-size: 15px;
            line-height: 1.4;
            margin: 0;
            padding: 20px;
        }
        
        .header {
            text-align: center;
            margin-bottom: 20px;
        }
        
        .header h1 {
            font-size: 20px;
            font-weight: bold;
            margin: 5px 0;
        }
        
        .header h2 {
            font-size: 18px;
            font-weight: normal;
            margin: 5px 0;
        }

        body.is-windows .header h1 {
            font-size: 21px;
        }

        body.is-windows .header h2 {
            font-size: 18px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            table-layout: fixed;
        }
        
        table th,
        table td {
            border: 1px solid #000;
            padding: 6px 5px;
            text-align: left;
            vertical-align: top;
            overflow-wrap: anywhere;
            word-break: break-word;
            white-space: normal;
        }
        
        table th {
            background-color: #f0f0f0;
            font-weight: bold;
            text-align: center;
        }
        
        .center {
            text-align: center;
        }

        .col-no { width: 7%; }
        .col-name { width: 35%; }
        .col-position { width: 18%; }
        .col-time { width: 8%; }
        .col-remark { width: 24%; }
        .col-remark-only { width: 40%; }
        
        .summary {
            margin: 20px 0;
            padding: 10px;
            border: 1px solid #000;
        }
        
        .signature {
            margin-top: 0;
            padding-top: 28px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 24px;
            page-break-inside: avoid;
            break-inside: avoid;
        }
        
        .signature-box {
            text-align: center;
            width: 50%;
            page-break-inside: avoid;
            break-inside: avoid;
        }

        .signature-box-inner {
            width: 260px;
            margin: 0 auto;
            text-align: left;
        }

        .signature-entry {
            width: 100%;
            margin: 0;
            text-align: left;
        }

        .signature-name {
            margin: 10px 0 0;
            text-align: center;
        }

        .signature-role {
            margin: 8px 0 0;
            text-align: center;
        }

        .signature-detail {
            display: inline-block;
            margin-top: 10px;
            margin-left: 72px;
            text-align: center;
        }

        
        .signature-line {
            margin-top: 30px;
            padding-top: 5px;
            text-align: center;
        }

        .signature-line-date,
        .signature-ack {
            width: 220px;
            margin: 0 auto;
            text-align: left;
        }
        
        .print-btn {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 10px 14px;
            background-color: #16a34a;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }
        
        .print-btn:hover {
            background-color: #15803d;
        }
        
        .footer {
            text-align: right;
            margin-top: 20px;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <button class="print-btn no-print" onclick="window.print()">
        <i class="fas fa-print"></i> พิมพ์รายงาน
    </button>

    <div style="text-align: center; margin-bottom: 8px;">
        <img src="../assets/images/logo.png" alt="โลโก้โรงเรียน" style="height: 100px; margin-bottom: 4px;">
    </div>

    <div class="header">
        <h1>รายงานการลงเวลาของข้าราชการและบุคลากร โรงเรียนจอมทอง</h1>
        <h2>ประจำวันที่ <?php echo $thaiDate; ?></h2>
    </div>

    <!-- ตารางที่ 1: รายชื่อผู้มาลงเวลาปฏิบัติราชการ -->
    <h3 style="margin-top: 20px; margin-bottom: 10px;">รายชื่อผู้มาลงเวลาปฏิบัติราชการ</h3>
    <table>
        <colgroup>
            <col class="col-no">
            <col class="col-name">
            <col class="col-position">
            <col class="col-time">
            <col class="col-time">
            <col class="col-remark">
        </colgroup>
        <thead>
            <tr>
                <th>ลำดับที่</th>
                <th>ชื่อ - สกุล</th>
                <th class="center">ตำแหน่ง</th>
                <th>เวลามา</th>
                <th>เวลากลับ</th>
                <th class="center">หมายเหตุ</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            if (empty($presentRecords)) {
                echo '<tr><td colspan="6" class="center">ไม่มีข้อมูล</td></tr>';
            } else {
                $no = 1;
                foreach ($presentRecords as $record): 
                    $checkInTime = $record['check_in_time'] ? date('H:i', strtotime($record['check_in_time'])) . ' น.' : '-';
                    $checkOutTime = $record['check_out_time'] ? date('H:i', strtotime($record['check_out_time'])) . ' น.' : '-';
                    $remarkText = getPrintRemarkText($record);
            ?>
            <tr>
                <td class="center"><?php echo $no++; ?></td>
                <td><?php echo $record['first_name'] . ' ' . $record['last_name']; ?></td>
                <td class="center"><?php echo $record['position'] ?: '-'; ?></td>
                <td class="center time-cell"><?php echo $checkInTime; ?></td>
                <td class="center time-cell"><?php echo $checkOutTime; ?></td>
                <td class="center"><?php echo htmlspecialchars($remarkText); ?></td>
            </tr>
            <?php 
                endforeach;
            }
            ?>
        </tbody>
    </table>

    <!-- ตารางที่ 2: รายชื่อผู้ลา/ไม่มาปฏิบัติราชการ/ไม่ลงเวลา -->
    <h3 style="margin-top: 30px; margin-bottom: 10px;">รายชื่อผู้ลา/ไม่มาปฏิบัติราชการ/ไม่ลงเวลา</h3>
    <table>
        <colgroup>
            <col class="col-no">
            <col class="col-name">
            <col class="col-position">
            <col class="col-remark-only">
        </colgroup>
        <thead>
            <tr>
                <th>ลำดับที่</th>
                <th>ชื่อ - สกุล</th>
                <th class="center">ตำแหน่ง</th>
                <th class="center">หมายเหตุ</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $combinedAbsent = array_merge($remarkRecords, $absentRecords);
            if (empty($combinedAbsent)) {
                echo '<tr><td colspan="4" class="center">ไม่มีข้อมูล</td></tr>';
            } else {
                $no = 1;
                foreach ($combinedAbsent as $record): 
                    $remarkText = getPrintRemarkText($record);
            ?>
            <tr>
                <td class="center"><?php echo $no++; ?></td>
                <td><?php echo $record['first_name'] . ' ' . $record['last_name']; ?></td>
                <td class="center"><?php echo $record['position'] ?: '-'; ?></td>
                <td class="center"><?php echo htmlspecialchars($remarkText); ?></td>
            </tr>
            <?php 
                endforeach;
            }
            ?>
        </tbody>
    </table>

    <div class="summary">
        <strong>สรุป:</strong> ข้าราชการและบุคลากรทั้งสิ้น <?php echo $totalTeachers; ?> คน<br>
        - มาลงเวลาปฏิบัติราชการ <?php echo $presentCount; ?> คน<br>
        <?php if (!empty($remarkStats)): ?>
        <?php foreach ($remarkStats as $type => $count): ?>
        - <?php echo $type . ' ' . $count . ' คน'; ?><br>
        <?php endforeach; ?>
        <?php endif; ?>
        <?php if ($absentCount > 0): ?>
        - ไม่มาลงเวลาปฏิบัติราชการ <?php echo $absentCount; ?> คน<br>
        <?php endif; ?>
    </div>

    <div class="signature">
        <div class="signature-box">
            <div class="signature-box-inner">
                <p class="signature-entry">ลงชื่อ</p>
                <div class="signature-detail">
                    <p class="signature-name">( นางสาวสุวภัทร ใจเอื้อ )</p>
                    <p class="signature-role">ผู้บันทึกรายงาน</p>
                </div>
            </div>
        </div>
        
        <div class="signature-box">
            <div class="signature-box-inner">
                <p class="signature-entry">ลงชื่อ</p>
                <div class="signature-detail">
                    <p class="signature-name">(นางเมธาวี ไชยแก้ว)</p>
                    <p class="signature-role">ผู้ตรวจทานรายงาน</p>
                </div>
            </div>
            <div class="signature-line">
                <p>เสนอผู้อำนวยการโรงเรียนทราบ</p>
                <p class="signature-ack"><span style="display: inline-block; width: 20px; height: 20px; border: 2px solid #000; margin-right: 10px; vertical-align: middle;"></span> ทราบ</p>
                <p>&nbsp;</p>
                <p>&nbsp;</p>
                <p>(นางสาววัลภมาภรค์ อาจนาเสียว)</p>
                <p class="signature-line-date">วันที่ ..........................................</p>
            </div>
        </div>
    </div>
<script>
    if (/Windows/i.test(navigator.userAgent)) {
        document.body.classList.add('is-windows');
    }
</script>
</body>
</html>
