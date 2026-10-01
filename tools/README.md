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
- **`backup.php`** — sao lưu CSDL bằng PHP thuần (PDO + gzip, KHÔNG dùng
  `mysqldump`/shell vì host cấm `exec`), vào `../private/backups/`, tự dọn
  bản cũ (`--keep=N`), `--dry-run` liệt kê bảng + số dòng, `--config=` cho
  CSDL thử. Đặt lịch qua hPanel Cron Jobs (lệnh mẫu ở đầu file).

Script thêm sau (GĐ7/8 theo PLAN, ví dụ `create-lecturer.php`,
`fix-data.php`) theo đúng quy ước ở trên — xem docs/04-API-PHP.md mục 9.
