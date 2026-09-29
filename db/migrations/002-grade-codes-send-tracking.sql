-- =====================================================================
--  db/migrations/002-grade-codes-send-tracking.sql
--
--  Bổ sung cho CSDL Hostinger ĐÃ CHẠY db/schema.sql bản GĐ1 (chỉ có
--  StudentID, CodeHash, Attempts, ExpiresAt, CreatedAt trong grade_codes).
--  Thêm 3 cột cần cho GĐ3 (requestGradeCode/verifyGradeCode) — xem
--  docs/04-API-PHP.md mục 10.1. db/schema.sql đã cập nhật để một CSDL
--  MỚI (chưa từng chạy) tạo đủ 3 cột này ngay từ đầu; file này chỉ dành
--  cho CSDL đã có bảng grade_codes cũ (bảng hiện đang trống, không có
--  dữ liệu sinh viên thật nào bị ảnh hưởng).
--
--  Cách chạy trên Hostinger: hPanel → Databases → phpMyAdmin → chọn CSDL
--  u464424582_diemdanh → tab SQL → dán nội dung file này → Go.
--  An toàn chạy lại nhiều lần (kiểm tra tồn tại cột trước khi thêm).
-- =====================================================================

SET NAMES utf8mb4;

-- MariaDB 11.8 hỗ trợ `ADD COLUMN IF NOT EXISTS` — dùng luôn để idempotent,
-- không cần thủ tục kiểm information_schema.
ALTER TABLE grade_codes
  ADD COLUMN IF NOT EXISTS SendCount     INT NOT NULL DEFAULT 0 AFTER Attempts,
  ADD COLUMN IF NOT EXISTS WindowStartAt DATETIME NULL          AFTER SendCount,
  ADD COLUMN IF NOT EXISTS LastSentAt    DATETIME NULL          AFTER WindowStartAt;
