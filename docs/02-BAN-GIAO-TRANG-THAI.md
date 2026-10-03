# 02 — Bàn giao trạng thái dự án

Cập nhật: 03/10/2026 — **ĐÃ CUTOVER 07:45 giờ VN** sang PHP/MariaDB trên Hostinger (PR #20); theo dõi đến 10/10/2026.
Bản trước (12/09/2026, kiến trúc Apps Script + Google Sheets) nằm trong lịch
sử git của file này.

Tài liệu này để **bất kỳ ai hoặc công cụ AI nào** (Claude, Antigravity, Cursor,
hoặc chính tác giả sau vài tuần) mở thư mục dự án lên là làm tiếp được ngay,
không cần đọc lại lịch sử hội thoại.

---

## 1. Dự án là gì

Hệ thống điểm danh sinh viên có kiểm chứng + xem điểm.
Chủ nhiệm: Đinh Quốc Tuấn — Trường ĐH Khoa học Tự nhiên, ĐHQG-HCM.

Kiến trúc hiện tại (từ GĐ10):
HTML + Tailwind + JavaScript ES6 OOP (MVC + Service Layer)
→ **PHP 8.3 (`api/index.php`) → MariaDB 11.8**, tất cả trên **Hostinger**,
cùng tên miền `https://diemdanhsv.com`.

Apps Script + Google Sheets (bản cũ) vẫn tồn tại làm **đường lùi** qua
`?api=gas` trong ≥ 1 tuần sau cutover (mục 4), rồi mới gỡ.

**Đọc trước khi sửa bất cứ thứ gì:** `docs/01-THIETKE-kien-truc.md` (nguồn
chân lý về thiết kế, mã 4 ký tự, xác minh Mức 1, sáu lớp bù D.8) và
`docs/04-API-PHP.md` (hợp đồng API PHP, từng giai đoạn GĐ1–GĐ9 + các bản gia cố).

---

## 2. Vị trí mọi thứ

| Thứ | Ở đâu |
|---|---|
| Mã nguồn | GitHub `Tuan-23880095/diemdanhsv` — nhánh `main` = trang thật |
| Trang thật | `https://diemdanhsv.com` (Hostinger Premium, PHP 8.3, SSL) — hPanel **Git auto-deploy `main` → `public_html`** |
| Backend | `api/index.php` + `api/lib/*.php` (router, auth, điểm danh, xem điểm, quản trị, chuyên cần, rate limit) |
| CSDL thật | MariaDB `u464424582_diemdanh` (15 bảng, `db/schema.sql` + `db/migrations/002–005`) |
| CSDL thử | `u464424582_ddtest` — chỉ dành cho `tools/smoke_test.php` |
| Bí mật | `domains/diemdanhsv.com/private/config.php` (NGOÀI `public_html`, mẫu `db/config.sample.php`); `private/config.test.php` cho CSDL thử; `private/backups/`, `private/import/` |
| Script quản trị CLI | `tools/` (bị `.htaccess` chặn web): `smoke_test.php`, `migrate.php`, `import.php`, `compare_gas_php.php`, `backup.php` — xem `tools/README.md` |
| Thiết kế | `docs/01-THIETKE-kien-truc.md` |
| Hợp đồng API + lịch sử giai đoạn | `docs/04-API-PHP.md` (mục 1–22) |
| Review bảo mật | `docs/05-GD5-smoke-review.md` (lần 1), `docs/04` mục 18 (lần 2) |
| Checklist trước/sau cutover | `docs/06-GD9-staging-checklist.md` |
| Bản cũ (tham chiếu, dự phòng) | `gas/*.gs` + Google Sheet `1ZmgdFwiHNGxCW3OFIOtoPYOZ5EoIjkS1qrL4n0luWFU` |
| Kho tri thức | NotebookLM **web-diemdanh-xemdiem** (`9bbd1bc4-90c8-4bb9-9b17-bbbf26c20906`) |
| Trạng thái điều phối (Quản gia) | Google Drive `Claude-Agent/diemdanhsv/` — file `STATE-*` mới nhất |

SSH: `ssh -p 65002 u464424582@<host>` rồi **`cd domains/diemdanhsv.com/public_html`**
(dễ quên dòng `cd`). Host **cấm `exec`/`proc_open`** — mọi tool CLI là PHP thuần.

---

## 3. Đã xong (GĐ1–GĐ10)

| GĐ | Nội dung | Ghi chú |
|---|---|---|
| 1 | Hợp đồng API 13 action cũ ↔ PHP | `docs/04` mục 1–11 |
| 2 | Nền PHP: router, PDO, phong bì `{status,message,data}`, POST `text/plain` | `api/index.php`, `api/lib/response.php` |
| 3 | `login`/`logout` (rehash mật khẩu cũ sha256 → `password_hash`), mã xem điểm 2 bước (SMTP **stub**) | `auth.php`, `gradeauth.php`, `mailer.php` |
| 4 | `openAttendance`/`closeAttendance`/`checkin`/`liveRoster` đủ 6 lớp D.8 | `attendance.php`, `roles.php` |
| 5 | `studentHistory`/`myGrades`/`listClasses`/`listSessions`; review bảo mật lần 1 | `queries.php`, `docs/05` |
| 6 | Di dời dữ liệu: `gas/13-ExportJSON.gs` → `tools/import.php` (dry-run, idempotent); `tools/backup.php` | `docs/04` mục 15 |
| 7 | Quản trị web: môn/lớp/SV/buổi, nhập CSV danh sách lớp (12 action, cần token + đúng lớp) | `admin.php`, `pages/admin.html` |
| 8 | Chuyên cần tự động, nhập điểm CSV, điểm danh tay (6 action) | `grading.php`, `docs/04` mục 17 |
| 9 | Chạy thử song song `?api=`, `tools/compare_gas_php.php`, review bảo mật lần 2 (12 lỗi) | `docs/04` mục 18, `docs/06` |
| gia cố | M4 rate limit theo IP, M5 kẹp phút, M2 gửi lại không ghi đè, L8 một thông báo lỗi, L13 import lọc cột, L11 SV ghi danh muộn, chống cache `config.js` | `docs/04` mục 19–22; smoke test **193 kiểm tra** |
| 10 | **Cutover**: `DEFAULT_API = 'php'`, `?api=gas` làm đường lùi, workflow Firebase chỉ chạy tay, tài liệu này | PR GĐ10 |

Kiểm thử: `php tools/smoke_test.php --config=../private/config.test.php` trên
host → **0 FAIL** (193 kiểm tra, 13 action cũ + 18 action quản trị + các bản
gia cố). Migration 002–005 đã áp trên CSDL thật.

---

## 4. Sau cutover — việc tiếp theo theo thứ tự

1. **Theo dõi 1 tuần điểm danh thật** không sự cố. Mỗi buổi: giảng viên mở mã
   trên `pages/lecturer.html`, sinh viên `pages/student.html`; đối chiếu số có
   mặt/trễ/vắng với cảm nhận trên lớp. Lỗi → xem `error_log` trong `public_html`
   (file bị `.htaccess` chặn web, đọc qua SSH) và `audit_log` trong CSDL.
2. **Đường lùi (rollback)** nếu có sự cố lớn: KHÔNG cần deploy — bảo mọi người
   mở trang với `?api=gas` (Apps Script vẫn chạy song song). Rollback hẳn: sửa
   `DEFAULT_API = 'gas'` trong `js/config/config.js` **và đổi `?v=`** trong 4
   trang `pages/*.html`, qua PR → merge → hPanel tự deploy → xóa cache
   (hPanel → Hiệu suất → Trình quản lý bộ nhớ đệm → Xóa tất cả).
3. **Sau ≥ 1 tuần ổn**: gỡ `gas` khỏi `API_TARGETS` (giữ `gas/` trong repo làm
   tham chiếu), dừng deployment Apps Script, đổi `?v=`.
4. **Việc còn mở**: ~~hộp thư noreply@ + SMTP thật~~ **xong 03/10** (`api/lib/mailer.php`
   SMTP PHP thuần, `tools/mail_test.php`); ~~cron sao lưu~~ **xong 03/10** (chạy
   hằng ngày, cũng dọn `auth_tokens` — L4 **xong**); ~~tạo tài khoản admin~~ **xong**
   (`tools/user.php`). Còn: ẩn/hiện link "Quản trị" trên trang chủ; xuất bảng điểm
   CSV/Excel; L5 token qua query string (ghi nhận); đặt lại vai trò 1607 → ADMIN
   nếu thầy muốn (`tools/user.php --set-role`). Dữ liệu demo DEMO101 (lớp/môn đã
   Ngưng) có thể giữ hoặc xoá sau.
5. **Phase sau** (chưa lên lịch): khiếu nại (`complaints`, action thứ 14), QR,
   Google Login (Mức 3 — chỉ sửa `identify_student()`), dashboard phân tích.

---

## 5. Quyết định đã chốt — đừng tự đổi

| Quyết định | Chốt ngày | Ghi chú |
|---|---|---|
| Mã điểm danh **4 ký tự**, bộ ký tự dễ đọc | 12/09/2026 | Không nâng 6 ký tự, không QR ở giai đoạn này; bù bằng M4 (rate limit) + M5 (kẹp phút) |
| Xác minh **Mức 1** (MSSV + mã) | 12/09/2026 | Không Google Login. Nâng lên Mức 3 chỉ sửa `identify_student()` (`api/lib/roles.php`) |
| Sáu lớp bù D.8 **bắt buộc** | 12/09/2026 | Mục 7 |
| Chạy độc lập PHP + MariaDB trên Hostinger, giữ 13 action + phong bì cũ | 29/09/2026 | PLAN GĐ1–GĐ10; frontend không đổi hợp đồng |
| Công thức chuyên cần: −3 vắng, −1,5 có phép, −1 trễ; 3 trễ = 1 vắng, 2 phép = 1 vắng; ≥ 3 vắng tđ → cấm thi; cột "Chuyên cần" 10 % | 02/10/2026 | `app.attendance_rules` trong config; `docs/04` mục 17 |
| Điểm tổng = Σ điểm×trọng số/100 trên phần đã chấm, quy về tối đa 10; hiện thêm "trên phần đã chấm" | 02/10/2026 | `grading_total()`, GradeView |
| SV ghi danh muộn không bị tính vắng buổi trước ngày ghi danh (có rào cho dữ liệu import cùng ngày) | 02/10/2026 | `docs/04` mục 22; tắt bằng `count_from_enrollment=false` |
| Gửi lại check-in không ghi đè bản ghi đầu; tài khoản khoá mất quyền ngay (M10) | 02/10/2026 | Khác bản GAS — cố ý |
| Mọi thay đổi qua PR vào `main`; không push thẳng; không dữ liệu SV thật trong repo/test | 29/09/2026 | main được bảo vệ |

---

## 6. Ràng buộc kỹ thuật — vi phạm là hỏng

- **Phong bì API giữ nguyên:** `{status:'success'|'error', message, data}`;
  lỗi nghiệp vụ trả HTTP 200 + `status:'error'`. POST gửi
  `Content-Type: text/plain;charset=utf-8`. `js/services/APIService.js`
  không được đổi khuôn.
- **Bí mật chỉ đọc từ `../private/config.php`** (ngoài `public_html`). Không
  bao giờ commit mật khẩu CSDL/SMTP. Repo chỉ có `db/config.sample.php`.
- **Code quản trị không ràng buộc lớp → CLI trong `tools/`** (kiểm
  `php_sapi_name() === 'cli'`); action quản trị trên web phải `require_role` +
  `assert_class_access`/`admin_load_class` trước khi đụng dữ liệu.
- **Mọi SQL tham số hoá**; mọi thao tác ghi nhiều bước trong `db_transaction()`;
  UNIQUE KEY là chốt chặn thật (`uq_att_student_session`, `uq_gcol_class_name`).
- **Không trả thông điệp PDO/SQLSTATE cho client** (`api/index.php` bắt
  `PDOException|Error` → thông báo chung). LECTURER nhận **một** câu cho "không
  tồn tại"/"không có quyền" (L8).
- **Đổi `js/config/config.js` → đổi `?v=` trong 4 trang** `pages/*.html`;
  `.htaccess` đã đặt `no-cache` cho `config.js` và `*.html`.
- **Không `mode:'no-cors'`, không `onclick` trong HTML, mọi dữ liệu từ máy chủ
  qua `_esc()` trước khi vào `innerHTML`.**
- **Host cấm `exec`/`proc_open`** — tool CLI phải PHP thuần (`backup.php` dùng
  PDO + gzip, không `mysqldump`).
- **Chỉ dữ liệu demo** (`smoke_*`, `DEMO*`, MSSV `990000xx`) trong test/seed/
  tài liệu; `smoke_test.php` từ chối chạy trên CSDL có dữ liệu thật.
- **Mỗi lần thêm migration:** `db/migrations/NNN-*.sql` idempotent + cập nhật
  `db/schema.sql`; chạy `php tools/migrate.php --dry-run` rồi `--yes`.

---

## 7. Sáu lớp bù D.8 nằm ở đâu (bản PHP)

| Lớp | Nội dung | Vị trí |
|---|---|---|
| 1 | Mã mới mỗi buổi, hạn giờ ngắn (kẹp 1–60 phút — M5) | `action_open_attendance`, `generate_unique_code` (`api/lib/attendance.php`) |
| 2 | Kiểm tra ghi danh | `identify_student` (`api/lib/roles.php`) |
| 3 | Một MSSV một bản ghi mỗi buổi | UNIQUE `uq_att_student_session` + `checkin_merge_resend` (M2: gửi lại không ghi đè) |
| 4 | DeviceHash phát hiện điểm danh hộ | `action_checkin` (đếm trùng) + `liveRoster.deviceAlerts` + `RosterView._renderAlerts` |
| 5 | Danh sách thời gian thực | `action_live_roster` + polling 10 s (`CONFIG.ROSTER_POLL_MS`) |
| 6 | Nhật ký mọi thao tác | `log_audit` (`api/lib/audit.php`) → bảng `audit_log` |
| + | Giới hạn tần suất theo IP/tên đăng nhập (M4) | `api/lib/ratelimit.php`, bảng `rate_limits` |

Cắt bất kỳ lớp nào là hệ thống trở thành sổ điểm danh tự khai.

---

## 8. Nếu làm tiếp bằng công cụ AI

1. Clone repo (`Tuan-23880095/diemdanhsv`), **đọc `docs/01` và `docs/04` trước**.
2. Làm trên nhánh `agent/web-<việc>`, mở PR; chủ nhiệm test trên host (`smoke_test.php`
   phải 0 FAIL) rồi merge. Không push `main`.
3. Không có MySQL cục bộ thì vẫn làm được: `php -l`, `node --check`, viết kiểm
   tra vào `tools/smoke_test.php` — kết quả thật chạy trên host.
4. Tra quyết định thiết kế bằng NotebookLM `web-diemdanh-xemdiem` thay vì dán cả
   tài liệu vào context.

Ngữ cảnh hội thoại **không** chuyển được giữa các công cụ. Thứ chuyển được là:
mã nguồn trong repo, tài liệu `docs/`, STATE trên Drive, và notebook.
