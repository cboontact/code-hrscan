#!/bin/bash
# อัปโหลดไฟล์โค้ดขึ้นโฮสต์ผ่าน FTP โดยสำรองไฟล์เดิมบนโฮสต์ลงเครื่องก่อนทุกครั้ง
#
#   ./scripts/deploy-ftp.sh                           อัปโหลดรายการไฟล์ด้านล่าง
#   ./scripts/deploy-ftp.sh restore <โฟลเดอร์สำรอง>   ย้อนกลับไปใช้ไฟล์ที่สำรองไว้
#
# วิธีทำงานต่อไฟล์: ส่งขึ้นเป็นชื่อชั่วคราว -> ดาวน์โหลดกลับมาเทียบว่าตรงกับต้นทางทุกไบต์
# -> เปลี่ยนชื่อทับไฟล์จริง ไฟล์จริงบนโฮสต์จะไม่ถูกแตะจนกว่าไฟล์ใหม่จะครบถ้วน
#
# สคริปต์นี้แตะเฉพาะไฟล์โค้ดในรายการ ไม่แตะฐานข้อมูล ไม่แตะ uploads/
# และไม่แตะ config/database.php, config/environment.php
# รหัสผ่านถูกถามตอนรัน ไม่ถูกเก็บลงไฟล์

set -u

PROJECT_DIR="$(cd "$(dirname "$0")/.." && pwd)"

# ค่าการเชื่อมต่ออยู่ใน scripts/deploy.env (ไม่เข้า git) ดูตัวอย่างที่ scripts/deploy.env.example
if [ -f "$PROJECT_DIR/scripts/deploy.env" ]; then
    # shellcheck disable=SC1091
    . "$PROJECT_DIR/scripts/deploy.env"
fi

FTP_HOST="${FTP_HOST:-}"
FTP_PORT="${FTP_PORT:-21}"
FTP_USER="${FTP_USER:-}"
# โฟลเดอร์ของระบบบนโฮสต์ นับจากโฟลเดอร์เริ่มต้นของบัญชี FTP
REMOTE_DIR="${REMOTE_DIR:-hrscan}"
# FTP_TLS=off เพื่อไม่ใช้การเข้ารหัส (ใช้เมื่อโฮสต์ตัดการส่งไฟล์แบบเข้ารหัส)
FTP_TLS="${FTP_TLS:-on}"

if [ -z "$FTP_HOST" ] || [ -z "$FTP_USER" ]; then
    echo "ยังไม่ได้ตั้งค่า FTP_HOST / FTP_USER"
    echo "คัดลอก scripts/deploy.env.example เป็น scripts/deploy.env แล้วกรอกค่า"
    exit 1
fi

MAX_ATTEMPTS=4

FILES=(
    config/config.php
    api/scan.php
    api/settings.php
    api/attendance.php
    api/summary-report.php
    api/recent-scans.php
    api/create-attendance-record.php
    api/update-attendance-time.php
    api/update-attendance-remark.php
    api/teachers.php
    admin/index.php
    admin/js/app.js
    admin/print-report.php
    admin/print-summary-report.php
)

cd "$PROJECT_DIR" || exit 1

MODE="deploy"
SOURCE_DIR="$PROJECT_DIR"
if [ "${1:-}" = "restore" ]; then
    MODE="restore"
    SOURCE_DIR="${2:-}"
    if [ -z "$SOURCE_DIR" ] || [ ! -d "$SOURCE_DIR" ]; then
        echo "ใช้: $0 restore <โฟลเดอร์สำรอง เช่น deploy-backups/20261111-203000>"
        exit 1
    fi
    SOURCE_DIR="$(cd "$SOURCE_DIR" && pwd)"
fi

for f in "${FILES[@]}"; do
    if [ ! -s "$SOURCE_DIR/$f" ]; then
        echo "ไม่พบไฟล์ต้นทาง หรือไฟล์ว่าง: $SOURCE_DIR/$f"
        exit 1
    fi
done

