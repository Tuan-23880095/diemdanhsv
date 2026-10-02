# tools/ — script quản trị CLI-only

Thư mục này bị `.htaccess` chặn (không lộ ra web công khai) — đúng rule 3
của dự án: "Code quản trị (import điểm, sửa dữ liệu hàng loạt, tạo tài
khoản giảng viên...) chỉ được viết dưới dạng script CLI trong tools/,
không bao giờ để lộ ra web công khai."

Quy ước cho mọi script thêm vào đây (từ GĐ6 trở đi theo PLAN):

- Mở đầu file bằng kiểm tra chạy CLI, từ chối nếu bị gọi qua web (phòng
  trường hợp `.htaccess` bị gỡ nhầm hoặc host chạy khác cấu hình):

  ```php
  if (php_sapi_name() !== 'cli') {
      http_response_code(403);
      exit('Chỉ chạy được từ CLI.');
  }
  ```

- Không dùng dữ liệu sinh viên thật khi viết/test script (rule 2 của dự án).
- Đọc bí mật CSDL qua `app_config()` (`api/lib/config.php`), không hardcode.
- Idempotent khi có thể (an toàn khi chạy lại), có chế độ dry-run cho thao
  tác di dời/sửa dữ liệu hàng loạt — xem PLAN GĐ6 (`tools/import.php`).

## Script hiện có (từ GĐ6)

- **`import.php`** — di dời dữ liệu từ file JSON xuất bởi
  `gas/13-ExportJSON.gs` sang MariaDB. `--dry-run` để đối soát số dòng
  trước (không ghi gì); `--yes` để chạy thật. Idempotent (ON DUPLICATE KEY
  UPDATE theo khoá chính có sẵn). Xem docs/04-API-PHP.md mục 15.
- **`migrate.php`** — chạy `db/migrations/*.sql` theo thứ tự (idempotent).
  `--dry-run` chỉ liệt kê; `--config=` để trỏ CSDL thử. Dùng khi có migration
  mới (ví dụ 003 bỏ khoá ngoại `classes.LecturerID`).
- **`smoke_test.php`** — smoke test 13 + 12 action trên CSDL THỬ với dữ liệu
  demo (xem `docs/05-GD5-smoke-review.md`). Tự áp migrations trước khi chạy.
- **`compare_gas_php.php`** — so khớp file JSON xuất từ Apps Script với CSDL
  (CHỈ ĐỌC): số dòng, ID thiếu/thừa, dòng lệch nội dung. Dùng ở checklist GĐ9
  (`docs/06-GD9-staging-checklist.md` mục 2).
- **`backup.php`** — sao lưu CSDL bằng PHP thuần (PDO + gzip, KHÔNG dùng
  `mysqldump`/shell vì host cấm `exec`), vào `../private/backups/`, tự dọn
  bản cũ (`--keep=N`), `--dry-run` liệt kê bảng + số dòng, `--config=` cho
  CSDL thử. Đặt lịch qua hPanel Cron Jobs (lệnh mẫu ở đầu file). Sau mỗi lần
  sao lưu thành công còn dọn token đăng nhập/xem điểm đã hết hạn trong
  `auth_tokens` (L4, docs/04 mục 24) — tắt bằng `--no-clean-tokens`.

Script thêm sau (GĐ7/8 theo PLAN, ví dụ `create-lecturer.php`,
`fix-data.php`) theo đúng quy ước ở trên — xem docs/04-API-PHP.md mục 9.
- **`set_password.php`** — đặt lại mật khẩu một tài khoản `users` (quên mật
  khẩu — hash không khôi phục được). `--user=<UserID|Username>`, `--generate`
  (sinh 12 ký tự, in một lần) hoặc nhập từ STDIN, `--legacy` (ghi
  sha256(salt|plain) + in Salt/PasswordHash để dán vào sheet 01_USERS cho Apps
  Script dự phòng; PHP tự rehash lần đăng nhập đầu), `--dry-run`, bắt buộc
  `--yes` để ghi. Huỷ token đăng nhập cũ, ghi audit `TOOLS_SET_PASSWORD`.
- **`user.php`** — quản lý tài khoản `users` (thay `createLecturerAccounts()` của
  Apps Script, docs/04 mục 9): `--list`; `--create --username= --fullname=
  [--email=] [--role=ADMIN|LECTURER] [--id=] --generate|STDIN [--legacy] --yes`;
  `--set-role --user= --role= --yes`; `--set-status --user= --status= --yes`
  (INACTIVE huỷ token). Không bao giờ hạ/khoá ADMIN đang hoạt động cuối cùng.
  Audit `TOOLS_USER_CREATE/SET_ROLE/SET_STATUS`. Không in họ tên/email.
- **`mail_test.php`** — gửi MỘT email thử qua cấu hình `smtp` trong
  `../private/config.php` (`--to=<email>`, `--config=` cho file khác); in
  ĐÃ GỬI / CHẾ ĐỘ STUB / GỬI THẤT BẠI + lý do. Không chạm CSDL, không in mật
  khẩu. Chạy trước checklist GĐ9 mục 5 (docs/04 mục 25).

