-- =====================================================================
--  db/migrations/010-live-rooms.sql — Phòng tương tác trực tiếp cho trang
--  trình chiếu bài giảng (dkv/trinhchieu.html ↔ dkv/sv.html), docs/04 mục 36.
--
--  live_rooms     : mỗi dòng = một phòng giảng viên mở từ trang trình chiếu.
--                   Code 5 ký tự (khác độ dài mã điểm danh 4 ký tự để không lẫn),
--                   KHÔNG trùng với phòng khác đang mở — code sinh mã trong
--                   khoá tên GET_LOCK('live_room_open') rồi mới kiểm + ghi.
--                   HostKeyHash = SHA-256 của khoá bí mật chỉ trang trình chiếu
--                   giữ: chỉ ai có khoá này mới đọc được câu trả lời của phòng.
--                   ClassID/SessionID/Label: tuỳ chọn, gắn qua mã điểm danh
--                   đang OPEN (attendance_keys) để biết phòng thuộc lớp nào.
--  live_responses : câu trả lời sinh viên gửi lên (quiz / nhóm / hỏi đáp).
--                   ResponseID tự tăng làm mốc "chỉ lấy bản ghi mới hơn" khi
--                   trang trình chiếu hỏi định kỳ. Luôn lọc theo RoomID → hai
--                   lớp học song song không bao giờ lẫn dữ liệu.
--                   uq_live_quiz: mỗi MSSV chỉ một đáp án cho mỗi câu quiz
--                   trong một phòng (nhóm/hỏi đáp có QIndex NULL nên không bị chặn).
--  Dọn dẹp: api/lib/liveroom.php tự xoá phòng quá 14 ngày (kèm câu trả lời,
--  ON DELETE CASCADE) khi có người mở phòng mới — không cần cron.
--
--  Cách chạy: php tools/migrate.php --yes (SSH), hoặc hPanel → phpMyAdmin →
--  chọn CSDL → tab SQL → dán toàn bộ file → Go. Chạy lại an toàn (IF NOT EXISTS).
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS live_rooms (
  RoomID      VARCHAR(40)  NOT NULL,                 -- LRM_xxxxxxxxxxxx
  Code        CHAR(5)      NOT NULL,                 -- mã phòng sinh viên nhập / quét QR
  HostKeyHash CHAR(64)     NOT NULL,                 -- sha256(khoá trang trình chiếu)
  LessonCode  VARCHAR(20)  NOT NULL,                 -- mã bài giảng, vd 4-1
  ClassID     VARCHAR(40)  NULL,                     -- gắn qua mã điểm danh (tuỳ chọn)
  SessionID   VARCHAR(40)  NULL,
  Label       VARCHAR(120) NULL,                     -- tên lớp hiển thị
  Status      ENUM('OPEN','CLOSED') NOT NULL DEFAULT 'OPEN',
  CreatedIp   VARCHAR(45)  NULL,
  CreatedAt   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ExpiresAt   DATETIME     NOT NULL,
  ClosedAt    DATETIME     NULL,
  PRIMARY KEY (RoomID),
  KEY idx_live_code (Code, Status),
  KEY idx_live_created (CreatedAt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS live_responses (
  ResponseID  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  RoomID      VARCHAR(40)  NOT NULL,
  Kind        ENUM('quiz','group','qa') NOT NULL,
  LessonCode  VARCHAR(20)  NOT NULL,
  MSSV        VARCHAR(15)  NOT NULL,
  StudentName VARCHAR(80)  NOT NULL,
  InRoster    TINYINT(1)   NULL,                     -- 1/0 khi phòng gắn lớp; NULL khi không gắn
  QIndex      SMALLINT     NULL,                     -- quiz: số thứ tự câu (từ 0)
  Choice      CHAR(1)      NULL,                     -- quiz: A/B/C/D
  GroupName   VARCHAR(80)  NULL,                     -- bài nhóm
  Body        TEXT         NULL,                     -- nội dung nhóm / câu hỏi
  ClientIp    VARCHAR(45)  NULL,
  CreatedAt   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (ResponseID),
  UNIQUE KEY uq_live_quiz (RoomID, LessonCode, QIndex, MSSV),
  KEY idx_live_room (RoomID, ResponseID),
  CONSTRAINT fk_live_room FOREIGN KEY (RoomID) REFERENCES live_rooms(RoomID) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
