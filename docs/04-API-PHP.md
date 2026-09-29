# 04 — Hợp đồng API PHP (thay Apps Script): 13 action, ánh xạ dữ liệu, cấu hình

Giai đoạn: GĐ1/10 (PLAN diemdanhsv). Chỉ tài liệu, chưa có code chạy.
Nguồn đối chiếu: `gas/05-Api.gs`, `gas/03-Auth.gs`, `gas/04-AttendanceService.gs`,
`gas/08-GradeService.gs`, `gas/00-Config.gs`, `gas/02-Repo.gs`, `db/schema.sql`,
`js/services/APIService.js`, `js/config/config.js`, `docs/01-THIETKE-kien-truc.md`.

> Tài liệu này là nguồn chân lý cho việc viết `api/index.php` ở GĐ2–GĐ5. Khi
> có thay đổi thiết kế, sửa ở đây trước, rồi mới sửa code — cùng nguyên tắc
> với `docs/01-THIETKE-kien-truc.md`.

---

## 1. Khuôn dạng chung (KHÔNG được đổi — rule bắt buộc của dự án)

- Một điểm vào duy nhất: `api/index.php` (route bằng tham số `action`,
  giống `doGet`/`doPost` cũ — không cần router phức tạp kiểu `/api/checkin`).
- **GET** cho thao tác ĐỌC: `?action=...&...`.
- **POST** cho thao tác GHI (và các thao tác cần giấu tham số khỏi URL/log
  như `login`, `checkin`): body là JSON thô, header
  `Content-Type: text/plain;charset=utf-8` (đúng như `js/services/APIService.js`
  đang gửi — **không đổi** file này ở phía frontend).
- Phong bì phản hồi — **giữ nguyên 100%**:
  ```json
  { "status": "success" | "error", "message": "…", "data": … }
  ```
  `status: 'error'` thì `data: null`; `APIService._unwrap()` ném `Error(message)`
  khi gặp `status === 'error'`, nên PHP phải trả đúng field này, không phải
  mã lỗi HTTP riêng (giữ HTTP 200 cho mọi phản hồi hợp lệ về khuôn, kể cả lỗi
  nghiệp vụ; chỉ dùng mã HTTP khác khi bản thân request hỏng, ví dụ sai method).
- PHP đọc body qua `file_get_contents('php://input')` rồi `json_decode()`
  (tương đương `parseBody_()` cũ) — không dùng `$_POST` vì content-type là
  `text/plain`, PHP không tự parse.
- Action rỗng hoặc không khớp danh sách 13 action → `fail('Action không hợp lệ: ' . $action)`
  đúng thông báo cũ để không phải sửa frontend.

## 2. CORS

Frontend (`https://diemdanhsv.com/...`) và API (`https://diemdanhsv.com/api/...`)
**cùng domain** → same-origin, trình duyệt không gửi preflight OPTIONS và
không cần header `Access-Control-Allow-Origin`. `api/index.php` **không cần**
xử lý method `OPTIONS`. Giữ quy ước `Content-Type: text/plain` ở frontend dù
same-origin không bắt buộc — giữ vì rule 5 (không đổi khuôn cũ) và vì đổi
`APIService.js` không nằm trong phạm vi GĐ1–GĐ5.

Nếu về sau tách domain (ví dụ API ở `api.diemdanhsv.com`), phải quay lại đây
bổ sung header CORS — ghi chú để không quên.

## 3. API_URL tuyệt đối

Đề xuất: `https://diemdanhsv.com/api/index.php`.

`js/config/config.js` → `CONFIG.API_URL` **CHỈ đổi trỏ sang PHP sau khi**
GĐ5 (checklist 13/13 action qua smoke test trên dữ liệu demo) xong — đổi sớm
làm sập app Apps Script đang chạy thật (đã ghi trong STATE/PLAN, nhắc lại ở
đây để không quên khi viết code GĐ2).

## 4. Ánh xạ 12 sheet cũ → 14 bảng MySQL

