const API_BASE = '../api/';
let activityLogsSearchTimeout;

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
        return await response.json();
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

function formatActionLabel(action) {
    const map = {
        CHECK_IN: 'สแกนเข้า',
        CHECK_OUT: 'สแกนออก'
    };
    return map[action] || action || '-';
}

function renderActivityLogsTable(records) {
    const tbody = document.getElementById('activity-logs-table-body');

    if (!records || records.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="5" class="px-6 py-8 text-center text-gray-500">
                    <i class="fas fa-inbox text-3xl mb-2"></i>
                    <p>ไม่พบข้อมูล log การสแกน</p>
                </td>
            </tr>
        `;
        return;
    }

    tbody.innerHTML = records.map(log => `
        <tr class="hover:bg-gray-50">
            <td class="px-4 py-3 text-sm text-gray-700 whitespace-nowrap">${formatLogDateTime(log.created_at)}</td>
            <td class="px-4 py-3 text-sm text-gray-800">
                <div class="font-semibold">${escapeHtml(formatActionLabel(log.action))}</div>
                <div class="text-xs text-gray-500">${escapeHtml(log.action || '-')}</div>
            </td>
            <td class="px-4 py-3 text-sm text-gray-700">${escapeHtml(log.description || '-')}</td>
            <td class="px-4 py-3 text-sm text-gray-700 whitespace-nowrap">${escapeHtml(log.ip_address || '-')}</td>
            <td class="px-4 py-3 text-sm text-gray-700 break-all">${escapeHtml(log.user_agent || '-')}</td>
        </tr>
    `).join('');
}

function populateActionOptions(actions = []) {
    const select = document.getElementById('activity-log-action');
    const current = select.value;
    select.innerHTML = '<option value="">ทั้งหมด</option>';

    actions.forEach(action => {
        const option = document.createElement('option');
        option.value = action;
        option.textContent = formatActionLabel(action);
        select.appendChild(option);
    });

    if (current) {
        select.value = current;
    }
}

function renderPagination(pagination) {
    const container = document.getElementById('activity-logs-pagination');

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
                onclick="loadActivityLogs(${pagination.page - 1})"
                ${pagination.page === 1 ? 'disabled' : ''}
                class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors font-semibold disabled:opacity-50 disabled:cursor-not-allowed">
                <i class="fas fa-chevron-left"></i> ก่อนหน้า
            </button>
            ${pages.map(page => {
                if (page === '...') {
                    return '<span class="px-3 py-2 text-gray-500">...</span>';
                }
                const active = page === pagination.page;
                return `<button onclick="loadActivityLogs(${page})" class="px-4 py-2 ${active ? 'bg-blue-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-100'} rounded-lg transition-colors font-semibold">${page}</button>`;
            }).join('')}
            <button
                onclick="loadActivityLogs(${pagination.page + 1})"
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

async function loadActivityLogs(page = 1) {
    const loadingEl = document.getElementById('activity-logs-loading');
    const contentEl = document.getElementById('activity-logs-content');

    loadingEl.classList.remove('hidden');
    contentEl.classList.add('hidden');

    try {
        const params = new URLSearchParams({
            action: 'list',
            page: String(page),
            limit: '20'
        });

        const search = document.getElementById('activity-log-search').value.trim();
        const logAction = document.getElementById('activity-log-action').value;
        const dateFrom = document.getElementById('activity-log-date-from').value;
        const dateTo = document.getElementById('activity-log-date-to').value;

        if (search) params.append('search', search);
        if (logAction) params.append('log_action', logAction);
        if (dateFrom) params.append('date_from', dateFrom);
        if (dateTo) params.append('date_to', dateTo);

        const result = await apiRequest(`activity-logs.php?${params.toString()}`);
        if (!result.success) {
            alert(result.message || 'ไม่สามารถโหลดข้อมูล log การสแกนได้');
            return;
        }

        populateActionOptions(result.data.actions || []);
        renderActivityLogsTable(result.data.records || []);
        renderPagination(result.data.pagination || null);

        loadingEl.classList.add('hidden');
        contentEl.classList.remove('hidden');
    } catch (error) {
        console.error('Load activity logs error:', error);
        alert('เกิดข้อผิดพลาดในการโหลดข้อมูล log การสแกน');
    }
}

document.getElementById('activity-log-refresh-btn').addEventListener('click', () => {
    loadActivityLogs(1);
});

document.getElementById('activity-log-search').addEventListener('input', () => {
    clearTimeout(activityLogsSearchTimeout);
    activityLogsSearchTimeout = setTimeout(() => loadActivityLogs(1), 400);
});

document.getElementById('activity-log-action').addEventListener('change', () => {
    loadActivityLogs(1);
});

document.getElementById('activity-log-date-from').addEventListener('change', () => {
    loadActivityLogs(1);
});

document.getElementById('activity-log-date-to').addEventListener('change', () => {
    loadActivityLogs(1);
});

document.getElementById('logout-btn').addEventListener('click', async () => {
    const result = await apiRequest('auth.php?action=logout', 'POST');
    if (result.success) {
        window.location.href = 'index.php';
    } else {
        alert(result.message || 'ไม่สามารถออกจากระบบได้');
    }
});

initializeBuddhistDateInputs();
loadActivityLogs(1);

