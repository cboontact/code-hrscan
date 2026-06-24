<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title>ระบบลงเวลาราชการ ข้าราชการครูและบุคลากร - โรงเรียนจอมทอง</title>
    <link rel="icon" type="image/png" href="assets/images/logo.png">
    <link rel="shortcut icon" type="image/png" href="assets/images/logo.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --theme-primary: #2563eb;
            --theme-primary-dark: #1d4ed8;
            --theme-soft: #dbeafe;
            --theme-soft-text: #dbeafe;
            --theme-dot: rgba(37, 99, 235, 0.16);
            --theme-icon: rgba(37, 99, 235, 0.08);
            --theme-ring: rgba(37, 99, 235, 0.22);
            --theme-border: #3b82f6;
        }

        * {
            font-family: 'Noto Sans Thai', sans-serif;
        }

        body {
            background-color: #ffffff;
            background-image: radial-gradient(circle at 1px 1px, var(--theme-dot) 1.2px, transparent 0);
            background-size: 28px 28px;
        }

        .page-shell {
            position: relative;
            isolation: isolate;
        }

        .background-icons {
            position: fixed;
            inset: 0;
            pointer-events: none;
            z-index: 0;
            overflow: hidden;
        }

        .bg-icon {
            position: absolute;
            color: var(--theme-icon);
            filter: blur(0.2px);
        }

        .bg-icon.one {
            top: 10%;
            left: 6%;
            font-size: 4.5rem;
            transform: rotate(-12deg);
        }

        .bg-icon.two {
            top: 14%;
            right: 10%;
            font-size: 6rem;
            transform: rotate(8deg);
        }

        .bg-icon.three {
            top: 44%;
            left: 12%;
            font-size: 5rem;
            transform: rotate(-10deg);
        }

        .bg-icon.four {
            right: 7%;
            bottom: 22%;
            font-size: 5.5rem;
            transform: rotate(14deg);
        }

        .bg-icon.five {
            left: 42%;
            bottom: 10%;
            font-size: 4rem;
            transform: rotate(-6deg);
        }

        .content-layer {
            position: relative;
            z-index: 1;
        }

        .theme-primary-header {
            background: linear-gradient(135deg, var(--theme-primary), var(--theme-primary-dark));
        }

        .theme-secondary-header {
            background: linear-gradient(135deg, var(--theme-primary-dark), var(--theme-primary));
        }

        .theme-accent-text {
            color: var(--theme-primary) !important;
        }

        .theme-soft-text {
            color: var(--theme-soft-text) !important;
        }

        .theme-soft-surface {
            background: linear-gradient(180deg, #ffffff, var(--theme-soft));
        }

        .theme-border {
            border-color: var(--theme-border) !important;
        }

        .theme-input:focus {
            border-color: var(--theme-primary) !important;
            box-shadow: 0 0 0 4px var(--theme-ring);
        }

        .scan-animation {
            animation: scanPulse 2s ease-in-out infinite;
        }
        
        @keyframes scanPulse {
            0%, 100% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.05); opacity: 0.8; }
        }
        
        .success-animation {
            animation: successBounce 0.6s ease-out;
        }
        
        @keyframes successBounce {
            0% { transform: scale(0.3); opacity: 0; }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); opacity: 1; }
        }

        .spinner {
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top: 3px solid white;
            width: 20px;
            height: 20px;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .notification {
            animation: slideInRight 0.4s ease-out;
        }

        .rfid-mask-input {
            color: transparent;
            caret-color: var(--theme-primary);
        }

        .rfid-mask-input::placeholder {
            color: transparent;
        }

        .rfid-mask-overlay {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0 1rem;
            color: #374151;
            pointer-events: none;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            letter-spacing: 0.08em;
        }

        .rfid-mask-overlay.is-placeholder {
            color: #9ca3af;
            letter-spacing: normal;
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
    </style>
</head>
<body class="min-h-screen flex flex-col text-gray-900">
    <div class="page-shell min-h-screen flex flex-col">
    <div class="background-icons" aria-hidden="true">
        <i class="bg-icon one fas fa-id-card-alt"></i>
        <i class="bg-icon two far fa-clock"></i>
        <i class="bg-icon three fas fa-fingerprint"></i>
        <i class="bg-icon four far fa-calendar-check"></i>
        <i class="bg-icon five fas fa-wifi"></i>
    </div>

    <div id="notification-container" class="fixed bottom-4 right-4 z-50 space-y-2"></div>

    <div class="container mx-auto px-4 py-8 flex-grow content-layer">
        <!-- Header -->
        <div class="text-center mb-8">
            <img src="assets/images/logo.png?v=<?php echo time(); ?>" 
                 alt="โรงเรียนจอมทอง" 
                 class="mx-auto mb-4 w-32 h-32 object-contain rounded-full bg-white p-2 shadow-lg"
                 onerror="this.src='https://ui-avatars.com/api/?name=โรงเรียนจอมทอง&size=200&background=3b82f6&color=fff'">
            <h1 class="text-3xl font-bold text-gray-800 mb-2">
                <i class="fas fa-clock theme-accent-text"></i> ระบบลงเวลาราชการ ข้าราชการครูและบุคลากร
            </h1>
            <p class="text-xl text-gray-600 font-bold">โรงเรียนจอมทอง</p>
        </div>

        <!-- Main Content: 2 Columns -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 lg:grid-rows-1">
            <!-- Left Column: Scan Area (2/3 width) -->
            <div class="lg:col-span-2 flex">
                <div class="bg-white/95 backdrop-blur-sm rounded-2xl shadow-[0_24px_70px_-28px_rgba(15,23,42,0.28)] overflow-hidden flex-1 border border-white/80">
                <div class="theme-primary-header p-6 text-white">
                    <div class="flex justify-between items-start">
                        <div class="text-left">
                            <h2 class="text-4xl font-bold">
                                <i class="fas fa-id-card-alt"></i> สแกนบัตร RFID
                            </h2>
                            <p class="theme-soft-text mt-2 text-lg">กรุณานำบัตรเข้าใกล้เครื่องอ่าน</p>
                        </div>
                        <div class="text-right">
                            <div class="text-4xl font-bold" id="header-time">
                                <i class="far fa-clock"></i> <span id="header-time-text"></span>
                            </div>
                            <div class="text-lg theme-soft-text" id="header-date">
                                <i class="far fa-calendar-alt"></i> <span id="header-date-text"></span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="p-8">
                    <div id="scan-area" class="text-center mb-6">
                        <div class="theme-soft-surface inline-block p-8 rounded-full scan-animation mb-4 shadow-inner">
                            <i class="fas fa-id-card text-6xl theme-accent-text"></i>
                        </div>
                        <p class="text-gray-600 text-lg">
                            <i class="fas fa-hand-pointer"></i> กรุณาแตะบัตร RFID
                        </p>
                    </div>

                    <div id="result-area" class="hidden">
                        <div id="result-content"></div>
                    </div>

                    <div class="mt-6 max-w-lg mx-auto relative">
                        <input type="text" 
                               id="rfid-input" 
                               class="theme-input rfid-mask-input w-full px-4 py-3 border-2 border-gray-300 rounded-lg focus:outline-none transition-colors text-center text-lg"
                               placeholder="กรุณาสแกนบัตร"
                               inputmode="numeric"
                               autocomplete="off"
                               autocapitalize="off"
                               autocorrect="off"
                               spellcheck="false"
                               data-lpignore="true"
                               autofocus>
                        <div id="rfid-mask-overlay" class="rfid-mask-overlay is-placeholder">กรุณาสแกนบัตร</div>
                    </div>
                </div>
                </div>
            </div>

            <!-- Right Column: Recent Scans (1/3 width) -->
            <div class="lg:col-span-1 flex">
                <div class="bg-white/95 backdrop-blur-sm rounded-2xl shadow-[0_24px_70px_-28px_rgba(15,23,42,0.28)] overflow-hidden flex-1 flex flex-col border border-white/80">
                    <div class="theme-secondary-header p-4 text-white">
                        <h2 class="text-xl font-semibold text-center">
                            <i class="fas fa-history"></i> รายการล่าสุด
                        </h2>
                    </div>
                    <div id="recent-scans-list" class="p-3 overflow-y-auto flex-1">
                        <div class="text-center text-gray-400 py-8">
                            <i class="fas fa-spinner fa-spin text-3xl mb-2"></i>
                            <p>กำลังโหลด...</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <!-- Footer -->
    <footer class="py-4 mt-auto border-t border-slate-200/80 bg-transparent content-layer">
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
    </div>

    <script>
        const dayThemes = [
            { primary: '#dc2626', dark: '#991b1b', soft: '#fee2e2', softText: '#fecaca', dot: 'rgba(220, 38, 38, 0.15)', icon: 'rgba(220, 38, 38, 0.08)', ring: 'rgba(220, 38, 38, 0.20)', border: '#ef4444' },
            { primary: '#ca8a04', dark: '#a16207', soft: '#fef3c7', softText: '#fde68a', dot: 'rgba(202, 138, 4, 0.16)', icon: 'rgba(202, 138, 4, 0.08)', ring: 'rgba(202, 138, 4, 0.22)', border: '#eab308' },
            { primary: '#ec4899', dark: '#be185d', soft: '#fce7f3', softText: '#fbcfe8', dot: 'rgba(236, 72, 153, 0.15)', icon: 'rgba(236, 72, 153, 0.08)', ring: 'rgba(236, 72, 153, 0.20)', border: '#f472b6' },
            { primary: '#16a34a', dark: '#166534', soft: '#dcfce7', softText: '#bbf7d0', dot: 'rgba(22, 163, 74, 0.15)', icon: 'rgba(22, 163, 74, 0.08)', ring: 'rgba(22, 163, 74, 0.20)', border: '#22c55e' },
            { primary: '#ea580c', dark: '#c2410c', soft: '#ffedd5', softText: '#fed7aa', dot: 'rgba(234, 88, 12, 0.15)', icon: 'rgba(234, 88, 12, 0.08)', ring: 'rgba(234, 88, 12, 0.20)', border: '#f97316' },
            { primary: '#2563eb', dark: '#1d4ed8', soft: '#dbeafe', softText: '#bfdbfe', dot: 'rgba(37, 99, 235, 0.16)', icon: 'rgba(37, 99, 235, 0.08)', ring: 'rgba(37, 99, 235, 0.22)', border: '#3b82f6' },
            { primary: '#7c3aed', dark: '#5b21b6', soft: '#ede9fe', softText: '#ddd6fe', dot: 'rgba(124, 58, 237, 0.15)', icon: 'rgba(124, 58, 237, 0.08)', ring: 'rgba(124, 58, 237, 0.20)', border: '#8b5cf6' }
        ];

        function applyDayTheme(date) {
            const theme = dayThemes[date.getDay()];
            const root = document.documentElement;
            root.style.setProperty('--theme-primary', theme.primary);
            root.style.setProperty('--theme-primary-dark', theme.dark);
            root.style.setProperty('--theme-soft', theme.soft);
            root.style.setProperty('--theme-soft-text', theme.softText);
            root.style.setProperty('--theme-dot', theme.dot);
            root.style.setProperty('--theme-icon', theme.icon);
            root.style.setProperty('--theme-ring', theme.ring);
            root.style.setProperty('--theme-border', theme.border);
        }

        function updateDateTime() {
            const now = new Date();
            const dateOptions = { 
                year: 'numeric', 
                month: 'long', 
                day: 'numeric',
                weekday: 'long'
            };
            const timeOptions = { 
                hour: '2-digit', 
                minute: '2-digit', 
                second: '2-digit',
                hour12: false
            };
            
            // แสดงวันที่แบบมีเว้นวรรคระหว่าง "วัน" กับชื่อวัน
            const fullDate = now.toLocaleDateString('th-TH-u-ca-buddhist', dateOptions);
            const formattedDate = fullDate.replace(/^วัน/, 'วัน ');
            applyDayTheme(now);
            
            // อัปเดตวันที่และเวลาในหัวการ์ดสแกน RFID
            const headerTimeTextElement = document.getElementById('header-time-text');
            const headerDateTextElement = document.getElementById('header-date-text');
            
            if (headerTimeTextElement) {
                headerTimeTextElement.textContent = now.toLocaleTimeString('th-TH-u-ca-buddhist', timeOptions);
            }
            
            if (headerDateTextElement) {
                // แสดงวันที่แบบเต็ม วัน พุธที่ 11 มีนาคม 2569
                headerDateTextElement.textContent = formattedDate;
            }
        }

        updateDateTime();
        setInterval(updateDateTime, 1000);

        function updateRfidMask() {
            const input = document.getElementById('rfid-input');
            const overlay = document.getElementById('rfid-mask-overlay');
            if (!input || !overlay) return;

            if (!input.value) {
                overlay.textContent = 'กรุณาสแกนบัตร';
                overlay.classList.add('is-placeholder');
                return;
            }

            overlay.textContent = '♥'.repeat(input.value.length);
            overlay.classList.remove('is-placeholder');
        }

        function showNotification(message, type = 'success') {
            const container = document.getElementById('notification-container');
            const notification = document.createElement('div');
            
            const colors = {
                success: 'bg-green-500',
                error: 'bg-red-500',
                warning: 'bg-yellow-500',
                info: 'bg-blue-500'
            };

            const icons = {
                success: 'fa-check-circle',
                error: 'fa-exclamation-circle',
                warning: 'fa-exclamation-triangle',
                info: 'fa-info-circle'
            };

            notification.className = `notification ${colors[type]} text-white px-6 py-4 rounded-lg shadow-2xl flex items-center gap-3 min-w-[300px] max-w-md`;
            notification.innerHTML = `
                <i class="fas ${icons[type]} text-2xl"></i>
                <span class="flex-1">${message}</span>
                <button onclick="this.parentElement.remove()" class="text-white hover:text-gray-200">
                    <i class="fas fa-times"></i>
                </button>
            `;

            container.appendChild(notification);

            setTimeout(() => {
                notification.style.animation = 'slideInRight 0.4s ease-out reverse';
                setTimeout(() => notification.remove(), 400);
            }, 5000);
        }

        function setButtonLoading(button, loading) {
            if (loading) {
                button.disabled = true;
                button.innerHTML = '<div class="spinner mx-auto"></div>';
            } else {
                button.disabled = false;
                button.innerHTML = '<i class="fas fa-check-circle"></i><span>ยืนยัน</span>';
            }
        }

        function showWarningOnScreen(message, data) {
            const resultArea = document.getElementById('result-area');
            const resultContent = document.getElementById('result-content');
            const scanArea = document.getElementById('scan-area');

            scanArea.classList.add('hidden');
            resultArea.classList.remove('hidden');

            resultContent.innerHTML = `
                <div class="text-center py-8">
                    <div class="mb-6">
                        <i class="fas fa-exclamation-triangle text-yellow-500 text-6xl"></i>
                    </div>
                    <h3 class="text-3xl font-bold text-yellow-600 mb-4">
                        ${message}
                    </h3>
                    <div class="text-gray-700 text-lg mb-4">
                        <p class="font-semibold">${data.teacher.name}</p>
                        <p class="text-gray-600">${data.teacher.department}</p>
                    </div>
                    ${data.check_in_time ? `
                        <p class="text-gray-600">
                            <i class="fas fa-sign-in-alt text-green-600"></i> 
                            เวลามา: ${new Date(data.check_in_time).toLocaleTimeString('th-TH')}
                        </p>
                    ` : ''}
                </div>
            `;

            setTimeout(() => {
                resultArea.classList.add('hidden');
                scanArea.classList.remove('hidden');
                document.getElementById('rfid-input').value = '';
                updateRfidMask();
                document.getElementById('rfid-input').focus();
            }, 3000);
        }

        function showErrorOnScreen(message) {
            const resultArea = document.getElementById('result-area');
            const resultContent = document.getElementById('result-content');
            const scanArea = document.getElementById('scan-area');

            scanArea.classList.add('hidden');
            resultArea.classList.remove('hidden');

            resultContent.innerHTML = `
                <div class="text-center py-8">
                    <div class="mb-6">
                        <i class="fas fa-exclamation-circle text-red-500 text-6xl"></i>
                    </div>
                    <h3 class="text-3xl font-bold text-red-600 mb-4">
                        ${message}
                    </h3>
                    <p class="text-gray-600 text-lg">
                        กรุณาติดต่อเจ้าหน้าที่
                    </p>
                </div>
            `;

            setTimeout(() => {
                resultArea.classList.add('hidden');
                scanArea.classList.remove('hidden');
                document.getElementById('rfid-input').value = '';
                document.getElementById('rfid-input').focus();
            }, 3000);
        }

        function getAttendancePhaseBadges(record) {
            const hasCheckIn = !!record.check_in_time;
            const hasCheckOut = !!record.check_out_time;
            const status = record.status || '';

            const badges = [];

            if (hasCheckIn) {
                const isLateCheckIn = ['late', 'late_checked_out', 'late_no_check_out'].includes(status);
                badges.push({
                    label: isLateCheckIn ? 'เข้า: มาสาย' : 'เข้า: ตรงเวลา',
                    className: isLateCheckIn ? 'bg-yellow-100 text-yellow-800' : 'bg-green-100 text-green-800',
                    icon: isLateCheckIn ? 'fa-clock' : 'fa-right-to-bracket'
                });
            } else if (hasCheckOut || status === 'no_check_in_but_checked_out') {
                badges.push({
                    label: 'เข้า: ไม่สแกน',
                    className: 'bg-red-100 text-red-800',
                    icon: 'fa-ban'
                });
            }

            if (hasCheckOut) {
                badges.push({
                    label: 'ออก: ตรงเวลา',
                    className: 'bg-blue-100 text-blue-800',
                    icon: 'fa-right-from-bracket'
                });
            } else if (['on_time_no_check_out', 'late_no_check_out'].includes(status)) {
                badges.push({
                    label: 'ออก: ไม่สแกน',
                    className: 'bg-orange-100 text-orange-800',
                    icon: 'fa-hourglass-end'
                });
            }

            return badges;
        }

        function renderAttendancePhaseBadges(record) {
            return getAttendancePhaseBadges(record).map((badge) => `
                <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-semibold ${badge.className}">
                    <i class="fas ${badge.icon} mr-2"></i>${badge.label}
                </span>
            `).join('');
        }

        function showResult(data, type) {
            const resultArea = document.getElementById('result-area');
            const resultContent = document.getElementById('result-content');
            const scanArea = document.getElementById('scan-area');

            scanArea.classList.add('hidden');
            resultArea.classList.remove('hidden');

            const photoUrl = data.teacher.photo ? './' + data.teacher.photo : 'https://ui-avatars.com/api/?name=' + encodeURIComponent(data.teacher.name) + '&size=200&background=random';

            resultContent.innerHTML = `
                <div class="success-animation text-center">
                    <div class="mb-4">
                        <img src="${photoUrl}" 
                             alt="${data.teacher.name}" 
                             class="w-32 h-32 rounded-full mx-auto object-cover border-4 theme-border shadow-lg"
                             onerror="this.src='https://ui-avatars.com/api/?name=${encodeURIComponent(data.teacher.name)}&size=200&background=random'">
                    </div>
                    <h3 class="text-2xl font-bold text-gray-800 mb-2">
                        ${data.teacher.name}
                    </h3>
                    <p class="text-gray-600 mb-4">
                        <i class="fas fa-building"></i> ${data.teacher.department}
                    </p>
                    <div class="text-lg font-semibold text-gray-700 mb-4">
                        ${data.status_text || (data.type === 'check_in' ? 'ลงเวลามา' : 'ลงเวลากลับ')}
                    </div>
                    <div class="flex flex-wrap items-center justify-center gap-2 mb-4">
                        ${renderAttendancePhaseBadges(data)}
                    </div>
                    <div class="bg-gray-50 rounded-lg p-4 space-y-2">
                        ${data.check_in_time ? `
                            <p class="text-gray-700">
                                <i class="fas fa-sign-in-alt text-green-600"></i> 
                                <strong>เวลามา:</strong> ${new Date(data.check_in_time).toLocaleTimeString('th-TH')}
                            </p>
                        ` : ''}
                        ${data.check_out_time ? `
                            <p class="text-gray-700">
                                <i class="fas fa-sign-out-alt theme-accent-text"></i> 
                                <strong>เวลากลับ:</strong> ${new Date(data.check_out_time).toLocaleTimeString('th-TH')}
                            </p>
                        ` : ''}
                    </div>
                </div>
            `;

            setTimeout(() => {
                resultArea.classList.add('hidden');
                scanArea.classList.remove('hidden');
                document.getElementById('rfid-input').value = '';
                document.getElementById('rfid-input').focus();
            }, 3000);
        }

        async function scanRFID() {
            const rfidInput = document.getElementById('rfid-input');
            const rfidCode = rfidInput.value.trim();

            if (!rfidCode) {
                showNotification('กรุณากรอกรหัส RFID', 'warning');
                return;
            }

            // Disable input ขณะกำลังส่งข้อมูล
            rfidInput.disabled = true;

            try {
                const apiUrl = './api/scan.php';
                const response = await fetch(apiUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({ rfid_code: rfidCode })
                });
                
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                const result = await response.json();

                if (result.success) {
                    // ตรวจสอบว่าเป็นกรณีพิเศษที่ต้องแสดงเตือนบนหน้าจอหรือไม่
                    if (result.data.type === 'too_early' || result.data.type === 'too_late' || 
                        result.data.type === 'already_completed' || result.data.type === 'no_check_in') {
                        showWarningOnScreen(result.message, result.data);
                    } else {
                        showNotification(result.message, 'success');
                        showResult(result.data, result.data.type);
                    }
                } else {
                    // แสดง error บนหน้าจอสแกนโดยตรง
                    showErrorOnScreen(result.message);
                }

            } catch (error) {
                console.error('Error details:', error);
                showNotification('เกิดข้อผิดพลาด: ' + error.message, 'error');
            } finally {
                rfidInput.value = '';
                rfidInput.disabled = false;
                updateRfidMask();
                rfidInput.focus();
            }
        }

        let autoScanTimeout;
        
        document.getElementById('rfid-input').addEventListener('input', (e) => {
            const rfidCode = e.target.value.trim();
            updateRfidMask();
            
            // ล้าง timeout เดิม
            clearTimeout(autoScanTimeout);
            
            // ถ้ากรอกครบแล้ว (มากกว่า 3 ตัวอักษร) ให้ส่งอัตโนมัติหลังจาก 500ms
            if (rfidCode.length >= 3) {
                autoScanTimeout = setTimeout(() => {
                    scanRFID();
                }, 500);
            }
        });
        
        document.getElementById('rfid-input').addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                clearTimeout(autoScanTimeout);
                scanRFID();
            }
        });

        updateRfidMask();
        document.getElementById('rfid-input').focus();

        // ฟังก์ชันดึงและแสดงรายการสแกนล่าสุด
        async function loadRecentScans() {
            try {
                const response = await fetch('./api/recent-scans.php');
                const result = await response.json();
                
                if (result.success && result.data.records.length > 0) {
                    displayRecentScans(result.data.records);
                } else {
                    document.getElementById('recent-scans-list').innerHTML = `
                        <div class="text-center text-gray-400 py-8">
                            <i class="fas fa-inbox text-3xl mb-2"></i>
                            <p>ยังไม่มีรายการสแกน</p>
                        </div>
                    `;
                }
            } catch (error) {
                console.error('Error loading recent scans:', error);
            }
        }

        // ฟังก์ชันแสดงรายการสแกนล่าสุด
        function displayRecentScans(records) {
            const container = document.getElementById('recent-scans-list');
            
            container.innerHTML = records.map(record => {
                const checkInTime = record.check_in_time ? new Date(record.check_in_time).toLocaleTimeString('th-TH', {hour: '2-digit', minute: '2-digit'}) : '-';
                const checkOutTime = record.check_out_time ? new Date(record.check_out_time).toLocaleTimeString('th-TH', {hour: '2-digit', minute: '2-digit'}) : '-';
                const hasCheckOut = record.check_out_time !== null;
                
                const photoUrl = record.photo ? './' + record.photo : 'https://ui-avatars.com/api/?name=' + encodeURIComponent(record.first_name + ' ' + record.last_name) + '&size=80&background=random';
                
                return `
                    <div class="mb-3 p-3 bg-gray-50 rounded-lg border border-gray-200 hover:shadow-md transition-shadow">
                        <div class="flex items-start gap-3">
                            <div class="flex-shrink-0">
                                <img src="${photoUrl}" 
                                     alt="${record.first_name} ${record.last_name}" 
                                     class="w-10 h-10 rounded-full object-cover border-2 theme-border"
                                     onerror="this.src='https://ui-avatars.com/api/?name=${encodeURIComponent(record.first_name + ' ' + record.last_name)}&size=80&background=random'">
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-semibold text-gray-800 truncate">
                                    ${record.first_name} ${record.last_name}
                                </p>
                                <p class="text-xs text-gray-500 truncate">${record.department}</p>
                                <div class="mt-1 flex flex-wrap items-center gap-1 text-xs">
                                    ${renderAttendancePhaseBadges(record)}
                                </div>
                                <div class="mt-2 text-xs text-gray-600">
                                    <div class="flex items-center gap-1">
                                        <i class="fas fa-sign-in-alt text-green-600"></i>
                                        <span>${checkInTime}</span>
                                        ${hasCheckOut ? `
                                            <i class="fas fa-arrow-right mx-1 text-gray-400"></i>
                                            <i class="fas fa-sign-out-alt theme-accent-text"></i>
                                            <span>${checkOutTime}</span>
                                        ` : ''}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            }).join('');
        }

        // โหลดรายการสแกนล่าสุดทันทีเมื่อเปิดหน้า
        loadRecentScans();

        // รีเฟรชรายการสแกนล่าสุดทุก 5 วินาที
        setInterval(loadRecentScans, 5000);
    </script>
</body>
</html>
