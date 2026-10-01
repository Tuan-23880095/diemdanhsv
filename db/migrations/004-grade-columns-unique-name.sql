-- =====================================================================
--  db/migrations/004-grade-columns-unique-name.sql
--
--  Thêm UNIQUE KEY (ClassID, Name) cho grade_columns.
--  Lý do: api/lib/grading.php grading_ensure_column() tra cột điểm theo
--  (ClassID, Name). Không có ràng buộc UNIQUE thì hai request đồng thời
--  (vd hai lần bấm "Ghi vào bảng điểm") có thể tạo HAI cột "Chuyên cần"
--  cho cùng một lớp → trọng số 10% bị cộng hai lần vào điểm tổng.
--  Phát hiện khi review bảo mật độc lập lần 2 (GĐ9, mục L6).
--
--  Cách chạy: php tools/migrate.php (xem đầu file đó), hoặc hPanel →
--  phpMyAdmin → chọn CSDL → tab SQL → dán → Go.
--
--  LƯU Ý: nếu CSDL đã có hai cột cùng tên trong một lớp, câu ALTER sẽ lỗi
--  (1062 Duplicate entry). Khi đó phải gộp/sửa tên tay trước:
--    SELECT ClassID, Name, COUNT(*) c FROM grade_columns
--     GROUP BY ClassID, Name HAVING c > 1;
--  CSDL thật hiện chưa có dữ liệu nên không gặp trường hợp này.
--  An toàn chạy lại nhiều lần (bỏ qua nếu khoá đã có).
-- =====================================================================

SET NAMES utf8mb4;

-- MariaDB không có "ADD UNIQUE KEY IF NOT EXISTS" cho mọi phiên bản → dùng
-- thủ tục kiểm information_schema để idempotent.
DROP PROCEDURE IF EXISTS mig004_add_unique;

CREATE PROCEDURE mig004_add_unique()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'grade_columns'
       AND INDEX_NAME = 'uq_gcol_class_name'
  ) THEN
    ALTER TABLE grade_columns ADD UNIQUE KEY uq_gcol_class_name (ClassID, Name);
  END IF;
END;

CALL mig004_add_unique();

DROP PROCEDURE IF EXISTS mig004_add_unique;