printf 'รหัสผ่าน FTP ของ %s: ' "$FTP_USER"
read -r -s FTP_PASS
echo
if [ -z "$FTP_PASS" ]; then
    echo "ไม่ได้กรอกรหัสผ่าน ยกเลิก"
    exit 1
fi

ROOT_URL="ftp://$FTP_HOST:$FTP_PORT"
BASE_URL="$ROOT_URL/$REMOTE_DIR"
TLS_OPT="--ssl-reqd"
WORK_DIR="$(mktemp -d)"
trap 'rm -rf "$WORK_DIR"' EXIT

# ส่งชื่อผู้ใช้/รหัสผ่านให้ curl ทาง config เพื่อไม่ให้โผล่ในรายการ process
ftp_curl() {
    local esc_pass="${FTP_PASS//\\/\\\\}"
    esc_pass="${esc_pass//\"/\\\"}"
    # shellcheck disable=SC2086
    curl --silent --show-error --fail --connect-timeout 20 --max-time 180 $TLS_OPT \
        -K <(printf 'user = "%s:%s"\n' "$FTP_USER" "$esc_pass") "$@"
}

confirm() {
    local answer
    printf '%s [y/N] ' "$1"
    read -r answer
    case "$answer" in
        y|Y|ั) return 0 ;;
        *) return 1 ;;
    esac
}

echo "กำลังเชื่อมต่อ $FTP_HOST:$FTP_PORT ..."
if [ "$FTP_TLS" = "off" ] || ! ftp_curl --list-only "$BASE_URL/" >/dev/null 2>&1; then
    TLS_OPT=""
    if ! ERR="$(ftp_curl --list-only "$BASE_URL/" 2>&1 >/dev/null)"; then
        echo "เชื่อมต่อหรือเปิดโฟลเดอร์ '$REMOTE_DIR' ไม่ได้: $ERR"
        echo "โฟลเดอร์ที่เห็นจากบัญชี FTP นี้:"
        ftp_curl --list-only "$ROOT_URL/" 2>&1 | sed 's/^/    /'
        echo "ถ้าระบบอยู่โฟลเดอร์อื่น ให้รันใหม่แบบ: REMOTE_DIR=ชื่อโฟลเดอร์ $0"
        exit 1
    fi
    if ! confirm "จะเชื่อมต่อแบบไม่เข้ารหัส (รหัสผ่านถูกส่งแบบไม่เข้ารหัส) ทำต่อไหม?"; then
        echo "ยกเลิก"
        exit 1
    fi
fi

# 1) สำรองไฟล์ที่อยู่บนโฮสต์ตอนนี้
BACKUP_DIR="$PROJECT_DIR/deploy-backups/$(date +%Y%m%d-%H%M%S)"
echo "สำรองไฟล์ปัจจุบันจากโฮสต์ไปที่ $BACKUP_DIR"
TO_UPLOAD=()
for f in "${FILES[@]}"; do
    mkdir -p "$BACKUP_DIR/$(dirname "$f")"
    if ftp_curl -o "$BACKUP_DIR/$f" "$BASE_URL/$f" 2>/dev/null; then
        if cmp -s "$BACKUP_DIR/$f" "$SOURCE_DIR/$f"; then
            echo "  ตรงกันแล้ว ข้าม   $f"
            continue
        fi
        echo "  สำรองแล้ว         $f ($(wc -c < "$BACKUP_DIR/$f" | tr -d ' ') ไบต์)"
    else
        rm -f "$BACKUP_DIR/$f"
        if [ "$MODE" != "restore" ]; then
            echo "สำรองไม่สำเร็จ: $f"
            echo "ยังไม่ได้อัปโหลดอะไรขึ้นโฮสต์ ตรวจว่า REMOTE_DIR='$REMOTE_DIR' คือโฟลเดอร์ระบบจริง"
            exit 1
        fi
        echo "  ไม่มีบนโฮสต์      $f"
    fi
    TO_UPLOAD+=("$f")
done

if [ "${#TO_UPLOAD[@]}" -eq 0 ]; then
    echo
    echo "ไฟล์บนโฮสต์ตรงกับต้นทางครบทุกไฟล์แล้ว ไม่มีอะไรต้องอัปโหลด"
    exit 0
