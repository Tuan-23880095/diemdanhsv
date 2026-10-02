# GĐ5 (nợ) — Smoke test 13/13 action + review bảo mật độc lập

Phiên 2026-10-01 (thầy bấm chạy ngay, bỏ qua lịch). Trả nợ GĐ5 trong PLAN:
"smoke test 13/13 trên seed + review bảo mật độc lập (Claude Opus, KHÔNG
Abacus)". Nhánh `agent/web-g5b`, tách khỏi PR #6 (GĐ6). Hai PR không sửa
chung file nào, merge theo thứ tự nào cũng được.

## 1. Smoke test — `tools/smoke_test.php`

Script này chỉ chạy bằng CLI. Nó tự bật máy chủ dev `php -S 127.0.0.1:<cổng>`
rồi gọi API bằng HTTP thật, đúng khuôn của `js/services/APIService.js`:

- GET dùng query string.
- POST gửi body JSON với `Content-Type: text/plain;charset=utf-8`.
- Mọi phản hồi phải đúng phong bì `{status, message, data}`.

Script phủ đủ 13/13 action và gồm khoảng 60 kiểm tra. Các ca bị từ chối (sai
quyền, sai lớp, sai mã, hết hạn, sai phương thức…) cũng được kiểm. Có 3 kiểm
tra **song song** (curl_multi) để chứng minh đã sửa H1 ở mục 2.

### Rào an toàn (rule 2)

- Bắt buộc có `--config=<file cấu hình CSDL THỬ>`. Script từ chối nếu file
  này chính là `../private/config.php` của CSDL thật.
- Script từ chối chạy nếu CSDL đích có bất kỳ dòng users/courses/classes/
  students nào không phải dữ liệu demo (ID tiền tố `SMOKE_`).
- Dữ liệu demo toàn là dữ liệu giả: MSSV `9900000x`, email
  `@example.invalid`. Chạy xong script tự dọn sạch, trừ khi có `--keep-data`.
- `api/lib/config.php` có thêm biến môi trường `DIEMDANH_CONFIG`, chỉ có tác
  dụng ở SAPI `cli` và `cli-server` (`php -S`). LiteSpeed trên host thật
  không đọc biến này, nên request web không đổi được CSDL đích.

### Giới hạn của phiên này

Sandbox **không cài được MariaDB**: apt bị proxy chặn khi tải gói
`mariadb-server`. Vì vậy các kiểm tra cần CSDL **chưa chạy thật**. Phần đã
chạy thật trong sandbox:

- `php -l` sạch toàn bộ.
- Qua `php -S`: `ping` OK, thiếu action, sai phương thức và PUT → 405 đều đúng
  khuôn.
- Khi CSDL không kết nối được, client nhận "Lỗi máy chủ. Vui lòng thử lại
  sau." thay cho chuỗi `SQLSTATE[HY000] [2002] …` như trước khi sửa M1.

**Thầy cần chạy** (một lần, qua SSH):

1. Trên hPanel, tạo CSDL thứ hai (ví dụ `u464424582_ddtest`) cùng một user
   riêng.
2. Copy `db/config.sample.php` thành `../private/config.test.php`, rồi điền
   thông tin CSDL THỬ vào mục `db`.
3. Chạy `php tools/smoke_test.php --config=../private/config.test.php --init-schema`.
4. Kết quả mong đợi: `KẾT QUẢ: N PASS, 0 FAIL`. Dán kết quả vào PR nếu có
   FAIL.

## 2. Review bảo mật độc lập

Review do một subagent Claude Opus làm riêng: chỉ đọc code, không sửa. Tôi
đã đọc lại code để xác nhận từng mục trước khi sửa.

Các phần review xác nhận **đạt**:

- Không có SQL injection: mọi câu SQL đều tham số hoá.
- Token dùng `random_bytes(32)`, có hạn dùng phía server, bị xoá khi logout.
- Mọi action của giảng viên đều gọi `require_role` rồi `assert_class_access`.
- `myGrades` lấy danh tính từ token, không nhận MSSV từ tham số.
- Bí mật chỉ đọc từ `../private/config.php`.
- Rehash mật khẩu cũ `sha256(salt|plain)` đúng như gas/03-Auth.gs.
- Không có CORS (cùng domain).
- Audit log không chứa mật khẩu hay mã xác minh.

### Đã sửa trong PR này

