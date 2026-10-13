-- =====================================================================
--  db/migrations/009-class-archives.sql — Lưu trữ cuối học kỳ (docs/04 mục 32)
--
--  Mỗi dòng = một lớp học phần đã kết thúc: giữ THỐNG KÊ ẨN DANH (StatsJSON — không có
--  họ tên/MSSV/email) để so sánh chương trình và mức độ người học qua các năm, sau khi dữ
--  liệu chi tiết của lớp đã được tải về máy giảng viên (gói ZIP) và xoá khỏi máy chủ.
--  Status: PACKAGED = đã tạo gói sao lưu, chưa xoá; RESET = đã xoá dữ liệu chi tiết.
--
--  Cách chạy: php tools/migrate.php --yes (SSH), hoặc phpMyAdmin → SQL. Chạy lại an toàn.
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS class_archives (
  ArchiveID     VARCHAR(40)  NOT NULL,              -- ARC_xxxxxxxxxxxx
  ClassID       VARCHAR(40)  NOT NULL,
  ClassCode     VARCHAR(40)  NOT NULL,
  CourseCode    VARCHAR(30)  NULL,
  CourseName    VARCHAR(200) NULL,
  Semester      VARCHAR(10)  NULL,
  AcademicYear  VARCHAR(20)  NULL,
  LecturerName  VARCHAR(120) NULL,
  StudentsCount INT NOT NULL DEFAULT 0,
  Status        ENUM('PACKAGED','RESET') NOT NULL DEFAULT 'PACKAGED',
  StatsJSON     MEDIUMTEXT NULL,                    -- ảnh chụp thống kê ẩn danh
  PackageName   VARCHAR(160) NULL,
  PackageBytes  BIGINT NULL,
  PackageSha256 CHAR(64) NULL,
  PackagedAt    DATETIME NULL,
  PackagedBy    VARCHAR(40) NULL,
  DeletedJSON   TEXT NULL,                          -- số dòng/tệp đã xoá theo bảng
  SafetyFile    VARCHAR(200) NULL,                  -- bản cứu dữ liệu chữ (giữ 30 ngày)
  ResetAt       DATETIME NULL,
  ResetBy       VARCHAR(40) NULL,
  CreatedAt     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (ArchiveID),
  UNIQUE KEY uq_archive_class (ClassID),
  KEY idx_archive_course (CourseCode)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
