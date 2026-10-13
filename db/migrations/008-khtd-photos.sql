-- =====================================================================
--  db/migrations/008-khtd-photos.sql — Ảnh chụp mẫu vật trong phiếu online
--
--  Mỗi dòng = một ảnh SV tải lên cho một mẫu (một hàng của bảng mô tả).
--  Tệp ảnh nằm NGOÀI public_html: ../private/khtd_uploads/<WorksheetID>/<PhotoID>.jpg
--  (đổi được bằng app_config()['khtd']['upload_dir']). Chỉ xem qua khtd/anh.php
--  (SV chủ ảnh hoặc GV của lớp). Bài làm tham chiếu ảnh qua answers._photos.
--
--  Cách chạy: php tools/migrate.php --yes (SSH), hoặc phpMyAdmin → SQL. Chạy lại an toàn.
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS khtd_photos (
  PhotoID     VARCHAR(40)  NOT NULL,               -- PHO_xxxxxxxxxxxx
  WorksheetID VARCHAR(40)  NOT NULL,
  StudentID   VARCHAR(40)  NOT NULL,
  SectionID   VARCHAR(40)  NOT NULL,               -- id bảng trong SchemaJSON (vd 'mota')
  RowIdx      TINYINT UNSIGNED NOT NULL,           -- hàng (mẫu) 0-based
  FileName    VARCHAR(80)  NOT NULL,               -- tên tệp trong thư mục upload
  Mime        VARCHAR(40)  NOT NULL,
  Bytes       INT UNSIGNED NOT NULL,
  Width       SMALLINT UNSIGNED NULL,
  Height      SMALLINT UNSIGNED NULL,
  CreatedAt   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (PhotoID),
  KEY idx_photo_owner (WorksheetID, StudentID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