| Sheet cũ (`gas/01-Schema.gs`) | Bảng MySQL (`db/schema.sql`) | Khác biệt cần biết |
|---|---|---|
| `01_USERS` | `users` | `PasswordHash` chuyển sang `password_hash()` của PHP; `Salt` giữ lại tạm thời chỉ để verify hash cũ một lần khi rehash (mục 6). |
| `02_COURSES` | `courses` | Giữ nguyên tên cột. |
| `03_CLASSES` | `classes` | Giữ `RoomLat/RoomLng/AllowedRadiusM`; `LecturerID` **vẫn là chuỗi CSV nhiều UserID** (`"1607,2015"` — thiết kế D.9) trong MySQL luôn, KHÔNG tách bảng nối riêng ở GĐ1–GĐ5 để giữ đúng hành vi cũ; `classHasLecturer_()` port sang PHP y hệt (tách theo `/[,;.\s]+/`). |
| `04_STUDENTS` | `students` | `MSSV` là `VARCHAR(12)` — không mất số 0 đầu. |
| `05_ENROLLMENTS` | `enrollments` | Giữ nguyên. |
| `06_SESSIONS` | `sessions` | Giữ nguyên; cột `Date`/`StartTime`/`EndTime` kiểu SQL thật thay vì text. |
| `07_ATTENDANCE` | `attendance` | `UNIQUE KEY (StudentID, SessionID)` thay cho kiểm tra tay trong `upsert()` — MySQL tự chặn trùng, PHP dùng `INSERT ... ON DUPLICATE KEY UPDATE`. |
| `08_ATTENDANCE_KEYS` | `attendance_keys` | Giữ `LateAfter` tách khỏi `EndTime` (D.2). |
| `09_GRADE_COLUMNS` | `grade_columns` | Giữ `SortOrder`. |
| `10_GRADES` | `grades` | `UNIQUE KEY (StudentID, GradeColumnID)` — schema MySQL **không có `ClassID` trong khoá unique**; đúng vì một `GradeColumnID` đã thuộc một `ClassID` cố định (giữ đúng hành vi `myScores` cũ dùng `ClassID|GradeColumnID` làm khoá tra cứu, nhưng ràng buộc duy nhất chỉ cần `GradeColumnID` vì nó không dùng chung giữa các lớp). |
| `11_COMPLAINTS` | `complaints` | Giữ nguyên — **chưa có action API nào cho khiếu nại trong 13 action cũ** (xem mục 10). |
| `12_AUDIT_LOG` | `audit_log` | `Data` là cột `JSON` thật thay vì text — `logAudit()` PHP dùng `json_encode()`, không đổi cấu trúc dữ liệu bên trong. |
| *(không có — CacheService)* | `auth_tokens` (mới) | Thay `CacheService` cho token giảng viên (`tok_*`) và token xem điểm (`gtok_*`). |
| *(không có — CacheService)* | `grade_codes` (mới) | Thay `CacheService` cho mã xác minh xem điểm (`gcode_*`, `gtry_*`). **Xem lỗ hổng ở mục 10.** |

## 5. Bảng đối chiếu 13 action

Ký hiệu bảng liên quan chỉ liệt kê bảng chính, không lặp `audit_log` (mọi
action ghi đều phải insert `audit_log` — D.8 lớp 6, không được bỏ).

### GET — chỉ đọc

| # | action | Tham số vào | `data` trả về | Mã lỗi / thông báo (giữ nguyên) | Bảng chính |
|---|---|---|---|---|---|
| 1 | `ping` | — | `{ time, version }` | — | — |
| 2 | `studentHistory` | `mssv`, `classId` | `{ mssv, fullName, rows: [{sessionNo,date,content,status,checkInTime}] }` | "MSSV không hợp lệ (phải là 6–10 chữ số)."; "Không tìm thấy MSSV … trong hệ thống."; "MSSV … không có trong danh sách lớp này." | `students`, `enrollments`, `sessions`, `attendance` |
| 3 | `myGrades` | `token` (token xem điểm, KHÔNG nhận `mssv`) | `{ mssv, classes: [{classCode,courseName,courseCode,columns:[{name,weight,score}],average,weightDone,weightTotal}] }` | "Phiên xem điểm đã hết hạn. Vui lòng xin mã mới." | `auth_tokens`(Kind=GRADE), `classes`, `courses`, `grade_columns`, `grades`, `enrollments` |
| 4 | `liveRoster` | `token` (giảng viên), `sessionId` | `{ sessionId, counts, rows, absent, deviceAlerts, gpsAlerts }` | "Phiên đăng nhập đã hết hạn…"; "Tài khoản không có quyền…"; "Không tìm thấy buổi học …"; "Bạn không có quyền thao tác trên lớp này." | `auth_tokens`(LECTURER), `sessions`, `classes`, `students`, `attendance`, `enrollments` |
| 5 | `listClasses` | `token` | mảng lớp (lọc theo `LecturerID` nếu role LECTURER; ADMIN thấy hết — D.9) | như trên (role LECTURER/ADMIN) | `classes` |
| 6 | `listSessions` | `token`, `classId` | mảng buổi học, sort theo `SessionNo` | như trên + `assertClassAccess` | `sessions`, `classes` |

