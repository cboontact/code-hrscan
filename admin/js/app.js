const API_BASE = '../api/';
let currentUser = null;
let currentPage = 1;
let currentTab = 'dashboard';
const DEBUG_SUMMARY = false;
let summaryRecordsCache = [];
let summarySearchKeyword = '';
let summaryDepartmentFilter = '';
let currentAdminLogsPage = 1;
let adminLogsSearchTimeout;
const DELETE_TEACHER_CONFIRM_CODE = '50052001';
let teacherQuickMap = {};
let attendanceRecordsCache = [];
const selectedAttendanceRows = new Map();
let bodyScrollLockY = 0;
const openModalIds = new Set();

function lockBodyScroll() {
    if (openModalIds.size > 0) return;

    bodyScrollLockY = window.scrollY || document.documentElement.scrollTop || 0;
    const scrollbarWidth = window.innerWidth - document.documentElement.clientWidth;
    document.body.style.position = 'fixed';
    document.body.style.top = `-${bodyScrollLockY}px`;
    document.body.style.left = '0';
    document.body.style.right = '0';
    document.body.style.width = '100%';
    if (scrollbarWidth > 0) {
        document.body.style.paddingRight = `${scrollbarWidth}px`;
    }
}

function unlockBodyScroll() {
    if (openModalIds.size > 0) return;

    document.body.style.position = '';
    document.body.style.top = '';
    document.body.style.left = '';
    document.body.style.right = '';
    document.body.style.width = '';
    document.body.style.paddingRight = '';
    window.scrollTo({ top: bodyScrollLockY, behavior: 'auto' });
}

function openModalElement(modal) {
    if (!modal) return;

    lockBodyScroll();
    modal.classList.remove('hidden');
    if (modal.id) {
        openModalIds.add(modal.id);
    }
}

function closeModalElement(modal) {
    if (!modal) return;

    const wasOpen = !modal.classList.contains('hidden') || (modal.id && openModalIds.has(modal.id));
    modal.classList.add('hidden');
    if (modal.id) {
        openModalIds.delete(modal.id);
    }
    if (wasOpen) {
        unlockBodyScroll();
    }
}

function setModalElementOpen(modal, open) {
    if (open) {
        openModalElement(modal);
    } else {
        closeModalElement(modal);
    }
}

function initializeBuddhistDateInputs() {
    document.querySelectorAll('input[type="date"]').forEach(input => {
        input.setAttribute('lang', 'th-TH-u-ca-buddhist');
    });
}

function summaryLog(...args) {
    if (!DEBUG_SUMMARY) return;
}

let lastNotificationSignature = '';
let lastNotificationAt = 0;

function showNotification(message, type = 'success') {
    const container = document.getElementById('notification-container');
    if (!container) return;

    const normalizedMessage = String(message ?? '').trim();
    const signature = `${type}|${normalizedMessage}`;
    const now = Date.now();

    // กันแจ้งเตือนเดิมเด้งซ้ำติดกันในช่วงสั้น ๆ
    if (signature === lastNotificationSignature && now - lastNotificationAt < 750) {
        return;
    }

    lastNotificationSignature = signature;
    lastNotificationAt = now;

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
        <span class="flex-1">${normalizedMessage}</span>
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

function setButtonLoading(button, loading, originalText = '') {
    if (loading) {
        button.disabled = true;
        button.dataset.originalHtml = button.innerHTML;
        button.innerHTML = '<div class="spinner mx-auto"></div>';
    } else {
        button.disabled = false;
        button.innerHTML = button.dataset.originalHtml || originalText;
    }
}

async function apiRequest(endpoint, method = 'GET', data = null, skipNotification = false) {
    const controller = new AbortController();
    const timeoutMs = 15000;
    const timeoutId = setTimeout(() => controller.abort(), timeoutMs);
    const requestStart = Date.now();

    const options = {
        method: method,
        headers: {
            'Content-Type': 'application/json'
        },
        signal: controller.signal
    };

    if (data && method !== 'GET') {
        options.body = JSON.stringify(data);
    }

    try {
        if (DEBUG_SUMMARY && endpoint.includes('summary-report.php')) {
            summaryLog('API request start', { endpoint, method, data });
        }

        const response = await fetch(API_BASE + endpoint, options);
        const rawText = await response.text();
        let result;

        try {
            result = JSON.parse(rawText);
        } catch (parseError) {
            if (DEBUG_SUMMARY && endpoint.includes('summary-report.php')) {
                summaryLog('API response parse error', {
                    endpoint,
                    status: response.status,
                    preview: rawText.slice(0, 300)
                });
            }
            throw new Error(`Invalid JSON response from ${endpoint}`);
        }

        if (DEBUG_SUMMARY && endpoint.includes('summary-report.php')) {
            summaryLog('API request done', {
                endpoint,
                status: response.status,
                success: result.success,
                durationMs: Date.now() - requestStart,
                message: result.message
            });
        }

        if (!result.success && response.status === 401) {
            showDashboard(false);
            if (!skipNotification) {
                showNotification('กรุณาเข้าสู่ระบบใหม่', 'error');
            }
        }

        return result;
    } catch (error) {
        if (!skipNotification) {
            console.error('API Error:', error);
        }

        if (error.name === 'AbortError') {
            return { success: false, message: 'คำขอใช้เวลานานเกินไป กรุณาลองใหม่' };
        }

        return { success: false, message: 'เกิดข้อผิดพลาดในการเชื่อมต่อ' };
    } finally {
        clearTimeout(timeoutId);
    }
}

function showDashboard(show) {
    const loginPage = document.getElementById('login-page');
    const dashboardPage = document.getElementById('dashboard-page');

    if (loginPage) {
        loginPage.classList.toggle('hidden', show);
        loginPage.style.display = show ? 'none' : '';
    }
    if (dashboardPage) {
        dashboardPage.classList.toggle('hidden', !show);
        dashboardPage.style.display = show ? '' : 'none';
    }
}

async function checkAuth() {
    try {
        const result = await apiRequest('auth.php?action=check', 'GET', null, true);
        if (result.success) {
            currentUser = result.data.user;
            document.getElementById('admin-name').textContent = currentUser.full_name;
            document.getElementById('admin-role').textContent = getRoleText(currentUser.role);
            showDashboard(true);
            loadDashboard();
        } else {
            showDashboard(false);
        }
    } catch (error) {
        console.error('Auth check error:', error);
        showDashboard(false);
    }
}

function getRoleText(role) {
    const normalizedRole = normalizeRole(role);
    const roles = {
        'super_admin': 'ผู้ดูแลระบบหลัก',
        'admin': 'ผู้ดูแลระบบ',
        'viewer': 'ผู้ดูข้อมูล'
    };
    return roles[normalizedRole] || role;
}

function normalizeRole(role) {
    return String(role || '').trim().toLowerCase().replace(/[\s-]+/g, '_');
}

function canViewRfid() {
    return currentUser && normalizeRole(currentUser.role) === 'super_admin';
}

function canEditRfid() {
    const role = currentUser ? normalizeRole(currentUser.role) : '';
    return role === 'super_admin' || role === 'admin';
}

function canManageTimeRules() {
    return currentUser && normalizeRole(currentUser.role) === 'super_admin';
}

function canManageAcademicCalendars() {
    return currentUser && normalizeRole(currentUser.role) === 'super_admin';
}

function getAttendancePhaseBadges(record) {
    const hasCheckIn = !!record.check_in_time;
    const hasCheckOut = !!record.check_out_time;
    const status = String(record.status || '').trim();
    const statusText = String(record.status_text || '').trim();
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

    if (!badges.length) {
        badges.push({
            label: statusText || 'ยังไม่ลงเวลา',
            className: 'bg-red-200 text-red-900',
            icon: 'fa-circle-minus'
        });
    }

    return badges;
}

function renderAttendancePhaseBadges(record, size = 'sm') {
    const sizeClass = size === 'xs' ? 'px-2 py-0.5 text-xs' : 'px-3 py-1 text-sm';
    return getAttendancePhaseBadges(record).map((badge) => `
        <span class="inline-flex items-center rounded-full font-semibold whitespace-nowrap ${sizeClass} ${badge.className}">
            <i class="fas ${badge.icon} mr-1"></i>${escapeHtml(badge.label)}
        </span>
    `).join('');
}

function getAttendanceRemarkDisplay(record) {
    const remark = String(record?.remark || '').trim();
    return remark !== '' ? remark : '-';
}

function syncTeacherRfidVisibility() {
    const teacherId = document.getElementById('teacher-id')?.value || '';
    const teacherRfidGroup = document.getElementById('teacher-rfid-field-group');
    const quickRfidGroup = document.getElementById('quick-rfid-code-group');
    const teacherRfidInput = document.getElementById('teacher-rfid-code');
    const quickRfidInput = document.getElementById('quick-rfid-code');
    const allowVisibleRfid = canEditRfid();
    const isCreateMode = !teacherId;

    if (teacherRfidGroup) {
        teacherRfidGroup.classList.toggle('hidden', !allowVisibleRfid);
    }
    if (teacherRfidInput) {
        teacherRfidInput.disabled = !allowVisibleRfid;
        teacherRfidInput.required = allowVisibleRfid && isCreateMode;
        if (!allowVisibleRfid) {
            teacherRfidInput.value = '';
            teacherRfidInput.placeholder = '';
        }
    }

    if (quickRfidGroup) {
        quickRfidGroup.classList.toggle('hidden', !canEditRfid());
    }
    if (quickRfidInput) {
        quickRfidInput.disabled = !canEditRfid();
        quickRfidInput.required = canEditRfid();
        if (!canEditRfid()) {
            quickRfidInput.value = '';
            quickRfidInput.placeholder = '';
        }
    }
}

document.getElementById('login-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    
    const username = document.getElementById('username').value;
    const password = document.getElementById('password').value;
    const loginBtn = document.getElementById('login-btn');

    setButtonLoading(loginBtn, true);

    try {
        const result = await apiRequest('auth.php?action=login', 'POST', { username, password });

        if (result.success) {
            showNotification(result.message, 'success');
            currentUser = result.data.user;
            document.getElementById('admin-name').textContent = currentUser.full_name;
            document.getElementById('admin-role').textContent = getRoleText(currentUser.role);
            showDashboard(true);
            loadDashboard();
        } else {
            showNotification(result.message, 'error');
        }
    } catch (error) {
        console.error('Login error:', error);
        showNotification('เกิดข้อผิดพลาดในการเข้าสู่ระบบ', 'error');
    } finally {
        setButtonLoading(loginBtn, false, '<i class="fas fa-sign-in-alt"></i><span>เข้าสู่ระบบ</span>');
    }
});

document.getElementById('logout-btn').addEventListener('click', async () => {
    try {
        const result = await apiRequest('auth.php?action=logout', 'POST');
        showNotification(result.message, 'success');
        showDashboard(false);
        document.getElementById('login-form').reset();
    } catch (error) {
        console.error('Logout error:', error);
    }
});

document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        const tab = btn.dataset.tab;
        switchTab(tab);
    });
});

function switchTab(tab) {
    currentTab = tab;
    if (tab === 'summary') {
        summaryLog('Switch to summary tab');
    }
    
    document.querySelectorAll('.tab-btn').forEach(btn => {
        if (btn.dataset.tab === tab) {
            btn.classList.add('text-blue-600', 'border-b-2', 'border-blue-600');
            btn.classList.remove('text-gray-600');
        } else {
            btn.classList.remove('text-blue-600', 'border-b-2', 'border-blue-600');
            btn.classList.add('text-gray-600');
        }
    });

    document.querySelectorAll('.tab-content').forEach(content => {
        content.classList.add('hidden');
    });

    document.getElementById(`tab-${tab}`).classList.remove('hidden');
    document.getElementById(`tab-${tab}`).classList.add('fade-in');

    if (tab === 'dashboard') {
        loadDashboard();
    } else if (tab === 'attendance') {
        loadAttendance();
    } else if (tab === 'summary') {
        initializeSummaryTab(true);
    } else if (tab === 'teachers') {
        loadTeachers();
    } else if (tab === 'settings') {
        loadSettings();
    }
}

async function loadDashboard() {
    const dateInput = document.getElementById('dashboard-date');
    if (!dateInput.value) {
        setThaiInputDateValue('dashboard-date', new Date().toISOString().split('T')[0]);
    }
    syncVisibleDateDisplay('dashboard-date', 'dashboard-date-display');
    const dashboardDateIso = getIsoDateInputValue('dashboard-date');
    if (!dashboardDateIso) {
        showNotification('รูปแบบวันที่ไม่ถูกต้อง (ตัวอย่าง 10/03/2569)', 'error');
        return;
    }

    document.getElementById('stats-loading').classList.remove('hidden');
    document.getElementById('stats-content').classList.add('hidden');

    try {
        const result = await apiRequest(`attendance.php?action=stats&date=${dashboardDateIso}`);

        if (result.success) {
            const stats = result.data;
            
            document.getElementById('stat-total-teachers').textContent = stats.total_teachers;
            document.getElementById('stat-checked-in').textContent = stats.checked_in;
            document.getElementById('stat-late').textContent = stats.late;
            document.getElementById('stat-not-checked').textContent = stats.not_checked_in;
            document.getElementById('stat-remark').textContent = stats.remark_count ?? 0;

            await loadRecentAttendance(dashboardDateIso);
            document.getElementById('stats-loading').classList.add('hidden');
            document.getElementById('stats-content').classList.remove('hidden');
        } else {
            showNotification(result.message, 'error');
        }
    } catch (error) {
        console.error('Dashboard error:', error);
        showNotification('เกิดข้อผิดพลาดในการโหลดข้อมูล', 'error');
    }
}

async function loadRecentAttendance(date) {
    try {
        const result = await apiRequest(`attendance.php?action=list&date=${date}&limit=6&sort=recent`);

        if (result.success) {
            const container = document.getElementById('recent-attendance');
            
            if (result.data.records.length === 0) {
                container.innerHTML = `
                    <div class="col-span-full text-center py-8 text-gray-500">
                        <i class="fas fa-inbox text-4xl mb-2"></i>
                        <p>ยังไม่มีข้อมูลการลงเวลา</p>
                    </div>
                `;
                return;
            }

            container.innerHTML = result.data.records.map(record => {
                // ถ้ามีหมายเหตุ ให้แสดงสีส้ม
                const hasRemark = record.remark && record.remark.trim() !== '';
                
                const checkInTime = record.check_in_time ? new Date(record.check_in_time).toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit' }) : '-';
                const checkOutTime = record.check_out_time ? new Date(record.check_out_time).toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit' }) : '-';

                return `
                    <div class="bg-gray-50 rounded-lg p-3 border border-gray-100 hover:bg-gray-100 transition-colors">
                        <div class="flex items-start gap-3">
                            <img src="${record.teacher.photo ? '../' + record.teacher.photo : 'https://ui-avatars.com/api/?name=' + encodeURIComponent(record.teacher.name) + '&size=80&background=random'}" 
                                 alt="${record.teacher.name}" 
                                 class="w-10 h-10 rounded-full object-cover border-2 border-blue-500"
                                 onerror="this.src='https://ui-avatars.com/api/?name=${encodeURIComponent(record.teacher.name)}&size=80&background=random'">
                            <div class="min-w-0 flex-1">
                                <p class="font-semibold text-gray-800 truncate">${record.teacher.name}</p>
                                <p class="text-sm text-gray-600 truncate">
                                    <i class="fas fa-building"></i> ${record.teacher.department}
                                </p>
                            </div>
                        </div>
                        <div class="mt-3">
                            <div class="flex flex-wrap gap-1">
                                ${renderAttendancePhaseBadges(record, 'xs')}
                            </div>
                            <p class="text-sm text-gray-600 mt-2 whitespace-nowrap">
                                <i class="fas fa-sign-in-alt text-green-600"></i> ${checkInTime}
                                ${record.check_out_time ? `<i class="fas fa-sign-out-alt text-blue-600 ml-2"></i> ${checkOutTime}` : ''}
                            </p>
                        </div>
                    </div>
                `;
            }).join('');
        }
    } catch (error) {
        console.error('Recent attendance error:', error);
    }
}

document.getElementById('dashboard-date').addEventListener('change', () => {
    syncVisibleDateDisplay('dashboard-date', 'dashboard-date-display');
    loadDashboard();
});

async function loadAttendance(page = 1) {
    const dateInput = document.getElementById('attendance-date');
    if (!dateInput.value) {
        setThaiInputDateValue('attendance-date', new Date().toISOString().split('T')[0]);
    }
    syncVisibleDateDisplay('attendance-date', 'attendance-date-display');
    const attendanceDateIso = getIsoDateInputValue('attendance-date');
    if (!attendanceDateIso) {
        showNotification('รูปแบบวันที่ไม่ถูกต้อง (ตัวอย่าง 10/03/2569)', 'error');
        return;
    }

    const search = document.getElementById('attendance-search').value;
    const department = document.getElementById('attendance-department').value;
    const status = document.getElementById('attendance-status').value;

    document.getElementById('attendance-loading').classList.remove('hidden');
    document.getElementById('attendance-content').classList.add('hidden');

    try {
        await loadDepartments('attendance-department');

        const params = new URLSearchParams({
            action: 'list',
            date: attendanceDateIso,
            page: page,
            limit: 20
        });

        if (search) params.append('search', search);
        if (department) params.append('department', department);
        if (status) params.append('status', status);

        const result = await apiRequest(`attendance.php?${params.toString()}`);

        if (result.success) {
            attendanceRecordsCache = Array.isArray(result.data.records) ? result.data.records : [];
            selectedAttendanceRows.clear();
            renderAttendanceTable(attendanceRecordsCache);
            updateAttendanceSelectionUI();
            renderPagination('attendance-pagination', result.data.pagination, loadAttendance);

            document.getElementById('attendance-loading').classList.add('hidden');
            document.getElementById('attendance-content').classList.remove('hidden');
        } else {
            showNotification(result.message, 'error');
        }
    } catch (error) {
        console.error('Attendance error:', error);
        showNotification('เกิดข้อผิดพลาดในการโหลดข้อมูล', 'error');
    }
}

