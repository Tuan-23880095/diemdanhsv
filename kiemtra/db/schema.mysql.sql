-- =====================================================================
--  KIEMTRA — db/schema.mysql.sql
--  Bảng riêng của hệ thống kiểm tra (tiền tố kt_), chạy TRÊN CÙNG CSDL MySQL
--  với diemdanhsv.com. Dùng lại các bảng có sẵn: users, courses, classes,
--  students, enrollments, auth_tokens, grade_columns, grades, rate_limits.
--
--  hPanel → Databases → phpMyAdmin → chọn CSDL của diemdanhsv → Import file này.
--  Chạy lại nhiều lần an toàn (IF NOT EXISTS). Không đụng bảng của site chính.
-- =====================================================================
SET NAMES utf8mb4;

-- Chuẩn đầu ra học phần (CLO) — theo CourseID của bảng courses
CREATE TABLE IF NOT EXISTS kt_clos (
  CloID         VARCHAR(40)  NOT NULL,
  CourseID      VARCHAR(40)  NOT NULL,
  Code          VARCHAR(20)  NOT NULL,            -- CLO1, CLO2…
  Description   TEXT         NOT NULL,
  PassThreshold DECIMAL(5,2) NOT NULL DEFAULT 50, -- % điểm phần CLO để 1 SV "đạt CLO"
  TargetRate    DECIMAL(5,2) NOT NULL DEFAULT 70, -- % SV đạt để CLO của LỚP "đạt"
  SortOrder     INT          NOT NULL DEFAULT 0,
  Status        ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  CreatedAt     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (CloID),
  UNIQUE KEY uq_kt_clo_code (CourseID, Code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ngân hàng câu hỏi
CREATE TABLE IF NOT EXISTS kt_questions (
  QuestionID  VARCHAR(40)  NOT NULL,
  CourseID    VARCHAR(40)  NOT NULL,
  Type        ENUM('single','multi','short') NOT NULL DEFAULT 'single',
  Stem        TEXT         NOT NULL,
  ImageID     VARCHAR(40)  NULL,
  OptionsJSON TEXT         NULL,       -- [{"key":"A","text":"…"},…]
  AnswerJSON  TEXT         NOT NULL,   -- single: ["B"]; multi: ["A","C"]; short: ["đáp án 1","đáp án 2"]
  Explanation TEXT         NULL,
  CloID       VARCHAR(40)  NULL,
  Bloom       TINYINT      NULL,       -- 1 Nhớ … 6 Sáng tạo
  Difficulty  TINYINT      NOT NULL DEFAULT 2, -- 1 dễ, 2 trung bình, 3 khó
  Points      DECIMAL(5,2) NOT NULL DEFAULT 1,
  Topic       VARCHAR(120) NULL,
  Status      ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  CreatedBy   VARCHAR(40)  NULL,
  CreatedAt   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UpdatedAt   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (QuestionID),
  KEY idx_kt_q_course (CourseID, Status),
  KEY idx_kt_q_clo (CloID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ảnh minh hoạ câu hỏi (mẫu đá, lát mỏng…) — file nằm NGOÀI public_html
CREATE TABLE IF NOT EXISTS kt_images (
  ImageID   VARCHAR(40)  NOT NULL,
  FileName  VARCHAR(80)  NOT NULL,
  Mime      VARCHAR(40)  NOT NULL,
  Bytes     INT UNSIGNED NOT NULL,
  CreatedBy VARCHAR(40)  NULL,
  CreatedAt DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (ImageID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Đề kiểm tra (một buổi thi của một lớp)
CREATE TABLE IF NOT EXISTS kt_exams (
  ExamID          VARCHAR(40)  NOT NULL,
  ClassID         VARCHAR(40)  NOT NULL,
  Title           VARCHAR(200) NOT NULL,
  Code            CHAR(6)      NOT NULL,           -- mã phòng SV quét QR / nhập tay
  Status          ENUM('DRAFT','LOBBY','RUNNING','CLOSED') NOT NULL DEFAULT 'DRAFT',
  PrepMinutes     INT          NOT NULL DEFAULT 5,
  DurationMinutes INT          NOT NULL DEFAULT 30,
  SettingsJSON    TEXT         NULL,
  SupervisorPin   VARCHAR(8)   NOT NULL,           -- PIN giám thị mở khoá tại chỗ
  OpenedAt        DATETIME     NULL,
  StartAt         DATETIME     NULL,               -- = OpenedAt + PrepMinutes (tự bắt đầu)
  StartedAt       DATETIME     NULL,
  EndsAt          DATETIME     NULL,
  ClosedAt        DATETIME     NULL,
  ResultsPublished TINYINT(1)  NOT NULL DEFAULT 0, -- cho SV xem đáp án + giải thích
  CreatedBy       VARCHAR(40)  NULL,
  CreatedAt       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (ExamID),
  KEY idx_kt_exam_code (Code, Status),
  KEY idx_kt_exam_class (ClassID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Câu hỏi trong đề; SnapshotJSON đóng băng nội dung lúc mở phòng
CREATE TABLE IF NOT EXISTS kt_exam_items (
  ExamID       VARCHAR(40)  NOT NULL,
  QuestionID   VARCHAR(40)  NOT NULL,
  Ord          INT          NOT NULL DEFAULT 0,
  Points       DECIMAL(5,2) NOT NULL DEFAULT 1,
  SnapshotJSON MEDIUMTEXT   NULL,
  PRIMARY KEY (ExamID, QuestionID),
  KEY idx_kt_items_exam (ExamID, Ord)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lượt làm bài của sinh viên
CREATE TABLE IF NOT EXISTS kt_attempts (
  AttemptID    VARCHAR(40)  NOT NULL,
  ExamID       VARCHAR(40)  NOT NULL,
  StudentID    VARCHAR(40)  NOT NULL,
  MSSV         VARCHAR(12)  NOT NULL,
  FullName     VARCHAR(120) NOT NULL,
  SeatNo       INT          NOT NULL DEFAULT 0,    -- số thứ tự vào phòng (hiện trên máy SV)
  TokenHash    CHAR(64)     NOT NULL,
  DeviceID     VARCHAR(64)  NULL,
  DeviceInfo   VARCHAR(200) NULL,
  IP           VARCHAR(45)  NULL,
  Status       ENUM('JOINED','READY','DOING','LOCKED','SUBMITTED') NOT NULL DEFAULT 'JOINED',
  LayoutJSON   TEXT         NULL,                  -- thứ tự câu + hoán vị phương án riêng của SV
  AnswersJSON  MEDIUMTEXT   NULL,
  Violations   INT          NOT NULL DEFAULT 0,
  Unlocks      INT          NOT NULL DEFAULT 0,
  PinFails     INT          NOT NULL DEFAULT 0,
  LockReason   VARCHAR(120) NULL,
  ExtraMinutes INT          NOT NULL DEFAULT 0,
  AllowRejoin  TINYINT(1)   NOT NULL DEFAULT 0,
  JoinedAt     DATETIME     NULL,
  ReadyAt      DATETIME     NULL,
  LastSeenAt   DATETIME     NULL,
  SubmittedAt  DATETIME     NULL,
  SubmitReason VARCHAR(30)  NULL,                  -- sv_nop | het_gio | gv_thu | vi_pham
  RawScore     DECIMAL(7,2) NULL,
  MaxScore     DECIMAL(7,2) NULL,
  Score10      DECIMAL(4,2) NULL,
  DetailJSON   MEDIUMTEXT   NULL,                  -- đúng/sai từng câu
  PRIMARY KEY (AttemptID),
  UNIQUE KEY uq_kt_att (ExamID, StudentID),
  KEY idx_kt_att_exam (ExamID, Status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Nhật ký sự kiện giám sát (chỉ INSERT)
CREATE TABLE IF NOT EXISTS kt_events (
  EventID   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ExamID    VARCHAR(40)  NOT NULL,
  AttemptID VARCHAR(40)  NULL,
  Type      VARCHAR(30)  NOT NULL,
  Detail    VARCHAR(255) NULL,
  Counted   TINYINT(1)   NOT NULL DEFAULT 0,       -- 1 = tính là vi phạm
  ClientAt  VARCHAR(30)  NULL,
  CreatedAt DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (EventID),
  KEY idx_kt_ev_exam (ExamID, EventID),
  KEY idx_kt_ev_att (AttemptID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
