#!/usr/bin/env python3
"""Run scan.php against an isolated SQLite database; never contact the host.

Usage: PHP_BIN=/path/to/php python3 tests/scan-regression.py
SCAN_SOURCE may point to an older scan.php to reproduce the regression.
"""

import base64
import json
import os
from pathlib import Path
import subprocess
import unittest


ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get("PHP_BIN", "php")
SOURCE = Path(os.environ.get("SCAN_SOURCE", str(ROOT / "api/scan.php")))
NOTE = "เริ่มปฏิบัติงานตั้งแต่เวลา 12.00 น."


def scan(at, record=None, action="auto", token="a" * 64, active=True, method="POST"):
    source = SOURCE.read_text() if action == "auto" else (ROOT / "api/personal-attendance.php").read_text()
    service = (ROOT / "config/attendance-scan.php").read_text().removeprefix("<?php")
    source = source.replace("require_once __DIR__ . '/../config/attendance-scan.php';", service)
    source = source.replace("require_once __DIR__ . '/../config/database.php';", "")
    source = source.replace("require_once __DIR__ . '/../config/config.php';",
                            "require_once " + json.dumps(str(ROOT / "config/config.php")) + ";")
    source = source.replace("require_once '../config/database.php';", "")
    source = source.replace(
        "require_once '../config/config.php';",
        "require_once " + json.dumps(str(ROOT / "config/config.php")) + ";",
    )
    source = source.replace("autoMarkMissingCheckOut($db);", "")
    source = source.replace(
        "json_decode(file_get_contents('php://input'), true)",
        "json_decode(base64_decode('" + base64.b64encode(json.dumps({
            "rfid_code": "TEST-CARD", "token": token, "action": action
        }).encode()).decode() + "'), true)",
    )
    source = source.replace(
        "getAttendanceTimeRules($db, $today, $teacher['id'])",
        "$GLOBALS['testRules']",
    )
    source = source.replace("date('Y-m-d H:i:s')", "('2026-11-12 ' . $GLOBALS['testTime'])")
    source = source.replace("date('Y-m-d')", "'2026-11-12'")
    source = source.replace("date('H:i:s')", "$GLOBALS['testTime']")

    fixture = r'''
class Database {
    function getConnection() { return $GLOBALS['testDb']; }
}
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$GLOBALS['testDb'] = $db;
$db->exec("CREATE TABLE teachers (
    id INTEGER PRIMARY KEY, rfid_code TEXT, first_name TEXT, last_name TEXT,
    department TEXT, photo TEXT, status TEXT
)");
$db->exec("INSERT INTO teachers VALUES (343, 'TEST-CARD', 'Test', 'Person', 'Test', NULL, 'active')");
$db->exec("CREATE TABLE personal_attendance_links (teacher_id INTEGER, token_hash TEXT, is_active INTEGER)");
$stmt = $db->prepare('INSERT INTO personal_attendance_links VALUES (343, :hash, :active)');
$stmt->execute([':hash' => hash('sha256', str_repeat('a', 64)), ':active' => $GLOBALS['testActive']]);
$db->exec("CREATE TABLE activity_logs (user_id INTEGER, action TEXT, description TEXT, ip_address TEXT, user_agent TEXT)");
$db->exec("CREATE TABLE attendance_records (
    id INTEGER PRIMARY KEY AUTOINCREMENT, teacher_id INTEGER, rfid_code TEXT,
    check_in_time TEXT, check_out_time TEXT, attendance_date TEXT,
    status TEXT, remark TEXT, updated_at TEXT,
    UNIQUE (teacher_id, attendance_date)
)");
if ($GLOBALS['testRecord'] !== null) {
    $r = $GLOBALS['testRecord'];
    $stmt = $db->prepare('INSERT INTO attendance_records
        (id, teacher_id, rfid_code, attendance_date, check_in_time, check_out_time, status, remark)
        VALUES (99, 343, :rfid, :day, :in_time, :out_time, :status, :remark)');
    $stmt->execute([
        ':rfid' => '', ':day' => '2026-11-12',
        ':in_time' => $r['check_in_time'] ?? null,
        ':out_time' => $r['check_out_time'] ?? null,
        ':status' => $r['status'] ?? 'absent', ':remark' => $r['remark'] ?? null
    ]);
}
$GLOBALS['testRules'] = [
    'check_in_start' => '06:00:00', 'check_in_late' => '12:00:00',
    'check_out_start' => '20:00:00', 'check_out_end' => '23:59:00'
];
register_shutdown_function(function () use ($db) {
    file_put_contents('php://stderr', json_encode(
        $db->query('SELECT * FROM attendance_records ORDER BY id')->fetchAll(),
        JSON_UNESCAPED_UNICODE
    ));
});
'''
    payload = base64.b64encode(json.dumps(record).encode()).decode()
    program = (
        "<?php\n$_SERVER['REQUEST_METHOD']=" + json.dumps(method) + ";\n"
        "$GLOBALS['testActive']=" + ("1" if active else "0") + ";\n"
        "$GLOBALS['testTime']=" + json.dumps(at) + ";\n"
        "$GLOBALS['testRecord']=json_decode(base64_decode('" + payload + "'), true);\n"
        + fixture + "\n?>" + source
    )
    result = subprocess.run([PHP], input=program, text=True, capture_output=True, check=True)
    return json.loads(result.stdout), json.loads(result.stderr)