function getAttendanceSelectionKey(record) {
    if (!record || !record.teacher) return '';
    if (record.id) return `record-${record.id}`;
    return `new-${record.teacher.id}-${record.attendance_date}`;
}

function updateAttendanceSelectionUI() {
    const countEl = document.getElementById('attendance-selected-count');
    const bulkBtn = document.getElementById('bulk-edit-time-btn');
    const bulkRemarkBtn = document.getElementById('bulk-edit-remark-btn');
    const selectAll = document.getElementById('attendance-select-all');
    const selectableRecords = attendanceRecordsCache;
    const selectedCount = selectedAttendanceRows.size;

    if (countEl) {
        countEl.textContent = `เลือกแล้ว ${selectedCount} รายการ`;
    }
    if (bulkBtn) {
        bulkBtn.disabled = selectedCount === 0;
    }
    if (bulkRemarkBtn) {
        bulkRemarkBtn.disabled = selectedCount === 0;
    }
    if (selectAll) {
        if (!selectableRecords.length) {
            selectAll.checked = false;
            selectAll.indeterminate = false;
            selectAll.disabled = true;
            return;
        }
        selectAll.disabled = false;
        selectAll.checked = selectedCount === selectableRecords.length;
        selectAll.indeterminate = selectedCount > 0 && selectedCount < selectableRecords.length;
    }
}

window.toggleAttendanceRowSelection = function(selectionKey) {
    if (!selectionKey) return;
    const checkbox = document.getElementById(`attendance-select-${selectionKey}`);
    const record = attendanceRecordsCache.find(item => getAttendanceSelectionKey(item) === selectionKey);
    if (!checkbox || !record) return;

    if (checkbox.checked) {
        selectedAttendanceRows.set(selectionKey, {
            recordId: record.id || null,
            teacherId: record.teacher.id,
            teacherName: record.teacher.name,
            attendanceDate: record.attendance_date
        });
    } else {
        selectedAttendanceRows.delete(selectionKey);
    }

    updateAttendanceSelectionUI();
};

window.toggleAllAttendanceRows = function() {
    const selectAll = document.getElementById('attendance-select-all');
    if (!selectAll) return;

    const selectableRecords = attendanceRecordsCache;
    selectableRecords.forEach((record) => {
        const selectionKey = getAttendanceSelectionKey(record);
        const checkbox = document.getElementById(`attendance-select-${selectionKey}`);
        if (!checkbox) return;

        checkbox.checked = selectAll.checked;
        if (selectAll.checked) {
            selectedAttendanceRows.set(selectionKey, {
                recordId: record.id || null,
                teacherId: record.teacher.id,
                teacherName: record.teacher.name,
                attendanceDate: record.attendance_date
            });
        } else {
            selectedAttendanceRows.delete(selectionKey);
        }
    });

    updateAttendanceSelectionUI();
};

function renderAttendanceTable(records) {
    const tbody = document.getElementById('attendance-table-body');

    if (records.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="8" class="px-6 py-12 text-center text-gray-500">
                    <i class="fas fa-inbox text-4xl mb-2"></i>
                    <p>ไม่พบข้อมูล</p>
                </td>
            </tr>
        `;
        updateAttendanceSelectionUI();
        return;
    }

    tbody.innerHTML = records.map((record, index) => {
        const hasRemark = record.remark && record.remark.trim() !== '';
        const checkInTime = record.check_in_time ? new Date(record.check_in_time).toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit' }) : '-';
        const checkOutTime = record.check_out_time ? new Date(record.check_out_time).toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit' }) : '-';

        const bgClass = index % 2 === 0 ? 'bg-white' : 'bg-gray-50';
        const selectionKey = getAttendanceSelectionKey(record);
        const isSelected = selectedAttendanceRows.has(selectionKey);

        return `
            <tr class="${bgClass} hover:bg-blue-50 transition-colors">
                <td class="px-4 py-4 text-center align-top">
                    <input type="checkbox"
                           id="attendance-select-${selectionKey}"
                           class="w-4 h-4 text-blue-600 rounded border-gray-300 focus:ring-blue-500"
                           onchange="toggleAttendanceRowSelection('${selectionKey}')"
                           ${isSelected ? 'checked' : ''}>
                </td>
                <td class="px-6 py-4">
                    <div class="flex items-center gap-3">
                        <img src="${record.teacher.photo ? '../' + record.teacher.photo : 'https://ui-avatars.com/api/?name=' + encodeURIComponent(record.teacher.name) + '&size=80&background=random'}" 
                             alt="${record.teacher.name}" 
                             class="w-10 h-10 rounded-full object-cover border-2 border-blue-500"
                             onerror="this.src='https://ui-avatars.com/api/?name=${encodeURIComponent(record.teacher.name)}&size=80&background=random'">
                        <div>
                            <button type="button"
                                    onclick="editTeacher(${record.teacher.id})"
                                    class="block text-left font-semibold text-gray-800 hover:text-blue-700 transition-colors">
                                ${record.teacher.name}
                            </button>
                            <span class="block text-left text-xs text-gray-600">
                                ${record.teacher.citizen_id}
                            </span>
                        </div>
                    </div>
                </td>
                <td class="px-6 py-4 text-gray-700">${record.teacher.department}</td>
                <td class="px-6 py-4">
                    <span class="text-gray-800 font-semibold">
                        <i class="fas fa-sign-in-alt text-green-600"></i> ${checkInTime}
                    </span>
                </td>
                <td class="px-6 py-4">
                    <span class="text-gray-800 font-semibold">
                        <i class="fas fa-sign-out-alt text-blue-600"></i> ${checkOutTime}
                    </span>
                </td>
                <td class="px-6 py-4">
                    <div class="flex flex-wrap gap-2">
                        ${renderAttendancePhaseBadges(record)}
                    </div>
                </td>
                <td class="px-6 py-4">
                    <button onclick="openEditTimeModal(${record.id ?? 'null'}, ${record.teacher.id}, '${record.teacher.name}', '${record.attendance_date}')" 
                            class="inline-flex items-center gap-1 whitespace-nowrap px-3 py-1 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors text-sm">
                        <i class="fas fa-clock"></i>
                        <span>${record.id ? 'แก้ไข' : 'เพิ่ม'}เวลา</span>
                    </button>
                </td>
                <td class="px-6 py-4">
                    <div class="flex items-center gap-2">
                        <span class="text-sm text-gray-700" id="remark-text-${record.id || 'new-' + record.teacher.id}">
                            ${escapeHtml(getAttendanceRemarkDisplay(record))}
                        </span>
                        ${record.id ? `
                        <button onclick="openRemarkModal(${record.id}, '${record.teacher.name}', '${record.remark || ''}', ${record.teacher.id}, '${record.attendance_date}')" 
                                class="text-blue-600 hover:text-blue-800 transition-colors text-sm">
                            <i class="fas fa-edit"></i>
                        </button>
                        ` : `
                        <button onclick="openRemarkModal(null, '${record.teacher.name}', '${record.remark || ''}', ${record.teacher.id}, '${record.attendance_date}')" 
                                class="text-amber-600 hover:text-amber-800 transition-colors text-sm" 
                                title="เพิ่มหมายเหตุ">
                            <i class="fas fa-plus-circle"></i>
                        </button>
                        `}
                    </div>
                </td>
            </tr>
        `;
    }).join('');
    updateAttendanceSelectionUI();
}

// กรองแบบเรียลไทม์สำหรับบันทึกลงเวลา
let attendanceSearchTimeout;
document.getElementById('attendance-search').addEventListener('input', () => {
    clearTimeout(attendanceSearchTimeout);
    attendanceSearchTimeout = setTimeout(() => {
        loadAttendance(1);
    }, 500);
});

document.getElementById('attendance-department').addEventListener('change', () => {
    loadAttendance(1);
});

document.getElementById('attendance-status').addEventListener('change', () => {
    loadAttendance(1);
});

document.getElementById('attendance-date').addEventListener('change', () => {
    syncVisibleDateDisplay('attendance-date', 'attendance-date-display');
    loadAttendance(1);
});

const dashboardDateTrigger = document.getElementById('dashboard-date-trigger');
if (dashboardDateTrigger) {
    dashboardDateTrigger.addEventListener('click', () => openNativeDatePicker('dashboard-date'));
}

const dashboardDateDisplay = document.getElementById('dashboard-date-display');
if (dashboardDateDisplay) {
    dashboardDateDisplay.addEventListener('click', () => openNativeDatePicker('dashboard-date'));
}

const attendanceDateTrigger = document.getElementById('attendance-date-trigger');
if (attendanceDateTrigger) {
    attendanceDateTrigger.addEventListener('click', () => openNativeDatePicker('attendance-date'));
}

const attendanceDateDisplay = document.getElementById('attendance-date-display');
if (attendanceDateDisplay) {
    attendanceDateDisplay.addEventListener('click', () => openNativeDatePicker('attendance-date'));
}

const teacherBirthDateTrigger = document.getElementById('teacher-birth-date-trigger');
if (teacherBirthDateTrigger) {
    teacherBirthDateTrigger.addEventListener('click', () => openNativeDatePicker('teacher-birth-date'));
}

const teacherBirthDateDisplay = document.getElementById('teacher-birth-date-display');
if (teacherBirthDateDisplay) {
    teacherBirthDateDisplay.addEventListener('click', () => openNativeDatePicker('teacher-birth-date'));
}

const teacherBirthDate = document.getElementById('teacher-birth-date');
if (teacherBirthDate) {
    teacherBirthDate.addEventListener('change', () => {
        syncVisibleDateDisplay('teacher-birth-date', 'teacher-birth-date-display');
    });
}

const summaryStartDateTrigger = document.getElementById('summary-start-date-trigger');
if (summaryStartDateTrigger) {
    summaryStartDateTrigger.addEventListener('click', () => openNativeDatePicker('summary-start-date'));
}

const summaryStartDateDisplay = document.getElementById('summary-start-date-display');
if (summaryStartDateDisplay) {
    summaryStartDateDisplay.addEventListener('click', () => openNativeDatePicker('summary-start-date'));
}

const summaryEndDateTrigger = document.getElementById('summary-end-date-trigger');
if (summaryEndDateTrigger) {
    summaryEndDateTrigger.addEventListener('click', () => openNativeDatePicker('summary-end-date'));
}

const summaryEndDateDisplay = document.getElementById('summary-end-date-display');
if (summaryEndDateDisplay) {
    summaryEndDateDisplay.addEventListener('click', () => openNativeDatePicker('summary-end-date'));
}

const summaryStartDateInput = document.getElementById('summary-start-date');
if (summaryStartDateInput) {
    summaryStartDateInput.addEventListener('change', syncSummaryDateDisplays);
}

const summaryEndDateInput = document.getElementById('summary-end-date');
if (summaryEndDateInput) {
    summaryEndDateInput.addEventListener('change', syncSummaryDateDisplays);
}

// ปุ่มค้นหายังใช้งานได้
document.getElementById('search-attendance-btn').addEventListener('click', () => {
    loadAttendance(1);
});

const attendanceSelectAll = document.getElementById('attendance-select-all');
if (attendanceSelectAll) {
    attendanceSelectAll.addEventListener('change', () => {
        window.toggleAllAttendanceRows();
    });
}

window.openBulkEditTimeModal = function() {
    if (selectedAttendanceRows.size === 0) {
        showNotification('กรุณาเลือกอย่างน้อย 1 รายการ', 'warning');
        return;
    }

    document.getElementById('bulk-edit-time-count').textContent = `จำนวนที่เลือก: ${selectedAttendanceRows.size} รายการ`;
    document.getElementById('bulk-check-in-time').value = '';
    document.getElementById('bulk-check-out-time').value = '';
    openModalElement(document.getElementById('bulk-edit-time-modal'));
};

window.closeBulkEditTimeModal = function() {
    closeModalElement(document.getElementById('bulk-edit-time-modal'));
    document.getElementById('bulk-edit-time-form').reset();
};

const bulkEditTimeBtn = document.getElementById('bulk-edit-time-btn');
if (bulkEditTimeBtn) {
    bulkEditTimeBtn.addEventListener('click', () => {
        window.openBulkEditTimeModal();
    });
}

window.openBulkEditRemarkModal = function() {
    if (selectedAttendanceRows.size === 0) {
        showNotification('กรุณาเลือกอย่างน้อย 1 รายการ', 'warning');
        return;
    }

    document.getElementById('bulk-edit-remark-count').textContent = `จำนวนที่เลือก: ${selectedAttendanceRows.size} รายการ`;
    document.getElementById('bulk-remark-select').value = '';
    document.getElementById('bulk-remark-custom-input').value = '';
    document.getElementById('bulk-remark-custom-field').classList.add('hidden');
    openModalElement(document.getElementById('bulk-edit-remark-modal'));
};

window.closeBulkEditRemarkModal = function() {
    closeModalElement(document.getElementById('bulk-edit-remark-modal'));
    document.getElementById('bulk-edit-remark-form').reset();
    document.getElementById('bulk-remark-custom-field').classList.add('hidden');
};

const bulkEditRemarkBtn = document.getElementById('bulk-edit-remark-btn');
if (bulkEditRemarkBtn) {
    bulkEditRemarkBtn.addEventListener('click', () => {
        window.openBulkEditRemarkModal();
    });
}

const bulkRemarkSelect = document.getElementById('bulk-remark-select');
if (bulkRemarkSelect) {
    bulkRemarkSelect.addEventListener('change', function() {
        const customField = document.getElementById('bulk-remark-custom-field');
        if (!customField) return;
        customField.classList.toggle('hidden', this.value !== 'อื่นๆ');
    });
}

async function loadTeachers(page = 1) {
    currentPage = page;
    const search = document.getElementById('teacher-search').value;
    const department = document.getElementById('teacher-department').value;
    const status = document.getElementById('teacher-status').value;

    document.getElementById('teachers-loading').classList.remove('hidden');
    document.getElementById('teachers-content').classList.add('hidden');

    try {
        await loadDepartments('teacher-department');

        const params = new URLSearchParams({
            action: 'list',
            page: page,
            limit: 12
        });

        if (search) params.append('search', search);
        if (department) params.append('department', department);
        if (status) params.append('status', status);

        const result = await apiRequest(`teachers.php?${params.toString()}`);

        if (result.success) {
            renderTeachersGrid(result.data.teachers);
            renderPagination('teachers-pagination', result.data.pagination, loadTeachers);

            document.getElementById('teachers-loading').classList.add('hidden');
            document.getElementById('teachers-content').classList.remove('hidden');
        } else {
            showNotification(result.message, 'error');
        }
    } catch (error) {
        console.error('Teachers error:', error);
        showNotification('เกิดข้อผิดพลาดในการโหลดข้อมูล', 'error');
    }
}

function getDepartmentTheme(department = '') {
    const dept = String(department || '').trim();
    const exactThemes = {
        'ภาษาไทย': { headerClass: 'bg-gradient-to-r from-rose-500 to-pink-600', iconClass: 'fa-book-open-reader' },
        'คณิตศาสตร์': { headerClass: 'bg-gradient-to-r from-emerald-500 to-green-600', iconClass: 'fa-calculator' },
        'วิทยาศาสตร์': { headerClass: 'bg-gradient-to-r from-cyan-500 to-sky-600', iconClass: 'fa-flask-vial' },
        'คอมพิวเตอร์': { headerClass: 'bg-gradient-to-r from-slate-600 to-gray-800', iconClass: 'fa-laptop-code' },
        'สังคมศึกษาฯ': { headerClass: 'bg-gradient-to-r from-amber-500 to-yellow-600', iconClass: 'fa-earth-asia' },
        'ภาษาต่างประเทศ(อังกฤษ)': { headerClass: 'bg-gradient-to-r from-indigo-500 to-blue-700', iconClass: 'fa-comments' },
        'ภาษาต่างประเทศ(จีน)': { headerClass: 'bg-gradient-to-r from-fuchsia-500 to-purple-600', iconClass: 'fa-yin-yang' },
        'การงานอาชีพ': { headerClass: 'bg-gradient-to-r from-lime-500 to-green-700', iconClass: 'fa-screwdriver-wrench' },
        'ศิลปะ': { headerClass: 'bg-gradient-to-r from-red-500 to-rose-600', iconClass: 'fa-palette' },
        'สุขศึกษาและพลศึกษา': { headerClass: 'bg-gradient-to-r from-orange-500 to-amber-600', iconClass: 'fa-dumbbell' },
        'กิจกรรมพัฒนาผู้เรียน': { headerClass: 'bg-gradient-to-r from-teal-500 to-cyan-600', iconClass: 'fa-people-group' },
        'ผู้บริหาร': { headerClass: 'bg-gradient-to-r from-violet-500 to-purple-700', iconClass: 'fa-user-tie' },
        'บุคลากรทางการศึกษา': { headerClass: 'bg-gradient-to-r from-blue-500 to-indigo-600', iconClass: 'fa-chalkboard-user' },
        'เจ้าหน้าที่': { headerClass: 'bg-gradient-to-r from-stone-500 to-zinc-700', iconClass: 'fa-user-gear' },
        'นักการ/แม่บ้าน': { headerClass: 'bg-gradient-to-r from-emerald-700 to-teal-800', iconClass: 'fa-broom' }
    };

    if (exactThemes[dept]) {
        return exactThemes[dept];
    }

    if (dept.includes('คณิต')) {
        return { headerClass: 'bg-gradient-to-r from-emerald-500 to-green-600', iconClass: 'fa-calculator' };
    }
    if (dept.includes('วิทยา')) {
        return { headerClass: 'bg-gradient-to-r from-cyan-500 to-sky-600', iconClass: 'fa-flask-vial' };
    }
    if (dept.includes('คอมพิวเตอร์') || dept.includes('เทคโน')) {
        return { headerClass: 'bg-gradient-to-r from-slate-600 to-gray-800', iconClass: 'fa-laptop-code' };
    }
    if (dept.includes('สุขศึกษา') || dept.includes('พลศึกษา')) {
        return { headerClass: 'bg-gradient-to-r from-orange-500 to-amber-600', iconClass: 'fa-dumbbell' };
    }
    if (dept.includes('ผู้บริหาร') || dept.includes('บริหาร')) {
        return { headerClass: 'bg-gradient-to-r from-violet-500 to-purple-700', iconClass: 'fa-user-tie' };
    }
    if (dept.includes('ภาษา') && dept.includes('จีน')) {
        return { headerClass: 'bg-gradient-to-r from-fuchsia-500 to-purple-600', iconClass: 'fa-yin-yang' };
    }
    if (dept.includes('ภาษา') && dept.includes('อังกฤษ')) {
        return { headerClass: 'bg-gradient-to-r from-indigo-500 to-blue-700', iconClass: 'fa-comments' };
    }
    if (dept.includes('ภาษา')) {
        return { headerClass: 'bg-gradient-to-r from-rose-500 to-pink-600', iconClass: 'fa-book-open-reader' };
    }

    return { headerClass: 'bg-gradient-to-r from-blue-500 to-indigo-500', iconClass: 'fa-building' };
}

function renderTeachersGrid(teachers) {
    const grid = document.getElementById('teachers-grid');
    teacherQuickMap = {};

    if (teachers.length === 0) {
        grid.innerHTML = `
            <div class="col-span-full text-center py-12 text-gray-500">
                <i class="fas fa-inbox text-4xl mb-2"></i>
                <p>ไม่พบข้อมูล</p>
            </div>
        `;
        return;
    }

    teachers.forEach(teacher => {
        teacherQuickMap[String(teacher.id)] = teacher;
    });

    grid.innerHTML = teachers.map(teacher => {
        const theme = getDepartmentTheme(teacher.department);
        const statusColors = {
            'active': 'bg-green-100 text-green-800',
            'inactive': 'bg-red-100 text-red-800'
        };

        const statusTexts = {
            'active': 'ใช้งาน',
            'inactive': 'ไม่ใช้งาน'
        };

        const rfidValue = canViewRfid()
            ? (teacher.rfid_code || '-')
            : (teacher.rfid_masked || '• • • •');
        const rfidActionButton = canEditRfid() ? `
                                <button type="button"
                                        onclick="openQuickRfidModal(${teacher.id})"
                                        class="text-blue-600 hover:text-blue-800 transition-colors"
                                        title="แก้ไข RFID ด่วน">
                                    <i class="fas fa-pen-to-square"></i>
                                </button>
        ` : '';
        const rfidHtml = (canViewRfid() || teacher.rfid_masked || canEditRfid()) ? `
                            <p class="flex items-center justify-center gap-2">
                                <span><i class="fas fa-barcode"></i> ${rfidValue}</span>
                                ${rfidActionButton}
                            </p>
        ` : '';

        return `
            <div class="bg-white rounded-xl shadow-lg overflow-hidden hover:shadow-2xl transition-shadow">
                <div class="h-24 ${theme.headerClass} relative">
                    <i class="fas ${theme.iconClass} absolute right-4 top-1/2 -translate-y-1/2 text-white/20 text-6xl pointer-events-none"></i>
                    <div class="absolute top-3 left-3 inline-flex items-center gap-2 px-2.5 py-1 rounded-full bg-white/20 text-white text-xs font-semibold backdrop-blur-sm max-w-[75%]">
                        <i class="fas ${theme.iconClass}"></i>
                        <span class="truncate">${teacher.department}</span>
                    </div>
                </div>
                <div class="relative px-6 pb-6">
                    <span class="absolute top-3 right-3 inline-block px-3 py-1 rounded-full text-sm font-semibold ${statusColors[teacher.status]}">
                        ${statusTexts[teacher.status]}
                    </span>
                    <div class="absolute -top-16 left-1/2 transform -translate-x-1/2">
                        <img src="${teacher.photo ? '../' + teacher.photo : 'https://ui-avatars.com/api/?name=' + encodeURIComponent(teacher.first_name + ' ' + teacher.last_name) + '&size=200&background=random'}" 
                             alt="${teacher.first_name} ${teacher.last_name}" 
                             class="w-32 h-32 rounded-full object-cover border-4 border-white shadow-lg"
                             onerror="this.src='https://ui-avatars.com/api/?name=${encodeURIComponent(teacher.first_name + ' ' + teacher.last_name)}&size=200&background=random'">
                    </div>
                    <div class="pt-20 text-center">
                        <h3 class="text-xl font-bold text-gray-800 mb-1">
                            ${teacher.first_name} ${teacher.last_name}
                        </h3>
                        <div class="space-y-1 text-sm text-gray-600 mb-4 mt-2">
                            <p class="flex items-center justify-center gap-2">
                                <i class="fas fa-id-card"></i>
                                <span>${teacher.citizen_id}</span>
                                <button type="button"
                                        onclick="openTeacherDetailModal(${teacher.id})"
                                        class="text-blue-600 hover:text-blue-800 transition-colors"
                                        title="ดูรายละเอียด">
                                    <i class="fas fa-circle-info"></i>
                                </button>
                            </p>
                            ${rfidHtml}
                        </div>
                        <div class="flex gap-2">
                            <button onclick="editTeacher(${teacher.id})" 
                                    class="flex-1 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors font-semibold">
                                <i class="fas fa-edit"></i> แก้ไข
                            </button>
                            <button onclick='openDeleteTeacherModal(${teacher.id}, ${JSON.stringify(teacher.first_name + " " + teacher.last_name)})' 
                                    class="flex-1 px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors font-semibold">
                                <i class="fas fa-trash"></i> ลบ
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;
    }).join('');
}