### POST — mọi thao tác GHI, mật khẩu không bao giờ qua GET

| # | action | Tham số vào | `data` trả về | Mã lỗi / thông báo (giữ nguyên) | Bảng chính |
|---|---|---|---|---|---|
| 7 | `login` | `username`, `password` | `{ token, role, fullName }` | "Sai tên đăng nhập hoặc mật khẩu." (dùng chung cho cả hai trường hợp — không tiết lộ tài khoản tồn tại) | `users`, `auth_tokens`(LECTURER, TTL 6h) |
| 8 | `logout` | `token` | `{ ok: true }` | — | `auth_tokens` (xoá dòng) |
| 9 | `requestGradeCode` | `mssv` | `{ message }` — **luôn** một thông báo trung lập dù MSSV có tồn tại hay không | Thông báo trung lập; riêng "Bạn đã xin mã quá nhiều lần hôm nay…" và "Hệ thống đã hết lượt gửi email…" là hai trường hợp DUY NHẤT trả `ok:false` thật | `students`, `grade_codes` |
| 10 | `verifyGradeCode` | `mssv`, `code` | `{ token, mssv, fullName }` | "Mã đã hết hạn hoặc chưa được gửi…"; "Nhập sai quá nhiều lần. Mã đã bị huỷ…"; "Mã không đúng. Còn N lần thử." | `grade_codes`, `students`, `auth_tokens`(GRADE, TTL 30 phút) |
| 11 | `openAttendance` | `token`, `sessionId`, `presentMinutes?`, `windowMinutes?` | `{ code, sessionId, startTime, lateAfter, endTime }` | role LECTURER/ADMIN + `assertClassAccess`; "Không tìm thấy buổi học …" | `attendance_keys` (đóng mọi mã `OPEN` cũ của session, insert mã mới), `sessions`, `classes` |
| 12 | `closeAttendance` | `token`, `sessionId` | `{ sessionId, markedAbsent }` | như trên | `attendance_keys` (đóng), `attendance` (insert `ABSENT` cho SV ghi danh chưa check-in), `enrollments` |
| 13 | `checkin` | `mssv`, `code`, `lat`, `lng`, `accuracy`, `ip`, `os`, `browser`, `deviceType`, `deviceHash` | `{ mssv, fullName, classId, status, statusText, checkInTime, action, gpsFlag, distanceM }` | "Mã điểm danh phải gồm 4 ký tự chữ và số."; "Mã không đúng hoặc buổi điểm danh đã đóng."; "Đã hết hạn điểm danh cho buổi này."; + lỗi từ `identifyStudent` (mục `studentHistory`) | `attendance_keys`, `sessions`, `classes`, `students`, `enrollments`, `attendance` (upsert qua `UNIQUE KEY uq_att_student_session`) |

## 6. Auth & token — thay `CacheService` bằng bảng MySQL, `LockService` bằng transaction

- **`LockService.getScriptLock()`** (D.6, chống ghi trùng khi nhiều SV gửi
  cùng lúc) → PHP: bọc mọi thao tác ghi trong `PDO` transaction
  (`beginTransaction()`/`commit()`/`rollBack()`), dựa vào `UNIQUE KEY` của
  MySQL để tự chặn trùng thay vì tự cài khoá tiến trình. `checkin` dùng
  `INSERT ... ON DUPLICATE KEY UPDATE` trên `(StudentID, SessionID)` để giữ
  đúng ngữ nghĩa `upsert()` cũ.
