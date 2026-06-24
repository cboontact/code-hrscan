<?php
session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
if (!isset($_SESSION['admin_id'])) {
    header('Location: index.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ประวัติการสแกนบัตร</title>
    <link rel="icon" type="image/png" href="../assets/images/logo.png">
    <link rel="shortcut icon" type="image/png" href="../assets/images/logo.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body { font-family: 'Sarabun', sans-serif; }
        .spinner-dark {
            width: 40px;
            height: 40px;
            border: 4px solid #e5e7eb;
            border-top: 4px solid #2563eb;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body class="bg-gray-100">
    <nav class="bg-white shadow-sm border-b border-gray-200">
        <div class="container mx-auto px-4 py-4 flex items-center justify-between">
            <div>
                <h1 class="text-xl font-bold text-gray-800">
                    <i class="fas fa-id-card text-blue-600"></i> ประวัติการสแกนบัตร
                </h1>
                <p class="text-sm text-gray-500">ดูรายการสแกนเข้าและสแกนออกย้อนหลัง</p>
            </div>
            <div class="flex items-center gap-2">
                <a href="index.php" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition-colors font-semibold">
                    <i class="fas fa-arrow-left"></i> กลับหน้าแอดมิน
                </a>
                <a href="logs.php" class="px-4 py-2 bg-blue-100 text-blue-700 rounded-lg hover:bg-blue-200 transition-colors font-semibold">
                    <i class="fas fa-history"></i> ดู admin log
                </a>
                <button id="logout-btn" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors font-semibold">
                    <i class="fas fa-sign-out-alt"></i> ออกจากระบบ
                </button>
            </div>
        </div>
    </nav>

    <main class="container mx-auto px-4 py-6">
        <div class="bg-white rounded-xl shadow-lg p-6 mb-4">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
                <div class="lg:col-span-2">
                    <label class="block text-gray-700 font-semibold mb-2">
                        <i class="fas fa-search"></i> ค้นหา
                    </label>
                    <input type="text"
                           id="activity-log-search"
                           class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:outline-none focus:border-blue-500"
                           placeholder="ค้นหาจากรายละเอียด, IP, user agent...">
                </div>

                <div>
                    <label class="block text-gray-700 font-semibold mb-2">
                        <i class="fas fa-tasks"></i> เหตุการณ์
                    </label>
                    <select id="activity-log-action"
                            class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                        <option value="">ทั้งหมด</option>
                    </select>
                </div>

                <div>
                    <label class="block text-gray-700 font-semibold mb-2">
                        <i class="fas fa-calendar-day"></i> ตั้งแต่วันที่
                    </label>
                    <input type="date"
                           id="activity-log-date-from"
                           class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>

                <div>
                    <label class="block text-gray-700 font-semibold mb-2">
                        <i class="fas fa-calendar-day"></i> ถึงวันที่
                    </label>
                    <input type="date"
                           id="activity-log-date-to"
                           class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>
            </div>

            <div class="mt-4 flex justify-end">
                <button id="activity-log-refresh-btn"
                        class="px-6 py-2 bg-gradient-to-r from-blue-600 to-indigo-600 text-white rounded-lg hover:from-blue-700 hover:to-indigo-700 transition-all font-semibold shadow-lg">
                    <i class="fas fa-rotate-right"></i> โหลดข้อมูล
                </button>
            </div>
        </div>

        <div id="activity-logs-loading" class="text-center py-12 bg-white rounded-xl shadow-lg">
            <div class="spinner-dark mx-auto mb-4"></div>
            <p class="text-gray-600">กำลังโหลดข้อมูล...</p>
        </div>

        <div id="activity-logs-content" class="hidden">
            <div class="bg-white rounded-xl shadow-lg overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gradient-to-r from-blue-600 to-indigo-600 text-white">
                            <tr>
                                <th class="px-4 py-3 text-left font-semibold">เวลา</th>
                                <th class="px-4 py-3 text-left font-semibold">เหตุการณ์</th>
                                <th class="px-4 py-3 text-left font-semibold">รายละเอียด</th>
                                <th class="px-4 py-3 text-left font-semibold">IP</th>
                                <th class="px-4 py-3 text-left font-semibold">User Agent</th>
                            </tr>
                        </thead>
                        <tbody id="activity-logs-table-body"></tbody>
                    </table>
                </div>
                <div id="activity-logs-pagination" class="px-6 py-4 bg-gray-50 border-t border-gray-200"></div>
            </div>
        </div>
    </main>

    <script src="js/activity-logs.js?v=<?php echo filemtime(__DIR__ . '/js/activity-logs.js'); ?>"></script>
</body>
</html>