fi

echo
echo "จะอัปโหลด ${#TO_UPLOAD[@]} ไฟล์จาก $SOURCE_DIR ไปที่ $BASE_URL/"
if ! confirm "ยืนยันอัปโหลด?"; then
    echo "ยกเลิก ไม่ได้อัปโหลดอะไร"
    exit 0
fi

# คืนค่า 0 เมื่อไฟล์บนโฮสต์ตรงกับไฟล์ในเครื่องทุกไบต์
remote_matches() {
    local remote_path="$1" local_file="$2"
    rm -f "$WORK_DIR/check"
    ftp_curl -o "$WORK_DIR/check" "$BASE_URL/$remote_path" 2>/dev/null \
        && cmp -s "$WORK_DIR/check" "$local_file"
}

upload_file() {
    local f="$1"
    local src="$SOURCE_DIR/$f"
    local tmp="$f.uploading"
    local attempt

    for attempt in $(seq 1 "$MAX_ATTEMPTS"); do
        # โฮสต์อาจตอบ error ทั้งที่ไฟล์ไปครบ จึงตัดสินจากการดาวน์โหลดกลับมาเทียบ ไม่ใช่จากรหัสตอบกลับ
        ftp_curl -v -T "$src" "$BASE_URL/$tmp" >/dev/null 2>"$WORK_DIR/log"
        if remote_matches "$tmp" "$src"; then
            if ! ftp_curl --list-only "$ROOT_URL/" \
                    -Q "RNFR $REMOTE_DIR/$tmp" -Q "RNTO $REMOTE_DIR/$f" >/dev/null 2>"$WORK_DIR/log"; then
                # โฮสต์บางเจ้าไม่ยอมเปลี่ยนชื่อทับไฟล์เดิม: ลบไฟล์เดิมแล้วเปลี่ยนชื่อทันที
                ftp_curl --list-only "$ROOT_URL/" \
                    -Q "*DELE $REMOTE_DIR/$f" \
                    -Q "RNFR $REMOTE_DIR/$tmp" -Q "RNTO $REMOTE_DIR/$f" >/dev/null 2>"$WORK_DIR/log"
            fi
            if remote_matches "$f" "$src"; then
                return 0
            fi
        fi
        sleep 2
    done

    # ล้างไฟล์ชั่วคราวที่อาจค้าง และแสดงคำตอบจากโฮสต์ (กรองเฉพาะบรรทัด error ไม่มีรหัสผ่าน)
    grep -E '^< [45][0-9][0-9]' "$WORK_DIR/log" 2>/dev/null | sort -u | sed 's/^/      โฮสต์ตอบ: /'
    ftp_curl --list-only "$ROOT_URL/" -Q "*DELE $REMOTE_DIR/$tmp" >/dev/null 2>&1
    return 1
}

FAILED=()
for f in "${TO_UPLOAD[@]}"; do
    if upload_file "$f"; then
        echo "  อัปโหลดและตรวจแล้ว  $f"
    else
        echo "  ล้มเหลว             $f"
        FAILED+=("$f")
    fi
done

echo
if [ "${#FAILED[@]}" -ne 0 ]; then
    echo "อัปโหลดไม่สำเร็จ ${#FAILED[@]} ไฟล์: ${FAILED[*]}"
    echo "ไฟล์ที่ล้มเหลวยังเป็นเนื้อหาเดิมบนโฮสต์ (ดูได้ใน ${BACKUP_DIR#"$PROJECT_DIR"/})"
    if [ -n "$TLS_OPT" ]; then
        echo "ลองรันใหม่แบบไม่เข้ารหัส: FTP_TLS=off $0 $*"
    fi
    exit 1
fi

echo "เสร็จแล้ว ไฟล์บนโฮสต์ตรงกับต้นทางครบทุกไฟล์"
if [ "$MODE" = "deploy" ]; then
    echo "ถ้าต้องการย้อนกลับ: $0 restore ${BACKUP_DIR#"$PROJECT_DIR"/}"
fi