function getTeacherDetailRfidValue(teacher) {
    if (!teacher) return '-';
    if (canViewRfid()) {
        return teacher.rfid_code || '-';
    }
    if (teacher.rfid_masked) {
        return teacher.rfid_masked;
    }
    return 'ซ่อนข้อมูล';
}

function getTeacherDetailRows(teacher) {
    return [
        ['รหัสบุคลากร', teacher.id ?? '-'],
        ['ชื่อ', `${teacher.first_name || ''} ${teacher.last_name || ''}`.trim() || '-'],
        ['เลขประจำตัว / Passport', teacher.citizen_id || '-'],
        ['RFID', getTeacherDetailRfidValue(teacher)],
        ['กลุ่มสาระ / แผนก', teacher.department || '-'],
        ['ตำแหน่ง', teacher.position || '-'],
        ['ประเภทบุคลากร', teacher.personnel_type || '-'],
        ['วันเกิด', teacher.birth_date ? formatThaiShortDate(teacher.birth_date) : '-'],
        ['เพศ', teacher.gender || '-'],
        ['กรุ๊ปเลือด', teacher.blood_type || '-'],
        ['อีเมล', teacher.email || '-'],
        ['เบอร์โทร', teacher.phone || '-'],
        ['สถานะ', teacher.status === 'active' ? 'ใช้งาน' : 'ไม่ใช้งาน'],
        ['วันที่สร้าง', teacher.created_at ? formatThaiDateTime(teacher.created_at) : '-'],
        ['แก้ไขล่าสุด', teacher.updated_at ? formatThaiDateTime(teacher.updated_at) : '-']
    ];
}

window.openTeacherDetailModal = function(id) {
    const teacher = teacherQuickMap[String(id)];
    if (!teacher) {
        showNotification('ไม่พบข้อมูลบุคลากร', 'error');
        return;
    }

    const title = document.getElementById('teacher-detail-title');
    const subtitle = document.getElementById('teacher-detail-subtitle');
    const tbody = document.getElementById('teacher-detail-tbody');
    const modal = document.getElementById('teacher-detail-modal');

    if (!title || !subtitle || !tbody || !modal) return;

    title.textContent = `${teacher.first_name || ''} ${teacher.last_name || ''}`.trim() || 'รายละเอียดบุคลากร';
    subtitle.textContent = teacher.department || '-';
    tbody.innerHTML = getTeacherDetailRows(teacher).map((row, index) => `
        <tr class="${index % 2 === 0 ? 'bg-white' : 'bg-gray-50'}">
            <th class="w-1/3 px-4 py-3 text-left text-sm font-semibold text-gray-700">${escapeHtml(row[0])}</th>
            <td class="px-4 py-3 text-sm text-gray-800 break-words">${escapeHtml(row[1])}</td>
        </tr>
    `).join('');

    openModalElement(modal);
};

window.closeTeacherDetailModal = function() {
    const modal = document.getElementById('teacher-detail-modal');
    if (modal) {
        closeModalElement(modal);
    }
};

function renderPagination(containerId, pagination, loadFunction) {
    const container = document.getElementById(containerId);
    const isTeachersPagination = containerId === 'teachers-pagination';

    if (pagination.total_pages <= 1) {
        container.innerHTML = '';
        return;
    }

    const pages = [];
    for (let i = 1; i <= pagination.total_pages; i++) {
        if (i === 1 || i === pagination.total_pages || (i >= pagination.page - 2 && i <= pagination.page + 2)) {
            pages.push(i);
        } else if (pages[pages.length - 1] !== '...') {
            pages.push('...');
        }
    }

    container.innerHTML = `
        <div class="${isTeachersPagination ? 'flex flex-col items-center gap-3' : 'flex flex-col items-center gap-2'}">
            <div class="flex items-center gap-2">
            <button onclick="${loadFunction.name}(${pagination.page - 1})" 
                    ${pagination.page === 1 ? 'disabled' : ''}
                    class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors font-semibold disabled:opacity-50 disabled:cursor-not-allowed">
                <i class="fas fa-chevron-left"></i> ก่อนหน้า
            </button>
            
            ${pages.map(page => {
                if (page === '...') {
                    return '<span class="px-3 py-2 text-gray-500">...</span>';
                }
                const isActive = page === pagination.page;
                return `
                    <button onclick="${loadFunction.name}(${page})" 
                            class="px-4 py-2 ${isActive ? 'bg-blue-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-100'} rounded-lg transition-colors font-semibold">
                        ${page}
                    </button>
                `;
            }).join('')}
            
            <button onclick="${loadFunction.name}(${pagination.page + 1})" 
                    ${pagination.page === pagination.total_pages ? 'disabled' : ''}
                    class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors font-semibold disabled:opacity-50 disabled:cursor-not-allowed">
                ถัดไป <i class="fas fa-chevron-right"></i>
            </button>
            </div>

            <p class="text-sm text-gray-600 text-center">
                แสดง ${(pagination.page - 1) * pagination.limit + 1} - ${Math.min(pagination.page * pagination.limit, pagination.total)} 
                จากทั้งหมด ${pagination.total} รายการ
            </p>
        </div>
    `;
}

function formatLogDateTime(dateTime) {
    if (!dateTime) return '-';
    const dt = new Date(dateTime.replace(' ', 'T'));
    if (Number.isNaN(dt.getTime())) return dateTime;
    return dt.toLocaleString('th-TH', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit'
    });
}

function parseAdminLogDetails(detailsJson) {
    if (!detailsJson) return {};
    try {
        const parsed = JSON.parse(detailsJson);
        return parsed && typeof parsed === 'object' ? parsed : {};
    } catch (error) {
        return {};
    }
}

function formatAdminLogAction(action) {
    const map = {
        LOGIN: 'เข้าสู่ระบบ',
        LOGOUT: 'ออกจากระบบ',
        CREATE_TEACHER: 'เพิ่มข้อมูลครู',
        UPDATE_TEACHER: 'แก้ไขข้อมูลครู',
        DELETE_TEACHER: 'ลบข้อมูลครู',
        CREATE_ATTENDANCE_RECORD: 'เพิ่มบันทึกลงเวลา',
        UPDATE_ATTENDANCE_TIME: 'แก้ไขเวลาเข้า-ออก',
        UPDATE_ATTENDANCE_REMARK: 'แก้ไขหมายเหตุ',
        UPDATE_SETTINGS: 'บันทึกการตั้งค่า',
        CREATE_ACADEMIC_CALENDAR: 'เพิ่มปีการศึกษา',
        UPDATE_ACADEMIC_CALENDAR: 'แก้ไขปีการศึกษา',
        ACTIVATE_ACADEMIC_CALENDAR: 'เปลี่ยนปีการศึกษาใช้งาน',
        CHECK_IN: 'สแกนเข้างาน',
        CHECK_OUT: 'สแกนออกงาน'
    };
    return map[action] || action || '-';
}

function formatAdminLogThaiDate(dateString) {
    if (!dateString) return '-';
    const match = String(dateString).match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (!match) return dateString;
    return `${match[3]}/${match[2]}/${Number.parseInt(match[1], 10) + 543}`;
}

function buildAdminLogDetailsHtml(log) {
    const details = parseAdminLogDetails(log.details_json);
    const lines = [];

    if (details.teacher_name) lines.push(`บุคคล: ${escapeHtml(details.teacher_name)}`);
    if (details.attendance_date) lines.push(`วันที่: ${escapeHtml(formatAdminLogThaiDate(details.attendance_date))}`);
    if (details.citizen_id) lines.push(`เลขบัตร: ${escapeHtml(details.citizen_id)}`);
    if (details.rfid_code) lines.push(`RFID: ${escapeHtml(details.rfid_code)}`);
    if (details.academic_year_be) lines.push(`ปีการศึกษา: ${escapeHtml(details.academic_year_be)}`);
    if (Array.isArray(details.updated_keys) && details.updated_keys.length) {
        lines.push(`ตั้งค่าที่แก้ไข: ${escapeHtml(details.updated_keys.join(', '))}`);
    }
    if (details.old_check_in_time || details.new_check_in_time) {
        lines.push(`เวลาเข้า: ${escapeHtml(formatTimeOnly(details.old_check_in_time))} -> ${escapeHtml(formatTimeOnly(details.new_check_in_time))}`);
    } else if (details.check_in_time) {
        lines.push(`เวลาเข้า: ${escapeHtml(formatTimeOnly(details.check_in_time))}`);
    }
    if (details.old_check_out_time || details.new_check_out_time) {
        lines.push(`เวลาออก: ${escapeHtml(formatTimeOnly(details.old_check_out_time))} -> ${escapeHtml(formatTimeOnly(details.new_check_out_time))}`);
    } else if (details.check_out_time) {
        lines.push(`เวลาออก: ${escapeHtml(formatTimeOnly(details.check_out_time))}`);
    }
    if (Object.prototype.hasOwnProperty.call(details, 'old_remark') || Object.prototype.hasOwnProperty.call(details, 'new_remark')) {
        lines.push(`หมายเหตุ: ${escapeHtml(details.old_remark ?? '-')} -> ${escapeHtml(details.new_remark ?? '-')}`);
    }

    const headline = escapeHtml(log.description || '-');
    if (!lines.length) return `<div>${headline}</div>`;

    return `
        <div class="font-medium text-gray-800">${headline}</div>
        <div class="text-xs text-gray-600 mt-1 space-y-0.5">
            ${lines.map(line => `<div>${line}</div>`).join('')}
        </div>
    `;
}