- **Token giảng viên** (`CacheService` key `tok_<uuid>`, TTL 6 giờ,
  `gas/03-Auth.gs` → `AuthService.login`) → bảng `auth_tokens`:
  `Token = bin2hex(random_bytes(32))`, `Kind='LECTURER'`, `SubjectID=UserID`,
  `ExpiresAt = NOW() + INTERVAL 6 HOUR`. `requireRole()` PHP: `SELECT` theo
  `Token`, kiểm `ExpiresAt > NOW()`, kiểm `Role` nằm trong danh sách cho phép
  — ném lỗi đúng 2 thông báo cũ ("Phiên đăng nhập đã hết hạn…" / "Tài khoản
  không có quyền…").
- **Token xem điểm** (`CacheService` key `gtok_<uuid>`, TTL 30 phút,
  `gas/08-GradeService.gs` → `GradeAuth.verifyCode`) → cùng bảng
  `auth_tokens`, `Kind='GRADE'`, `SubjectID=StudentID`, TTL 1800 giây.
- **Đăng nhập & rehash mật khẩu** (`hashPassword_` cũ = `sha256(salt + '|' + plain)`
  dạng hex, `gas/03-Auth.gs`): khi `login` nhận `password`, PHP thử theo thứ tự:
  1. Nếu `PasswordHash` **không** có tiền tố `$2y$`/`$2a$`/`$argon2` (tức còn
     là hash sha256 cũ) → tính `hash('sha256', $user['Salt'] . '|' . $password)`,
     so với `PasswordHash`. Khớp thì coi như đăng nhập đúng, **ngay lập tức**
     `UPDATE users SET PasswordHash = password_hash($password, PASSWORD_DEFAULT), Salt = NULL`
     (rehash một lần, xoá Salt để đánh dấu đã chuyển hẳn sang bcrypt/argon2).
  2. Nếu `PasswordHash` đã là bcrypt/argon2 (đã rehash trước đó, hoặc tài
     khoản tạo mới bằng PHP) → dùng thẳng `password_verify($password, $hash)`.
  3. Sai ở cả hai bước → thông báo trung lập "Sai tên đăng nhập hoặc mật khẩu."
     giống hệt bản cũ.

## 7. Sáu lớp bù D.8 (`docs/01-THIETKE-kien-truc.md` phần D.8) → PHP

| Lớp | Cơ chế cũ (Apps Script) | Cơ chế PHP |
|---|---|---|
| 1. Mã ngẫu nhiên, hạn giờ ngắn | `generateUniqueCode_()` + `attendance_keys.EndTime` | Y hệt logic, cùng bảng chữ `CONFIG.CODE_ALPHABET`, cùng độ dài 4 — đưa vào `config/app.php` không viết cứng trong code |
| 2. Kiểm tra ghi danh | `identifyStudent()` tra `enrollments` | `SELECT` `enrollments` theo `StudentID+ClassID+Status=ACTIVE`, y hệt điều kiện |
| 3. Một SV/một session một bản ghi | `upsert()` trong `withLock_` | `UNIQUE KEY uq_att_student_session` + `INSERT ... ON DUPLICATE KEY UPDATE` trong transaction |
| 4. DeviceHash cảnh báo trùng thiết bị | so `DeviceHash` các dòng cùng `SessionID` | `SELECT` các dòng `attendance` cùng `SessionID, DeviceHash` khác `StudentID` — giữ nguyên logic đếm `conflicts` |
| 5. Giảng viên xem danh sách real-time | `liveRoster()` | Y hệt, PHP trả cùng cấu trúc `rows/absent/deviceAlerts/gpsAlerts` |
| 6. Audit log mọi lần điểm danh/sửa | `logAudit()` | `INSERT audit_log` — PHP: không được để lỗi ghi log làm hỏng nghiệp vụ chính (bọc `try/catch` riêng, giống `logAudit()` cũ có `try/catch` không ném lỗi ra ngoài) |

## 8. Cấu hình & bí mật

Bí mật đọc từ `../private/config.php` — **nằm ngoài `public_html`** (rule 4
của dự án). Repo chỉ commit `db/config.sample.php` với placeholder, KHÔNG
bao giờ commit file thật:

```php
<?php
// ../private/config.php — NẰM NGOÀI public_html. KHÔNG commit file thật.
// Chỉ commit bản mẫu tại db/config.sample.php với giá trị placeholder.
return [
    'db' => [
        'host'    => 'localhost',
        'name'    => 'u000000000_dbname',   // đổi theo CSDL thật trên hPanel
        'user'    => 'u000000000_dbuser',
        'pass'    => 'CHANGE_ME',
        'charset' => 'utf8mb4',
    ],
    'smtp' => [
        'host' => 'smtp.hostinger.com',
        'port' => 465,
        'user' => 'noreply@diemdanhsv.com', // GĐ3: dùng stub cho đến khi thầy tạo hộp thư thật
        'pass' => 'CHANGE_ME',
    ],
    'app' => [
        'timezone'      => 'Asia/Ho_Chi_Minh',
        'code_length'   => 4,
        'code_alphabet' => 'ACDEFGHJKMNPQRTUVWXY34679', // bỏ 0/O,1/I/L,2/Z,5/S,8/B — giữ nguyên bộ ký tự cũ
        'present_minutes' => 5,
        'window_minutes'  => 15,
        'gps_accuracy_limit_m' => 150,
        'default_radius_m'     => 100,
    ],
];
```

`api/index.php` (GĐ2) nạp bằng `require dirname(__DIR__) . '/../private/config.php';`
— đường dẫn chính xác phụ thuộc cấu trúc thư mục Hostinger thật, cần xác
nhận khi viết code GĐ2 (không đoán ở đây).

## 9. Danh sách hàm quản trị GAS → nơi chuyển tới

| Hàm/Script GAS | Việc làm | Nơi chuyển tới (theo PLAN) |
|---|---|---|
| `initializeSpreadsheet()`, `verifySchema()` (`01-Schema.gs`) | Tạo/kiểm schema | **Không cần port** — `db/schema.sql` đã chạy thật, xác nhận đủ 14 bảng |
| `createLecturerAccounts()` (`03-Auth.gs`) | Tạo tài khoản giảng viên/admin hàng loạt, chạy tay từ trình soạn thảo | `tools/create-lecturer.php` — **CLI only** (`php_sapi_name()==='cli'`), không lộ ra web (rule 3) |
| `07-Import.gs` (`runImport`/`previewImport`) | Di dời dữ liệu từ Sheets sang CSDL mới | `tools/import.php` — CLI, idempotent, dry-run, đối soát số dòng (GĐ6, THẦY chạy qua SSH) |
| `09-GradeImport.gs`, `10-AttendanceScore.gs` | Nhập điểm CSV hàng loạt, tính điểm chuyên cần | Xem ghi chú **mục 10** — cần thầy xác nhận CLI hay web có xác thực vai trò |
| `11-FixRealData.gs` | Sửa dữ liệu thật một lần (định dạng `LecturerID`…) | `tools/fix-data.php` — CLI only, chạy một lần rồi bỏ (giống bản gốc) |
| `12-ManualAttendance.gs` | Giảng viên nhập tay điểm danh cho buổi cũ | Web, có `requireRole`+`assertClassAccess` — không phải "code quản trị" theo nghĩa rule 3 (xem mục 10) |

## 10. Việc còn để ngỏ — cần thầy xác nhận trước khi code GĐ2–GĐ8

1. ~~**`grade_codes` thiếu 2 cột so với logic cũ.**~~ **ĐÃ GIẢI QUYẾT ở GĐ3.**
   `GradeAuth` cũ (`gas/08-GradeService.gs`) dùng 4 khoá cache riêng: mã
   (`gcode_`), số lần sai (`gtry_`), **đã gửi trong 60 giây gần nhất hay
   chưa** (`gsent_`), và **đếm số lần gửi trong 24 giờ** (`gcount_`, giới
   hạn `MAX_SENDS_PER_DAY=5`). GĐ3 thêm 3 cột vào `grade_codes`:
   `SendCount` (thay `gcount_`), `WindowStartAt` (mốc bắt đầu cửa sổ 24h
   CUỘN — không theo ngày lịch, đúng TTL 86400s của cache cũ),
   `LastSentAt` (thay `gsent_`, cooldown 60s). `db/schema.sql` đã cập nhật
   cho CSDL mới; CSDL đã tồn tại trên host thật (chưa có dữ liệu) dùng
   `db/migrations/002-grade-codes-send-tracking.sql`. Chi tiết luồng ở
   mục 12 dưới đây.
2. **Ranh giới "code quản trị chỉ CLI" (rule 3) áp dụng tới đâu.** Rule 3 ghi
   "Code quản trị (import điểm, sửa dữ liệu hàng loạt, tạo tài khoản giảng
   viên...) chỉ được viết dưới dạng script CLI trong `tools/`". Đọc theo
   nguyên văn, cụm "import điểm" có thể hiểu là *di dời/nạp hàng loạt từ
   ngoài vào* (như `07-Import.gs`, `09-GradeImport.gs` — không có xác thực
   vai trò, chạy tay từ trình soạn thảo) — khác với tính năng **giảng viên
   nhập điểm CSV cho lớp mình** mà PLAN GĐ7/8 mô tả là tính năng web bình
   thường, đi qua `requireRole`+`assertClassAccess` như mọi action khác.
   Tài liệu này tạm hiểu: **script không có ràng buộc vai trò/phạm vi lớp
   (di dời toàn CSDL, sửa dữ liệu thật một lần) → CLI trong `tools/`; tính
   năng có `token`+role LECTURER/ADMIN và giới hạn đúng lớp mình dạy → được
   phép là web action bình thường trong `api/index.php`, xác thực như 13
   action đã có.** Cách hiểu này cần thầy xác nhận trước khi bắt đầu GĐ7/8 —
   ghi vào STATE mục "chờ thầy quyết", **không tự quyết ở GĐ1**.
