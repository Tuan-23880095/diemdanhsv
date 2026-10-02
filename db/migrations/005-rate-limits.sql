-- =====================================================================
--  db/migrations/005-rate-limits.sql
--
--  Bảng đếm cho giới hạn tần suất theo IP / tên đăng nhập (M4, review bảo
--  mật docs/05-GD5-smoke-review.md; code ở api/lib/ratelimit.php; đối chiếu
--  docs/04-API-PHP.md mục 19). Dùng cho `login`, `checkin`,
--  `requestGradeCode`, `verifyGradeCode` — các action công khai không token.
--
--  Mỗi dòng = một (Bucket, ClientKey): cửa sổ cố định bắt đầu WindowStart,
--  Hits lượt đã dùng. ClientKey là IP (VARCHAR 45 đủ cho IPv6) hoặc tên đăng
--  nhập đã chuẩn hoá — KHÔNG chứa dữ liệu sinh viên. Dòng cũ hơn 1 ngày được
--  api/lib/ratelimit.php tự dọn (≈ 1/50 lượt ghi), không cần cron.
--
--  Code FAIL-OPEN khi bảng chưa có (chỉ ghi error_log) — nhưng vẫn phải chạy
--  migration này trên host để lớp bảo vệ có hiệu lực:
--    php tools/migrate.php --dry-run   rồi   php tools/migrate.php --yes
--  hoặc hPanel → phpMyAdmin → chọn CSDL → tab SQL → dán → Go.
--  An toàn chạy lại nhiều lần (IF NOT EXISTS). db/schema.sql đã có bảng này
--  cho CSDL tạo mới.
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS rate_limits (
  Bucket      VARCHAR(32) NOT NULL,            -- login_ip | login_user | checkin_ip | gradecode_req_ip | gradecode_ver_ip
  ClientKey   VARCHAR(64) NOT NULL,            -- IP hoặc tên đăng nhập (chữ thường)
  WindowStart DATETIME    NOT NULL,            -- mốc mở cửa sổ hiện tại
  Hits        INT         NOT NULL DEFAULT 0,  -- số lượt trong cửa sổ
  PRIMARY KEY (Bucket, ClientKey),
  KEY idx_rl_window (WindowStart)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