function renderAdminLogsTable(records) {
    const tbody = document.getElementById('admin-logs-table-body');

    if (!records || records.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="6" class="px-6 py-8 text-center text-gray-500">
                    <i class="fas fa-inbox text-3xl mb-2"></i>
                    <p>ไม่พบข้อมูล log</p>
                </td>
            </tr>
        `;
        return;
    }

    tbody.innerHTML = records.map(log => {
        const username = log.admin_username || '-';
        const fullName = log.admin_name || '-';
        const endpoint = log.endpoint || '-';
        const ipAddress = log.ip_address || '-';
        const actionLabel = formatAdminLogAction(log.action);
        const method = log.request_method ? `<span class="text-xs bg-gray-200 text-gray-700 rounded px-2 py-0.5">${log.request_method}</span>` : '';
        const details = buildAdminLogDetailsHtml(log);

        return `
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-3 text-sm text-gray-700 whitespace-nowrap">${formatLogDateTime(log.created_at)}</td>
                <td class="px-4 py-3 text-sm text-gray-800">
                    <div class="font-semibold">${escapeHtml(username)}</div>
                    <div class="text-xs text-gray-500">${escapeHtml(fullName)}</div>
                </td>
                <td class="px-4 py-3 text-sm text-gray-800">
                    <div class="font-semibold">${escapeHtml(actionLabel)}</div>
                    <div class="text-xs text-gray-500">${escapeHtml(log.action || '-')}</div>
                </td>
                <td class="px-4 py-3 text-sm text-gray-700">
                    ${details}
                </td>
                <td class="px-4 py-3 text-sm text-gray-700 whitespace-nowrap">${escapeHtml(ipAddress)}</td>
                <td class="px-4 py-3 text-sm text-gray-700">
                    <div class="break-all">${escapeHtml(endpoint)}</div>
                    <div class="mt-1">${method}</div>
                </td>
            </tr>
        `;
    }).join('');
}

function populateAdminLogActionOptions(actions = []) {
    const select = document.getElementById('admin-log-action');
    if (!select) return;

    const currentValue = select.value;
    select.innerHTML = '<option value="">ทั้งหมด</option>';

    actions.forEach(action => {
        const option = document.createElement('option');
        option.value = action;
        option.textContent = action;
        select.appendChild(option);
    });

    if (currentValue) {
        select.value = currentValue;
    }
}

async function loadAdminLogs(page = 1) {
    currentAdminLogsPage = page;

    const loadingEl = document.getElementById('admin-logs-loading');
    const contentEl = document.getElementById('admin-logs-content');
    const search = (document.getElementById('admin-log-search')?.value || '').trim();
    const action = document.getElementById('admin-log-action')?.value || '';
    const dateFrom = document.getElementById('admin-log-date-from')?.value || '';
    const dateTo = document.getElementById('admin-log-date-to')?.value || '';

    if (dateFrom && dateTo && dateFrom > dateTo) {
        showNotification('ช่วงวันที่ของ Log ไม่ถูกต้อง', 'warning');
        return;
    }

    loadingEl.classList.remove('hidden');
    contentEl.classList.add('hidden');

    try {
        const params = new URLSearchParams({
            action: 'list',
            page: String(page),
            limit: '20'
        });
        if (search) params.append('search', search);
        if (action) params.append('log_action', action);
        if (dateFrom) params.append('date_from', dateFrom);
        if (dateTo) params.append('date_to', dateTo);

        const result = await apiRequest(`admin-logs.php?${params.toString()}`);

        if (!result.success) {
            showNotification(result.message || 'ไม่สามารถโหลดข้อมูล log ได้', 'error');
            document.getElementById('admin-logs-table-body').innerHTML = `
                <tr>
                    <td colspan="6" class="px-6 py-8 text-center text-red-500">
                        <i class="fas fa-exclamation-circle text-3xl mb-2"></i>
                        <p>${escapeHtml(result.message || 'เกิดข้อผิดพลาด')}</p>
                    </td>
                </tr>
            `;
            document.getElementById('admin-logs-pagination').innerHTML = '';
        } else {
            populateAdminLogActionOptions(result.data.actions || []);
            renderAdminLogsTable(result.data.records || []);
            renderPagination('admin-logs-pagination', result.data.pagination, loadAdminLogs);
        }
    } catch (error) {
        console.error('Load admin logs error:', error);
        showNotification('เกิดข้อผิดพลาดในการโหลด log', 'error');
    } finally {
        loadingEl.classList.add('hidden');
        contentEl.classList.remove('hidden');
    }
}

// ฟังก์ชันเปิด Modal แก้ไขหมายเหตุ
function openRemarkModal(recordId, teacherName, currentRemark, teacherId, attendanceDate) {
    document.getElementById('remark-record-id').value = recordId || '';
    document.getElementById('remark-teacher-id').value = teacherId || '';
    document.getElementById('remark-attendance-date').value = attendanceDate || getIsoDateInputValue('attendance-date');
    document.getElementById('remark-teacher-name').value = teacherName;
    
    const remarkSelect = document.getElementById('remark-select');
    const customField = document.getElementById('remark-custom-field');
    const customInput = document.getElementById('remark-custom-input');
    
    // ตรวจสอบว่าหมายเหตุปัจจุบันอยู่ใน dropdown หรือไม่
    const options = Array.from(remarkSelect.options).map(o => o.value);
    if (currentRemark && options.includes(currentRemark)) {
        remarkSelect.value = currentRemark;
        customField.classList.add('hidden');
    } else if (currentRemark) {
        remarkSelect.value = 'อื่นๆ';
        customField.classList.remove('hidden');
        customInput.value = currentRemark;
    } else {
        remarkSelect.value = '';
        customField.classList.add('hidden');
    }
    
    openModalElement(document.getElementById('remark-modal'));
}

// ฟังก์ชันปิด Modal หมายเหตุ
function closeRemarkModal() {
    closeModalElement(document.getElementById('remark-modal'));
    document.getElementById('remark-form').reset();
    document.getElementById('remark-custom-field').classList.add('hidden');
}

// เมื่อเลือก "อื่นๆ" ให้แสดงช่องกรอกข้อความ
document.getElementById('remark-select').addEventListener('change', function() {
    const customField = document.getElementById('remark-custom-field');
    if (this.value === 'อื่นๆ') {
        customField.classList.remove('hidden');
    } else {
        customField.classList.add('hidden');
    }
});

// บันทึกหมายเหตุ
document.getElementById('remark-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    
    const saveBtn = document.getElementById('save-remark-btn');
    setButtonLoading(saveBtn, true);
    
    try {
        const remarkSelect = document.getElementById('remark-select').value;
        const customInput = document.getElementById('remark-custom-input').value;
        const remark = remarkSelect === 'อื่นๆ' ? customInput : remarkSelect;
        const recordId = document.getElementById('remark-record-id').value;
        
        const data = {
            remark: remark
        };
        
        // ถ้ามี record id ให้ส่งไป (กรณีแก้ไข)
        if (recordId) {
            data.id = recordId;
        } else {
            // ถ้าไม่มี record id ให้ส่ง teacher_id และ attendance_date (กรณีเพิ่มใหม่)
            data.teacher_id = document.getElementById('remark-teacher-id').value;
            data.attendance_date = document.getElementById('remark-attendance-date').value;
        }
        
        const result = await apiRequest('update-attendance-remark.php', 'POST', data);
        
        if (result.success) {
            showNotification(result.message, 'success');
            closeRemarkModal();
            
            // โหลดข้อมูลใหม่เพื่อแสดงหมายเหตุที่อัพเดท
            loadAttendance(1);
        } else {
            showNotification(result.message, 'error');
        }
    } catch (error) {
        console.error('Update remark error:', error);
        showNotification('เกิดข้อผิดพลาดในการบันทึกหมายเหตุ', 'error');
    } finally {
        setButtonLoading(saveBtn, false);
    }
});

// ฟังก์ชันพิมพ์รายงาน
document.getElementById('print-report-btn').addEventListener('click', () => {
    const date = getIsoDateInputValue('attendance-date');
    if (!date) {
        showNotification('กรุณาเลือกวันที่', 'error');
        return;
    }

    const cacheBust = Date.now();
    
    // เปิดหน้าพิมพ์รายงานในหน้าต่างใหม่
    window.open(`print-report.php?date=${date}&v=${cacheBust}`, '_blank');
});

// พิมพ์รายงานสรุปตามช่วงวันที่
document.getElementById('print-summary-btn').addEventListener('click', () => {
    const startDate = getIsoDateInputValue('summary-start-date');
    const endDate = getIsoDateInputValue('summary-end-date');
    const keyword = (document.getElementById('summary-search')?.value || '').trim();
    const department = (document.getElementById('summary-department-filter')?.value || '').trim();

    if (!startDate || !endDate) {
        showNotification('กรุณาเลือกช่วงวันที่ก่อนพิมพ์รายงาน', 'error');
        return;
    }

    if (startDate > endDate) {
        showNotification('ช่วงวันที่ไม่ถูกต้อง', 'error');
        return;
    }

    const params = new URLSearchParams({
        start_date: startDate,
        end_date: endDate
    });
    if (keyword) {
        params.append('search', keyword);
    }
    if (department) {
        params.append('department', department);
    }
    params.set('v', Date.now().toString());
    window.open(`print-summary-report.php?${params.toString()}`, '_blank');
});

// ฟังก์ชันเปิด Modal แก้ไขเวลา (รองรับทั้งแก้ไขและเพิ่มใหม่)
async function openEditTimeModal(recordId, teacherId, teacherName, attendanceDate) {
    const editTimeModal = document.getElementById('edit-time-modal');
    const editTimeForm = document.getElementById('edit-time-form');
    if (!editTimeModal || !editTimeForm) return;

    editTimeModal.scrollTop = 0;
    editTimeForm.scrollTop = 0;

    // ถ้ามี recordId = มีการลงเวลาแล้ว (แก้ไข)
    // ถ้าไม่มี recordId = ยังไม่ลงเวลา (เพิ่มใหม่)
    
    if (recordId && recordId !== 'null') {
        // กรณีแก้ไข - ดึงข้อมูลเดิม
        try {
            const result = await apiRequest(`attendance.php?action=get&id=${recordId}`);
            
            if (result.success) {
                const record = result.data.record;
                
                document.getElementById('edit-record-id').value = record.id;
                document.getElementById('edit-teacher-id').value = record.teacher.id;
                document.getElementById('edit-teacher-name').value = record.teacher.name;
                document.getElementById('edit-attendance-date').value = record.attendance_date;
                
                // แปลงเวลาจาก datetime เป็น time
                if (record.check_in_time) {
                    const checkInTime = new Date(record.check_in_time).toTimeString().slice(0, 5);
                    document.getElementById('edit-check-in-time').value = checkInTime;
                }
                
                if (record.check_out_time) {
                    const checkOutTime = new Date(record.check_out_time).toTimeString().slice(0, 5);
                    document.getElementById('edit-check-out-time').value = checkOutTime;
                } else {
                    document.getElementById('edit-check-out-time').value = '';
                }
                
                openModalElement(editTimeModal);
            } else {
                showNotification(result.message, 'error');
            }
        } catch (error) {
            console.error('Load attendance error:', error);
            showNotification('เกิดข้อผิดพลาดในการโหลดข้อมูล', 'error');
        }
    } else {
        // กรณีเพิ่มใหม่ - ใส่ข้อมูลเปล่า
        document.getElementById('edit-record-id').value = '';
        document.getElementById('edit-teacher-id').value = teacherId;
        document.getElementById('edit-teacher-name').value = teacherName;
        document.getElementById('edit-attendance-date').value = attendanceDate;
        document.getElementById('edit-check-in-time').value = '';
        document.getElementById('edit-check-out-time').value = '';
        
        openModalElement(editTimeModal);
    }
}

// ฟังก์ชันปิด Modal แก้ไขเวลา
function closeEditTimeModal() {
    closeModalElement(document.getElementById('edit-time-modal'));
    document.getElementById('edit-time-form').reset();
}

// ฟังก์ชันบันทึกเวลา
document.getElementById('edit-time-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    
    const saveBtn = document.getElementById('save-time-btn');
    setButtonLoading(saveBtn, true);
    
    try {
        const recordId = document.getElementById('edit-record-id').value;
        const teacherId = document.getElementById('edit-teacher-id').value;
        const attendanceDate = document.getElementById('edit-attendance-date').value;
        const checkInTime = document.getElementById('edit-check-in-time').value;
        const checkOutTime = document.getElementById('edit-check-out-time').value;
        
        let result;
        
        if (recordId) {
            // กรณีแก้ไข - ใช้ API เดิม
            const data = {
                id: recordId,
                check_in_time: checkInTime,
                check_out_time: checkOutTime
            };
            result = await apiRequest('update-attendance-time.php', 'POST', data);
        } else {
            // กรณีเพิ่มใหม่ - ใช้ API ใหม่
            const data = {
                teacher_id: teacherId,
                attendance_date: attendanceDate,
                check_in_time: checkInTime,
                check_out_time: checkOutTime
            };
            result = await apiRequest('create-attendance-record.php', 'POST', data);
        }
        
        if (result.success) {
            showNotification(result.message, 'success');
            closeEditTimeModal();
            loadAttendance(1); // โหลดหน้าแรก
        } else {
            showNotification(result.message, 'error');
        }
    } catch (error) {
        console.error('Update time error:', error);
        showNotification('เกิดข้อผิดพลาดในการบันทึกเวลา', 'error');
    } finally {
        setButtonLoading(saveBtn, false);
    }
});

document.getElementById('bulk-edit-time-form').addEventListener('submit', async (e) => {
    e.preventDefault();

    const saveBtn = document.getElementById('save-bulk-time-btn');
    setButtonLoading(saveBtn, true);

    try {
        const checkInTime = document.getElementById('bulk-check-in-time').value;
        const checkOutTime = document.getElementById('bulk-check-out-time').value;
        const selectedItems = Array.from(selectedAttendanceRows.values());

        if (!selectedItems.length) {
            showNotification('ไม่พบรายการที่เลือก', 'warning');
            return;
        }

        let successCount = 0;
        const failedItems = [];

        for (const item of selectedItems) {
            let result;
            if (item.recordId) {
                result = await apiRequest('update-attendance-time.php', 'POST', {
                    id: item.recordId,
                    check_in_time: checkInTime,
                    check_out_time: checkOutTime
                }, true);
            } else {
                result = await apiRequest('create-attendance-record.php', 'POST', {
                    teacher_id: item.teacherId,
                    attendance_date: item.attendanceDate,
                    check_in_time: checkInTime,
                    check_out_time: checkOutTime
                }, true);
            }

            if (!result.success) {
                failedItems.push(`${item.teacherName}: ${result.message || 'เกิดข้อผิดพลาด'}`);
                continue;
            }

            successCount++;
        }

        if (successCount > 0 && failedItems.length === 0) {
            showNotification(`บันทึกเวลาสำเร็จ ${successCount} รายการ`, 'success');
        } else if (successCount > 0) {
            const failPreview = failedItems.slice(0, 2).join(' | ');
            showNotification(`บันทึกสำเร็จ ${successCount} รายการ, ไม่สำเร็จ ${failedItems.length} รายการ${failPreview ? ` (${failPreview})` : ''}`, 'warning');
            console.error('Bulk edit failures:', failedItems);
        } else {
            const failPreview = failedItems.slice(0, 2).join(' | ');
            showNotification(`ไม่สามารถบันทึกเวลาที่เลือกได้${failPreview ? ` (${failPreview})` : ''}`, 'error');
            console.error('Bulk edit failures:', failedItems);
        }

        closeBulkEditTimeModal();
        await loadAttendance(1);
    } catch (error) {
        console.error('Bulk update time error:', error);
        showNotification('เกิดข้อผิดพลาดในการบันทึกเวลาแบบหลายรายการ', 'error');
    } finally {
        setButtonLoading(saveBtn, false, '<i class="fas fa-save"></i> บันทึกทั้งหมด');
    }
});

document.getElementById('bulk-edit-remark-form').addEventListener('submit', async (e) => {
    e.preventDefault();

    const saveBtn = document.getElementById('save-bulk-remark-btn');
    setButtonLoading(saveBtn, true);

    try {
        const remarkSelectValue = document.getElementById('bulk-remark-select').value;
        const bulkRemark = remarkSelectValue === 'อื่นๆ'
            ? document.getElementById('bulk-remark-custom-input').value.trim()
            : remarkSelectValue;
        const selectedItems = Array.from(selectedAttendanceRows.values());

        if (!selectedItems.length) {
            showNotification('ไม่พบรายการที่เลือก', 'warning');
            return;
        }

        if (remarkSelectValue === 'อื่นๆ' && !bulkRemark) {
            showNotification('กรุณาระบุหมายเหตุ', 'warning');
            return;
        }

        let successCount = 0;
        const failedItems = [];

        for (const item of selectedItems) {
            const result = await apiRequest('update-attendance-remark.php', 'POST', {
                teacher_id: item.teacherId,
                attendance_date: item.attendanceDate,
                remark: bulkRemark
            }, true);

            if (result.success) {
                successCount++;
            } else {
                failedItems.push(`${item.teacherName}: ${result.message || 'เกิดข้อผิดพลาด'}`);
            }
        }

        if (successCount > 0 && failedItems.length === 0) {
            showNotification(`บันทึกหมายเหตุสำเร็จ ${successCount} รายการ`, 'success');
        } else if (successCount > 0) {
            const failPreview = failedItems.slice(0, 2).join(' | ');
            showNotification(`บันทึกสำเร็จ ${successCount} รายการ, ไม่สำเร็จ ${failedItems.length} รายการ${failPreview ? ` (${failPreview})` : ''}`, 'warning');
            console.error('Bulk remark failures:', failedItems);
        } else {
            const failPreview = failedItems.slice(0, 2).join(' | ');
            showNotification(`ไม่สามารถบันทึกหมายเหตุที่เลือกได้${failPreview ? ` (${failPreview})` : ''}`, 'error');
            console.error('Bulk remark failures:', failedItems);
        }

        closeBulkEditRemarkModal();
        await loadAttendance(1);
    } catch (error) {
        console.error('Bulk update remark error:', error);
        showNotification('เกิดข้อผิดพลาดในการบันทึกหมายเหตุแบบหลายรายการ', 'error');
    } finally {
        setButtonLoading(saveBtn, false, '<i class="fas fa-save"></i> บันทึกหมายเหตุ');
    }
});

// กรองแบบเรียลไทม์
let searchTimeout;
document.getElementById('teacher-search').addEventListener('input', () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        loadTeachers(1);
    }, 500); // รอ 500ms หลังจากพิมพ์เสร็จ
});

document.getElementById('teacher-department').addEventListener('change', () => {
    loadTeachers(1);
});

document.getElementById('teacher-status').addEventListener('change', () => {
    loadTeachers(1);
});

// ปุ่มค้นหายังใช้งานได้
document.getElementById('search-teacher-btn').addEventListener('click', () => {
    loadTeachers(1);
});

const adminLogRefreshBtn = document.getElementById('admin-log-refresh-btn');
if (adminLogRefreshBtn) {
    adminLogRefreshBtn.addEventListener('click', () => {
        loadAdminLogs(1);
    });
}

const adminLogActionSelect = document.getElementById('admin-log-action');
if (adminLogActionSelect) {
    adminLogActionSelect.addEventListener('change', () => {
        loadAdminLogs(1);
    });
}

const adminLogDateFromInput = document.getElementById('admin-log-date-from');
if (adminLogDateFromInput) {
    adminLogDateFromInput.addEventListener('change', () => {
        loadAdminLogs(1);
    });
}

const adminLogDateToInput = document.getElementById('admin-log-date-to');
if (adminLogDateToInput) {
    adminLogDateToInput.addEventListener('change', () => {
        loadAdminLogs(1);
    });
}

const adminLogSearchInput = document.getElementById('admin-log-search');
if (adminLogSearchInput) {
    adminLogSearchInput.addEventListener('input', () => {
        clearTimeout(adminLogsSearchTimeout);
        adminLogsSearchTimeout = setTimeout(() => {
            loadAdminLogs(1);
        }, 350);
    });
}

async function loadDepartments(selectId) {
    const select = document.getElementById(selectId);
    if (!select) return;

    const currentValue = select.value;
    const firstOption = select.options[0] ? select.options[0].cloneNode(true) : null;

    try {
        let departments = departmentsCache;
        if (!Array.isArray(departments)) {
            const result = await apiRequest('attendance.php?action=departments');
            if (!result.success) {
                return;
            }
            departments = Array.isArray(result.data.departments) ? result.data.departments : [];
            departmentsCache = departments;
        }

        select.innerHTML = '';

        if (firstOption) {
            select.appendChild(firstOption);
        }

        departments.forEach(dept => {
            const option = document.createElement('option');
            option.value = dept;
            option.textContent = dept;
            select.appendChild(option);
        });

        if (currentValue && Array.from(select.options).some(option => option.value === currentValue)) {
            select.value = currentValue;
        }
    } catch (error) {
        console.error('Load departments error:', error);
    }
}

let selectedPhotoFile = null;
let currentPhotoUrl = null;
const PHOTO_CROP_VIEW_SIZE = 320;
const PHOTO_CROP_OUTPUT_SIZE = 900;
let departmentsCache = null;
let photoCropState = {
    image: null,
    dataUrl: '',
    baseScale: 1,
    zoom: 1,
    offsetX: 0,
    offsetY: 0
};

function setTeacherGender(value = '') {
    const genderInput = document.getElementById('teacher-gender');
    const radioInputs = document.querySelectorAll('input[name="teacher-gender-radio"]');
    if (!genderInput) return;

    genderInput.value = value || '';
    radioInputs.forEach((radio) => {
        radio.checked = radio.value === (value || '');
    });
}

function initializeTeacherGenderCards() {
    const genderInput = document.getElementById('teacher-gender');
    const radioInputs = document.querySelectorAll('input[name="teacher-gender-radio"]');
    if (!genderInput || radioInputs.length === 0) return;

    radioInputs.forEach((radio) => {
        radio.addEventListener('change', () => {
            if (radio.checked) {
                genderInput.value = radio.value;
            }
        });
    });
}

function setTeacherStatus(status = 'inactive') {
    const statusInput = document.getElementById('teacher-status-input');
    const statusToggle = document.getElementById('teacher-status-toggle');
    if (!statusInput || !statusToggle) return;

    const normalizedStatus = status === 'active' ? 'active' : 'inactive';
    statusInput.value = normalizedStatus;
    statusToggle.checked = normalizedStatus === 'active';
}

function initializeTeacherStatusSwitch() {
    const statusInput = document.getElementById('teacher-status-input');
    const statusToggle = document.getElementById('teacher-status-toggle');
    if (!statusInput || !statusToggle) return;

    statusToggle.addEventListener('change', () => {
        statusInput.value = statusToggle.checked ? 'active' : 'inactive';
    });
}

window.openTeacherModal = function(teacherId = null) {
    const modal = document.getElementById('teacher-modal');
    const form = document.getElementById('teacher-form');
    const title = document.getElementById('teacher-modal-title');
    
    form.reset();
    document.getElementById('teacher-id').value = '';
    document.getElementById('teacher-rfid-code').placeholder = 'RFID001';
    setThaiInputDateValue('teacher-birth-date', '');
    syncVisibleDateDisplay('teacher-birth-date', 'teacher-birth-date-display');
    setTeacherGender('');
    setTeacherStatus('inactive');
    selectedPhotoFile = null;
    currentPhotoUrl = null;
    resetPhotoPreview();
    
    if (teacherId) {
        title.innerHTML = '<i class="fas fa-edit"></i> แก้ไขข้อมูลครู';
        loadTeacherData(teacherId);
    } else {
        title.innerHTML = '<i class="fas fa-user-plus"></i> เพิ่มข้อมูลบุคลากร';
        syncTeacherRfidVisibility();
    }
    
    openModalElement(modal);
};

function resetPhotoPreview() {
    document.getElementById('photo-preview').classList.add('hidden');
    document.getElementById('photo-placeholder').classList.remove('hidden');
    document.getElementById('remove-photo-btn').classList.add('hidden');
    document.getElementById('photo-preview').src = '';
}

function showPhotoPreview(url) {
    // ถ้า url เป็น path ที่เริ่มด้วย uploads/ ให้เพิ่ม ../
    const photoUrl = url.startsWith('uploads/') ? '../' + url : url;
    document.getElementById('photo-preview').src = photoUrl;
    document.getElementById('photo-preview').classList.remove('hidden');
    document.getElementById('photo-placeholder').classList.add('hidden');
    document.getElementById('remove-photo-btn').classList.remove('hidden');
}

function openPhotoCropModal() {
    openModalElement(document.getElementById('photo-crop-modal'));
}

function closePhotoCropModal() {
    closeModalElement(document.getElementById('photo-crop-modal'));
}

function updatePhotoCropPreview() {
    const cropImage = document.getElementById('photo-crop-image');
    if (!cropImage || !photoCropState.image) return;
    const scale = photoCropState.baseScale * photoCropState.zoom;
    cropImage.style.transform = `translate(-50%, -50%) translate(${photoCropState.offsetX}px, ${photoCropState.offsetY}px) scale(${scale})`;
}

async function openPhotoCropModalFromFile(file) {
    const dataUrl = await new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = (e) => resolve(e.target.result);
        reader.onerror = reject;
        reader.readAsDataURL(file);
    });

    const image = await new Promise((resolve, reject) => {
        const img = new Image();
        img.onload = () => resolve(img);
        img.onerror = reject;
        img.src = dataUrl;
    });

    const zoomInput = document.getElementById('photo-crop-zoom');
    const offsetXInput = document.getElementById('photo-crop-offset-x');
    const offsetYInput = document.getElementById('photo-crop-offset-y');
    const cropImage = document.getElementById('photo-crop-image');

    photoCropState.image = image;
    photoCropState.dataUrl = dataUrl;
    photoCropState.baseScale = Math.max(PHOTO_CROP_VIEW_SIZE / image.width, PHOTO_CROP_VIEW_SIZE / image.height);
    photoCropState.zoom = 1;
    photoCropState.offsetX = 0;
    photoCropState.offsetY = 0;

    cropImage.src = dataUrl;
    zoomInput.value = '100';
    offsetXInput.value = '0';
    offsetYInput.value = '0';
    updatePhotoCropPreview();
    openPhotoCropModal();
}

function renderCroppedPhotoCanvas() {
    if (!photoCropState.image) return null;
    const canvas = document.createElement('canvas');
    canvas.width = PHOTO_CROP_OUTPUT_SIZE;
    canvas.height = PHOTO_CROP_OUTPUT_SIZE;
    const ctx = canvas.getContext('2d');

    const scale = photoCropState.baseScale * photoCropState.zoom;
    const ratio = PHOTO_CROP_OUTPUT_SIZE / PHOTO_CROP_VIEW_SIZE;
    ctx.fillStyle = '#f3f4f6';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    ctx.translate(
        PHOTO_CROP_OUTPUT_SIZE / 2 + (photoCropState.offsetX * ratio),
        PHOTO_CROP_OUTPUT_SIZE / 2 + (photoCropState.offsetY * ratio)
    );
    ctx.scale(scale * ratio, scale * ratio);
    ctx.drawImage(
        photoCropState.image,
        -photoCropState.image.width / 2,
        -photoCropState.image.height / 2
    );

    return canvas;
}

// ฟังก์ชันบีบอัดรูปภาพเป็น JPG ไม่เกิน 1MB
async function compressCanvasToJpg(canvas, maxSizeKB = 1024) {
    return new Promise((resolve, reject) => {
        if (!canvas) {
            reject(new Error('Canvas not found'));
            return;
        }

        let quality = 0.92;
        let compressedDataUrl = canvas.toDataURL('image/jpeg', quality);

        while (compressedDataUrl.length > maxSizeKB * 1024 * 1.37 && quality > 0.2) {
            quality -= 0.05;
            compressedDataUrl = canvas.toDataURL('image/jpeg', quality);
        }

        resolve(compressedDataUrl);
    });
}

// จัดการการเลือกรูปภาพ
document.addEventListener('DOMContentLoaded', function() {
    const photoInput = document.getElementById('teacher-photo');
    const removePhotoBtn = document.getElementById('remove-photo-btn');
    const closePhotoCropBtn = document.getElementById('close-photo-crop-btn');
    const cancelPhotoCropBtn = document.getElementById('cancel-photo-crop-btn');
    const applyPhotoCropBtn = document.getElementById('apply-photo-crop-btn');
    const cropZoom = document.getElementById('photo-crop-zoom');
    const cropOffsetX = document.getElementById('photo-crop-offset-x');
    const cropOffsetY = document.getElementById('photo-crop-offset-y');
    initializeTeacherGenderCards();
    initializeTeacherStatusSwitch();
    
    if (photoInput) {
        photoInput.addEventListener('change', async function(e) {
            const file = e.target.files[0];
            if (file) {
                // ตรวจสอบประเภทไฟล์
                if (!file.type.startsWith('image/')) {
                    showNotification('กรุณาเลือกไฟล์รูปภาพเท่านั้น', 'error');
                    photoInput.value = '';
                    return;
                }
                
                try {
                    await openPhotoCropModalFromFile(file);
                } catch (error) {
                    console.error('Open crop modal error:', error);
                    showNotification('เกิดข้อผิดพลาดในการประมวลผลรูปภาพ', 'error');
                    photoInput.value = '';
                }
            }
        });
    }
    
    if (removePhotoBtn) {
        removePhotoBtn.addEventListener('click', function() {
            selectedPhotoFile = null;
            currentPhotoUrl = null;
            photoInput.value = '';
            resetPhotoPreview();
        });
    }

    if (closePhotoCropBtn) {
        closePhotoCropBtn.addEventListener('click', () => {
            closePhotoCropModal();
            if (photoInput) photoInput.value = '';
        });
    }

    if (cancelPhotoCropBtn) {
        cancelPhotoCropBtn.addEventListener('click', () => {
            closePhotoCropModal();
            if (photoInput) photoInput.value = '';
        });
    }

    if (cropZoom) {
        cropZoom.addEventListener('input', () => {
            photoCropState.zoom = Number(cropZoom.value) / 100;
            updatePhotoCropPreview();
        });
    }

    if (cropOffsetX) {
        cropOffsetX.addEventListener('input', () => {
            photoCropState.offsetX = Number(cropOffsetX.value);
            updatePhotoCropPreview();
        });
    }

    if (cropOffsetY) {
        cropOffsetY.addEventListener('input', () => {
            photoCropState.offsetY = Number(cropOffsetY.value);
            updatePhotoCropPreview();
        });
    }

    if (applyPhotoCropBtn) {
        applyPhotoCropBtn.addEventListener('click', async () => {
            try {
                const canvas = renderCroppedPhotoCanvas();
                const compressedDataUrl = await compressCanvasToJpg(canvas, 1024);
                selectedPhotoFile = compressedDataUrl;
                showPhotoPreview(compressedDataUrl);
                closePhotoCropModal();
                const sizeKB = Math.round(compressedDataUrl.length * 0.75 / 1024);
                showNotification(`ตั้งค่ารูปภาพเรียบร้อย (~${sizeKB} KB)`, 'success');
            } catch (error) {
                console.error('Crop image error:', error);
                showNotification('เกิดข้อผิดพลาดในการครอบรูปภาพ', 'error');
            }
        });
    }
});

window.closeTeacherModal = function() {
    closePhotoCropModal();
    closeModalElement(document.getElementById('teacher-modal'));
};

async function loadTeacherData(id) {
    try {
        const result = await apiRequest(`teachers.php?action=get&id=${id}`);
        
        if (result.success) {
            const teacher = result.data.teacher;
            const teacherRfidInput = document.getElementById('teacher-rfid-code');
            document.getElementById('teacher-id').value = teacher.id;
            document.getElementById('teacher-first-name').value = teacher.first_name;
            document.getElementById('teacher-last-name').value = teacher.last_name;
            document.getElementById('teacher-citizen-id').value = teacher.citizen_id;
            if (teacherRfidInput) {
                teacherRfidInput.value = canViewRfid() ? (teacher.rfid_code || '') : '';
                teacherRfidInput.placeholder = !canViewRfid() && teacher.rfid_masked ? teacher.rfid_masked : 'RFID001';
            }
            document.getElementById('teacher-position').value = teacher.position || '';
            document.getElementById('teacher-personnel-type').value = teacher.personnel_type || '';
            setThaiInputDateValue('teacher-birth-date', teacher.birth_date || '');
            syncVisibleDateDisplay('teacher-birth-date', 'teacher-birth-date-display');
            setTeacherGender(teacher.gender || '');
            document.getElementById('teacher-blood-type').value = teacher.blood_type || '';
            
            document.getElementById('teacher-email').value = teacher.email || '';
            document.getElementById('teacher-phone').value = teacher.phone || '';
            setTeacherStatus(teacher.status || 'inactive');
            
            // เซ็ตค่า department
            const departmentSelect = document.getElementById('teacher-department-input');
            if (departmentSelect && teacher.department) {
                departmentSelect.value = teacher.department;
            }
            
            // แสดงรูปภาพถ้ามี
            if (teacher.photo) {
                currentPhotoUrl = teacher.photo;
                showPhotoPreview(teacher.photo);
            } else {
                resetPhotoPreview();
            }

            syncTeacherRfidVisibility();
        } else {
            showNotification(result.message, 'error');
            closeTeacherModal();
        }
    } catch (error) {
        console.error('Load teacher error:', error);
        showNotification('เกิดข้อผิดพลาดในการโหลดข้อมูล', 'error');
        closeTeacherModal();
    }
}

document.getElementById('teacher-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    
    const saveBtn = document.getElementById('save-teacher-btn');
    setButtonLoading(saveBtn, true);
    
    const teacherId = document.getElementById('teacher-id').value;
    
    try {
        let photoPath = currentPhotoUrl;
        
        // ถ้ามีการเลือกรูปใหม่ ให้อัพโหลดรูปก่อน
        if (selectedPhotoFile && typeof selectedPhotoFile === 'string') {
            showNotification('กำลังอัพโหลดรูปภาพ...', 'info');
            
            // อัพโหลดรูปผ่าน API
            const uploadResult = await apiRequest('upload-photo.php', 'POST', {
                photo: selectedPhotoFile
            });
            
            if (uploadResult.success) {
                photoPath = uploadResult.data.photo_path;
                showNotification(`อัพโหลดรูปสำเร็จ (${uploadResult.data.file_size})`, 'success');
            } else {
                throw new Error('ไม่สามารถอัพโหลดรูปภาพได้');
            }
        }
        
        const data = {
            first_name: document.getElementById('teacher-first-name').value,
            last_name: document.getElementById('teacher-last-name').value,
            citizen_id: document.getElementById('teacher-citizen-id').value.trim().replace(/\s+/g, ''),
            department: document.getElementById('teacher-department-input').value,
            position: document.getElementById('teacher-position').value,
            personnel_type: document.getElementById('teacher-personnel-type').value,
            birth_date: getIsoDateInputValue('teacher-birth-date'),
            gender: document.getElementById('teacher-gender').value,
            blood_type: document.getElementById('teacher-blood-type').value,
            email: document.getElementById('teacher-email').value,
            phone: document.getElementById('teacher-phone').value,
            status: document.getElementById('teacher-status-input').value,
            photo: photoPath
        };

        if (canEditRfid()) {
            const rfidCode = (document.getElementById('teacher-rfid-code').value || '').trim();
            if (!teacherId || rfidCode !== '') {
                data.rfid_code = rfidCode;
            }
        }
        
        const action = teacherId ? 'update' : 'create';
        const endpoint = teacherId 
            ? `teachers.php?action=${action}&id=${teacherId}` 
            : `teachers.php?action=${action}`;
        
        const result = await apiRequest(endpoint, 'POST', data);
        
        if (result.success) {
            showNotification(result.message, 'success');
            closeTeacherModal();
            loadTeachers(currentPage);
        } else {
            showNotification(result.message, 'error');
        }
    } catch (error) {
        console.error('Save teacher error:', error);
        showNotification('เกิดข้อผิดพลาดในการบันทึกข้อมูล', 'error');
    } finally {
        setButtonLoading(saveBtn, false);
    }
});

window.editTeacher = function(id) {
    openTeacherModal(id);
};

window.openQuickRfidModal = function(teacherId) {
    if (!canEditRfid()) {
        showNotification('เฉพาะ admin และ super admin เท่านั้นที่แก้ไข RFID ได้', 'warning');
        return;
    }

    const teacher = teacherQuickMap[String(teacherId)];
    if (!teacher) {
        showNotification('ไม่พบข้อมูลครูสำหรับแก้ไข RFID', 'error');
        return;
    }

    document.getElementById('quick-rfid-teacher-id').value = teacher.id;
    document.getElementById('quick-rfid-teacher-name').value = `${teacher.first_name} ${teacher.last_name}`;
    document.getElementById('quick-rfid-code').value = canViewRfid() ? (teacher.rfid_code || '') : '';
    document.getElementById('quick-rfid-code').placeholder = !canViewRfid() && teacher.rfid_masked ? teacher.rfid_masked : 'RFID001';
    openModalElement(document.getElementById('quick-rfid-modal'));
    document.getElementById('quick-rfid-code').focus();
    if (canViewRfid()) {
        document.getElementById('quick-rfid-code').select();
    }
    syncTeacherRfidVisibility();
};

window.closeQuickRfidModal = function() {
    const modal = document.getElementById('quick-rfid-modal');
    const form = document.getElementById('quick-rfid-form');
    if (form) form.reset();
    if (modal) closeModalElement(modal);
};

const quickRfidForm = document.getElementById('quick-rfid-form');
if (quickRfidForm) {
    quickRfidForm.addEventListener('submit', async (e) => {
        e.preventDefault();

        if (!canEditRfid()) {
            showNotification('เฉพาะ admin และ super admin เท่านั้นที่แก้ไข RFID ได้', 'warning');
            return;
        }

        const saveBtn = document.getElementById('save-quick-rfid-btn');
        const teacherId = document.getElementById('quick-rfid-teacher-id').value;
        const rfidCode = (document.getElementById('quick-rfid-code').value || '').trim();

        if (!teacherId || !rfidCode) {
            showNotification('กรุณากรอก RFID ให้ครบถ้วน', 'warning');
            return;
        }

        setButtonLoading(saveBtn, true);
        try {
            const result = await apiRequest(`teachers.php?action=update&id=${teacherId}`, 'POST', {
                rfid_code: rfidCode
            });

            if (result.success) {
                showNotification('บันทึก RFID สำเร็จ', 'success');
                closeQuickRfidModal();
                loadTeachers(currentPage);
            } else {
                showNotification(result.message || 'ไม่สามารถบันทึก RFID ได้', 'error');
            }
        } catch (error) {
            console.error('Quick RFID update error:', error);
            showNotification('เกิดข้อผิดพลาดในการบันทึก RFID', 'error');
        } finally {
            setButtonLoading(saveBtn, false);
        }
    });
}

window.openDeleteTeacherModal = function(id, name) {
    document.getElementById('delete-teacher-id').value = id;
    document.getElementById('delete-teacher-name').value = name || '';
    document.getElementById('delete-teacher-code').value = '';
    openModalElement(document.getElementById('delete-teacher-modal'));
    document.getElementById('delete-teacher-code').focus();
};

window.closeDeleteTeacherModal = function() {
    const form = document.getElementById('delete-teacher-form');
    if (form) form.reset();
    closeModalElement(document.getElementById('delete-teacher-modal'));
};

window.deleteTeacher = function(id, name) {
    window.openDeleteTeacherModal(id, name);
};

document.getElementById('add-teacher-btn').addEventListener('click', () => {
    openTeacherModal();
});

const deleteTeacherForm = document.getElementById('delete-teacher-form');
if (deleteTeacherForm) {
    deleteTeacherForm.addEventListener('submit', async (e) => {
        e.preventDefault();

        const teacherId = document.getElementById('delete-teacher-id').value;
        const teacherName = document.getElementById('delete-teacher-name').value;
        const deleteCode = (document.getElementById('delete-teacher-code').value || '').trim();
        const confirmBtn = document.getElementById('confirm-delete-teacher-btn');

        if (deleteCode !== DELETE_TEACHER_CONFIRM_CODE) {
            showNotification('รหัสยืนยันไม่ถูกต้อง ไม่สามารถลบข้อมูลได้', 'error');
            return;
        }

        setButtonLoading(confirmBtn, true);
        try {
            const result = await apiRequest(`teachers.php?action=delete&id=${teacherId}`, 'POST', {
                delete_code: deleteCode
            });

            if (result.success) {
                showNotification(result.message || `ลบข้อมูล ${teacherName} สำเร็จ`, 'success');
                closeDeleteTeacherModal();
                loadTeachers(currentPage);
            } else {
                showNotification(result.message || 'ไม่สามารถลบข้อมูลได้', 'error');
            }
        } catch (error) {
            console.error('Delete teacher error:', error);
            showNotification('เกิดข้อผิดพลาดในการลบข้อมูล', 'error');
        } finally {
            setButtonLoading(confirmBtn, false, '<i class="fas fa-trash"></i> ยืนยันลบ');
        }
    });
}

// ฟังก์ชันโหลดข้อมูลสรุป
async function loadSummaryReport() {
    const startDateRaw = document.getElementById('summary-start-date').value;
    const endDateRaw = document.getElementById('summary-end-date').value;
    const startDate = toIsoDateFromInput(startDateRaw);
    const endDate = toIsoDateFromInput(endDateRaw);
    const tbody = document.getElementById('summary-table-body');
    summaryLog('loadSummaryReport called', { startDate, endDate, startDateRaw, endDateRaw });

    if (startDateRaw && !startDate) {
        renderEmptySummaryRows('รูปแบบวันที่เริ่มต้นไม่ถูกต้อง (ตัวอย่าง 10/03/2569)');
        return;
    }
    if (endDateRaw && !endDate) {
        renderEmptySummaryRows('รูปแบบวันที่สิ้นสุดไม่ถูกต้อง (ตัวอย่าง 10/03/2569)');
        return;
    }

    if (startDate && endDate && startDate > endDate) {
        summaryLog('Invalid date range', { startDate, endDate });
        tbody.innerHTML = `
            <tr>
                <td colspan="9" class="px-6 py-8 text-center text-red-500">
                    <i class="fas fa-exclamation-circle text-3xl mb-2"></i>
                    <p>ช่วงวันที่ไม่ถูกต้อง: วันที่เริ่มต้องไม่มากกว่าวันที่สิ้นสุด</p>
                </td>
            </tr>
        `;
        return;
    }
    
    tbody.innerHTML = `
        <tr>
            <td colspan="9" class="px-6 py-8 text-center text-gray-500">
                <i class="fas fa-spinner fa-spin text-3xl mb-2"></i>
                <p>กำลังโหลดข้อมูล...</p>
            </td>
        </tr>
    `;
    
    try {
        const params = new URLSearchParams();
        if (startDate) params.append('start_date', startDate);
        if (endDate) params.append('end_date', endDate);
        summaryLog('Request summary-report', params.toString());
        
        const result = await apiRequest(`summary-report.php?${params.toString()}`);
        
        if (result.success && result.data && Array.isArray(result.data.records) && result.data.records.length > 0) {
            summaryRecordsCache = result.data.records;
            summaryLog('Summary data loaded', {
                count: result.data.records.length,
                workDays: result.data.work_days
            });
            displaySummaryReport(result.data.records);
        } else if (!result.success) {
            summaryRecordsCache = [];
            summaryLog('Summary API failed', result);
            tbody.innerHTML = `
                <tr>
                    <td colspan="9" class="px-6 py-8 text-center text-red-500">
                        <i class="fas fa-exclamation-circle text-3xl mb-2"></i>
                        <p>${result.message || 'เกิดข้อผิดพลาดในการโหลดข้อมูล'}</p>
                    </td>
                </tr>
            `;
        } else {
            summaryRecordsCache = [];
            tbody.innerHTML = `
                <tr>
                    <td colspan="9" class="px-6 py-8 text-center text-gray-500">
                        <i class="fas fa-inbox text-3xl mb-2"></i>
                        <p>ไม่พบข้อมูล</p>
                    </td>
                </tr>
            `;
            summaryLog('Summary data is empty');
        }
    } catch (error) {
        console.error('Load summary error:', error);
        summaryLog('loadSummaryReport exception', error);
        tbody.innerHTML = `
            <tr>
                <td colspan="9" class="px-6 py-8 text-center text-red-500">
                    <i class="fas fa-exclamation-circle text-3xl mb-2"></i>
                    <p>เกิดข้อผิดพลาดในการโหลดข้อมูล</p>
                </td>
            </tr>
        `;
    }
}

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function renderSummaryCountButton(count, teacherId, category, colorClass) {
    const safeCount = Number.parseInt(count, 10) || 0;
    if (safeCount <= 0) {
        return `<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full ${colorClass}">${safeCount} ครั้ง</span>`;
    }

    const safeTeacherId = Number.parseInt(teacherId, 10) || 0;
    return `
        <button onclick="openSummaryDetailModal(${safeTeacherId}, '${category}')"
                class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full ${colorClass} hover:opacity-80 transition-opacity underline underline-offset-2"
                title="คลิกเพื่อดูรายละเอียด">
            ${safeCount} ครั้ง
        </button>
    `;
}