3. **Khiếu nại (`complaints`) chưa có action API nào** trong 13 action cũ dù
   bảng `complaints` đã có trong schema và PHẦN A của thiết kế gốc có nhắc
   "sinh viên... lựa chọn khiếu nại, nhập nội dung khiếu nại". Không có
   trong phạm vi 13 action port lần này — nếu cần, đây là action **thứ 14**,
   ngoài phạm vi GĐ1–GĐ5 hiện tại, để dành cho giai đoạn sau nếu thầy muốn.
4. **SMTP thật.** `requestGradeCode` cần gửi mail thật; theo PLAN GĐ3 vẫn
   dùng stub (ví dụ ghi log thay vì gửi) cho đến khi thầy tạo hộp thư
   `noreply@diemdanhsv.com`.

## 11. Việc tiếp theo (GĐ4)

GĐ2 (nền PHP + `ping`) và GĐ3 (`login`/`logout`/`requestGradeCode`/
`verifyGradeCode`, xem mục 12) đã xong. GĐ4 (PLAN): `openAttendance`,
`closeAttendance`, `checkin`, `liveRoster` đủ 6 lớp bù D.8 (mục 7) — cần
thêm `require_role()`/`assert_class_access()` (đọc bảng `auth_tokens`
Kind='LECTURER', port `classHasLecturer_()` từ `gas/03-Auth.gs`) mà GĐ3
chưa cần dùng tới.