class ScanRegression(unittest.TestCase):
    def test_personal_status_does_not_record_attendance_or_expose_rfid(self):
        response, rows = scan('11:00:00', action='status')
        self.assertTrue(response['success'])
        self.assertEqual(rows, [])
        self.assertEqual(response['data']['name'], 'Test Person')
        self.assertNotIn('TEST-CARD', json.dumps(response))

    def test_personal_link_rejects_invalid_revoked_and_missing_tokens(self):
        for token, active in [('b' * 64, True), ('', True), ('a' * 64, False)]:
            with self.subTest(token_length=len(token), active=active):
                response, rows = scan('11:00:00', action='check_in', token=token, active=active)
                self.assertFalse(response['success'])
                self.assertEqual(rows, [])

    def test_personal_link_rejects_get_and_unknown_actions(self):
        for action, method in [('check_in', 'GET'), ('erase', 'POST')]:
            with self.subTest(action=action, method=method):
                response, rows = scan('11:00:00', action=action, method=method)
                self.assertFalse(response['success'])
                self.assertEqual(rows, [])

    def test_personal_check_in_updates_note_and_repeat_never_checks_out(self):
        response, rows = scan('11:47:00', {'remark': NOTE}, action='check_in')
        self.assertEqual(response['data']['type'], 'check_in')
        self.assertEqual(rows[0]['remark'], NOTE)
        record = dict(rows[0])
        response, rows = scan('20:00:00', record, action='check_in')
        self.assertEqual(response['data']['type'], 'already_checked_in')
        self.assertEqual(rows[0]['check_in_time'], '2026-11-12 11:47:00')
        self.assertIsNone(rows[0]['check_out_time'])

    def test_personal_checkout_cannot_accidentally_check_in(self):
        response, rows = scan('11:00:00', action='check_out')
        self.assertFalse(response['success'])
        self.assertEqual(rows, [])
        response, rows = scan('20:00:00', {'remark': NOTE}, action='check_out')
        self.assertEqual(response['data']['type'], 'check_out')
        self.assertIsNone(rows[0]['check_in_time'])
        self.assertEqual(rows[0]['check_out_time'], '2026-11-12 20:00:00')

    def test_personal_check_in_after_checkout_start_is_still_check_in(self):
        response, rows = scan('20:01:00', action='check_in')
        self.assertEqual(response['data']['type'], 'check_in')
        self.assertEqual(rows[0]['status'], 'late')
        self.assertIsNone(rows[0]['check_out_time'])

    def test_personal_checkout_preserves_original_check_in(self):
        record = {'check_in_time': '2026-11-12 11:47:00', 'status': 'present'}
        response, rows = scan('20:00:00', record, action='check_out')
        self.assertEqual(response['data']['type'], 'check_out')
        self.assertEqual(rows[0]['check_in_time'], record['check_in_time'])
        self.assertEqual(rows[0]['check_out_time'], '2026-11-12 20:00:00')

    def test_note_only_record_accepts_check_in_and_preserves_note(self):
        for at, status in [("06:00:00", "present"), ("11:47:00", "present"),
                           ("12:00:00", "present"), ("12:01:00", "late")]:
            with self.subTest(at=at):
                response, rows = scan(at, {"remark": NOTE})
                self.assertEqual(response["data"]["type"], "check_in")
                self.assertEqual(response["data"]["base_status"], status)
                self.assertEqual(len(rows), 1)
                self.assertEqual(int(rows[0]["id"]), 99)
                self.assertEqual(rows[0]["check_in_time"], "2026-11-12 " + at)
                self.assertIsNone(rows[0]["check_out_time"])
                self.assertEqual(rows[0]["status"], status)
                self.assertEqual(rows[0]["remark"], NOTE)
                self.assertEqual(rows[0]["rfid_code"], "TEST-CARD")

    def test_before_check_in_start_does_not_change_record(self):
        response, rows = scan("05:59:59", {"remark": NOTE})
        self.assertFalse(response["success"])
        self.assertIsNone(rows[0]["check_in_time"])
        self.assertEqual(rows[0]["remark"], NOTE)

    def test_approved_late_note_keeps_present_status(self):
        response, rows = scan("12:01:00", {"remark": "ขออนุญาตมาสาย"})
        self.assertEqual(response["data"]["base_status"], "present")
        self.assertEqual(rows[0]["status"], "present")

    def test_note_only_record_can_scan_out_without_check_in(self):
        response, rows = scan("20:00:00", {"remark": NOTE})
        self.assertEqual(response["data"]["type"], "check_out")
        self.assertEqual(len(rows), 1)
        self.assertEqual(int(rows[0]["id"]), 99)
        self.assertIsNone(rows[0]["check_in_time"])
        self.assertEqual(rows[0]["check_out_time"], "2026-11-12 20:00:00")
        self.assertEqual(rows[0]["remark"], NOTE + " | ไม่สแกนเข้า แต่สแกนออก")

    def test_new_record_still_accepts_on_time_late_and_checkout(self):
        for at, kind, status in [("11:47:00", "check_in", "present"),
                                 ("12:01:00", "check_in", "late"),
                                 ("20:00:00", "check_out", "absent")]:
            with self.subTest(at=at):
                response, rows = scan(at)
                self.assertEqual(response["data"]["type"], kind)
                self.assertEqual(len(rows), 1)
                self.assertEqual(rows[0]["status"], status)

    def test_checked_in_record_waits_for_checkout_then_checks_out(self):
        record = {"check_in_time": "2026-11-12 11:47:00", "status": "present", "remark": NOTE}
        for at, kind in [("19:59:59", "too_early"), ("20:00:00", "check_out"),
                         ("23:59:01", "too_late")]:
            with self.subTest(at=at):
                response, rows = scan(at, record)
                self.assertEqual(response["data"]["type"], kind)
                self.assertEqual(rows[0]["check_in_time"], record["check_in_time"])
                self.assertEqual(rows[0]["remark"], NOTE)
                self.assertEqual(rows[0]["check_out_time"],
                                 "2026-11-12 " + at if kind == "check_out" else None)

    def test_completed_and_checkout_only_records_are_not_overwritten(self):
        for check_in in [None, "2026-11-12 11:47:00"]:
            with self.subTest(check_in=check_in):
                record = {"check_in_time": check_in, "check_out_time": "2026-11-12 20:00:00"}
                response, rows = scan("21:00:00", record)
                self.assertEqual(response["data"]["type"], "already_completed")
                self.assertEqual(rows[0]["check_in_time"], check_in)
                self.assertEqual(rows[0]["check_out_time"], record["check_out_time"])


if __name__ == "__main__":
    unittest.main(verbosity=2)
