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

Chưa có script nào ở GĐ2 — thư mục này chỉ tạo trước để `.htaccess` có chỗ
chặn, tránh phải sửa `.htaccess` lại ở GĐ6/7/8 (docs/04-API-PHP.md mục 9).
