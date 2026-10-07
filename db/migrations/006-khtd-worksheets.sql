-- 006 — Phiếu học tập online (module khtd), chấm bằng Gemini, gửi email kết quả.
-- Chạy: php tools/migrate.php (CLI). Idempotent (IF NOT EXISTS; ALTER ENUM bỏ qua nếu đã có).

-- Token sinh viên cho phiếu online: thêm Kind 'KHTD' (hạn 3 giờ).
ALTER TABLE auth_tokens MODIFY Kind ENUM('LECTURER','GRADE','KHTD') NOT NULL;

CREATE TABLE IF NOT EXISTS khtd_worksheets (
  WorksheetID VARCHAR(40) NOT NULL,                 -- WSH_xxxxxxxxxxxx
  ClassID     VARCHAR(40) NOT NULL,
  No          TINYINT     NOT NULL,                 -- số thứ tự phiếu (1..6)
  Title       VARCHAR(200) NOT NULL,
  SchemaJSON  MEDIUMTEXT  NOT NULL,                 -- cấu trúc phiếu: sections, rubric, answer_hints
  OpenAt      DATETIME NULL,                        -- NULL = mở ngay
  CloseAt     DATETIME NULL,                        -- NULL = không hạn
  Status      ENUM('OPEN','CLOSED','HIDDEN') NOT NULL DEFAULT 'OPEN',
  CreatedBy   VARCHAR(40) NULL,
  CreatedAt   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UpdatedAt   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (WorksheetID),
  UNIQUE KEY uq_ws_class_no (ClassID, No),
  KEY idx_ws_class (ClassID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS khtd_submissions (
  SubmissionID VARCHAR(40) NOT NULL,                -- SUB_xxxxxxxxxxxx
  WorksheetID  VARCHAR(40) NOT NULL,
  StudentID    VARCHAR(40) NOT NULL,
  AnswersJSON  MEDIUMTEXT  NOT NULL,                -- bài làm (nháp hoặc đã nộp)
  Status       ENUM('DRAFT','SUBMITTED','AI_GRADED','FINAL') NOT NULL DEFAULT 'DRAFT',
  SubmittedAt  DATETIME NULL,
  AiScore      DECIMAL(4,2) NULL,                   -- điểm Gemini đề xuất (0–10)
  AiJSON       MEDIUMTEXT NULL,                     -- điểm từng tiêu chí + nhận xét (JSON)
  AiModel      VARCHAR(60) NULL,
  AiGradedAt   DATETIME NULL,
  FinalScore   DECIMAL(4,2) NULL,                   -- điểm GV duyệt
  FinalNote    TEXT NULL,
  GradedBy     VARCHAR(40) NULL,
  GradedAt     DATETIME NULL,
  EmailSentAt  DATETIME NULL,
  EmailTo      VARCHAR(190) NULL,
  CreatedAt    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UpdatedAt    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (SubmissionID),
  UNIQUE KEY uq_sub_student_ws (StudentID, WorksheetID),
  KEY idx_sub_ws (WorksheetID, Status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