function getSummaryDetailsByCategory(record, category) {
    if (!record || !record.details) return [];
    if (category !== 'all') {
        return Array.isArray(record.details[category]) ? record.details[category] : [];
    }

    const merged = []
        .concat(record.details.present || [])
        .concat(record.details.late || [])
        .concat(record.details.leave || [])
        .concat(record.details.absent || [])
        .concat(record.details.other || []);

    return merged.sort((a, b) => {
        const aDate = `${a.attendance_date || ''} ${a.check_in_time || ''}`;
        const bDate = `${b.attendance_date || ''} ${b.check_in_time || ''}`;
        return aDate.localeCompare(bDate);
    });
}

function getFilteredSummaryRecords() {
    const keyword = summarySearchKeyword.trim().toLowerCase();
    const departmentFilter = summaryDepartmentFilter.trim();

    return summaryRecordsCache.filter(record => {
        const fullName = `${record.first_name || ''} ${record.last_name || ''}`.toLowerCase();
        const department = (record.department || '').toLowerCase();
        const matchesKeyword = !keyword || fullName.includes(keyword) || department.includes(keyword);
        const matchesDepartment = !departmentFilter || (record.department || '') === departmentFilter;
        return matchesKeyword && matchesDepartment;
    });
}

function populateSummaryDepartmentOptions(records = []) {
    const select = document.getElementById('summary-department-filter');
    if (!select) return;

    const currentValue = summaryDepartmentFilter || select.value || '';
    const departments = Array.from(new Set(
        (Array.isArray(records) ? records : [])
            .map(record => (record.department || '').trim())
            .filter(Boolean)
    )).sort((a, b) => a.localeCompare(b, 'th'));

    select.innerHTML = '<option value="">ทุกกลุ่มสาระ / แผนก</option>';
    departments.forEach(department => {
        const option = document.createElement('option');
        option.value = department;
        option.textContent = department;
        select.appendChild(option);
    });

    if (currentValue && departments.includes(currentValue)) {
        select.value = currentValue;
        summaryDepartmentFilter = currentValue;
    } else {
        select.value = '';
        summaryDepartmentFilter = '';
    }
}

