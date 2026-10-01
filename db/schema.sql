-- =====================================================================
--  db/schema.sql — CSDL MySQL/MariaDB cho Web điểm danh (bản độc lập)
--  Thay cho 12 sheet của Google Sheets (gas/01-Schema.gs).
--
--  Cách dùng trên Hostinger:
--    hPanel → Websites → Manage → Databases → phpMyAdmin → chọn CSDL
--    → tab Import → chọn file này → Go.
--
--  Nguyên tắc giữ nguyên từ thiết kế (docs/01-THIETKE-kien-truc.md):
--    - ID dạng chuỗi có tiền tố (STD_xxx, CLS_xxx…), KHÔNG dùng số dòng (C.1)
--    - Cặp StudentID + SessionID là duy nhất (D.8 lớp 3) → UNIQUE KEY
--    - Xoá mềm bằng cột Status, giữ CreatedAt
--    - 12_AUDIT_LOG bắt buộc (D.8 lớp 6)
--  Tên bảng/cột giữ đúng như tên sheet/cột cũ để chuyển dữ liệu 1-1.
--  Chạy lại nhiều lần an toàn (IF NOT EXISTS).
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '+07:00';

-- 01 — Tài khoản giảng viên / admin
CREATE TABLE IF NOT EXISTS users (
  UserID        VARCHAR(40)  NOT NULL,
  Username      VARCHAR(60)  NOT NULL,
  FullName      VARCHAR(120) NOT NULL,
  Email         VARCHAR(160) NULL,
  PasswordHash  VARCHAR(255) NOT NULL,          -- dùng password_hash() của PHP
  Salt          VARCHAR(64)  NULL,              -- chỉ để nhận hash cũ từ Apps Script
  Role          ENUM('ADMIN','LECTURER','STUDENT') NOT NULL DEFAULT 'LECTURER',
  Status        ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  CreatedAt     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (UserID),
  UNIQUE KEY uq_users_username (Username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 02 — Môn học (Course ≠ Class, thiết kế C.1)
CREATE TABLE IF NOT EXISTS courses (
  CourseID      VARCHAR(40)  NOT NULL,
  CourseCode    VARCHAR(30)  NOT NULL,
  CourseName    VARCHAR(200) NOT NULL,
  Credits       DECIMAL(4,1) NULL,
  TheoryHours   INT NULL,
  PracticeHours INT NULL,
  Status        ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  CreatedAt     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (CourseID),
  KEY idx_courses_code (CourseCode)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 03 — Lớp mở trong học kỳ; toạ độ phòng học theo lớp (D.3)
CREATE TABLE IF NOT EXISTS classes (
  ClassID        VARCHAR(40) NOT NULL,
  CourseID       VARCHAR(40) NOT NULL,
  LecturerID     VARCHAR(40) NOT NULL,
  ClassCode      VARCHAR(40) NOT NULL,
  Semester       VARCHAR(10) NULL,
  AcademicYear   VARCHAR(20) NULL,
  RoomLat        DECIMAL(10,7) NULL,
  RoomLng        DECIMAL(10,7) NULL,
  AllowedRadiusM INT NOT NULL DEFAULT 100,
  Status         ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  CreatedAt      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (ClassID),
  KEY idx_classes_lecturer (LecturerID),
  KEY idx_classes_course (CourseID),
  -- KHÔNG đặt khoá ngoại trên LecturerID: cột này lưu DANH SÁCH UserID cách
  -- nhau bởi dấu phẩy ("1607,2015" — thiết kế D.9, gas/11-FixRealData.gs,
  -- docs/04-API-PHP.md mục 4). Khoá ngoại sẽ từ chối mọi lớp có 2 giảng viên.
  -- Ứng dụng tự kiểm từng UserID khi ghi (api/lib/admin.php adminSaveClass).
  -- CSDL đã tạo trước đó: chạy db/migrations/003-classes-lecturerid-csv.sql.
  CONSTRAINT fk_classes_course   FOREIGN KEY (CourseID)   REFERENCES courses(CourseID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 04 — Sinh viên (MSSV lưu dạng CHUỖI để không mất số 0 đầu)
CREATE TABLE IF NOT EXISTS students (
  StudentID  VARCHAR(40)  NOT NULL,
  MSSV       VARCHAR(12)  NOT NULL,
  FullName   VARCHAR(120) NOT NULL,
  Email      VARCHAR(160) NULL,
  Status     ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  CreatedAt  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (StudentID),
  UNIQUE KEY uq_students_mssv (MSSV)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 05 — Ghi danh: nhiều-nhiều Student ↔ Class (D.8 lớp 2)
CREATE TABLE IF NOT EXISTS enrollments (
  EnrollmentID VARCHAR(40) NOT NULL,
  StudentID    VARCHAR(40) NOT NULL,
  ClassID      VARCHAR(40) NOT NULL,
  Status       ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  CreatedAt    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (EnrollmentID),
  UNIQUE KEY uq_enroll (StudentID, ClassID),
  KEY idx_enroll_class (ClassID),
  CONSTRAINT fk_enroll_student FOREIGN KEY (StudentID) REFERENCES students(StudentID),
  CONSTRAINT fk_enroll_class   FOREIGN KEY (ClassID)   REFERENCES classes(ClassID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 06 — Buổi học / thời khoá biểu
CREATE TABLE IF NOT EXISTS sessions (
  SessionID  VARCHAR(40) NOT NULL,
  ClassID    VARCHAR(40) NOT NULL,
  SessionNo  INT NOT NULL,
  `Date`     DATE NULL,
  DayOfWeek  VARCHAR(20) NULL,
  StartTime  VARCHAR(5)  NULL,     -- 'HH:MM'
  EndTime    VARCHAR(5)  NULL,
  Content    TEXT NULL,
  Status     ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  CreatedAt  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (SessionID),
  KEY idx_sessions_class (ClassID, SessionNo),
  CONSTRAINT fk_sessions_class FOREIGN KEY (ClassID) REFERENCES classes(ClassID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 07 — Điểm danh; khoá nghiệp vụ StudentID + SessionID (D.8 lớp 3)
CREATE TABLE IF NOT EXISTS attendance (
  AttendanceID VARCHAR(40) NOT NULL,
  StudentID    VARCHAR(40) NOT NULL,
  SessionID    VARCHAR(40) NOT NULL,
  Status       ENUM('PRESENT','LATE','ABSENT','EXCUSED') NOT NULL,
  CheckInTime  DATETIME NULL,
  GpsLat       DECIMAL(10,7) NULL,
  GpsLng       DECIMAL(10,7) NULL,
  GpsAccuracy  DECIMAL(8,1)  NULL,
  DistanceM    DECIMAL(9,1)  NULL,
  GpsFlag      ENUM('VALID','OUT_OF_RANGE','LOW_ACCURACY','NO_GPS') NULL,
  IP           VARCHAR(45) NULL,   -- đủ cho IPv6; PHP lấy từ REMOTE_ADDR
  OS           VARCHAR(40) NULL,
  Browser      VARCHAR(40) NULL,
  DeviceType   VARCHAR(20) NULL,
  DeviceHash   VARCHAR(64) NULL,
  Note         VARCHAR(500) NULL,
  CreatedAt    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (AttendanceID),
  UNIQUE KEY uq_att_student_session (StudentID, SessionID),
  KEY idx_att_session (SessionID),
  KEY idx_att_device (SessionID, DeviceHash),
  CONSTRAINT fk_att_student FOREIGN KEY (StudentID) REFERENCES students(StudentID),
  CONSTRAINT fk_att_session FOREIGN KEY (SessionID) REFERENCES sessions(SessionID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 08 — Mã điểm danh 4 ký tự; LateAfter tách khỏi EndTime (D.2)
CREATE TABLE IF NOT EXISTS attendance_keys (
  KeyID     VARCHAR(40) NOT NULL,
  SessionID VARCHAR(40) NOT NULL,
  Code      CHAR(4)     NOT NULL,
  StartTime DATETIME NOT NULL,
  LateAfter DATETIME NOT NULL,
  EndTime   DATETIME NOT NULL,
  Status    ENUM('OPEN','CLOSED') NOT NULL DEFAULT 'OPEN',
  CreatedBy VARCHAR(40) NULL,
  CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (KeyID),
  KEY idx_keys_session (SessionID, Status),
  KEY idx_keys_code (Code, Status),
  CONSTRAINT fk_keys_session FOREIGN KEY (SessionID) REFERENCES sessions(SessionID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 09 — Cột điểm động (C.4)
CREATE TABLE IF NOT EXISTS grade_columns (
  GradeColumnID VARCHAR(40) NOT NULL,
  ClassID       VARCHAR(40) NOT NULL,
  Name          VARCHAR(100) NOT NULL,
  Weight        DECIMAL(5,2) NOT NULL DEFAULT 0,
  SortOrder     INT NOT NULL DEFAULT 0,
  Status        ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  CreatedAt     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (GradeColumnID),
  -- Một lớp không có hai đầu điểm cùng tên: grading_ensure_column() tra theo
  -- (ClassID, Name) nên trùng tên sẽ sinh hai cột, cộng trọng số hai lần
  -- (GĐ9 review lần 2, L6). CSDL đã tạo trước: migration 004.
  UNIQUE KEY uq_gcol_class_name (ClassID, Name),
  KEY idx_gcol_class (ClassID, SortOrder),
  CONSTRAINT fk_gcol_class FOREIGN KEY (ClassID) REFERENCES classes(ClassID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10 — Điểm
CREATE TABLE IF NOT EXISTS grades (
  GradeID       VARCHAR(40) NOT NULL,
  StudentID     VARCHAR(40) NOT NULL,
  ClassID       VARCHAR(40) NOT NULL,
  GradeColumnID VARCHAR(40) NOT NULL,
  Score         DECIMAL(5,2) NULL,
  UpdatedBy     VARCHAR(40) NULL,
  UpdatedAt     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (GradeID),
  UNIQUE KEY uq_grade (StudentID, GradeColumnID),
  KEY idx_grades_class (ClassID),
  CONSTRAINT fk_grades_student FOREIGN KEY (StudentID)     REFERENCES students(StudentID),
  CONSTRAINT fk_grades_class   FOREIGN KEY (ClassID)       REFERENCES classes(ClassID),
  CONSTRAINT fk_grades_col     FOREIGN KEY (GradeColumnID) REFERENCES grade_columns(GradeColumnID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11 — Khiếu nại
CREATE TABLE IF NOT EXISTS complaints (
  ComplaintID VARCHAR(40) NOT NULL,
  StudentID   VARCHAR(40) NOT NULL,
  ClassID     VARCHAR(40) NULL,
  Type        ENUM('ATTENDANCE','GRADE','OTHER') NOT NULL,
  TargetID    VARCHAR(40) NULL,
  Content     TEXT NOT NULL,
  CreatedAt   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  Status      ENUM('PENDING','PROCESSING','RESOLVED','REJECTED') NOT NULL DEFAULT 'PENDING',
  Response    TEXT NULL,
  ResolvedBy  VARCHAR(40) NULL,
  ResolvedAt  DATETIME NULL,
  PRIMARY KEY (ComplaintID),
  KEY idx_compl_student (StudentID),
  KEY idx_compl_status (Status),
  CONSTRAINT fk_compl_student FOREIGN KEY (StudentID) REFERENCES students(StudentID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12 — Nhật ký — BẮT BUỘC (D.8 lớp 6). Chỉ INSERT, không UPDATE/DELETE.
CREATE TABLE IF NOT EXISTS audit_log (
  LogID      VARCHAR(40) NOT NULL,
  `Time`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  Actor      VARCHAR(40) NULL,
  ActorRole  VARCHAR(20) NULL,
  Action     VARCHAR(60) NOT NULL,
  TargetType VARCHAR(30) NULL,
  TargetID   VARCHAR(40) NULL,
  Data       JSON NULL,
  IP         VARCHAR(45) NULL,
  PRIMARY KEY (LogID),
  KEY idx_audit_time (`Time`),
  KEY idx_audit_target (TargetType, TargetID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Hai bảng MỚI, thay cho CacheService của Apps Script
-- ---------------------------------------------------------------------

-- Token đăng nhập giảng viên (6 giờ) và token xem điểm của sinh viên
CREATE TABLE IF NOT EXISTS auth_tokens (
  Token      CHAR(64)    NOT NULL,           -- bin2hex(random_bytes(32))
  Kind       ENUM('LECTURER','GRADE') NOT NULL,
  SubjectID  VARCHAR(40) NOT NULL,           -- UserID hoặc StudentID
  ExpiresAt  DATETIME    NOT NULL,
  CreatedAt  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (Token),
  KEY idx_tokens_exp (ExpiresAt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mã xác minh gửi qua email khi sinh viên xem điểm (requestGradeCode).
-- SendCount/WindowStartAt thay 'gcount_' (giới hạn 5 lần gửi / 24h cuộn),
-- LastSentAt thay 'gsent_' (cooldown 60s chống bấm trùng) của bản Apps
-- Script cũ (gas/08-GradeService.gs) — bổ sung ở GĐ3, xem docs/04-API-PHP.md
-- mục 10.1. Một dòng/sinh viên, ghi đè mỗi lần xin mã mới (không xoá dòng
-- khi mã hết hạn, để giữ được lịch sử gửi cho giới hạn 24h).
CREATE TABLE IF NOT EXISTS grade_codes (
  StudentID     VARCHAR(40) NOT NULL,
  CodeHash      VARCHAR(255) NOT NULL,     -- hash('sha256', mã) — không lưu mã thô
  Attempts      INT NOT NULL DEFAULT 0,
  ExpiresAt     DATETIME NOT NULL,
  SendCount     INT NOT NULL DEFAULT 0,    -- số lần gửi trong cửa sổ 24h hiện tại
  WindowStartAt DATETIME NULL,             -- mốc bắt đầu cửa sổ 24h (cuộn, không theo ngày lịch)
  LastSentAt    DATETIME NULL,             -- lần gửi gần nhất — cooldown 60s
  CreatedAt     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (StudentID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
