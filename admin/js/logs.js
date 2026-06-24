const API_BASE = '../api/';
let adminLogsSearchTimeout;

function initializeBuddhistDateInputs() {
    document.querySelectorAll('input[type="date"]').forEach(input => {
        input.setAttribute('lang', 'th-TH-u-ca-buddhist');
    });
}

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

async function apiRequest(endpoint, method = 'GET', data = null) {
    const options = {
        method,
        headers: {
            'Content-Type': 'application/json'
        }
    };

    if (data && method !== 'GET') {
        options.body = JSON.stringify(data);
    }

    try {
        const response = await fetch(API_BASE + endpoint, options);
        const result = await response.json();
        return result;
    } catch (error) {
        console.error('API error:', error);
        return { success: false, message: 'เกิดข้อผิดพลาดในการเชื่อมต่อ' };
    }
}

function formatLogDateTime(dateTime) {
    if (!dateTime) return '-';
    const dt = new Date(String(dateTime).replace(' ', 'T'));
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

function parseDetailsJson(detailsJson) {
    if (!detailsJson) return {};
    try {
        const parsed = JSON.parse(detailsJson);
        return parsed && typeof parsed === 'object' ? parsed : {};
    } catch (error) {
        return {};
    }
}

function formatActionLabel(action) {
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

function formatThaiDate(dateString) {
    if (!dateString) return '-';
    const match = String(dateString).match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (!match) return dateString;
    const year = Number(match[1]) + 543;
    return `${match[3]}/${match[2]}/${year}`;
}

function formatTimeOnly(dateTimeString) {
    if (!dateTimeString) return '-';
    const match = String(dateTimeString).match(/(?:\d{4}-\d{2}-\d{2})[ T](\d{2}):(\d{2})/);
    if (match) return `${match[1]}:${match[2]}`;
    const only = String(dateTimeString).match(/^(\d{2}):(\d{2})/);
    return only ? `${only[1]}:${only[2]}` : dateTimeString;
}

function buildDetailsHtml(log) {
    const details = parseDetailsJson(log.details_json);
    const lines = [];

    if (details.teacher_name) {
        lines.push(`บุคคล: ${escapeHtml(details.teacher_name)}`);
    }
    if (details.attendance_date) {
        lines.push(`วันที่: ${escapeHtml(formatThaiDate(details.attendance_date))}`);
    }
    if (details.citizen_id) {
        lines.push(`เลขบัตร: ${escapeHtml(details.citizen_id)}`);
    }
    if (details.rfid_code) {
        lines.push(`RFID: ${escapeHtml(details.rfid_code)}`);
    }
    if (details.academic_year_be) {
        lines.push(`ปีการศึกษา: ${escapeHtml(details.academic_year_be)}`);
    }
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
        const oldRemark = details.old_remark ?? '-';
        const newRemark = details.new_remark ?? '-';
        lines.push(`หมายเหตุ: ${escapeHtml(oldRemark)} -> ${escapeHtml(newRemark)}`);
    }

    const headline = escapeHtml(log.description || '-');
    if (!lines.length) {
        return `<div>${headline}</div>`;
    }

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
        const method = log.request_method
            ? `<span class="text-xs bg-gray-200 text-gray-700 rounded px-2 py-0.5">${escapeHtml(log.request_method)}</span>`
            : '';
        const actionLabel = formatActionLabel(log.action);
        const detailsHtml = buildDetailsHtml(log);

        return `
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-3 text-sm text-gray-700 whitespace-nowrap">${formatLogDateTime(log.created_at)}</td>
                <td class="px-4 py-3 text-sm text-gray-800">
                    <div class="font-semibold">${escapeHtml(log.admin_username || '-')}</div>
                    <div class="text-xs text-gray-500">${escapeHtml(log.admin_name || '-')}</div>
                </td>
                <td class="px-4 py-3 text-sm text-gray-800">
                    <div class="font-semibold">${escapeHtml(actionLabel)}</div>
                    <div class="text-xs text-gray-500">${escapeHtml(log.action || '-')}</div>
                </td>
                <td class="px-4 py-3 text-sm text-gray-700">
                    ${detailsHtml}
                </td>
                <td class="px-4 py-3 text-sm text-gray-700 whitespace-nowrap">${escapeHtml(log.ip_address || '-')}</td>
                <td class="px-4 py-3 text-sm text-gray-700">
                    <div class="break-all">${escapeHtml(log.endpoint || '-')}</div>
                    <div class="mt-1">${method}</div>
                </td>
            </tr>
        `;
    }).join('');
}

