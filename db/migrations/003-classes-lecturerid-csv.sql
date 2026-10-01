-- =====================================================================
--  db/migrations/003-classes-lecturerid-csv.sql
--
--  Bỏ khoá ngoại fk_classes_lecturer (classes.LecturerID → users.UserID).
--  Lý do: LecturerID lưu DANH SÁCH UserID cách nhau bởi dấu phẩy
--  ("1607,2015" — thiết kế D.9, gas/11-FixRealData.gs, docs/04-API-PHP.md
--  mục 4). Khoá ngoại này từ chối mọi lớp có từ 2 giảng viên trở lên — phát
--  hiện khi chạy tools/smoke_test.php trên host (01/10/2026, GĐ7), và cũng
--  sẽ làm tools/import.php (GĐ6) thất bại khi nạp dữ liệu thật.
--  Việc kiểm "mọi UserID trong danh sách phải tồn tại và là LECTURER/ADMIN"
--  do ứng dụng làm (api/lib/admin.php adminSaveClass; tools/import.php báo
--  cảnh báo FK ở bước dry-run).
--
--  Cách chạy: php tools/migrate.php (CLI, xem đầu file đó), hoặc hPanel →
--  Databases → phpMyAdmin → chọn CSDL → tab SQL → dán → Go.
--  An toàn chạy lại nhiều lần (IF EXISTS). Chỉ số idx_classes_lecturer giữ nguyên.
-- =====================================================================

SET NAMES utf8mb4;

ALTER TABLE classes
  DROP FOREIGN KEY IF EXISTS fk_classes_lecturer;
