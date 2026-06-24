<?php
session_start();
$isAuthenticated = isset($_SESSION['admin_id']);
$isSuperAdmin = isset($_SESSION['admin_role']) && strtolower((string)$_SESSION['admin_role']) === 'super_admin';

// ป้องกันแคชหน้าผู้ดูแล เพื่อลดโอกาสเห็นข้อมูลจากหน้า cache เก่า
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ระบบจัดการ - โรงเรียนจอมทอง</title>
    <link rel="icon" type="image/png" href="../assets/images/logo.png">
    <link rel="shortcut icon" type="image/png" href="../assets/images/logo.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@300;400;500;600;700&family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            font-family: 'Noto Sans Thai', 'Sarabun', 'Leelawadee UI', 'Tahoma', sans-serif;
        }

        .tab-btn,
        #print-report-btn,
        #print-summary-btn {
            font-size: 15px;
            line-height: 1.5;
        }
        
        .spinner {
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top: 3px solid white;
            width: 20px;
            height: 20px;
            animation: spin 0.8s linear infinite;
        }

        .spinner-dark {
            border: 3px solid rgba(0, 0, 0, 0.1);
            border-top: 3px solid #3b82f6;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .notification {
            animation: slideInRight 0.4s ease-out;
        }

        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        .fade-in {
            animation: fadeIn 0.3s ease-in;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .photo-crop-area {
            position: relative;
            width: 320px;
            height: 320px;
            border-radius: 9999px;
            overflow: hidden;
            background: #e5e7eb;
            border: 3px solid #3b82f6;
            box-shadow: 0 10px 25px rgba(59, 130, 246, 0.25);
        }

        .photo-crop-image {
            position: absolute;
            left: 50%;
            top: 50%;
            max-width: none;
            user-select: none;
            pointer-events: none;
        }

        [id$="-modal"] form,
        [id$="-modal"] form > div {
            min-width: 0;
        }

        [id$="-modal"] input:not([type="hidden"]):not([type="checkbox"]):not([type="radio"]):not([type="range"]),
        [id$="-modal"] select,
        [id$="-modal"] textarea {
            box-sizing: border-box;
            max-width: 100%;
            min-width: 0;
        }
    </style>
</head>
<body class="bg-gray-50 min-h-screen flex flex-col">
    <!-- Notification Container -->
    <div id="notification-container" class="fixed bottom-4 right-4 space-y-3 z-[9999]"></div>

    <div id="login-page"
         class="min-h-screen bg-gradient-to-br from-blue-50 to-indigo-100 flex items-center justify-center px-4<?php echo $isAuthenticated ? ' hidden' : ''; ?>"
         <?php echo $isAuthenticated ? 'style="display:none;"' : ''; ?>>
        <div class="max-w-md w-full">
            <div class="text-center mb-8">
                <img src="../assets/images/logo.png" 
                     alt="โรงเรียนจอมทอง" 
                     class="mx-auto mb-4 w-24 h-24 object-contain rounded-full bg-white p-2 shadow-lg"
                     onerror="this.src='https://ui-avatars.com/api/?name=โรงเรียนจอมทอง&size=200&background=3b82f6&color=fff'">
                <h1 class="text-3xl font-bold text-gray-800 mb-2">
                    <i class="fas fa-user-shield text-blue-600"></i> ระบบจัดการ
                </h1>
                <p class="text-gray-600">โรงเรียนจอมทอง</p>
            </div>

            <div class="bg-white rounded-2xl shadow-2xl p-8">
                <h2 class="text-2xl font-semibold text-gray-800 mb-6 text-center">
                    <i class="fas fa-sign-in-alt"></i> เข้าสู่ระบบ
                </h2>
                
                <form id="login-form" class="space-y-4">
                    <div>
                        <label class="block text-gray-700 font-semibold mb-2">
                            <i class="fas fa-user"></i> ชื่อผู้ใช้
                        </label>
                        <input type="text" 
                               id="username" 
                               class="w-full px-4 py-3 border-2 border-gray-300 rounded-lg focus:outline-none focus:border-blue-500 transition-colors"
                               placeholder="กรอกชื่อผู้ใช้"
                               required>
                    </div>

                    <div>
                        <label class="block text-gray-700 font-semibold mb-2">
                            <i class="fas fa-lock"></i> รหัสผ่าน
                        </label>
                        <input type="password" 
                               id="password" 
                               class="w-full px-4 py-3 border-2 border-gray-300 rounded-lg focus:outline-none focus:border-blue-500 transition-colors"
                               placeholder="กรอกรหัสผ่าน"
                               required>
                    </div>

                    <button type="submit" 
                            id="login-btn"
                            class="w-full px-6 py-3 bg-gradient-to-r from-blue-600 to-indigo-600 text-white rounded-lg hover:from-blue-700 hover:to-indigo-700 transition-all font-semibold shadow-lg hover:shadow-xl disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2">
                        <i class="fas fa-sign-in-alt"></i>
                        <span>เข้าสู่ระบบ</span>
                    </button>
                </form>

                <div class="mt-6 pt-6 border-t border-gray-200">
                    <a href="../" class="block text-center text-blue-600 hover:text-blue-700 font-semibold">
                        <i class="fas fa-arrow-left"></i> กลับหน้าหลัก
                    </a>
                </div>

            </div>
        </div>
    </div>

    <div id="dashboard-page"
         class="flex-grow flex flex-col<?php echo $isAuthenticated ? '' : ' hidden'; ?>"
         <?php echo $isAuthenticated ? '' : 'style="display:none;"'; ?>>
        <nav class="bg-white shadow-lg sticky top-0 z-40">
            <div class="container mx-auto px-4">
                <div class="flex items-center justify-between h-16">
                    <div class="flex items-center gap-4">
                        <img src="../assets/images/logo.png" 
                             alt="โรงเรียนจอมทอง" 
                             class="w-10 h-10 object-contain"
                             onerror="this.src='https://ui-avatars.com/api/?name=โรงเรียนจอมทอง&size=80&background=3b82f6&color=fff'">
                        <div>
                            <h1 class="text-lg font-bold text-gray-800">
                                <i class="fas fa-clock text-blue-600"></i> ระบบลงเวลาราชการ ข้าราชการครูและบุคลากร
                            </h1>
                            <p class="text-xs text-gray-500">โรงเรียนจอมทอง</p>
                        </div>
                    </div>

                    <div class="flex items-center gap-4">
                        <div class="hidden md:block text-right">
                            <p class="text-sm font-semibold text-gray-800" id="admin-name"></p>
                            <p class="text-xs text-gray-500" id="admin-role"></p>
                        </div>
                        <?php if ($isSuperAdmin): ?>
                        <div class="hidden lg:flex items-center gap-2">
                            <a href="logs.php"
                               class="px-3 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors font-semibold text-sm whitespace-nowrap">
                                <i class="fas fa-history"></i>
                                <span class="ml-1">admin log</span>
                            </a>
                        </div>
                        <?php endif; ?>
                        <button id="logout-btn" 
                                class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors font-semibold shadow hover:shadow-lg">
                            <i class="fas fa-sign-out-alt"></i>
                            <span class="hidden md:inline ml-2">ออกจากระบบ</span>
                        </button>
                    </div>
                </div>
            </div>
        </nav>

        <div class="container mx-auto px-4 py-6">
            <div class="mb-6">
                <div class="flex flex-wrap gap-2 border-b border-gray-200">
                    <button class="tab-btn px-6 py-3 font-semibold text-blue-600 border-b-2 border-blue-600" data-tab="dashboard">
                        <i class="fas fa-chart-line"></i> แดชบอร์ด
                    </button>
                    <button class="tab-btn px-6 py-3 font-semibold text-gray-600 hover:text-blue-600 transition-colors" data-tab="attendance">
                        <i class="fas fa-clipboard-list"></i> บันทึกการลงเวลา
                    </button>
                    <button class="tab-btn px-6 py-3 font-semibold text-gray-600 hover:text-blue-600 transition-colors" data-tab="summary">
                        <i class="fas fa-chart-bar"></i> สรุปข้อมูล
                    </button>
                    <button class="tab-btn px-6 py-3 font-semibold text-gray-600 hover:text-blue-600 transition-colors" data-tab="teachers">
                        <i class="fas fa-users"></i> จัดการบุคลากร
                    </button>
                    <button class="tab-btn px-6 py-3 font-semibold text-gray-600 hover:text-blue-600 transition-colors" data-tab="settings">
                        <i class="fas fa-cog"></i> การตั้งค่า
                    </button>
                </div>
            </div>

            <div id="tab-dashboard" class="tab-content">
                <div class="mb-6 flex flex-wrap gap-4 items-center justify-between">
                    <h2 class="text-2xl font-bold text-gray-800">
                        <i class="fas fa-chart-line text-blue-600"></i> สรุปภาพรวม
                    </h2>
                    <div class="flex items-center gap-2">
                        <label class="text-gray-700 font-semibold">
                            <i class="far fa-calendar-alt"></i> วันที่:
                        </label>
                        <div class="relative">
                            <input type="text"
                                   id="dashboard-date-display"
                                   class="px-4 py-2 pr-11 border-2 border-gray-300 rounded-lg bg-white text-gray-800 focus:outline-none"
                                   placeholder="เลือกวันที่"
                                   readonly>
                            <button type="button"
                                    id="dashboard-date-trigger"
                                    class="absolute right-2 top-1/2 -translate-y-1/2 text-blue-600 hover:text-blue-700">
                                <i class="fas fa-calendar-alt"></i>
                            </button>
                            <input type="date"
                                   id="dashboard-date"
                                   class="absolute opacity-0 pointer-events-none w-0 h-0"
                                   tabindex="-1"
                                   aria-hidden="true"
                                   value="">
                        </div>
                    </div>
                </div>

                <div id="stats-loading" class="text-center py-12">
                    <div class="spinner-dark mx-auto mb-4"></div>
                    <p class="text-gray-600">กำลังโหลดข้อมูล...</p>
                </div>

                <div id="stats-content" class="hidden">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-6 mb-6">
                        <div class="bg-gradient-to-br from-blue-500 to-blue-600 rounded-xl shadow-lg p-6 text-white">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-blue-100 text-sm font-semibold">บุคลากรทั้งหมด</p>
                                    <p class="text-3xl font-bold mt-2" id="stat-total-teachers">0</p>
                                </div>
                                <div class="bg-white bg-opacity-20 rounded-full p-4">
                                    <i class="fas fa-users text-3xl"></i>
                                </div>
                            </div>
                        </div>

                        <div class="bg-gradient-to-br from-green-500 to-green-600 rounded-xl shadow-lg p-6 text-white">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-green-100 text-sm font-semibold">ลงเวลาแล้ว</p>
                                    <p class="text-3xl font-bold mt-2" id="stat-checked-in">0</p>
                                </div>
                                <div class="bg-white bg-opacity-20 rounded-full p-4">
                                    <i class="fas fa-check-circle text-3xl"></i>
                                </div>
                            </div>
                        </div>

                        <div class="bg-gradient-to-br from-yellow-500 to-yellow-600 rounded-xl shadow-lg p-6 text-white">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-yellow-100 text-sm font-semibold">มาสาย</p>
                                    <p class="text-3xl font-bold mt-2" id="stat-late">0</p>
                                </div>
                                <div class="bg-white bg-opacity-20 rounded-full p-4">
                                    <i class="fas fa-clock text-3xl"></i>
                                </div>
                            </div>
                        </div>

                        <div class="bg-gradient-to-br from-red-500 to-red-600 rounded-xl shadow-lg p-6 text-white">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-red-100 text-sm font-semibold">ยังไม่ลงเวลา</p>
                                    <p class="text-3xl font-bold mt-2" id="stat-not-checked">0</p>
                                </div>
                                <div class="bg-white bg-opacity-20 rounded-full p-4">
                                    <i class="fas fa-exclamation-circle text-3xl"></i>
                                </div>
                            </div>
                        </div>

                        <div class="bg-gradient-to-br from-purple-500 to-violet-600 rounded-xl shadow-lg p-6 text-white">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-purple-100 text-sm font-semibold">หมายเหตุ/อื่น ๆ</p>
                                    <p class="text-3xl font-bold mt-2" id="stat-remark">0</p>
                                </div>
                                <div class="bg-white bg-opacity-20 rounded-full p-4">
                                    <i class="fas fa-sticky-note text-3xl"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-xl shadow-lg p-6">
                        <h3 class="text-xl font-bold text-gray-800 mb-4">
                            <i class="fas fa-list-alt text-blue-600"></i> รายการล่าสุด
                        </h3>
                        <div id="recent-attendance" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3 max-h-[520px] overflow-y-auto pr-1"></div>
                    </div>
                </div>
            </div>

            <div id="tab-attendance" class="tab-content hidden">
                <div class="mb-6">
                    <h2 class="text-2xl font-bold text-gray-800 mb-4">
                        <i class="fas fa-clipboard-list text-blue-600"></i> บันทึกการลงเวลา
                    </h2>

                    <div class="bg-white rounded-xl shadow-lg p-6">
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-4">
                            <div>
                                <label class="block text-gray-700 font-semibold mb-2">
                                    <i class="far fa-calendar-alt"></i> วันที่
                                </label>
                                <div class="relative">
                                    <input type="text"
                                           id="attendance-date-display"
                                           class="w-full px-4 py-2 pr-11 border-2 border-gray-300 rounded-lg bg-white text-gray-800 focus:outline-none"
                                           placeholder="เลือกวันที่"
                                           readonly>
                                    <button type="button"
                                            id="attendance-date-trigger"
                                            class="absolute right-3 top-1/2 -translate-y-1/2 text-blue-600 hover:text-blue-700">
                                        <i class="fas fa-calendar-alt"></i>
                                    </button>
                                    <input type="date"
                                           id="attendance-date"
                                           class="absolute opacity-0 pointer-events-none w-0 h-0"
                                           tabindex="-1"
                                           aria-hidden="true">
                                </div>
                            </div>

                            <div>
                                <label class="block text-gray-700 font-semibold mb-2">
                                    <i class="fas fa-search"></i> ค้นหา
                                </label>
                                <input type="text" 
                                       id="attendance-search" 
                                       class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:outline-none focus:border-blue-500"
                                       placeholder="ชื่อ, เลขบัตร...">
                            </div>

                            <div>
                                <label class="block text-gray-700 font-semibold mb-2">
                                    <i class="fas fa-building"></i> กลุ่มสาระ / แผนก
                                </label>
                                <select id="attendance-department" 
                                        class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                                    <option value="">ทั้งหมด</option>
                                </select>
                            </div>

                            <div>
                                <label class="block text-gray-700 font-semibold mb-2">
                                    <i class="fas fa-filter"></i> สถานะ
                                </label>
                                <select id="attendance-status" 
                                        class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                                    <option value="">ทั้งหมด</option>
                                <option value="present">มาตรงเวลา</option>
                                <option value="late">มาสาย</option>
                                <option value="incomplete">ไม่สแกนออก</option>
                                <option value="absent">ยังไม่ได้ลงเวลา</option>
                            </select>
                        </div>
                        </div>

                        <div class="flex flex-wrap justify-end items-center gap-3">
                            <span id="attendance-selected-count" class="text-sm text-gray-600">เลือกแล้ว 0 รายการ</span>
                            <button id="bulk-edit-time-btn"
                                    class="px-6 py-2 bg-gradient-to-r from-amber-600 to-orange-600 text-white rounded-lg hover:from-amber-700 hover:to-orange-700 transition-all font-semibold shadow-lg hover:shadow-xl disabled:opacity-50 disabled:cursor-not-allowed"
                                    disabled>
                                <i class="fas fa-users-cog"></i> แก้ไขเวลาที่เลือก
                            </button>
                            <button id="bulk-edit-remark-btn"
                                    class="px-6 py-2 bg-gradient-to-r from-yellow-600 to-amber-600 text-white rounded-lg hover:from-yellow-700 hover:to-amber-700 transition-all font-semibold shadow-lg hover:shadow-xl disabled:opacity-50 disabled:cursor-not-allowed"
                                    disabled>
                                <i class="fas fa-note-sticky"></i> แก้ไขหมายเหตุที่เลือก
                            </button>
                            <button id="search-attendance-btn" 
                                    class="px-6 py-2 bg-gradient-to-r from-blue-600 to-indigo-600 text-white rounded-lg hover:from-blue-700 hover:to-indigo-700 transition-all font-semibold shadow-lg hover:shadow-xl">
                                <i class="fas fa-search"></i> ค้นหา
                            </button>
                            <button id="print-report-btn" 
                                    class="px-6 py-2 bg-gradient-to-r from-green-600 to-emerald-600 text-white rounded-lg hover:from-green-700 hover:to-emerald-700 transition-all font-semibold shadow-lg hover:shadow-xl">
                                <i class="fas fa-print"></i> พิมพ์รายงาน
                            </button>
                        </div>
                    </div>
                </div>

                <div id="attendance-loading" class="text-center py-12 bg-white rounded-xl shadow-lg">
                    <div class="spinner-dark mx-auto mb-4"></div>
                    <p class="text-gray-600">กำลังโหลดข้อมูล...</p>
                </div>

                <div id="attendance-content" class="hidden">
                    <div class="bg-white rounded-xl shadow-lg overflow-hidden">
                        <div class="overflow-x-auto">
                            <table class="w-full">
                                <thead class="bg-gradient-to-r from-blue-600 to-indigo-600 text-white">
                                    <tr>
                                        <th class="px-4 py-4 text-center font-semibold w-12">
                                            <input type="checkbox" id="attendance-select-all" class="w-4 h-4 rounded border-white/60 text-blue-600 focus:ring-blue-300">
                                        </th>
                                        <th class="px-6 py-4 text-left font-semibold">
                                            <i class="fas fa-user"></i> ชื่อ-นามสกุล
                                        </th>
                                        <th class="px-6 py-4 text-left font-semibold">
                                            <i class="fas fa-building"></i> กลุ่มสาระ / แผนก
                                        </th>
                                        <th class="px-6 py-4 text-left font-semibold whitespace-nowrap">
                                            <span class="inline-flex items-center gap-1 whitespace-nowrap">
                                                <i class="fas fa-sign-in-alt"></i>
                                                <span>เวลามา</span>
                                            </span>
                                        </th>
                                        <th class="px-6 py-4 text-left font-semibold whitespace-nowrap">
                                            <span class="inline-flex items-center gap-1 whitespace-nowrap">
                                                <i class="fas fa-sign-out-alt"></i>
                                                <span>เวลากลับ</span>
                                            </span>
                                        </th>
                                        <th class="px-6 py-4 text-left font-semibold">
                                            <i class="fas fa-info-circle"></i> สถานะ
                                        </th>
                                        <th class="px-6 py-4 text-left font-semibold">
                                            <i class="fas fa-cog"></i> จัดการ
                                        </th>
                                        <th class="px-6 py-4 text-left font-semibold">
                                            <i class="fas fa-sticky-note"></i> หมายเหตุ
                                        </th>
                                    </tr>
                                </thead>
                                <tbody id="attendance-table-body">
                                </tbody>
                            </table>
                        </div>

                        <div id="attendance-pagination" class="px-6 py-4 bg-gray-50 border-t border-gray-200 flex items-center justify-between">
                        </div>
                    </div>
                </div>
            </div>

            <div id="tab-summary" class="tab-content hidden">
                <div class="mb-6">
                    <h2 class="text-2xl font-bold text-gray-800 mb-4">
                        <i class="fas fa-chart-bar text-blue-600"></i> สรุปข้อมูลการมา-สาย-ลา-ขาด-อื่น ๆ
                    </h2>
                    
                    <!-- กรองตามปีการศึกษาและภาคเรียน -->
                    <div class="bg-blue-50 p-4 rounded-lg mb-4">
                        <div class="flex flex-wrap gap-3 items-center">
                            <span class="text-gray-700 font-semibold">
                                <i class="fas fa-graduation-cap"></i> ปีการศึกษา:
                            </span>
                            <select id="academic-year" class="px-4 py-2 border border-gray-300 rounded-lg bg-white">
                                <!-- จะถูกสร้างด้วย JavaScript -->
                            </select>
                            
                            <span class="text-gray-700 font-semibold">
                                <i class="fas fa-book"></i> ภาคเรียน:
                            </span>
                            <select id="semester" class="px-4 py-2 border border-gray-300 rounded-lg bg-white">
                                <option value="all">ทั้งปี</option>
                                <option value="1">ภาคเรียนที่ 1</option>
                                <option value="2">ภาคเรียนที่ 2</option>
                            </select>
                            
                            <button id="load-academic-btn" class="px-6 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition-colors">
                                <i class="fas fa-search"></i> ดูข้อมูล
                            </button>
                        </div>
                    </div>
                    
                    <!-- ปุ่มกรองและกำหนดวันที่ (แถวเดียว) -->
                    <div class="flex flex-nowrap gap-2 items-center overflow-x-auto whitespace-nowrap pb-1">
                        <button class="period-btn shrink-0 px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-blue-600 hover:text-white transition-colors" data-period="today">
                            <i class="fas fa-calendar-day"></i> วันนี้
                        </button>
                        <button class="period-btn shrink-0 px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-blue-600 hover:text-white transition-colors" data-period="week">
                            <i class="fas fa-calendar-week"></i> สัปดาห์นี้
                        </button>
                        <button class="period-btn shrink-0 px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-blue-600 hover:text-white transition-colors" data-period="month">
                            <i class="fas fa-calendar-alt"></i> เดือนนี้
                        </button>
                        <button class="period-btn shrink-0 px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-blue-600 hover:text-white transition-colors" data-period="year">
                            <i class="fas fa-calendar"></i> ปีนี้
                        </button>
                        <span class="shrink-0 ml-2 text-gray-700 font-semibold">กำหนดเอง:</span>
                        <div class="relative shrink-0">
                            <input type="text"
                                   id="summary-start-date-display"
                                   class="px-4 py-2 pr-11 border border-gray-300 rounded-lg bg-white text-gray-800 focus:outline-none"
                                   placeholder="เลือกวันที่"
                                   readonly>
                            <button type="button"
                                    id="summary-start-date-trigger"
                                    class="absolute right-3 top-1/2 -translate-y-1/2 text-blue-600 hover:text-blue-700">
                                <i class="fas fa-calendar-alt"></i>
                            </button>
                            <input type="date"
                                   id="summary-start-date"
                                   class="absolute opacity-0 pointer-events-none w-0 h-0"
                                   tabindex="-1"
                                   aria-hidden="true">
                        </div>
                        <span class="shrink-0 text-gray-600">ถึง</span>
                        <div class="relative shrink-0">
                            <input type="text"
                                   id="summary-end-date-display"
                                   class="px-4 py-2 pr-11 border border-gray-300 rounded-lg bg-white text-gray-800 focus:outline-none"
                                   placeholder="เลือกวันที่"
                                   readonly>
                            <button type="button"
                                    id="summary-end-date-trigger"
                                    class="absolute right-3 top-1/2 -translate-y-1/2 text-blue-600 hover:text-blue-700">
                                <i class="fas fa-calendar-alt"></i>
                            </button>
                            <input type="date"
                                   id="summary-end-date"
                                   class="absolute opacity-0 pointer-events-none w-0 h-0"
                                   tabindex="-1"
                                   aria-hidden="true">
                        </div>
                        <button id="load-summary-btn" class="shrink-0 px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors">
                            <i class="fas fa-search"></i> ค้นหา
                        </button>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow-lg overflow-hidden">
                    <div class="px-4 py-3 bg-gray-50 border-b border-gray-200 flex items-center justify-end gap-2">
                        <div class="w-full md:w-64">
                            <select id="summary-department-filter"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500 bg-white">
                                <option value="">ทุกกลุ่มสาระ / แผนก</option>
                            </select>
                        </div>
                        <div class="relative w-full md:w-80">
                            <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
                            <input type="text"
                                   id="summary-search"
                                   class="w-full pl-10 pr-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500"
                                   placeholder="ค้นหารายชื่อครู...">
                        </div>
                        <button id="print-summary-btn" class="shrink-0 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                            <i class="fas fa-print"></i> พิมพ์รายงาน
                        </button>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gradient-to-r from-blue-600 to-indigo-600 text-white">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">ลำดับ</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">ชื่อ-นามสกุล</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">กลุ่มสาระ / แผนก</th>
                                    <th class="px-6 py-3 text-center text-xs font-medium uppercase tracking-wider">มาตรงเวลา</th>
                                    <th class="px-6 py-3 text-center text-xs font-medium uppercase tracking-wider">มาสาย</th>
                                    <th class="px-6 py-3 text-center text-xs font-medium uppercase tracking-wider">ลา</th>
                                    <th class="px-6 py-3 text-center text-xs font-medium uppercase tracking-wider">ขาด</th>
                                    <th class="px-6 py-3 text-center text-xs font-medium uppercase tracking-wider">อื่น ๆ</th>
                                    <th class="px-6 py-3 text-center text-xs font-medium uppercase tracking-wider">รวม</th>
                                </tr>
                            </thead>
                            <tbody id="summary-table-body" class="bg-white divide-y divide-gray-200">
                                <tr>
                                    <td colspan="9" class="px-6 py-8 text-center text-gray-500">
                                        <i class="fas fa-spinner fa-spin text-3xl mb-2"></i>
                                        <p>กำลังโหลดข้อมูล...</p>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div id="tab-teachers" class="tab-content hidden">
                <div class="mb-6 flex flex-wrap gap-4 items-center justify-between">
                    <h2 class="text-2xl font-bold text-gray-800">
                        <i class="fas fa-users text-blue-600"></i> จัดการบุคลากร
                    </h2>
                    <button id="add-teacher-btn" 
                            class="px-6 py-2 bg-gradient-to-r from-green-600 to-teal-600 text-white rounded-lg hover:from-green-700 hover:to-teal-700 transition-all font-semibold shadow-lg hover:shadow-xl">
                        <i class="fas fa-plus-circle"></i> เพิ่มข้อมูลบุคลากร
                    </button>
                </div>

                <div class="bg-white rounded-xl shadow-lg p-6 mb-6">
                    <div class="grid grid-cols-1 md:grid-cols-[1fr_1fr_1fr_auto] gap-4 items-end">
                        <div>
                            <label class="block text-gray-700 font-semibold mb-2">
                                <i class="fas fa-search"></i> ค้นหา
                            </label>
                            <input type="text" 
                                   id="teacher-search" 
                                   class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:outline-none focus:border-blue-500"
                                   placeholder="ชื่อ, เลขบัตร, RFID...">
                        </div>

                        <div>
                            <label class="block text-gray-700 font-semibold mb-2">
                                <i class="fas fa-building"></i> กลุ่มสาระ / แผนก
                            </label>
                            <select id="teacher-department" 
                                    class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                                <option value="">ทั้งหมด</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-gray-700 font-semibold mb-2">
                                <i class="fas fa-filter"></i> สถานะ
                            </label>
                            <select id="teacher-status" 
                                    class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                                <option value="">ทั้งหมด</option>
                                <option value="active">ใช้งาน</option>
                                    <option value="inactive">ไม่ใช้งาน</option>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-transparent font-semibold mb-2 select-none">.</label>
                            <button id="search-teacher-btn" 
                                    class="w-full md:w-auto px-6 py-2 bg-gradient-to-r from-blue-600 to-indigo-600 text-white rounded-lg hover:from-blue-700 hover:to-indigo-700 transition-all font-semibold shadow-lg hover:shadow-xl">
                                <i class="fas fa-search"></i> ค้นหา
                            </button>
                        </div>
                    </div>
                </div>

                <div id="teachers-loading" class="text-center py-12 bg-white rounded-xl shadow-lg">
                    <div class="spinner-dark mx-auto mb-4"></div>
                    <p class="text-gray-600">กำลังโหลดข้อมูล...</p>
                </div>

                <div id="teachers-content" class="hidden">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6" id="teachers-grid">
                    </div>

                    <div id="teachers-pagination" class="mt-6 flex items-center justify-center">
                    </div>
                </div>
            </div>

            <div id="tab-settings" class="tab-content hidden">
                <div class="mb-6 flex flex-wrap gap-4 items-center justify-between">
                    <h2 class="text-2xl font-bold text-gray-800">
                        <i class="fas fa-cog text-blue-600"></i> การตั้งค่าระบบ
                    </h2>
                </div>

                <div class="bg-white rounded-lg shadow-lg p-6 mb-6">
                    <h3 class="text-xl font-bold text-gray-800 mb-6 pb-4 border-b border-gray-200">
                        <i class="fas fa-graduation-cap text-purple-600"></i> กำหนดปีการศึกษาและภาคเรียน
                    </h3>

                    <div id="settings-superadmin-note" class="hidden mb-4 rounded-lg border border-indigo-200 bg-indigo-50 px-4 py-3 text-sm text-indigo-800">
                        เฉพาะผู้ดูแลระบบหลักเท่านั้นที่สามารถเพิ่ม แก้ไข หรือสลับปีการศึกษาที่ใช้งานได้
                    </div>

                    <div class="mb-6 bg-gray-50 border border-gray-200 rounded-xl overflow-hidden">
                        <div class="px-4 py-3 bg-gray-100 border-b border-gray-200 flex items-center justify-between">
                            <h4 class="font-bold text-gray-800">
                                <i class="fas fa-list"></i> รายการปีการศึกษา
                            </h4>
                            <div class="flex items-center gap-3">
                                <span class="text-sm text-gray-600">กดปุ่ม "ใช้งาน" เพื่อสลับปฏิทินที่ระบบใช้</span>
                                <button type="button" id="edit-settings-btn"
                                        class="px-4 py-2 bg-gradient-to-r from-blue-600 to-indigo-600 text-white rounded-lg hover:from-blue-700 hover:to-indigo-700 transition-all font-semibold shadow-lg">
                                    <i class="fas fa-plus"></i> เพิ่มปีการศึกษา
                                </button>
                            </div>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="min-w-full">
                                <thead class="bg-white">
                                    <tr class="text-left text-gray-700 border-b border-gray-200">
                                        <th class="px-4 py-3">ปีการศึกษา (พ.ศ.)</th>
                                        <th class="px-4 py-3">ภาคเรียนที่ 1</th>
                                        <th class="px-4 py-3">ภาคเรียนที่ 2</th>
                                        <th class="px-4 py-3">สถานะ</th>
                                        <th class="px-4 py-3 text-right">จัดการ</th>
                                    </tr>
                                </thead>
                                <tbody id="settings-calendar-list-body" class="divide-y divide-gray-100"></tbody>
                            </table>
                        </div>
                        <div id="settings-calendar-empty" class="hidden px-4 py-6 text-center text-gray-500">
                            <i class="fas fa-inbox text-2xl mb-2"></i>
                            <p>ยังไม่มีปีการศึกษา กรุณาเพิ่มรายการแรก</p>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow-lg p-6">
                    <div class="flex flex-wrap items-start justify-between gap-4 mb-6 pb-4 border-b border-gray-200">
                        <div>
                            <h3 class="text-xl font-bold text-gray-800">
                                <i class="fas fa-clock text-amber-600"></i> กฎเวลาเข้าออก
                            </h3>
                            <p class="text-sm text-gray-600 mt-1">
                                กำหนดกฎปกติและกฎพิเศษตามช่วงวันที่หรือหลายวันได้ เช่น ช่วงปิดเทอม 10-20 มีนาคม
                            </p>
                        </div>
                        <div class="flex items-center gap-3">
                            <div id="time-rules-today-summary" class="text-sm text-gray-600 text-right"></div>
                            <button type="button" id="add-time-rule-btn"
                                    class="px-4 py-2 bg-gradient-to-r from-amber-500 to-orange-500 text-white rounded-lg hover:from-amber-600 hover:to-orange-600 transition-all font-semibold shadow-lg">
                                <i class="fas fa-plus"></i> เพิ่มกฎเวลา
                            </button>
                        </div>
                    </div>

                    <div id="time-rules-superadmin-note" class="hidden mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                        เฉพาะผู้ดูแลระบบหลักเท่านั้นที่สามารถเพิ่ม แก้ไข หรือลบกฎเวลาได้
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full">
                            <thead class="bg-white border-b border-gray-200">
                                <tr class="text-left text-gray-700">
                                    <th class="px-4 py-3">ชื่อกฎ</th>
                                    <th class="px-4 py-3">ช่วงวันที่</th>
                                    <th class="px-4 py-3">วันที่ใช้</th>
                                    <th class="px-4 py-3">เวลาเข้า</th>
                                    <th class="px-4 py-3">เวลาออก</th>
                                    <th class="px-4 py-3">หมายเหตุ</th>
                                    <th class="px-4 py-3">สถานะ</th>
                                    <th class="px-4 py-3 text-right">จัดการ</th>
                                </tr>
                            </thead>
                            <tbody id="time-rules-list-body" class="divide-y divide-gray-100"></tbody>
                        </table>
                    </div>

                    <div id="time-rules-empty" class="hidden px-4 py-8 text-center text-gray-500">
                        <i class="fas fa-clock text-2xl mb-2"></i>
                        <p>ยังไม่มีกฎเวลา</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal เพิ่ม/แก้ไขปีการศึกษา -->
    <div id="settings-modal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-[8900] flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl shadow-2xl max-w-3xl w-full max-h-[90vh] overflow-y-auto">
            <div class="bg-gradient-to-r from-indigo-600 to-blue-600 p-5 text-white flex items-center justify-between">
                <h2 class="text-2xl font-bold" id="settings-modal-title">
                    <i class="fas fa-plus-circle"></i> เพิ่มปีการศึกษา
                </h2>
                <button type="button" id="settings-modal-close" class="text-white hover:text-gray-200 text-3xl leading-none">&times;</button>
            </div>

            <form id="settings-form" class="p-6">
                <input type="hidden" id="settings-calendar-id" value="">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="md:col-span-2 bg-blue-50 p-4 rounded-lg">
                        <label class="block text-gray-700 font-semibold mb-2">
                            <i class="fas fa-calendar-alt"></i> ปีการศึกษา (พ.ศ.)
                        </label>
                        <input type="number" id="current-academic-year"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg"
                               placeholder="2568" min="2400" max="2800">
                        <p class="text-sm text-gray-600 mt-1">กรอกปีการศึกษาที่ต้องการเพิ่มหรือแก้ไข</p>
                    </div>

                    <div class="md:col-span-2">
                        <h4 class="font-bold text-gray-800 mb-3 text-lg">
                            <i class="fas fa-calendar-check text-green-600"></i> ภาคเรียนที่ 1
                        </h4>
                    </div>

                    <div>
                        <label class="block text-gray-700 font-semibold mb-2">วันเริ่มต้น</label>
                        <input type="date" id="semester-1-start"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg">
                    </div>

                    <div>
                        <label class="block text-gray-700 font-semibold mb-2">วันสิ้นสุด</label>
                        <input type="date" id="semester-1-end"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg">
                    </div>

                    <div class="md:col-span-2 mt-4">
                        <h4 class="font-bold text-gray-800 mb-3 text-lg">
                            <i class="fas fa-calendar-check text-blue-600"></i> ภาคเรียนที่ 2
                        </h4>
                    </div>

                    <div>
                        <label class="block text-gray-700 font-semibold mb-2">วันเริ่มต้น</label>
                        <input type="date" id="semester-2-start"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg">
                    </div>

                    <div>
                        <label class="block text-gray-700 font-semibold mb-2">วันสิ้นสุด</label>
                        <input type="date" id="semester-2-end"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg">
                    </div>
                </div>

                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" id="cancel-settings-btn"
                            class="px-8 py-3 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition-all font-semibold">
                        <i class="fas fa-times"></i> ยกเลิก
                    </button>
                    <button type="submit" id="save-settings-btn"
                            class="px-8 py-3 bg-gradient-to-r from-green-600 to-teal-600 text-white rounded-lg hover:from-green-700 hover:to-teal-700 transition-all font-semibold shadow-lg">
                        <i class="fas fa-save"></i> บันทึกปีการศึกษา
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div id="time-rule-modal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-[8950] flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl shadow-2xl max-w-4xl w-full max-h-[90vh] overflow-y-auto">
            <div class="bg-gradient-to-r from-amber-500 to-orange-500 p-5 text-white flex items-center justify-between">
                <h2 class="text-2xl font-bold" id="time-rule-modal-title">
                    <i class="fas fa-plus-circle"></i> เพิ่มกฎเวลา
                </h2>
                <button type="button" id="time-rule-modal-close" class="text-white hover:text-gray-200 text-3xl leading-none">&times;</button>
            </div>

            <form id="time-rule-form" class="p-6">
                <input type="hidden" id="time-rule-id" value="">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="md:col-span-2">
                        <label class="block text-gray-700 font-semibold mb-2">
                            <i class="fas fa-tag"></i> ชื่อกฎ
                        </label>
                        <input type="text" id="time-rule-name"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg"
                               placeholder="เช่น เวลาปิดเทอม / เวลาวันศุกร์ / วันประชุม">
                    </div>

                    <div class="md:col-span-2">
                        <label class="inline-flex items-center gap-2 text-gray-700 font-semibold mb-3">
                            <input type="checkbox" id="time-rule-use-date-range" class="rounded border-gray-300 text-amber-600 focus:ring-amber-500">
                            <span>ใช้เฉพาะช่วงวันที่</span>
                        </label>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-gray-700 font-semibold mb-2">วันเริ่มต้น</label>
                                <input type="date" id="time-rule-start-date"
                                       class="w-full px-4 py-2 border border-gray-300 rounded-lg">
                            </div>
                            <div>
                                <label class="block text-gray-700 font-semibold mb-2">วันสิ้นสุด</label>
                                <input type="date" id="time-rule-end-date"
                                       class="w-full px-4 py-2 border border-gray-300 rounded-lg">
                            </div>
                        </div>
                        <p class="text-sm text-gray-500 mt-2">ถ้าไม่เลือก ระบบจะถือว่าเป็นกฎถาวรที่ใช้ซ้ำได้ทุกสัปดาห์</p>
                    </div>

                    <div class="md:col-span-2">
                        <label class="block text-gray-700 font-semibold mb-3">
                            <i class="fas fa-calendar-week"></i> ใช้ในวัน
                        </label>
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
                            <label class="flex items-center gap-2"><input type="checkbox" class="time-rule-weekday rounded border-gray-300 text-amber-600 focus:ring-amber-500" value="1">จันทร์</label>
                            <label class="flex items-center gap-2"><input type="checkbox" class="time-rule-weekday rounded border-gray-300 text-amber-600 focus:ring-amber-500" value="2">อังคาร</label>
                            <label class="flex items-center gap-2"><input type="checkbox" class="time-rule-weekday rounded border-gray-300 text-amber-600 focus:ring-amber-500" value="3">พุธ</label>
                            <label class="flex items-center gap-2"><input type="checkbox" class="time-rule-weekday rounded border-gray-300 text-amber-600 focus:ring-amber-500" value="4">พฤหัสบดี</label>
                            <label class="flex items-center gap-2"><input type="checkbox" class="time-rule-weekday rounded border-gray-300 text-amber-600 focus:ring-amber-500" value="5">ศุกร์</label>
                            <label class="flex items-center gap-2"><input type="checkbox" class="time-rule-weekday rounded border-gray-300 text-amber-600 focus:ring-amber-500" value="6">เสาร์</label>
                            <label class="flex items-center gap-2"><input type="checkbox" class="time-rule-weekday rounded border-gray-300 text-amber-600 focus:ring-amber-500" value="7">อาทิตย์</label>
                        </div>
                    </div>

                    <div>
                        <label class="block text-gray-700 font-semibold mb-2">เริ่มสแกนเข้า</label>
                        <input type="time" id="time-rule-check-in-start"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg" step="1">
                    </div>

                    <div>
                        <label class="block text-gray-700 font-semibold mb-2">เริ่มมาสาย</label>
                        <input type="time" id="time-rule-check-in-late"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg" step="1">
                    </div>

                    <div>
                        <label class="block text-gray-700 font-semibold mb-2">เริ่มสแกนออก</label>
                        <input type="time" id="time-rule-check-out-start"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg" step="1">
                    </div>

                    <div>
                        <label class="block text-gray-700 font-semibold mb-2">สิ้นสุดสแกนออก</label>
                        <input type="time" id="time-rule-check-out-end"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg" step="1">
                    </div>

                    <div>
                        <label class="block text-gray-700 font-semibold mb-2">ลำดับความสำคัญ</label>
                        <input type="number" id="time-rule-priority"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg"
                               min="0" max="9999" value="100">
                        <p class="text-sm text-gray-500 mt-1">เลขมากจะถูกเลือกก่อนเมื่อมีหลายกฎตรงกัน</p>
                    </div>

                    <div class="flex items-center">
                        <label class="inline-flex items-center gap-2 text-gray-700 font-semibold mt-7">
                            <input type="checkbox" id="time-rule-active" class="rounded border-gray-300 text-amber-600 focus:ring-amber-500" checked>
                            <span>เปิดใช้งานกฎนี้</span>
                        </label>
                    </div>

                    <div class="md:col-span-2">
                        <label class="block text-gray-700 font-semibold mb-2">หมายเหตุ</label>
                        <textarea id="time-rule-note"
                                  class="w-full px-4 py-2 border border-gray-300 rounded-lg min-h-[96px]"
                                  placeholder="เช่น 10-20 มีนาคม สแกนสายไม่เกิน 08:00 น. เพราะปิดเทอม"></textarea>
                    </div>
                </div>

                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" id="cancel-time-rule-btn"
                            class="px-8 py-3 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition-all font-semibold">
                        <i class="fas fa-times"></i> ยกเลิก
                    </button>
                    <button type="submit" id="save-time-rule-btn"
                            class="px-8 py-3 bg-gradient-to-r from-amber-500 to-orange-500 text-white rounded-lg hover:from-amber-600 hover:to-orange-600 transition-all font-semibold shadow-lg">
                        <i class="fas fa-save"></i> บันทึกกฎเวลา
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal เพิ่ม/แก้ไขครู -->
    <div id="teacher-modal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-[9000] flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl shadow-2xl max-w-2xl w-full max-h-[90vh] overflow-y-auto">
            <div class="bg-gradient-to-r from-blue-600 to-indigo-600 p-6 text-white">
                <h2 class="text-2xl font-bold" id="teacher-modal-title">
                    <i class="fas fa-user-plus"></i> เพิ่มข้อมูลบุคลากร
                </h2>
            </div>
            
            <form id="teacher-form" class="p-6 space-y-4">
                <input type="hidden" id="teacher-id">
                <input type="hidden" id="teacher-photo-url">
                
                <!-- Upload รูปภาพ -->
                <div class="mb-6">
                    <label class="block text-gray-700 font-semibold mb-3 text-center">
                        <i class="fas fa-camera"></i> รูปภาพครู
                    </label>
                    <div class="flex flex-col items-center">
                        <div class="relative mb-4">
                            <div id="photo-preview-container" class="w-32 h-32 rounded-full overflow-hidden border-4 border-blue-500 shadow-lg bg-gray-100 flex items-center justify-center">
                                <img id="photo-preview" src="" alt="Preview" class="hidden w-full h-full object-cover">
                                <div id="photo-placeholder" class="text-gray-400 text-center">
                                    <i class="fas fa-user text-5xl mb-2"></i>
                                    <p class="text-xs">ไม่มีรูปภาพ</p>
                                </div>
                            </div>
                            <button type="button" id="remove-photo-btn" class="hidden absolute -top-2 -right-2 w-8 h-8 bg-red-500 text-white rounded-full hover:bg-red-600 shadow-lg">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        <label for="teacher-photo" class="cursor-pointer px-6 py-2 bg-gradient-to-r from-purple-500 to-pink-500 text-white rounded-lg hover:from-purple-600 hover:to-pink-600 transition-all font-semibold shadow-lg hover:shadow-xl">
                            <i class="fas fa-upload"></i> เลือกรูปภาพ
                        </label>
                        <input type="file" id="teacher-photo" accept="image/*" class="hidden">
                        <p class="text-xs text-gray-500 mt-2">เลือกรูปแล้วสามารถซูม/เลื่อนเพื่อครอบให้พอดีวงกลมได้</p>
                    </div>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-gray-700 font-semibold mb-2">
                            <i class="fas fa-user"></i> ชื่อ <span class="text-red-500">*</span>
                        </label>
                        <input type="text" id="teacher-first-name" required
                               class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                    </div>
                    
                    <div>
                        <label class="block text-gray-700 font-semibold mb-2">
                            <i class="fas fa-user"></i> นามสกุล <span class="text-red-500">*</span>
                        </label>
                        <input type="text" id="teacher-last-name" required
                               class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                    </div>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-gray-700 font-semibold mb-2">
                            <i class="fas fa-id-card"></i> เลขประจำตัว / Passport <span class="text-red-500">*</span>
                        </label>
                        <input type="text" id="teacher-citizen-id" required maxlength="50"
                               class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:outline-none focus:border-blue-500"
                               placeholder="1234567890123 หรือ AB1234567">
                    </div>
                    
                    <div id="teacher-rfid-field-group">
                        <label class="block text-gray-700 font-semibold mb-2">
                            <i class="fas fa-barcode"></i> รหัส RFID <span class="text-red-500">*</span>
                        </label>
                        <input type="text" id="teacher-rfid-code" required
                               class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:outline-none focus:border-blue-500"
                               placeholder="RFID001">
                    </div>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-gray-700 font-semibold mb-2">
                            <i class="fas fa-building"></i> กลุ่มสาระ/แผนก <span class="text-red-500">*</span>
                        </label>
                        <select id="teacher-department-input" required
                                class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                            <option value="">-- เลือกกลุ่มสาระ / แผนก --</option>
                            <option value="ภาษาไทย">ภาษาไทย</option>
                            <option value="คณิตศาสตร์">คณิตศาสตร์</option>
                            <option value="วิทยาศาสตร์">วิทยาศาสตร์</option>
                            <option value="คอมพิวเตอร์">คอมพิวเตอร์</option>
                            <option value="สังคมศึกษาฯ">สังคมศึกษาฯ</option>
                            <option value="ภาษาต่างประเทศ(อังกฤษ)">ภาษาต่างประเทศ(อังกฤษ)</option>
                            <option value="ภาษาต่างประเทศ(จีน)">ภาษาต่างประเทศ(จีน)</option>
                            <option value="การงานอาชีพ">การงานอาชีพ</option>
                            <option value="ศิลปะ">ศิลปะ</option>
                            <option value="สุขศึกษาและพลศึกษา">สุขศึกษาและพลศึกษา</option>
                            <option value="กิจกรรมพัฒนาผู้เรียน">กิจกรรมพัฒนาผู้เรียน</option>
                            <option value="ผู้บริหาร">ผู้บริหาร</option>
                            <option value="บุคลากรทางการศึกษา">บุคลากรทางการศึกษา</option>
                            <option value="เจ้าหน้าที่">เจ้าหน้าที่</option>
                            <option value="นักการ/แม่บ้าน">นักการ/แม่บ้าน</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-gray-700 font-semibold mb-2">
                            <i class="fas fa-user-tie"></i> ตำแหน่ง
                        </label>
                        <select id="teacher-position"
                                class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                            <option value="">-- เลือกตำแหน่ง --</option>
                            <option value="ผู้อำนวยการ">ผู้อำนวยการ</option>
                            <option value="รองผู้อำนวยการ">รองผู้อำนวยการ</option>
                            <option value="ครูผู้ช่วย">ครูผู้ช่วย</option>
                            <option value="ครู">ครู</option>
                            <option value="ครูอัตราจ้าง">ครูอัตราจ้าง</option>
                            <option value="ครูชาวต่างชาติ">ครูชาวต่างชาติ</option>
                            <option value="พนักงานราชการ">พนักงานราชการ</option>
                            <option value="เจ้าหน้าที่ธุรการ">เจ้าหน้าที่ธุรการ</option>
                            <option value="พนักงานขับรถ">พนักงานขับรถ</option>
                            <option value="เจ้าหน้าที่รักษาความปลอดภัย">เจ้าหน้าที่รักษาความปลอดภัย</option>
                            <option value="นักการภารโรง">นักการภารโรง</option>
                            <option value="แม่บ้าน">แม่บ้าน</option>
                            <option value="อื่น ๆ">อื่น ๆ</option>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-gray-700 font-semibold mb-2">
                            <i class="fas fa-id-badge"></i> ประเภทบุคลากร
                        </label>
                        <select id="teacher-personnel-type"
                                class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                            <option value="">-- เลือกประเภทบุคลากร --</option>
                            <option value="ข้าราชการครู">ข้าราชการครู</option>
                            <option value="ผู้บริหารโรงเรียน">ผู้บริหารโรงเรียน</option>
                            <option value="พนักงานราชการ">พนักงานราชการ</option>
                            <option value="ครูอัตราจ้าง">ครูอัตราจ้าง</option>
                            <option value="ลูกจ้างประจำ">ลูกจ้างประจำ</option>
                            <option value="ลูกจ้างชั่วคราว">ลูกจ้างชั่วคราว</option>
                            <option value="บุคลากรทางการศึกษา">บุคลากรทางการศึกษา</option>
                            <option value="อื่น ๆ">อื่น ๆ</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-gray-700 font-semibold mb-2">
                            <i class="fas fa-cake-candles"></i> วันเกิด
                        </label>
                        <div class="relative">
                            <input type="text"
                                   id="teacher-birth-date-display"
                                   class="w-full px-4 py-2 pr-11 border-2 border-gray-300 rounded-lg bg-white text-gray-800 focus:outline-none cursor-pointer"
                                   placeholder="วว/ดด/พ.ศ."
                                   readonly>
                            <button type="button"
                                    id="teacher-birth-date-trigger"
                                    class="absolute inset-y-0 right-0 px-3 text-blue-600 hover:text-blue-700">
                                <i class="fas fa-calendar-alt"></i>
                            </button>
                            <input type="date"
                                   id="teacher-birth-date"
                                   class="absolute inset-0 opacity-0 pointer-events-none"
                                   tabindex="-1">
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-gray-700 font-semibold mb-2">
                            <i class="fas fa-venus-mars"></i> เพศ
                        </label>
                        <input type="hidden" id="teacher-gender" value="">
                        <div class="flex flex-wrap gap-2">
                            <label class="cursor-pointer">
                                <input type="radio" name="teacher-gender-radio" value="ชาย" class="sr-only peer">
                                <div class="inline-flex items-center px-3 py-2 border-2 border-gray-300 rounded-lg text-sm font-medium text-gray-700 transition-all whitespace-nowrap peer-checked:border-blue-500 peer-checked:bg-blue-50 peer-checked:text-blue-700">
                                    <i class="fas fa-mars mr-1 text-xs"></i> ชาย
                                </div>
                            </label>
                            <label class="cursor-pointer">
                                <input type="radio" name="teacher-gender-radio" value="หญิง" class="sr-only peer">
                                <div class="inline-flex items-center px-3 py-2 border-2 border-gray-300 rounded-lg text-sm font-medium text-gray-700 transition-all whitespace-nowrap peer-checked:border-pink-500 peer-checked:bg-pink-50 peer-checked:text-pink-700">
                                    <i class="fas fa-venus mr-1 text-xs"></i> หญิง
                                </div>
                            </label>
                            <label class="cursor-pointer">
                                <input type="radio" name="teacher-gender-radio" value="อื่น ๆ" class="sr-only peer">
                                <div class="inline-flex items-center px-3 py-2 border-2 border-gray-300 rounded-lg text-sm font-medium text-gray-700 transition-all whitespace-nowrap peer-checked:border-purple-500 peer-checked:bg-purple-50 peer-checked:text-purple-700">
                                    <i class="fas fa-genderless mr-1 text-xs"></i> อื่น ๆ
                                </div>
                            </label>
                        </div>
                    </div>

                    <div>
                        <label class="block text-gray-700 font-semibold mb-2">
                            <i class="fas fa-droplet text-red-500"></i> กรุ๊ปเลือด
                        </label>
                        <select id="teacher-blood-type"
                                class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                            <option value="">-- เลือกกรุ๊ปเลือด --</option>
                            <option value="A+">A+</option>
                            <option value="A-">A-</option>
                            <option value="B+">B+</option>
                            <option value="B-">B-</option>
                            <option value="AB+">AB+</option>
                            <option value="AB-">AB-</option>
                            <option value="O+">O+</option>
                            <option value="O-">O-</option>
                            <option value="Bombay">Bombay</option>
                            <option value="Rh-null">Rh-null</option>
                            <option value="อื่น ๆ">อื่น ๆ</option>
                        </select>
                    </div>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-gray-700 font-semibold mb-2">
                            <i class="fas fa-envelope"></i> อีเมล
                        </label>
                        <input type="email" id="teacher-email"
                               class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:outline-none focus:border-blue-500"
                               placeholder="teacher@school.ac.th">
                    </div>
                    
                    <div>
                        <label class="block text-gray-700 font-semibold mb-2">
                            <i class="fas fa-phone"></i> เบอร์โทรศัพท์
                        </label>
                        <input type="tel" id="teacher-phone" maxlength="10" pattern="[0-9]{10}"
                               class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:outline-none focus:border-blue-500"
                               placeholder="0812345678">
                    </div>
                </div>
                
                <div>
                    <label class="block text-gray-700 font-semibold mb-2">
                        <i class="fas fa-toggle-on"></i> สถานะ
                    </label>
                    <input type="hidden" id="teacher-status-input" value="inactive">
                    <label for="teacher-status-toggle" class="inline-flex items-center gap-3 cursor-pointer select-none">
                        <span class="text-sm font-medium text-gray-600">ไม่ใช้งาน</span>
                        <span class="relative">
                            <input type="checkbox" id="teacher-status-toggle" class="sr-only peer">
                            <span class="block w-14 h-8 bg-gray-300 rounded-full transition-colors peer-checked:bg-green-500"></span>
                            <span class="absolute left-1 top-1 w-6 h-6 bg-white rounded-full shadow transition-transform peer-checked:translate-x-6"></span>
                        </span>
                        <span class="text-sm font-medium text-green-700">ใช้งาน</span>
                    </label>
                </div>
                
                <div class="flex gap-3 pt-4 border-t">
                    <button type="button" onclick="closeTeacherModal()"
                            class="flex-1 px-6 py-3 bg-gray-500 text-white rounded-lg hover:bg-gray-600 transition-colors font-semibold">
                        <i class="fas fa-times"></i> ยกเลิก
                    </button>
                    <button type="submit" id="save-teacher-btn"
                            class="flex-1 px-6 py-3 bg-gradient-to-r from-blue-600 to-indigo-600 text-white rounded-lg hover:from-blue-700 hover:to-indigo-700 transition-all font-semibold shadow-lg">
                        <i class="fas fa-save"></i> บันทึก
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal ครอบรูปภาพบุคลากร -->
    <div id="photo-crop-modal" class="hidden fixed inset-0 bg-black bg-opacity-60 z-[9100] flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl shadow-2xl max-w-2xl w-full">
            <div class="bg-gradient-to-r from-purple-600 to-pink-600 p-5 text-white flex items-center justify-between">
                <h2 class="text-xl font-bold">
                    <i class="fas fa-crop-simple"></i> ปรับตำแหน่งรูปภาพ
                </h2>
                <button type="button" id="close-photo-crop-btn" class="text-white hover:text-gray-200 text-3xl leading-none">&times;</button>
            </div>

            <div class="p-6">
                <div class="flex justify-center mb-5">
                    <div class="photo-crop-area">
                        <img id="photo-crop-image" class="photo-crop-image" src="" alt="Crop Preview">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">ซูม</label>
                        <input type="range" id="photo-crop-zoom" min="100" max="300" step="1" value="100" class="w-full">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">เลื่อนซ้าย-ขวา</label>
                        <input type="range" id="photo-crop-offset-x" min="-120" max="120" step="1" value="0" class="w-full">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">เลื่อนขึ้น-ลง</label>
                        <input type="range" id="photo-crop-offset-y" min="-120" max="120" step="1" value="0" class="w-full">
                    </div>
                </div>

                <div class="mt-5 flex justify-end gap-3">
                    <button type="button" id="cancel-photo-crop-btn"
                            class="px-6 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition-colors font-semibold">
                        <i class="fas fa-times"></i> ยกเลิก
                    </button>
                    <button type="button" id="apply-photo-crop-btn"
                            class="px-6 py-2 bg-gradient-to-r from-blue-600 to-indigo-600 text-white rounded-lg hover:from-blue-700 hover:to-indigo-700 transition-all font-semibold">
                        <i class="fas fa-check"></i> ใช้รูปนี้
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal แก้ไข RFID ด่วน -->
    <div id="quick-rfid-modal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-[9050] flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full">
            <div class="bg-gradient-to-r from-slate-700 to-gray-900 p-5 text-white flex items-center justify-between">
                <h2 class="text-xl font-bold">
                    <i class="fas fa-barcode"></i> แก้ไข RFID ด่วน
                </h2>
                <button type="button" onclick="closeQuickRfidModal()" class="text-white hover:text-gray-200 text-3xl leading-none">&times;</button>
            </div>

            <form id="quick-rfid-form" class="p-6 space-y-4">
                <input type="hidden" id="quick-rfid-teacher-id">

                <div>
                    <label class="block text-gray-700 font-semibold mb-2">ชื่อ-นามสกุล</label>
                    <input type="text" id="quick-rfid-teacher-name" readonly
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg bg-gray-100 text-gray-700">
                </div>

                <div id="quick-rfid-code-group">
                    <label class="block text-gray-700 font-semibold mb-2">
                        <i class="fas fa-barcode"></i> รหัส RFID ใหม่
                    </label>
                    <input type="text" id="quick-rfid-code" required
                           class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:outline-none focus:border-blue-500"
                           placeholder="RFID001">
                </div>

                <div class="flex gap-3 pt-2">
                    <button type="button" onclick="closeQuickRfidModal()"
                            class="flex-1 px-4 py-2 bg-gray-500 text-white rounded-lg hover:bg-gray-600 transition-colors font-semibold">
                        <i class="fas fa-times"></i> ยกเลิก
                    </button>
                    <button type="submit" id="save-quick-rfid-btn"
                            class="flex-1 px-4 py-2 bg-gradient-to-r from-blue-600 to-indigo-600 text-white rounded-lg hover:from-blue-700 hover:to-indigo-700 transition-all font-semibold">
                        <i class="fas fa-save"></i> บันทึก
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal ยืนยันลบครู -->
    <div id="delete-teacher-modal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-[9050] flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full">
            <div class="bg-gradient-to-r from-red-600 to-rose-700 p-5 text-white flex items-center justify-between">
                <h2 class="text-xl font-bold">
                    <i class="fas fa-triangle-exclamation"></i> ยืนยันการลบครู
                </h2>
                <button type="button" onclick="closeDeleteTeacherModal()" class="text-white hover:text-gray-200 text-3xl leading-none">&times;</button>
            </div>

            <form id="delete-teacher-form" class="p-6 space-y-4">
                <input type="hidden" id="delete-teacher-id">

                <div class="p-4 bg-red-50 border border-red-200 rounded-lg text-red-700 text-sm">
                    การลบข้อมูลนี้ไม่สามารถย้อนกลับได้
                </div>

                <div>
                    <label class="block text-gray-700 font-semibold mb-2">ชื่อครูที่จะลบ</label>
                    <input type="text" id="delete-teacher-name" readonly
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg bg-gray-100 text-gray-700">
                </div>

                <div>
                    <label class="block text-gray-700 font-semibold mb-2">
                        <i class="fas fa-key"></i> รหัสยืนยันการลบ
                    </label>
                    <input type="password" id="delete-teacher-code" required inputmode="numeric" autocomplete="off"
                           class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:outline-none focus:border-red-500"
                           placeholder="กรอกรหัสยืนยัน">
                    <p class="text-xs text-gray-500 mt-1">ต้องกรอกรหัสให้ถูกต้องก่อนลบ</p>
                </div>

                <div class="flex gap-3 pt-2">
                    <button type="button" onclick="closeDeleteTeacherModal()"
                            class="flex-1 px-4 py-2 bg-gray-500 text-white rounded-lg hover:bg-gray-600 transition-colors font-semibold">
                        <i class="fas fa-times"></i> ยกเลิก
                    </button>
                    <button type="submit" id="confirm-delete-teacher-btn"
                            class="flex-1 px-4 py-2 bg-gradient-to-r from-red-600 to-rose-700 text-white rounded-lg hover:from-red-700 hover:to-rose-800 transition-all font-semibold">
                        <i class="fas fa-trash"></i> ยืนยันลบ
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal แก้ไขหมายเหตุ -->
    <div id="remark-modal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-[9000] flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full">
            <div class="bg-gradient-to-r from-amber-600 to-orange-600 p-6 text-white">
                <h2 class="text-2xl font-bold">
                    <i class="fas fa-sticky-note"></i> แก้ไขหมายเหตุ
                </h2>
            </div>
            
            <form id="remark-form" class="p-6 space-y-4">
                <input type="hidden" id="remark-record-id">
                <input type="hidden" id="remark-teacher-id">
                <input type="hidden" id="remark-attendance-date">
                
                <div>
                    <label class="block text-gray-700 font-semibold mb-2">
                        ชื่อครู
                    </label>
                    <input type="text" id="remark-teacher-name" readonly
                           class="w-full px-4 py-2 bg-gray-100 border-2 border-gray-300 rounded-lg">
                </div>
                
                <div>
                    <label class="block text-gray-700 font-semibold mb-2">
                        <i class="fas fa-sticky-note"></i> หมายเหตุ
                    </label>
                    <select id="remark-select"
                            class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:outline-none focus:border-amber-500">
                        <option value="">-</option>
                        <option value="ลาป่วย">ลาป่วย</option>
                        <option value="ลากิจ">ลากิจ</option>
                        <option value="ลาคลอดบุตร">ลาคลอดบุตร</option>
                        <option value="อบรม/ประชุม">อบรม/ประชุม</option>
                        <option value="ไปราชการ">ไปราชการ</option>
                        <option value="ลืมสแกนบัตร">ลืมสแกนบัตร</option>
                        <option value="ลืมบัตร">ลืมบัตร</option>
                        <option value="ขออนุญาตมาสาย">ขออนุญาตมาสาย</option>
                        <option value="อื่นๆ">อื่นๆ (ระบุ)</option>
                    </select>
                </div>
                
                <div id="remark-custom-field" class="hidden">
                    <label class="block text-gray-700 font-semibold mb-2">
                        ระบุหมายเหตุ
                    </label>
                    <input type="text" id="remark-custom-input"
                           class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:outline-none focus:border-amber-500"
                           placeholder="ระบุหมายเหตุ...">
                </div>
                
                <div class="flex gap-3 pt-4">
                    <button type="button" onclick="closeRemarkModal()"
                            class="flex-1 px-6 py-3 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition-all font-semibold">
                        <i class="fas fa-times"></i> ยกเลิก
                    </button>
                    <button type="submit" id="save-remark-btn"
                            class="flex-1 px-6 py-3 bg-gradient-to-r from-amber-600 to-orange-600 text-white rounded-lg hover:from-amber-700 hover:to-orange-700 transition-all font-semibold shadow-lg">
                        <i class="fas fa-save"></i> บันทึก
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal แก้ไขเวลาเข้า-ออก -->
    <div id="edit-time-modal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-[9000] flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full">
            <div class="bg-gradient-to-r from-blue-600 to-indigo-600 p-6 text-white">
                <h2 class="text-2xl font-bold">
                    <i class="fas fa-clock"></i> แก้ไขเวลาเข้า-ออก
                </h2>
            </div>
            
            <form id="edit-time-form" class="p-6 space-y-4">
                <input type="hidden" id="edit-record-id">
                <input type="hidden" id="edit-teacher-id">
                
                <div>
                    <label class="block text-gray-700 font-semibold mb-2">
                        ชื่อครู
                    </label>
                    <input type="text" id="edit-teacher-name" readonly
                           class="w-full px-4 py-2 bg-gray-100 border-2 border-gray-300 rounded-lg">
                </div>

                <div>
                    <label class="block text-gray-700 font-semibold mb-2">
                        <i class="fas fa-calendar"></i> วันที่
                    </label>
                    <input type="date" id="edit-attendance-date" readonly
                           class="w-full px-4 py-2 bg-gray-100 border-2 border-gray-300 rounded-lg">
                </div>
                
                <div>
                    <label class="block text-gray-700 font-semibold mb-2">
                        <i class="fas fa-sign-in-alt"></i> เวลาเข้า
                    </label>
                    <input type="time" id="edit-check-in-time" required
                           class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>
                
                <div>
                    <label class="block text-gray-700 font-semibold mb-2">
                        <i class="fas fa-sign-out-alt"></i> เวลาออก
                    </label>
                    <input type="time" id="edit-check-out-time"
                           class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>
                
                <div class="flex gap-3 pt-4">
                    <button type="button" onclick="closeEditTimeModal()"
                            class="flex-1 px-6 py-3 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition-all font-semibold">
                        <i class="fas fa-times"></i> ยกเลิก
                    </button>
                    <button type="submit" id="save-time-btn"
                            class="flex-1 px-6 py-3 bg-gradient-to-r from-blue-600 to-indigo-600 text-white rounded-lg hover:from-blue-700 hover:to-indigo-700 transition-all font-semibold shadow-lg">
                        <i class="fas fa-save"></i> บันทึก
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal แก้ไขเวลาเข้า-ออกหลายรายการ -->
    <div id="bulk-edit-time-modal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-[9000] flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full">
            <div class="bg-gradient-to-r from-amber-600 to-orange-600 p-6 text-white">
                <h2 class="text-2xl font-bold">
                    <i class="fas fa-users-cog"></i> แก้ไขเวลาแบบหลายคน
                </h2>
                <p id="bulk-edit-time-count" class="text-sm text-amber-100 mt-1">จำนวนที่เลือก: 0 รายการ</p>
            </div>

            <form id="bulk-edit-time-form" class="p-6 space-y-4">
                <div>
                    <label class="block text-gray-700 font-semibold mb-2">
                        <i class="fas fa-sign-in-alt"></i> เวลาเข้า
                    </label>
                    <input type="time" id="bulk-check-in-time" required
                           class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:outline-none focus:border-amber-500">
                </div>

                <div>
                    <label class="block text-gray-700 font-semibold mb-2">
                        <i class="fas fa-sign-out-alt"></i> เวลาออก
                    </label>
                    <input type="time" id="bulk-check-out-time"
                           class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:outline-none focus:border-amber-500">
                </div>

                <div class="flex gap-3 pt-4">
                    <button type="button" onclick="closeBulkEditTimeModal()"
                            class="flex-1 px-6 py-3 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition-all font-semibold">
                        <i class="fas fa-times"></i> ยกเลิก
                    </button>
                    <button type="submit" id="save-bulk-time-btn"
                            class="flex-1 px-6 py-3 bg-gradient-to-r from-amber-600 to-orange-600 text-white rounded-lg hover:from-amber-700 hover:to-orange-700 transition-all font-semibold shadow-lg">
                        <i class="fas fa-save"></i> บันทึกทั้งหมด
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div id="bulk-edit-remark-modal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-[9000] flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full">
            <div class="bg-gradient-to-r from-yellow-600 to-amber-600 p-6 text-white">
                <h2 class="text-2xl font-bold">
                    <i class="fas fa-note-sticky"></i> แก้ไขหมายเหตุแบบหลายคน
                </h2>
                <p id="bulk-edit-remark-count" class="text-sm text-yellow-100 mt-1">จำนวนที่เลือก: 0 รายการ</p>
            </div>

            <form id="bulk-edit-remark-form" class="p-6 space-y-4">
                <div>
                    <label class="block text-gray-700 font-semibold mb-2">
                        <i class="fas fa-sticky-note"></i> หมายเหตุ
                    </label>
                    <select id="bulk-remark-select"
                            class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:outline-none focus:border-amber-500">
                        <option value="">-</option>
                        <option value="ลาป่วย">ลาป่วย</option>
                        <option value="ลากิจ">ลากิจ</option>
                        <option value="ลาคลอดบุตร">ลาคลอดบุตร</option>
                        <option value="อบรม/ประชุม">อบรม/ประชุม</option>
                        <option value="ไปราชการ">ไปราชการ</option>
                        <option value="ลืมสแกนบัตร">ลืมสแกนบัตร</option>
                        <option value="ลืมบัตร">ลืมบัตร</option>
                        <option value="ขออนุญาตมาสาย">ขออนุญาตมาสาย</option>
                        <option value="อื่นๆ">อื่นๆ (ระบุ)</option>
                    </select>
                </div>

                <div id="bulk-remark-custom-field" class="hidden">
                    <label class="block text-gray-700 font-semibold mb-2">
                        ระบุหมายเหตุ
                    </label>
                    <input type="text" id="bulk-remark-custom-input"
                           class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:outline-none focus:border-amber-500"
                           placeholder="ระบุหมายเหตุ...">
                </div>

                <div class="flex gap-3 pt-4">
                    <button type="button" onclick="closeBulkEditRemarkModal()"
                            class="flex-1 px-6 py-3 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition-all font-semibold">
                        <i class="fas fa-times"></i> ยกเลิก
                    </button>
                    <button type="submit" id="save-bulk-remark-btn"
                            class="flex-1 px-6 py-3 bg-gradient-to-r from-yellow-600 to-amber-600 text-white rounded-lg hover:from-yellow-700 hover:to-amber-700 transition-all font-semibold shadow-lg">
                        <i class="fas fa-save"></i> บันทึกหมายเหตุ
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal รายละเอียดสรุปข้อมูล -->
    <div id="summary-detail-modal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-[9000] flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl shadow-2xl max-w-4xl w-full max-h-[85vh] flex flex-col">
            <div class="bg-gradient-to-r from-blue-600 to-indigo-600 p-5 text-white flex items-center justify-between">
                <div>
                    <h3 id="summary-detail-title" class="text-xl font-bold">รายละเอียด</h3>
                    <p id="summary-detail-meta" class="text-sm text-blue-100 mt-1"></p>
                </div>
                <button type="button" onclick="closeSummaryDetailModal()" class="text-white hover:text-gray-200 text-xl">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="p-4 overflow-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-600">ลำดับ</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-600">วันที่</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-600">เวลาเข้า/ออก</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-600">สถานะ</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-600">หมายเหตุ</th>
                        </tr>
                    </thead>
                    <tbody id="summary-detail-tbody" class="bg-white divide-y divide-gray-200"></tbody>
                </table>
            </div>
        </div>
    </div>

    <div id="teacher-detail-modal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-[9000] flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl shadow-2xl max-w-3xl w-full max-h-[85vh] flex flex-col overflow-hidden">
            <div class="bg-gradient-to-r from-slate-700 to-slate-900 p-5 text-white flex items-center justify-between">
                <div>
                    <h3 id="teacher-detail-title" class="text-xl font-bold">รายละเอียดบุคลากร</h3>
                    <p id="teacher-detail-subtitle" class="text-sm text-slate-200 mt-1"></p>
                </div>
                <button type="button" onclick="closeTeacherDetailModal()" class="text-white hover:text-gray-200 text-xl">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="p-5 overflow-auto">
                <div class="overflow-hidden rounded-xl border border-gray-200">
                    <table class="min-w-full divide-y divide-gray-200">
                        <tbody id="teacher-detail-tbody" class="divide-y divide-gray-200 bg-white"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="bg-gray-50 border-t border-gray-200 py-4 mt-auto">
        <div class="text-center text-gray-600 text-sm">
            <div class="mb-2">
                <i class="fas fa-code"></i> <strong>Chomthong School</strong>
            </div>
            <div class="flex items-center justify-center gap-4 text-gray-500">
                <svg class="w-7 h-7 text-black" viewBox="0 0 24 24" fill="currentColor" title="Next.js">
                    <path d="M11.572 0c-.176 0-.31.001-.358.007a19.76 19.76 0 0 1-.364.033C7.443.346 4.25 2.185 2.228 5.012a11.875 11.875 0 0 0-2.119 5.243c-.096.659-.108.854-.108 1.747s.012 1.089.108 1.748c.652 4.506 3.86 8.292 8.209 9.695.779.25 1.6.422 2.534.525.363.04 1.935.04 2.299 0 1.611-.178 2.977-.577 4.323-1.264.207-.106.247-.134.219-.158-.02-.013-.9-1.193-1.955-2.62l-1.919-2.592-2.404-3.558a338.739 338.739 0 0 0-2.422-3.556c-.009-.002-.018 1.579-.023 3.51-.007 3.38-.01 3.515-.052 3.595a.426.426 0 0 1-.206.214c-.075.037-.14.044-.495.044H7.81l-.108-.068a.438.438 0 0 1-.157-.171l-.05-.106.006-4.703.007-4.705.072-.092a.645.645 0 0 1 .174-.143c.096-.047.134-.051.54-.051.478 0 .558.018.682.154.035.038 1.337 1.999 2.895 4.361a10760.433 10760.433 0 0 0 4.735 7.17l1.9 2.879.096-.063a12.317 12.317 0 0 0 2.466-2.163 11.944 11.944 0 0 0 2.824-6.134c.096-.66.108-.854.108-1.748 0-.893-.012-1.088-.108-1.747-.652-4.506-3.859-8.292-8.208-9.695a12.597 12.597 0 0 0-2.499-.523A33.119 33.119 0 0 0 11.573 0zm4.069 7.217c.347 0 .408.005.486.047a.473.473 0 0 1 .237.277c.018.06.023 1.365.018 4.304l-.006 4.218-.744-1.14-.746-1.14v-3.066c0-1.982.01-3.097.023-3.15a.478.478 0 0 1 .233-.296c.096-.05.13-.054.5-.054z"/>
                </svg>
                <i class="fab fa-html5 text-2xl text-orange-600" title="HTML5"></i>
                <i class="fas fa-database text-2xl text-blue-600" title="MySQL"></i>
                <svg class="w-7 h-7 text-cyan-500" viewBox="0 0 24 24" fill="currentColor" title="Tailwind CSS">
                    <path d="M12 6c-2.67 0-4.33 1.33-5 4 1-1.33 2.17-1.83 3.5-1.5.76.19 1.31.74 1.91 1.35.98 1 2.09 2.15 4.59 2.15 2.67 0 4.33-1.33 5-4-1 1.33-2.17 1.83-3.5 1.5-.76-.19-1.3-.74-1.91-1.35C15.61 7.15 14.5 6 12 6m-5 6c-2.67 0-4.33 1.33-5 4 1-1.33 2.17-1.83 3.5-1.5.76.19 1.3.74 1.91 1.35C8.39 16.85 9.5 18 12 18c2.67 0 4.33-1.33 5-4-1 1.33-2.17 1.83-3.5 1.5-.76-.19-1.3-.74-1.91-1.35C10.61 13.15 9.5 12 7 12z"/>
                </svg>
            </div>
        </div>
    </footer>

    <script src="js/app.js?v=<?php echo filemtime(__DIR__ . '/js/app.js'); ?>"></script>
</body>
</html>