## 12. Cập nhật GĐ3 — `login`/`logout`/`requestGradeCode`/`verifyGradeCode`

Port đúng theo mục 6 (rehash mật khẩu qua 2 bước) và mục 10.1 (bổ sung
`grade_codes`). Vài quyết định triển khai cụ thể, để phiên sau không phải
đọc lại code:

- **Mã xác minh xem điểm lưu HASH (`hash('sha256', $code)`) trong
  `CodeHash`**, không lưu mã thô — khác bản GAS cũ (cache lưu thô), an
  toàn hơn nếu CSDL bị lộ. Không ảnh hưởng hành vi phía người dùng.
- **`grade_codes` một dòng/sinh viên, KHÔNG xoá dòng** khi mã hết hạn hay
  đã dùng — chỉ `invalidate_grade_code()` (đặt `CodeHash=''`,
  `ExpiresAt` về quá khứ) để giữ lại `SendCount`/`WindowStartAt` cho giới
  hạn 24h. Xoá dòng ở đây sẽ vô tình reset giới hạn 5 lần/24h.
- **Cooldown 60s và giới hạn 5 lần/24h dùng `db_now()`** (giờ CSDL, không
  phải giờ PHP) để tránh lệch giờ giữa host PHP và MariaDB khi so sánh
  `LastSentAt`/`WindowStartAt`.
- **SMTP vẫn là stub** (`api/lib/mailer.php` → `mail_send()`, chỉ
  `error_log()`) — bỏ qua bước kiểm "quota gửi mail còn lại"
  (`MailApp.getRemainingDailyQuota()` cũ không có tương đương chờ SMTP
  thật). Thay thân `mail_send()` khi có hộp thư `noreply@diemdanhsv.com`
  thật, không cần đổi nơi gọi.
- **Test đã chạy trong phiên này:** `php -l` sạch trên toàn bộ file mới/sửa.
  Sandbox phiên này KHÔNG có MariaDB/MySQL cục bộ (không có mạng ra ngoài
  để cài) nên chỉ test được các hàm THUẦN không đụng CSDL (`is_modern_hash`,
  `legacy_sha256_hash` + `password_hash`/`password_verify` roundtrip,
  `generate_grade_code`, các hằng số TTL/giới hạn, công thức "còn N lần
  thử") — tất cả PASS. **Chưa test được toàn luồng qua PDO thật** (login
  với tài khoản demo, rehash mật khẩu cũ→mới, xin mã→xác minh→token, hết
  hạn token) — cần chạy trên host Hostinger hoặc máy có MariaDB trước khi
  coi GĐ3 là "Xong khi" đầy đủ theo PLAN ("Test tài khoản demo: đăng nhập,
  rehash, token hết hạn").