function renderEmptySummaryRows(message) {
    const tbody = document.getElementById('summary-table-body');
    tbody.innerHTML = `
        <tr>
            <td colspan="9" class="px-6 py-8 text-center text-gray-500">
                <i class="fas fa-inbox text-3xl mb-2"></i>
                <p>${message}</p>
            </td>
        </tr>
    `;
}

function renderFilteredSummaryReport() {
    const filteredRecords = getFilteredSummaryRecords();
    if (!filteredRecords.length) {
        const hasActiveFilter = summarySearchKeyword.trim() || summaryDepartmentFilter.trim();
        const message = hasActiveFilter ? 'ไม่พบข้อมูลที่ค้นหา' : 'ไม่พบข้อมูล';
        renderEmptySummaryRows(message);
        return;
    }
    renderSummaryReportTable(filteredRecords);
}

// ฟังก์ชันแสดงข้อมูลสรุป
function renderSummaryReportTable(records) {
    const tbody = document.getElementById('summary-table-body');
    summaryLog('displaySummaryReport', { rows: records.length });
    
    tbody.innerHTML = records.map((record, index) => {
        const teacherId = Number.parseInt(record.id, 10) || 0;
        const presentCount = Number.parseInt(record.present_count, 10) || 0;
        const lateCount = Number.parseInt(record.late_count, 10) || 0;
        const leaveCount = Number.parseInt(record.leave_count, 10) || 0;
        const absentCount = Number.parseInt(record.absent_count, 10) || 0;
        const otherCount = Number.parseInt(record.other_count, 10) || 0;
        const total = presentCount + lateCount + leaveCount + absentCount + otherCount;
        
        return `
            <tr class="hover:bg-gray-50">
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">${index + 1}</td>
                <td class="px-6 py-4 whitespace-nowrap">
                    <div class="flex items-center">
                        <div class="flex-shrink-0 h-10 w-10">
                            <img class="h-10 w-10 rounded-full object-cover" 
                                 src="${record.photo ? '../' + record.photo : 'https://ui-avatars.com/api/?name=' + encodeURIComponent(record.first_name + ' ' + record.last_name) + '&size=80&background=random'}" 
                                 alt="${record.first_name} ${record.last_name}"
                                 onerror="this.src='https://ui-avatars.com/api/?name=${encodeURIComponent(record.first_name + ' ' + record.last_name)}&size=80&background=random'">
                        </div>
                        <div class="ml-4">
                            <div class="text-sm font-medium text-gray-900">
                                ${record.first_name} ${record.last_name}
                            </div>
                        </div>
                    </div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">${record.department}</td>
                <td class="px-6 py-4 whitespace-nowrap text-center">
                    ${renderSummaryCountButton(presentCount, teacherId, 'present', 'bg-green-100 text-green-800')}
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-center">
                    ${renderSummaryCountButton(lateCount, teacherId, 'late', 'bg-yellow-100 text-yellow-800')}
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-center">
                    ${renderSummaryCountButton(leaveCount, teacherId, 'leave', 'bg-blue-100 text-blue-800')}
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-center">
                    ${renderSummaryCountButton(absentCount, teacherId, 'absent', 'bg-red-100 text-red-800')}
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-center">
                    ${renderSummaryCountButton(otherCount, teacherId, 'other', 'bg-purple-100 text-purple-800')}
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-center">
                    ${renderSummaryCountButton(total, teacherId, 'all', 'bg-gray-100 text-gray-800')}
                </td>
            </tr>
        `;
    }).join('');
}

function displaySummaryReport(records) {
    summaryRecordsCache = Array.isArray(records) ? records : [];
    populateSummaryDepartmentOptions(summaryRecordsCache);
    renderFilteredSummaryReport();
}

function getSummaryCategoryLabel(category) {
    const labels = {
        present: 'มาตรงเวลา',
        late: 'มาสาย',
        leave: 'ลา',
        absent: 'ขาด',
        other: 'อื่น ๆ',
        all: 'รวม'
    };
    return labels[category] || 'รายละเอียด';
}

function getSummaryStatusText(status) {
    const map = {
        present: 'มาตรงเวลา',
        late: 'มาสาย',
        absent: 'ขาด',
        incomplete: 'ไม่สแกนออก'
    };
    return map[status] || status || '-';
}

function formatThaiShortDate(dateString) {
    if (!dateString) return '-';
    const match = String(dateString).match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (!match) return dateString;

    const rawYear = Number.parseInt(match[1], 10);
    const year = rawYear >= 2400 ? rawYear : rawYear + 543;
    const month = Number.parseInt(match[2], 10);
    const day = Number.parseInt(match[3], 10);
    const thaiMonths = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
    const monthText = thaiMonths[month - 1] || '';
    return `${day} ${monthText} ${year}`;
}

function formatThaiDateTime(dateTimeString) {
    if (!dateTimeString) return '-';
    const match = String(dateTimeString).match(/^(\d{4}-\d{2}-\d{2})[ T](\d{2}):(\d{2})/);
    if (!match) return dateTimeString;
    return `${formatThaiShortDate(match[1])} ${match[2]}:${match[3]}`;
}

function toIsoDateFromInput(value) {
    const raw = String(value || '').trim();
    if (!raw) return '';

    if (/^\d{4}-\d{2}-\d{2}$/.test(raw)) {
        return raw;
    }

    const slash = raw.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
    if (!slash) {
        return '';
    }

    const day = Number.parseInt(slash[1], 10);
    const month = Number.parseInt(slash[2], 10);
    let year = Number.parseInt(slash[3], 10);

    if (year >= 2400) {
        year -= 543;
    }

    if (Number.isNaN(day) || Number.isNaN(month) || Number.isNaN(year)) {
        return '';
    }

    const test = new Date(year, month - 1, day);
    if (test.getFullYear() !== year || test.getMonth() + 1 !== month || test.getDate() !== day) {
        return '';
    }

    return `${String(year).padStart(4, '0')}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
}

function isoToThaiInputDate(isoDate) {
    if (!isoDate || !/^\d{4}-\d{2}-\d{2}$/.test(String(isoDate))) return '';
    const [yearStr, monthStr, dayStr] = String(isoDate).split('-');
    const beYear = Number.parseInt(yearStr, 10) + 543;
    return `${dayStr}/${monthStr}/${beYear}`;
}

function setThaiInputDateValue(inputId, isoDate) {
    const el = document.getElementById(inputId);
    if (!el) return;
    let normalized = String(isoDate || '');
    const beMatch = normalized.match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if (beMatch) {
        const y = Number.parseInt(beMatch[1], 10);
        if (y >= 2400) {
            normalized = `${String(y - 543).padStart(4, '0')}-${beMatch[2]}-${beMatch[3]}`;
        }
    }
    if (el.type === 'date') {
        el.value = normalized || '';
        return;
    }
    el.value = isoToThaiInputDate(normalized);
}

function getIsoDateInputValue(inputId) {
    const el = document.getElementById(inputId);
    if (!el) return '';
    if (el.type === 'date') {
        const raw = String(el.value || '').trim();
        return /^\d{4}-\d{2}-\d{2}$/.test(raw) ? raw : '';
    }
    return toIsoDateFromInput(el.value);
}

function formatIsoToThaiDisplay(isoDate) {
    if (!isoDate || !/^\d{4}-\d{2}-\d{2}$/.test(String(isoDate))) return '';
    const [yearStr, monthStr, dayStr] = String(isoDate).split('-');
    const beYear = Number.parseInt(yearStr, 10) + 543;
    return `${dayStr}/${monthStr}/${beYear}`;
}

function syncVisibleDateDisplay(hiddenInputId, displayInputId) {
    const hiddenInput = document.getElementById(hiddenInputId);
    const displayInput = document.getElementById(displayInputId);
    if (!hiddenInput || !displayInput) return;
    displayInput.value = formatIsoToThaiDisplay(hiddenInput.value);
}

function openNativeDatePicker(hiddenInputId) {
    const hiddenInput = document.getElementById(hiddenInputId);
    if (!hiddenInput) return;
    if (typeof hiddenInput.showPicker === 'function') {
        hiddenInput.showPicker();
    } else {
        hiddenInput.focus();
        hiddenInput.click();
    }
}

function syncSummaryDateDisplays() {
    syncVisibleDateDisplay('summary-start-date', 'summary-start-date-display');
    syncVisibleDateDisplay('summary-end-date', 'summary-end-date-display');
}

function formatTimeOnly(dateTimeString) {
    if (!dateTimeString) return '-';
    const match = String(dateTimeString).match(/(?:\d{4}-\d{2}-\d{2})[ T](\d{2}):(\d{2})/);
    if (match) {
        return `${match[1]}:${match[2]}`;
    }
    const timeOnlyMatch = String(dateTimeString).match(/^(\d{2}):(\d{2})/);
    if (timeOnlyMatch) {
        return `${timeOnlyMatch[1]}:${timeOnlyMatch[2]}`;
    }
    return dateTimeString;
}

window.openSummaryDetailModal = function(teacherId, category) {
    const selectedTeacherId = Number.parseInt(teacherId, 10);
    const record = summaryRecordsCache.find(item => Number.parseInt(item.id, 10) === selectedTeacherId);
    if (!record || !record.details) {
        return;
    }

    const details = getSummaryDetailsByCategory(record, category);
    const title = document.getElementById('summary-detail-title');
    const meta = document.getElementById('summary-detail-meta');
    const tbody = document.getElementById('summary-detail-tbody');

    title.textContent = `รายละเอียด ${getSummaryCategoryLabel(category)}`;
    meta.textContent = `${record.first_name} ${record.last_name} | ${record.department}`;

    if (details.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="5" class="px-4 py-6 text-center text-gray-500">ไม่พบข้อมูล</td>
            </tr>
        `;
    } else {
        tbody.innerHTML = details.map((item, idx) => `
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-3 text-sm text-gray-800">${idx + 1}</td>
                <td class="px-4 py-3 text-sm text-gray-800">${escapeHtml(formatThaiShortDate(item.attendance_date || '-'))}</td>
                <td class="px-4 py-3 text-sm text-gray-800">
                    เข้า: ${escapeHtml(formatTimeOnly(item.check_in_time || '-'))}<br>
                    ออก: ${escapeHtml(formatTimeOnly(item.check_out_time || '-'))}
                </td>
                <td class="px-4 py-3 text-sm text-gray-800">${escapeHtml(getSummaryStatusText(item.status))}</td>
                <td class="px-4 py-3 text-sm text-gray-800">${escapeHtml(item.remark || '-')}</td>
            </tr>
        `).join('');
    }

    openModalElement(document.getElementById('summary-detail-modal'));
};

