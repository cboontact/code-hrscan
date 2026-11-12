<?php
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('X-Frame-Options: DENY');
$nonce = base64_encode(random_bytes(18));
header("Content-Security-Policy: default-src 'none'; script-src 'nonce-{$nonce}'; style-src 'nonce-{$nonce}'; connect-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <title>ลงเวลาส่วนตัว · โรงเรียนจอมทอง</title>
    <style nonce="<?= htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') ?>">
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100dvh; background: #f1f5f9; color: #172554; font-family: system-ui, sans-serif; padding: 32px 16px; }
        main { width: 100%; max-width: 480px; margin: 0 auto; }
        .brand { font-size: 14px; color: #64748b; margin-bottom: 24px; text-align: center; }
        .card { background: white; border: 1px solid #e2e8f0; border-radius: 24px; padding: 28px 24px; box-shadow: 0 12px 36px #0f172a08; }
        .eyebrow { color: #2563eb; font-size: 13px; font-weight: 650; margin: 0 0 8px; }
        h1 { margin: 0; font-size: 25px; line-height: 1.45; }
        .muted { color: #64748b; font-size: 14px; line-height: 1.7; }
        #date { border-bottom: 1px solid #e2e8f0; padding-bottom: 20px; margin-bottom: 20px; }
        .times { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin: 20px 0; }
        .time { background: #f8fafc; border-radius: 14px; padding: 16px; }
        .time span { display: block; color: #64748b; font-size: 13px; margin-bottom: 6px; }
        .time strong { font-size: 25px; font-variant-numeric: tabular-nums; }
        button { width: 100%; border: 0; border-radius: 14px; padding: 17px; font: inherit; font-size: 18px; font-weight: 650; cursor: pointer; margin-top: 12px; min-height: 60px; }
        #check-in { color: white; background: #2563eb; }
        #check-out { color: #1e40af; background: #eff6ff; border: 1px solid #bfdbfe; }
        button:disabled { opacity: .45; cursor: default; }
        button:focus-visible { outline: 3px solid #f59e0b; outline-offset: 3px; }
        #notice { padding: 14px 16px; background: #f8fafc; border-radius: 12px; font-size: 14px; line-height: 1.65; margin: 16px 0 0; }
        #notice.error { background: #fff1f2; color: #be123c; }
        #notice.success { background: #ecfdf5; color: #047857; }
        #rules { margin-top: 22px; }
        footer { text-align: center; font-size: 12px; color: #64748b; line-height: 1.8; margin: 20px 12px; }
    </style>
</head>
<body>
<main>
    <div class="brand">โรงเรียนจอมทอง · ระบบลงเวลาราชการ</div>
    <section class="card" aria-label="ลงเวลาส่วนตัว">
        <p class="eyebrow">ลงเวลาส่วนตัว</p>
        <h1 id="name">กำลังตรวจสอบลิงก์…</h1>
        <div id="department" class="muted"></div>
        <p id="date" class="muted"></p>
        <div id="status" class="muted">กำลังโหลดข้อมูลวันนี้</div>
        <div class="times">
            <div class="time"><span>เวลาเข้า</span><strong id="in-time">—</strong></div>
            <div class="time"><span>เวลาออก</span><strong id="out-time">—</strong></div>
        </div>
        <button id="check-in" type="button" disabled>ลงเวลาเข้า</button>
        <button id="check-out" type="button" disabled>ลงเวลาออก</button>
        <p id="notice" role="status" aria-live="polite">เปิดลิงก์เพื่อดูข้อมูล แล้วกดปุ่มเมื่อต้องการลงเวลา</p>
        <div id="rules" class="muted"></div>
        <button id="refresh" type="button">โหลดข้อมูลใหม่</button>
    </section>
    <footer>ลิงก์นี้ใช้ลงเวลาแทนเจ้าของได้ กรุณาเก็บเป็นส่วนตัว<br>การเปิดลิงก์อย่างเดียวจะไม่บันทึกเวลา</footer>
</main>
<script nonce="<?= htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') ?>">
    const token = new URLSearchParams(location.hash.slice(1)).get('t') || '';
    const byId = id => document.getElementById(id);
    let state = null;
    let busy = false;
    const timeOnly = value => value ? value.slice(11, 19) : '—';

    function notify(message, type = '') {
        byId('notice').textContent = message;
        byId('notice').className = type;
    }

    function updateButtons() {
        const record = state?.record;
        byId('check-in').disabled = busy || !state || !!record?.check_in_time || !!record?.check_out_time;
        byId('check-out').disabled = busy || !state || !!record?.check_out_time;
        byId('refresh').disabled = busy;
    }

    async function request(action) {
        const response = await fetch('./api/personal-attendance.php', {
            method: 'POST', cache: 'no-store', credentials: 'omit',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ token, action })
        });
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.message || 'ไม่สามารถลงเวลาได้');
        return result;
    }

    async function loadStatus() {
        state = null;
        updateButtons();
        const result = await request('status');
        state = result.data;
        byId('name').textContent = state.name;
        byId('department').textContent = state.department || '';
        byId('date').textContent = new Intl.DateTimeFormat('th-TH', {
            dateStyle: 'full', timeZone: 'Asia/Bangkok'
        }).format(new Date(state.date + 'T12:00:00+07:00'));
        byId('status').textContent = state.status_text;
        byId('in-time').textContent = timeOnly(state.record?.check_in_time);
        byId('out-time').textContent = timeOnly(state.record?.check_out_time);
        const r = state.rules;
        byId('rules').textContent = `เริ่มเข้า ${r.check_in_start.slice(0, 5)} น. · สายหลัง ${r.check_in_late.slice(0, 5)} น. · ออก ${r.check_out_start.slice(0, 5)}–${r.check_out_end.slice(0, 5)} น.`;
        updateButtons();
    }

    async function act(action) {
        if (busy) return;
        busy = true;
        updateButtons();
        notify(action === 'status' ? 'กำลังโหลดข้อมูล…' : 'กำลังบันทึกเวลา…');
        try {
            let message = '';
            if (action !== 'status') message = (await request(action)).message;
            await loadStatus();
            notify(message || 'ข้อมูลล่าสุดพร้อมใช้งาน', message ? 'success' : '');
        } catch (error) {
            notify(error instanceof TypeError ? 'ติดต่อระบบไม่ได้ กรุณาตรวจอินเทอร์เน็ตแล้วโหลดข้อมูลใหม่' : error.message, 'error');
        } finally {
            busy = false;
            updateButtons();
        }
    }

    byId('check-in').addEventListener('click', () => act('check_in'));
    byId('check-out').addEventListener('click', () => act('check_out'));
    byId('refresh').addEventListener('click', () => act('status'));
    if (/^[a-f0-9]{64}$/.test(token)) {
        act('status');
    } else {
        byId('name').textContent = 'กรุณาใช้ลิงก์ส่วนตัว';
        byId('status').textContent = 'ไม่พบสิทธิ์ลงเวลา';
        notify('ลิงก์นี้ไม่มีรหัสส่วนตัว กรุณาเปิดลิงก์ฉบับเต็มที่ได้รับ', 'error');
        byId('refresh').disabled = true;
    }
</script>
</body>
</html>