| Mã | Mức | Vấn đề | Sửa |
|---|---|---|---|
| H1 | CAO | `verifyGradeCode` đọc `Attempts` rồi mới ghi, hai bước tách rời. Bắn nhiều request song song thì vượt giới hạn 5 lần đoán (không gian mã 25^4), và một mã đúng có thể đổi được nhiều token. `requestGradeCode` cũng đua như vậy với `SendCount`. | Đưa cả hai vào một transaction có `SELECT … FOR UPDATE`. `api_ok`/`api_fail` (có `exit`) chỉ gọi sau khi commit. So mã bằng `hash_equals`. |
| H2 | CAO | Stub `mail_send` ghi nguyên văn mã xem điểm vào `error_log`. Trên shared hosting, file `error_log` có thể nằm trong `public_html/api/`, và `.htaccess` chưa chặn tên này. | Mặc định không ghi thân email. Chỉ ghi khi config bật `app.mail_stub_log_body = true` (dùng cho CSDL thử). `.htaccess` chặn mọi file tên `error_log`. |
| M1 | TB | `index.php` trả nguyên văn mọi exception cho client: `SQLSTATE…`, tên cột, cả tên user CSDL khi mất kết nối. | Gặp `PDOException`/`Error` thì ghi log phía server và trả "Lỗi máy chủ…". Lỗi nghiệp vụ (`RuntimeException`) vẫn giữ thông điệp như cũ. Thêm `display_errors=0`. |
| M3 | TB | `attendance.IP` lấy từ tham số `ip` do client tự khai, nên giả mạo được. | Lấy từ `$_SERVER['REMOTE_ADDR']`, đúng chú thích của cột trong schema. |
| L1 | THẤP | Gọi trực tiếp được `api/lib/*.php` qua web. | `.htaccess` chặn `^api/lib/`. |
| L2 | THẤP | So hash mật khẩu cũ bằng `===`. | Đổi sang `hash_equals`. |

### CHƯA sửa — chờ thầy quyết

Các mục dưới đây đụng tới thiết kế hoặc hành vi, nên không tự sửa.

> **Cập nhật 02/10/2026:** **M4** và **M5** đã sửa trong PR gia cố trước cutover
> (`api/lib/ratelimit.php`, `db/migrations/005-rate-limits.sql`, kẹp phút trong
> `action_open_attendance`) — chi tiết `docs/04-API-PHP.md` mục 19. Hai mục
> đó giữ lại dưới đây làm hồ sơ. **M2** cũng đã sửa cùng ngày (PR riêng, docs/04
> mục 20). L3, L4, L5 vẫn chờ.

- ~~**M2 – D.8 lớp 4 bị ghi đè.**~~ **ĐÃ SỬA 02/10 (docs/04 mục 20).** Khi check-in lần 2 cùng MSSV, server UPDATE
  đè `DeviceHash`, `Note`, GPS và `Status`.
  - Hệ quả: gửi lại với `deviceHash:""` là xoá được cảnh báo trùng thiết bị
    trên `liveRoster` (chỉ còn lại trong `audit_log`). Bạn cùng lớp cũng có
    thể đổi PRESENT của người khác thành LATE.
  - Hành vi này giống y bản GAS cũ.
  - Đề xuất: lần 2 không xoá `DeviceHash` đã có, không hạ PRESENT→LATE, chỉ
    nối thêm vào `Note`. Hoặc từ chối hẳn lần 2.
- ~~**M4 – Chưa giới hạn tần suất (rate limit).**~~ **ĐÃ SỬA 02/10 (docs/04 mục 19).** Thiếu ở `checkin`, `login`
  và theo IP cho mã xem điểm.
  - Thông báo lỗi khác nhau khiến dò được mã đang mở và MSSV nào tồn tại.
  - Đề xuất: thêm một bảng đếm theo IP (cần migration) và trả một thông báo
    lỗi chung.
  - Nên làm trước cutover GĐ10.
- ~~**M5 – Không có bước kiểm cho `presentMinutes`/`windowMinutes`.**~~ **ĐÃ SỬA 02/10 (kẹp 1–60, docs/04 mục 19).**
  Giảng viên có thể mở mã sống rất lâu (chỉ trên lớp của chính mình).
  - Đề xuất: kẹp giá trị trong khoảng 1–60 phút.
- **L3 – Login để lộ username qua thời gian phản hồi.** Khi user không tồn
  tại, server trả về nhanh hơn vì không chạy bcrypt.
- **L4 – `auth_tokens` hết hạn không bao giờ bị dọn.** Có thể gộp vào cron
  `tools/backup.php` của GĐ6.
- **L5 – Token đi qua query string GET** (theo thiết kế cũ). Chỉ ghi nhận,
  chưa sửa.

### Lưu ý triển khai

Sau khi merge, host chạy code mới của `requestGradeCode`/`verifyGradeCode`.
Không cần migration nào. Frontend không phải đổi gì: khuôn phản hồi và các
thông điệp người dùng thấy vẫn giữ nguyên.