window.closeSummaryDetailModal = function() {
    closeModalElement(document.getElementById('summary-detail-modal'));
};

let academicCalendarsCache = [];
let activeAcademicCalendarId = null;
let settingsEditMode = false;
let editingCalendarId = null;
let attendanceTimeRulesCache = [];
let effectiveTimeRuleToday = null;
let editingTimeRuleId = null;

const WEEKDAY_LABELS = {
    1: 'จ.',
    2: 'อ.',
    3: 'พ.',
    4: 'พฤ.',
    5: 'ศ.',
    6: 'ส.',
    7: 'อา.'
};

function formatTimeRuleWeekdays(weekdays) {
    const values = String(weekdays || '')
        .split(',')
        .map(item => Number.parseInt(item, 10))
        .filter(item => item >= 1 && item <= 7);

    if (!values.length || values.length === 7) {
        return 'ทุกวัน';
    }

    return values.map(value => WEEKDAY_LABELS[value] || value).join(', ');
}

function normalizeTimeInputValue(value) {
    const raw = String(value || '').trim();
    if (!raw) return '';
    return raw.length === 8 ? raw.slice(0, 5) : raw;
}

function clearTimeRuleForm() {
    document.getElementById('time-rule-id').value = '';
    document.getElementById('time-rule-name').value = '';
    document.getElementById('time-rule-note').value = '';
    document.getElementById('time-rule-use-date-range').checked = false;
    document.getElementById('time-rule-start-date').value = '';
    document.getElementById('time-rule-end-date').value = '';
    document.getElementById('time-rule-check-in-start').value = '03:00';
    document.getElementById('time-rule-check-in-late').value = '08:00';
    document.getElementById('time-rule-check-out-start').value = '16:30';
    document.getElementById('time-rule-check-out-end').value = '23:59';
    document.getElementById('time-rule-priority').value = '100';
    document.getElementById('time-rule-active').checked = true;
    document.querySelectorAll('.time-rule-weekday').forEach(input => {
        input.checked = true;
    });
    syncTimeRuleDateRangeState();
}

function fillTimeRuleForm(rule) {
    if (!rule) {
        clearTimeRuleForm();
        return;
    }

    document.getElementById('time-rule-id').value = rule.id || '';
    document.getElementById('time-rule-name').value = rule.rule_name || '';
    document.getElementById('time-rule-note').value = rule.note || '';
    document.getElementById('time-rule-use-date-range').checked = !!(rule.start_date || rule.end_date);
    document.getElementById('time-rule-start-date').value = rule.start_date || '';
    document.getElementById('time-rule-end-date').value = rule.end_date || '';
    document.getElementById('time-rule-check-in-start').value = normalizeTimeInputValue(rule.check_in_start);
    document.getElementById('time-rule-check-in-late').value = normalizeTimeInputValue(rule.check_in_late);
    document.getElementById('time-rule-check-out-start').value = normalizeTimeInputValue(rule.check_out_start);
    document.getElementById('time-rule-check-out-end').value = normalizeTimeInputValue(rule.check_out_end);
    document.getElementById('time-rule-priority').value = String(rule.priority ?? 100);
    document.getElementById('time-rule-active').checked = Number.parseInt(rule.is_active, 10) === 1;

    const weekdaySet = new Set(String(rule.weekdays || '1,2,3,4,5,6,7').split(','));
    document.querySelectorAll('.time-rule-weekday').forEach(input => {
        input.checked = weekdaySet.has(input.value);
    });

    syncTimeRuleDateRangeState();
}

function syncTimeRuleDateRangeState() {
    const useRange = document.getElementById('time-rule-use-date-range')?.checked;
    const startDate = document.getElementById('time-rule-start-date');
    const endDate = document.getElementById('time-rule-end-date');

    [startDate, endDate].forEach(input => {
        if (!input) return;
        input.disabled = !useRange;
        input.classList.toggle('bg-gray-100', !useRange);
        input.classList.toggle('cursor-not-allowed', !useRange);
        if (!useRange) {
            input.value = '';
        }
    });
}

function setTimeRuleModalOpen(open, createMode = false) {
    const modal = document.getElementById('time-rule-modal');
    const title = document.getElementById('time-rule-modal-title');
    if (!modal || !title) return;

    setModalElementOpen(modal, open);
    title.innerHTML = open
        ? (createMode
            ? '<i class="fas fa-plus-circle"></i> เพิ่มกฎเวลา'
            : '<i class="fas fa-pen"></i> แก้ไขกฎเวลา')
        : '<i class="fas fa-plus-circle"></i> เพิ่มกฎเวลา';
}

function getTimeRuleById(ruleId) {
    const id = Number.parseInt(ruleId, 10);
    if (!id) return null;
    return attendanceTimeRulesCache.find(item => Number.parseInt(item.id, 10) === id) || null;
}

function renderEffectiveTimeRuleSummary() {
    const el = document.getElementById('time-rules-today-summary');
    if (!el) return;

    if (!effectiveTimeRuleToday) {
        el.innerHTML = '';
        return;
    }

    const note = effectiveTimeRuleToday.rule_note
        ? `<div class="text-xs text-gray-500 mt-1">${escapeHtml(effectiveTimeRuleToday.rule_note)}</div>`
        : '';

    el.innerHTML = `
        <div><span class="font-semibold text-gray-700">กฎที่ใช้วันนี้:</span> ${escapeHtml(effectiveTimeRuleToday.rule_name || 'ค่ามาตรฐาน')}</div>
        <div class="text-xs text-gray-500">
            เข้า ${escapeHtml(normalizeTimeInputValue(effectiveTimeRuleToday.check_in_start))} /
            สาย ${escapeHtml(normalizeTimeInputValue(effectiveTimeRuleToday.check_in_late))} /
            ออก ${escapeHtml(normalizeTimeInputValue(effectiveTimeRuleToday.check_out_start))}-${escapeHtml(normalizeTimeInputValue(effectiveTimeRuleToday.check_out_end))}
        </div>
        ${note}
    `;
}

function renderTimeRulesTable() {
    const tbody = document.getElementById('time-rules-list-body');
    const emptyEl = document.getElementById('time-rules-empty');
    if (!tbody || !emptyEl) return;

    const canManage = canManageTimeRules();
    document.getElementById('add-time-rule-btn')?.classList.toggle('hidden', !canManage);
    document.getElementById('time-rules-superadmin-note')?.classList.toggle('hidden', canManage);

    if (!attendanceTimeRulesCache.length) {
        tbody.innerHTML = '';
        emptyEl.classList.remove('hidden');
        return;
    }

    emptyEl.classList.add('hidden');

    tbody.innerHTML = attendanceTimeRulesCache.map(rule => {
        const isActive = Number.parseInt(rule.is_active, 10) === 1;
        const statusBadge = isActive
            ? '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">เปิดใช้งาน</span>'
            : '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-700">ปิดใช้งาน</span>';
        const dateRange = rule.start_date && rule.end_date
            ? `${escapeHtml(formatThaiShortDate(rule.start_date))} - ${escapeHtml(formatThaiShortDate(rule.end_date))}`
            : '<span class="text-gray-500">ถาวร</span>';
        const note = rule.note ? escapeHtml(rule.note) : '<span class="text-gray-400">-</span>';
        const actions = canManage
            ? `
                <button onclick="startEditTimeRule(${rule.id})"
                        class="px-3 py-1.5 bg-amber-500 text-white rounded-lg hover:bg-amber-600 text-sm transition-colors">
                    <i class="fas fa-pen"></i> แก้ไข
                </button>
                <button onclick="deleteTimeRule(${rule.id})"
                        class="px-3 py-1.5 bg-red-600 text-white rounded-lg hover:bg-red-700 text-sm transition-colors ml-2">
                    <i class="fas fa-trash"></i> ลบ
                </button>
            `
            : '<span class="text-sm text-gray-400">ดูได้อย่างเดียว</span>';

        return `
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-3">
                    <div class="font-semibold text-gray-800">${escapeHtml(rule.rule_name || '-')}</div>
                    <div class="text-xs text-gray-500">Priority ${escapeHtml(rule.priority || 0)}</div>
                </td>
                <td class="px-4 py-3 text-sm text-gray-700">${dateRange}</td>
                <td class="px-4 py-3 text-sm text-gray-700">${escapeHtml(formatTimeRuleWeekdays(rule.weekdays))}</td>
                <td class="px-4 py-3 text-sm text-gray-700">
                    เริ่ม ${escapeHtml(normalizeTimeInputValue(rule.check_in_start))}<br>
                    สาย ${escapeHtml(normalizeTimeInputValue(rule.check_in_late))}
                </td>
                <td class="px-4 py-3 text-sm text-gray-700">
                    เริ่ม ${escapeHtml(normalizeTimeInputValue(rule.check_out_start))}<br>
                    ถึง ${escapeHtml(normalizeTimeInputValue(rule.check_out_end))}
                </td>
                <td class="px-4 py-3 text-sm text-gray-700 max-w-xs">${note}</td>
                <td class="px-4 py-3">${statusBadge}</td>
                <td class="px-4 py-3 text-right whitespace-nowrap">${actions}</td>
            </tr>
        `;
    }).join('');
}

function getCalendarById(calendarId) {
    const id = Number.parseInt(calendarId, 10);
    if (!id) return null;
    return academicCalendarsCache.find(item => Number.parseInt(item.id, 10) === id) || null;
}

function clearSettingsForm() {
    document.getElementById('settings-calendar-id').value = '';
    document.getElementById('current-academic-year').value = '';
    document.getElementById('semester-1-start').value = '';
    document.getElementById('semester-1-end').value = '';
    document.getElementById('semester-2-start').value = '';
    document.getElementById('semester-2-end').value = '';
}

function fillSettingsForm(calendar) {
    if (!calendar) {
        clearSettingsForm();
        return;
    }
    document.getElementById('settings-calendar-id').value = calendar.id;
    document.getElementById('current-academic-year').value = calendar.academic_year_be || '';
    document.getElementById('semester-1-start').value = calendar.semester_1_start || '';
    document.getElementById('semester-1-end').value = calendar.semester_1_end || '';
    document.getElementById('semester-2-start').value = calendar.semester_2_start || '';
    document.getElementById('semester-2-end').value = calendar.semester_2_end || '';
}

function setSettingsEditMode(editable, createMode = false) {
    if (editable && !canManageAcademicCalendars()) {
        showNotification('เฉพาะผู้ดูแลระบบหลักเท่านั้น', 'warning');
        return;
    }

    settingsEditMode = editable;
    const modal = document.getElementById('settings-modal');
    const modalTitle = document.getElementById('settings-modal-title');
    const form = document.getElementById('settings-form');
    const fields = form.querySelectorAll('input');
    const addBtn = document.getElementById('edit-settings-btn');
    const cancelBtn = document.getElementById('cancel-settings-btn');
    const saveBtn = document.getElementById('save-settings-btn');

    setModalElementOpen(modal, editable);
    if (modalTitle) {
        modalTitle.innerHTML = editable
            ? (createMode
                ? '<i class="fas fa-plus-circle"></i> เพิ่มปีการศึกษา'
                : '<i class="fas fa-pen"></i> แก้ไขปีการศึกษา')
            : '<i class="fas fa-plus-circle"></i> เพิ่มปีการศึกษา';
    }

    fields.forEach(field => {
        if (field.type === 'hidden') return;
        field.disabled = false;
        field.classList.remove('bg-gray-100', 'cursor-not-allowed');
    });

    if (addBtn) {
        addBtn.disabled = editable;
        addBtn.classList.toggle('opacity-50', editable);
        addBtn.classList.toggle('cursor-not-allowed', editable);
    }
    cancelBtn.classList.remove('hidden');
    saveBtn.classList.remove('hidden');
    saveBtn.innerHTML = editable
        ? `<i class="fas fa-save"></i> ${createMode ? 'บันทึกปีการศึกษา' : 'บันทึกการแก้ไข'}`
        : '<i class="fas fa-save"></i> บันทึกปีการศึกษา';
}

function renderSettingsCalendarsTable() {
    const tbody = document.getElementById('settings-calendar-list-body');
    const emptyEl = document.getElementById('settings-calendar-empty');
    const canManage = canManageAcademicCalendars();

    if (!tbody || !emptyEl) return;

    if (!academicCalendarsCache.length) {
        tbody.innerHTML = '';
        emptyEl.classList.remove('hidden');
        return;
    }

    emptyEl.classList.add('hidden');
    tbody.innerHTML = academicCalendarsCache.map(calendar => {
        const isActive = Number.parseInt(calendar.is_active, 10) === 1;
        const statusBadge = isActive
            ? '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">กำลังใช้งาน</span>'
            : '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-700">ยังไม่ใช้งาน</span>';
        const actions = canManage
            ? `
                    <button onclick="startEditAcademicCalendar(${calendar.id})"
                            class="px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm transition-colors">
                        <i class="fas fa-pen"></i> แก้ไข
                    </button>
                    <button onclick="activateAcademicCalendar(${calendar.id})"
                            class="px-3 py-1.5 ${isActive ? 'bg-green-600 cursor-default' : 'bg-purple-600 hover:bg-purple-700'} text-white rounded-lg text-sm transition-colors ml-2"
                            ${isActive ? 'disabled' : ''}>
                        <i class="fas fa-check-circle"></i> ${isActive ? 'ใช้งานอยู่' : 'ใช้งาน'}
                    </button>
              `
            : '<span class="text-sm text-gray-400">ดูได้อย่างเดียว</span>';

        return `
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-3 font-semibold text-gray-800">${escapeHtml(calendar.academic_year_be)}</td>
                <td class="px-4 py-3 text-sm text-gray-700">
                    ${escapeHtml(formatThaiShortDate(calendar.semester_1_start))} - ${escapeHtml(formatThaiShortDate(calendar.semester_1_end))}
                </td>
                <td class="px-4 py-3 text-sm text-gray-700">
                    ${escapeHtml(formatThaiShortDate(calendar.semester_2_start))} - ${escapeHtml(formatThaiShortDate(calendar.semester_2_end))}
                </td>
                <td class="px-4 py-3">${statusBadge}</td>
                <td class="px-4 py-3 text-right whitespace-nowrap">
                    ${actions}
                </td>
            </tr>
        `;
    }).join('');
}

async function fetchAcademicCalendars(forceReload = false) {
    if (!forceReload && academicCalendarsCache.length > 0 && attendanceTimeRulesCache.length > 0) {
        return true;
    }

    const result = await apiRequest('settings.php?action=get');
    if (!result.success || !result.data) {
        return false;
    }

    academicCalendarsCache = Array.isArray(result.data.calendars) ? result.data.calendars : [];
    attendanceTimeRulesCache = Array.isArray(result.data.time_rules) ? result.data.time_rules : [];
    effectiveTimeRuleToday = result.data.effective_time_rule_today || null;
    const active = result.data.active_calendar || academicCalendarsCache.find(item => Number.parseInt(item.is_active, 10) === 1);
    activeAcademicCalendarId = active ? Number.parseInt(active.id, 10) : null;

    renderSettingsCalendarsTable();
    renderTimeRulesTable();
    renderEffectiveTimeRuleSummary();
    return true;
}

// ฟังก์ชันสร้าง dropdown ปีการศึกษา (อิงค่าจากปฏิทินที่ตั้งไว้)
async function populateAcademicYears(preferredCalendarId = null) {
    const select = document.getElementById('academic-year');
    if (!select) return;

    if (!academicCalendarsCache.length) {
        await fetchAcademicCalendars(true);
    }

    const selectedId = Number.parseInt(preferredCalendarId || select.value || activeAcademicCalendarId, 10);
    select.innerHTML = '';

    if (!academicCalendarsCache.length) {
        const emptyOption = document.createElement('option');
        emptyOption.value = '';
        emptyOption.textContent = 'ยังไม่มีปีการศึกษา';
        select.appendChild(emptyOption);
        return;
    }

    academicCalendarsCache.forEach(calendar => {
        const option = document.createElement('option');
        option.value = String(calendar.id);
        option.textContent = Number.parseInt(calendar.is_active, 10) === 1
            ? `${calendar.academic_year_be} (กำลังใช้งาน)`
            : `${calendar.academic_year_be}`;
        if (Number.parseInt(calendar.id, 10) === selectedId) {
            option.selected = true;
        }
        select.appendChild(option);
    });

    if (!select.value) {
        select.value = String(activeAcademicCalendarId || academicCalendarsCache[0].id);
    }
}

