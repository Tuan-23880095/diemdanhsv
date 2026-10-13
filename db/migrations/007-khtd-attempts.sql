-- =====================================================================
--  db/migrations/007-khtd-attempts.sql — Phiếu học tập online: làm nhiều lần
--
--  1. khtd_worksheets.MaxAttempts  — số lần nộp tối đa (1 = một lần như cũ,
--     0 = không giới hạn). ScorePolicy — điểm tính: BEST (cao nhất các lần)
--     hoặc LAST (lần nộp sau cùng).
--  2. khtd_submissions.AttemptNo   — mỗi lần làm là MỘT dòng (lần 1, 2, …).
--     Khoá duy nhất đổi từ (StudentID, WorksheetID) sang
--     (StudentID, WorksheetID, AttemptNo). Bài cũ tự thành "lần 1".
--  3. khtd_extra_attempts          — lượt làm lại giảng viên cấp riêng cho
--     từng sinh viên (vd sinh viên bấm nhầm "Nộp phiếu").
--
--  Tương thích ngược: code cũ vẫn chạy được sau migration (cột mới có giá trị
--  mặc định, mỗi SV vẫn chỉ có lần 1). Chạy TRƯỚC hoặc NGAY SAU khi merge.
--
--  Cách chạy: php tools/migrate.php --yes (SSH, từ public_html), hoặc hPanel →
--  phpMyAdmin → chọn CSDL → tab SQL → dán → Go. An toàn chạy lại nhiều lần.
-- =====================================================================

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS mig007_attempts;

CREATE PROCEDURE mig007_attempts()
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'khtd_worksheets' AND COLUMN_NAME = 'MaxAttempts') THEN
    ALTER TABLE khtd_worksheets ADD COLUMN MaxAttempts TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER Status;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'khtd_worksheets' AND COLUMN_NAME = 'ScorePolicy') THEN
    ALTER TABLE khtd_worksheets ADD COLUMN ScorePolicy ENUM('BEST','LAST') NOT NULL DEFAULT 'BEST' AFTER MaxAttempts;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'khtd_submissions' AND COLUMN_NAME = 'AttemptNo') THEN
    ALTER TABLE khtd_submissions ADD COLUMN AttemptNo TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER StudentID;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'khtd_submissions' AND INDEX_NAME = 'uq_sub_student_ws_attempt') THEN
    ALTER TABLE khtd_submissions ADD UNIQUE KEY uq_sub_student_ws_attempt (StudentID, WorksheetID, AttemptNo);
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'khtd_submissions' AND INDEX_NAME = 'uq_sub_student_ws') THEN
    ALTER TABLE khtd_submissions DROP INDEX uq_sub_student_ws;
  END IF;
END;

CALL mig007_attempts();

DROP PROCEDURE IF EXISTS mig007_attempts;

CREATE TABLE IF NOT EXISTS khtd_extra_attempts (
  WorksheetID VARCHAR(40) NOT NULL,
  StudentID   VARCHAR(40) NOT NULL,
  Extra       TINYINT UNSIGNED NOT NULL DEFAULT 0,  -- số lượt cấp thêm ngoài MaxAttempts
  GrantedBy   VARCHAR(40) NULL,
  UpdatedAt   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (WorksheetID, StudentID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