function populateActionOptions(actions = []) {
    const select = document.getElementById('admin-log-action');
    const current = select.value;
    select.innerHTML = '<option value="">ทั้งหมด</option>';

    actions.forEach(action => {
        const option = document.createElement('option');
        option.value = action;
        option.textContent = action;
        select.appendChild(option);
    });

    if (current) {
        select.value = current;
    }
}

function renderPagination(pagination) {
    const container = document.getElementById('admin-logs-pagination');

    if (!pagination || pagination.total_pages <= 1) {
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
        <div class="flex items-center gap-2 justify-center">
            <button
                onclick="loadAdminLogs(${pagination.page - 1})"
                ${pagination.page === 1 ? 'disabled' : ''}
                class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors font-semibold disabled:opacity-50 disabled:cursor-not-allowed">
                <i class="fas fa-chevron-left"></i> ก่อนหน้า
            </button>
            ${pages.map(page => {
                if (page === '...') {
                    return '<span class="px-3 py-2 text-gray-500">...</span>';
                }
                const active = page === pagination.page;
                return `<button onclick="loadAdminLogs(${page})" class="px-4 py-2 ${active ? 'bg-blue-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-100'} rounded-lg transition-colors font-semibold">${page}</button>`;
            }).join('')}
            <button
                onclick="loadAdminLogs(${pagination.page + 1})"
                ${pagination.page === pagination.total_pages ? 'disabled' : ''}
                class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors font-semibold disabled:opacity-50 disabled:cursor-not-allowed">
                ถัดไป <i class="fas fa-chevron-right"></i>
            </button>
        </div>
        <p class="text-sm text-gray-600 mt-2 text-center">
            แสดง ${(pagination.page - 1) * pagination.limit + 1} - ${Math.min(pagination.page * pagination.limit, pagination.total)}
            จากทั้งหมด ${pagination.total} รายการ
        </p>
    `;
}

async function loadAdminLogs(page = 1) {
    const loading = document.getElementById('admin-logs-loading');
    const content = document.getElementById('admin-logs-content');
    const search = document.getElementById('admin-log-search').value.trim();
    const action = document.getElementById('admin-log-action').value;
    const dateFrom = document.getElementById('admin-log-date-from').value;
    const dateTo = document.getElementById('admin-log-date-to').value;

    if (dateFrom && dateTo && dateFrom > dateTo) {
        alert('ช่วงวันที่ไม่ถูกต้อง');
        return;
    }

    loading.classList.remove('hidden');
    content.classList.add('hidden');

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
        populateActionOptions(result.data.actions || []);
        renderAdminLogsTable(result.data.records || []);
        renderPagination(result.data.pagination);
    }

    loading.classList.add('hidden');
    content.classList.remove('hidden');
}

document.getElementById('admin-log-refresh-btn').addEventListener('click', () => loadAdminLogs(1));
document.getElementById('admin-log-action').addEventListener('change', () => loadAdminLogs(1));
document.getElementById('admin-log-date-from').addEventListener('change', () => loadAdminLogs(1));
document.getElementById('admin-log-date-to').addEventListener('change', () => loadAdminLogs(1));
document.getElementById('admin-log-search').addEventListener('input', () => {
    clearTimeout(adminLogsSearchTimeout);
    adminLogsSearchTimeout = setTimeout(() => loadAdminLogs(1), 350);
});
document.getElementById('logout-btn').addEventListener('click', async () => {
    await apiRequest('auth.php?action=logout', 'POST');
    window.location.href = 'index.php';
});

initializeBuddhistDateInputs();
loadAdminLogs(1);