function getAcademicPeriod(calendar, semester) {
    if (!calendar) {
        return null;
    }

    if (semester === '1') {
        return {
            startDate: calendar.semester_1_start,
            endDate: calendar.semester_1_end
        };
    }

    if (semester === '2') {
        return {
            startDate: calendar.semester_2_start,
            endDate: calendar.semester_2_end
        };
    }

    return {
        startDate: calendar.semester_1_start,
        endDate: calendar.semester_2_end
    };
}

// ฟังก์ชันโหลดข้อมูลตามปีการศึกษาและภาคเรียน (อิงค่าจากการตั้งค่า)
async function loadAcademicSummary() {
    await loadAcademicSummaryFromSettings();
}

// ฟังก์ชันตั้งค่าช่วงเวลา
function setPeriod(period) {
    const today = new Date();
    let startDate, endDate;
    summaryLog('setPeriod', { period });
    
    switch(period) {
        case 'today':
            startDate = endDate = today;
            break;
        case 'week':
            // สัปดาห์นี้ (จันทร์ - อาทิตย์)
            const dayOfWeek = today.getDay();
            const monday = new Date(today);
            monday.setDate(today.getDate() - (dayOfWeek === 0 ? 6 : dayOfWeek - 1));
            startDate = monday;
            endDate = today;
            break;
        case 'month':
            // เดือนนี้
            startDate = new Date(today.getFullYear(), today.getMonth(), 1);
            endDate = new Date(today.getFullYear(), today.getMonth() + 1, 0);
            break;
        case 'year':
            // ปีนี้ = ทั้งปีการศึกษาที่เลือก (ภาคเรียนที่ 1 + ภาคเรียนที่ 2)
            const academicYearSelect = document.getElementById('academic-year');
            const selectedCalendar = getCalendarById(academicYearSelect?.value)
                || getCalendarById(activeAcademicCalendarId)
                || academicCalendarsCache[0]
                || null;
            const fullAcademicPeriod = getAcademicPeriod(selectedCalendar, 'all');

            if (fullAcademicPeriod?.startDate && fullAcademicPeriod?.endDate) {
                startDate = fullAcademicPeriod.startDate;
                endDate = fullAcademicPeriod.endDate;
            } else {
                // fallback กรณียังไม่ตั้งค่าปีการศึกษา
                startDate = new Date(today.getFullYear(), 0, 1);
                endDate = new Date(today.getFullYear(), 11, 31);
            }
            break;
    }

    const normalizePeriodDate = (value) => {
        if (value instanceof Date) {
            return value.toISOString().split('T')[0];
        }
        return String(value || '');
    };

    setThaiInputDateValue('summary-start-date', normalizePeriodDate(startDate));
    setThaiInputDateValue('summary-end-date', normalizePeriodDate(endDate));
    syncSummaryDateDisplays();
    
    // อัพเดทสีปุ่ม
    document.querySelectorAll('.period-btn').forEach(btn => {
        if (btn.dataset.period === period) {
            btn.className = 'period-btn px-4 py-2 bg-blue-600 text-white rounded-lg';
        } else {
            btn.className = 'period-btn px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-blue-600 hover:text-white transition-colors';
        }
    });
    
    // โหลดข้อมูลทันที
    loadSummaryReport();
}

// ตัวแปรเช็กว่าได้ initialize แท็บสรุปข้อมูลแล้วหรือยัง
let summaryInitialized = false;

// ฟังก์ชัน initialize แท็บสรุปข้อมูล
async function initializeSummaryTab(forceReload = false) {
    summaryLog('initializeSummaryTab', { summaryInitialized, forceReload });
    if (summaryInitialized) {
        if (forceReload) {
            setPeriod('year');
        }
        return;
    }
    
    // สร้าง dropdown ปีการศึกษา
    await fetchAcademicCalendars(true);
    await populateAcademicYears();
    
    // ค่าเริ่มต้นเข้าแท็บสรุป: ปีการศึกษาที่กำลังใช้งาน
    setPeriod('year');
    
    // Event listener สำหรับปุ่มค้นหา
    document.getElementById('load-summary-btn').addEventListener('click', () => {
        summaryLog('click load-summary-btn');
        loadSummaryReport();
    });
    
    // Event listener สำหรับปุ่มดูข้อมูลตามปีการศึกษา
    document.getElementById('load-academic-btn').addEventListener('click', () => {
        summaryLog('click load-academic-btn');
        loadAcademicSummaryFromSettings();
    });
    
    // Event listener สำหรับปุ่มกรองตามช่วงเวลา
    document.querySelectorAll('.period-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            summaryLog('click period-btn', { period: this.dataset.period });
            setPeriod(this.dataset.period);
        });
    });

    const summarySearchInput = document.getElementById('summary-search');
    if (summarySearchInput) {
        summarySearchInput.addEventListener('input', () => {
            summarySearchKeyword = summarySearchInput.value || '';
            renderFilteredSummaryReport();
        });
    }

    const summaryDepartmentSelect = document.getElementById('summary-department-filter');
    if (summaryDepartmentSelect) {
        summaryDepartmentSelect.addEventListener('change', () => {
            summaryDepartmentFilter = summaryDepartmentSelect.value || '';
            renderFilteredSummaryReport();
        });
    }
    
    summaryInitialized = true;
}

async function loadSettings() {
    try {
        const ok = await fetchAcademicCalendars(true);
        if (!ok) {
            showNotification('เกิดข้อผิดพลาดในการโหลดการตั้งค่า', 'error');
            return;
        }

        await populateAcademicYears();

        const activeCalendar = getCalendarById(activeAcademicCalendarId) || academicCalendarsCache[0] || null;
        fillSettingsForm(activeCalendar);
        editingCalendarId = activeCalendar ? Number.parseInt(activeCalendar.id, 10) : null;
        setSettingsEditMode(false);
        syncAcademicCalendarPermissionUi();
        renderTimeRulesTable();
        renderEffectiveTimeRuleSummary();
    } catch (error) {
        console.error('Load settings error:', error);
        showNotification('เกิดข้อผิดพลาดในการโหลดการตั้งค่า', 'error');
    }
}

// ฟังก์ชันบันทึกการตั้งค่า
document.getElementById('settings-form').addEventListener('submit', async function(e) {
    e.preventDefault();

    if (!canManageAcademicCalendars()) {
        showNotification('เฉพาะผู้ดูแลระบบหลักเท่านั้น', 'error');
        return;
    }

    if (!settingsEditMode) {
        showNotification('กรุณากดปุ่ม "เพิ่มปีการศึกษา" หรือ "แก้ไข" ก่อนบันทึก', 'warning');
        return;
    }
    
    const saveBtn = document.getElementById('save-settings-btn');
    setButtonLoading(saveBtn, true);
    
    try {
        const buddhistYear = Number.parseInt(document.getElementById('current-academic-year').value, 10);
        const semester1Start = document.getElementById('semester-1-start').value;
        const semester1End = document.getElementById('semester-1-end').value;
        const semester2Start = document.getElementById('semester-2-start').value;
        const semester2End = document.getElementById('semester-2-end').value;

        if (Number.isNaN(buddhistYear)) {
            showNotification('กรุณากรอกปีการศึกษาให้ถูกต้อง', 'error');
            return;
        }
        if (buddhistYear < 2400 || buddhistYear > 3000) {
            showNotification('ปีการศึกษาไม่ถูกต้อง', 'error');
            return;
        }
        if (!semester1Start || !semester1End || !semester2Start || !semester2End) {
            showNotification('กรุณากรอกวันที่ภาคเรียนให้ครบ', 'error');
            return;
        }

        if (semester1Start > semester1End) {
            showNotification('ภาคเรียนที่ 1: วันเริ่มต้นต้องไม่มากกว่าวันสิ้นสุด', 'error');
            return;
        }
        if (semester2Start > semester2End) {
            showNotification('ภาคเรียนที่ 2: วันเริ่มต้นต้องไม่มากกว่าวันสิ้นสุด', 'error');
            return;
        }

        const payload = {
            academic_year_be: buddhistYear,
            semester_1_start: semester1Start,
            semester_1_end: semester1End,
            semester_2_start: semester2Start,
            semester_2_end: semester2End
        };

        if (editingCalendarId) {
            payload.id = editingCalendarId;
        }

        const result = await apiRequest('settings.php?action=save_calendar', 'POST', payload);
        
        if (result.success) {
            showNotification(result.message, 'success');
            setSettingsEditMode(false);
            await loadSettings();
            if (summaryInitialized) {
                await fetchAcademicCalendars(true);
                await populateAcademicYears();
            }
        } else {
            showNotification(result.message, 'error');
        }
    } catch (error) {
        console.error('Save settings error:', error);
        showNotification('เกิดข้อผิดพลาดในการบันทึกการตั้งค่า', 'error');
    } finally {
        setButtonLoading(saveBtn, false);
    }
});

document.getElementById('edit-settings-btn').addEventListener('click', () => {
    if (!canManageAcademicCalendars()) {
        showNotification('เฉพาะผู้ดูแลระบบหลักเท่านั้น', 'warning');
        return;
    }

    editingCalendarId = null;
    clearSettingsForm();
    setSettingsEditMode(true, true);
});

document.getElementById('cancel-settings-btn').addEventListener('click', async () => {
    const activeCalendar = getCalendarById(activeAcademicCalendarId) || academicCalendarsCache[0] || null;
    fillSettingsForm(activeCalendar);
    editingCalendarId = activeCalendar ? Number.parseInt(activeCalendar.id, 10) : null;
    setSettingsEditMode(false);
    showNotification('ยกเลิกการแก้ไขแล้ว', 'info');
});

document.getElementById('time-rule-use-date-range').addEventListener('change', () => {
    syncTimeRuleDateRangeState();
});

document.getElementById('add-time-rule-btn').addEventListener('click', () => {
    if (!canManageTimeRules()) {
        showNotification('เฉพาะผู้ดูแลระบบหลักเท่านั้น', 'warning');
        return;
    }

    editingTimeRuleId = null;
    clearTimeRuleForm();
    setTimeRuleModalOpen(true, true);
});

document.getElementById('cancel-time-rule-btn').addEventListener('click', () => {
    setTimeRuleModalOpen(false);
});

document.getElementById('time-rule-modal-close').addEventListener('click', () => {
    setTimeRuleModalOpen(false);
});

document.getElementById('time-rule-form').addEventListener('submit', async (e) => {
    e.preventDefault();

    if (!canManageTimeRules()) {
        showNotification('เฉพาะผู้ดูแลระบบหลักเท่านั้น', 'error');
        return;
    }

    const saveBtn = document.getElementById('save-time-rule-btn');
    setButtonLoading(saveBtn, true);

    try {
        const weekdays = Array.from(document.querySelectorAll('.time-rule-weekday:checked')).map(input => input.value);
        if (!weekdays.length) {
            showNotification('กรุณาเลือกอย่างน้อย 1 วัน', 'error');
            return;
        }

        const useDateRange = document.getElementById('time-rule-use-date-range').checked;
        const payload = {
            rule_name: document.getElementById('time-rule-name').value.trim(),
            note: document.getElementById('time-rule-note').value.trim(),
            start_date: useDateRange ? document.getElementById('time-rule-start-date').value : '',
            end_date: useDateRange ? document.getElementById('time-rule-end-date').value : '',
            weekdays,
            check_in_start: document.getElementById('time-rule-check-in-start').value,
            check_in_late: document.getElementById('time-rule-check-in-late').value,
            check_out_start: document.getElementById('time-rule-check-out-start').value,
            check_out_end: document.getElementById('time-rule-check-out-end').value,
            priority: Number.parseInt(document.getElementById('time-rule-priority').value, 10) || 100,
            is_active: document.getElementById('time-rule-active').checked ? 1 : 0
        };

        if (editingTimeRuleId) {
            payload.id = editingTimeRuleId;
        }

        const result = await apiRequest('settings.php?action=save_time_rule', 'POST', payload);
        if (!result.success) {
            showNotification(result.message || 'ไม่สามารถบันทึกกฎเวลาได้', 'error');
            return;
        }

        showNotification(result.message, 'success');
        setTimeRuleModalOpen(false);
        await loadSettings();
    } catch (error) {
        console.error('Save time rule error:', error);
        showNotification('เกิดข้อผิดพลาดในการบันทึกกฎเวลา', 'error');
    } finally {
        setButtonLoading(saveBtn, false);
    }
});

const settingsModalCloseBtn = document.getElementById('settings-modal-close');
if (settingsModalCloseBtn) {
    settingsModalCloseBtn.addEventListener('click', () => {
        const activeCalendar = getCalendarById(activeAcademicCalendarId) || academicCalendarsCache[0] || null;
        fillSettingsForm(activeCalendar);
        editingCalendarId = activeCalendar ? Number.parseInt(activeCalendar.id, 10) : null;
        setSettingsEditMode(false);
    });
}

const settingsModal = document.getElementById('settings-modal');
if (settingsModal) {
    settingsModal.addEventListener('click', (event) => {
        if (event.target === settingsModal) {
            const activeCalendar = getCalendarById(activeAcademicCalendarId) || academicCalendarsCache[0] || null;
            fillSettingsForm(activeCalendar);
            editingCalendarId = activeCalendar ? Number.parseInt(activeCalendar.id, 10) : null;
            setSettingsEditMode(false);
        }
    });
}

window.startEditAcademicCalendar = function(calendarId) {
    if (!canManageAcademicCalendars()) {
        showNotification('เฉพาะผู้ดูแลระบบหลักเท่านั้น', 'warning');
        return;
    }

    const calendar = getCalendarById(calendarId);
    if (!calendar) {
        showNotification('ไม่พบข้อมูลปีการศึกษา', 'error');
        return;
    }
    editingCalendarId = Number.parseInt(calendar.id, 10);
    fillSettingsForm(calendar);
    setSettingsEditMode(true, false);
};

window.activateAcademicCalendar = async function(calendarId) {
    if (!canManageAcademicCalendars()) {
        showNotification('เฉพาะผู้ดูแลระบบหลักเท่านั้น', 'warning');
        return;
    }

    try {
        const result = await apiRequest('settings.php?action=set_active_calendar', 'POST', { id: calendarId });
        if (!result.success) {
            showNotification(result.message || 'ไม่สามารถเปลี่ยนปีการศึกษาที่ใช้งานได้', 'error');
            return;
        }

        showNotification(result.message, 'success');
        await loadSettings();
        if (summaryInitialized) {
            await fetchAcademicCalendars(true);
            await populateAcademicYears(calendarId);
            await loadAcademicSummaryFromSettings();
        }
    } catch (error) {
        console.error('Activate academic calendar error:', error);
        showNotification('เกิดข้อผิดพลาดในการเปลี่ยนปีการศึกษา', 'error');
    }
};

function syncAcademicCalendarPermissionUi() {
    const canManage = canManageAcademicCalendars();
    const addBtn = document.getElementById('edit-settings-btn');
    const note = document.getElementById('settings-superadmin-note');

    if (addBtn) {
        addBtn.classList.toggle('hidden', !canManage);
    }

    if (note) {
        note.classList.toggle('hidden', canManage);
    }
}

window.startEditTimeRule = function(ruleId) {
    if (!canManageTimeRules()) {
        showNotification('เฉพาะผู้ดูแลระบบหลักเท่านั้น', 'warning');
        return;
    }

    const rule = getTimeRuleById(ruleId);
    if (!rule) {
        showNotification('ไม่พบกฎเวลาที่ต้องการแก้ไข', 'error');
        return;
    }

    editingTimeRuleId = Number.parseInt(rule.id, 10);
    fillTimeRuleForm(rule);
    setTimeRuleModalOpen(true, false);
};

window.deleteTimeRule = async function(ruleId) {
    if (!canManageTimeRules()) {
        showNotification('เฉพาะผู้ดูแลระบบหลักเท่านั้น', 'warning');
        return;
    }

    const rule = getTimeRuleById(ruleId);
    if (!rule) {
        showNotification('ไม่พบกฎเวลาที่ต้องการลบ', 'error');
        return;
    }

    if (!window.confirm(`ลบกฎเวลา "${rule.rule_name}" ใช่หรือไม่?`)) {
        return;
    }

    try {
        const result = await apiRequest('settings.php?action=delete_time_rule', 'POST', { id: ruleId });
        if (!result.success) {
            showNotification(result.message || 'ไม่สามารถลบกฎเวลาได้', 'error');
            return;
        }

        showNotification(result.message, 'success');
        await loadSettings();
    } catch (error) {
        console.error('Delete time rule error:', error);
        showNotification('เกิดข้อผิดพลาดในการลบกฎเวลา', 'error');
    }
};

// ดึงช่วงวันที่ตามปีการศึกษาและภาคเรียนที่เลือก
async function getAcademicPeriodFromSettings(calendarId, semester) {
    if (!academicCalendarsCache.length) {
        await fetchAcademicCalendars(true);
    }

    const selectedCalendar = getCalendarById(calendarId) || getCalendarById(activeAcademicCalendarId) || academicCalendarsCache[0] || null;
    const period = getAcademicPeriod(selectedCalendar, semester);

    if (!period) {
        throw new Error('ยังไม่มีการตั้งค่าปีการศึกษา');
    }

    return period;
}

// โหลดข้อมูลสรุปตามปีการศึกษา/ภาคเรียนที่เลือก
async function loadAcademicSummaryFromSettings() {
    try {
        const calendarId = document.getElementById('academic-year').value;
        const semester = document.getElementById('semester').value;
        const period = await getAcademicPeriodFromSettings(calendarId, semester);

        setThaiInputDateValue('summary-start-date', period.startDate);
        setThaiInputDateValue('summary-end-date', period.endDate);
        syncSummaryDateDisplays();

        await loadSummaryReport();
    } catch (error) {
        console.error('Load academic summary from settings error:', error);
        renderEmptySummaryRows('ยังไม่มีปฏิทินปีการศึกษา กรุณาตั้งค่าที่แท็บการตั้งค่า');
    }
}

initializeBuddhistDateInputs();
checkAuth();
