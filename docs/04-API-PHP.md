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

## 11. Việc tiếp theo (GĐ10)

GĐ2 (nền PHP + `ping`), GĐ3 (`login`/`logout`/`requestGradeCode`/
`verifyGradeCode`, mục 12), GĐ4 (`openAttendance`/`closeAttendance`/
`checkin`/`liveRoster`, mục 13), GĐ5 (`studentHistory`/`myGrades`/
`listClasses`/`listSessions`, mục 14), GĐ6 (di dời dữ liệu + sao lưu,
mục 15) và GĐ7 (quản trị web tối thiểu, mục 16) đã xong — **đủ 13/13 action
cũ + 18 action quản trị** (GĐ7 mục 16, GĐ8 mục 17). Nợ GĐ5 (smoke test +
review bảo mật độc lập bằng Claude Opus, KHÔNG dùng Abacus) đã trả — xem
`docs/05-GD5-smoke-review.md`. GĐ9 (mục 18) đã xong phần code: chạy thử song
song `?api=php`, `tools/compare_gas_php.php`, review bảo mật lần 2 — còn chờ
thầy chạy checklist 7 mục trên dữ liệu thật (`docs/06-GD9-staging-checklist.md`).
GĐ10 (PLAN): cutover ngoài giờ dạy — đổi `API_URL`, giữ Apps Script dự phòng
≥ 1 tuần.

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

## 13. Cập nhật GĐ4 — `openAttendance`/`closeAttendance`/`checkin`/`liveRoster`

Port đúng theo mục 5 (bảng đối chiếu 13 action) và đủ sáu lớp bù D.8 (mục 7),
thêm `api/lib/roles.php` (mới) cho `require_role()`/`assert_class_access()`/
`identify_student()` mà GĐ3 chưa cần. Vài quyết định triển khai cụ thể, để
phiên sau không phải đọc lại code:

- **`require_role()` đọc bảng `auth_tokens` JOIN `users`** thay
  `CacheService.get('tok_'+token)` cũ — không lưu lại `role`/`name` trong
  chính token như cache cũ, tra trực tiếp `users.Role`/`users.FullName` qua
  `auth_tokens.SubjectID` mỗi lần gọi. **Không kiểm lại `users.Status`** ở
  đây, giữ đúng hành vi bản gốc (token còn hạn thì còn dùng được tới khi hết
  hạn/logout, kể cả nếu tài khoản bị khoá sau đó) — cần thầy xác nhận đây là
  hành vi mong muốn, hay nên thêm kiểm `Status='ACTIVE'` (siết chặt hơn bản
  gốc); tạm giữ nguyên bản gốc theo đúng rule "giữ nguyên hành vi" của PLAN.
- **Định dạng ngày giờ trả về cho frontend PHẢI có ký tự `'T'`**
  (`"Y-m-d\TH:i:s"`, hàm mới `db_stamp_to_iso()` trong `api/lib/attendance.php`)
  — KHÁC với định dạng DATETIME mặc định của MySQL (`"Y-m-d H:i:s"`, dùng
  dấu cách). `js/models/Attendance.js` (`timeOnly()`: `indexOf('T')`) và
  `js/views/StudentView.js` (`checkInTime.replace('T',' ')`) đọc đúng ký tự
  `'T'` này để tách giờ khỏi ngày — nếu trả nguyên định dạng MySQL, trang sẽ
  hiển thị cả ngày lẫn giờ thay vì chỉ "HH:mm". Áp dụng cho `startTime`,
  `lateAfter`, `endTime` (`openAttendance`) và `checkInTime`
  (`checkin`, `liveRoster`). `action_ping()` (GĐ2) đã theo đúng quy ước này
  từ trước, GĐ4 chỉ làm tương tự cho các trường mới.
- **Trường GPS/khoảng cách thiếu (`lat`/`lng`/`accuracy`/`distanceM`) trả
  về `''` (chuỗi rỗng) trong JSON, KHÔNG phải `null`** — khác quy ước PHP
  thông thường nhưng đúng hành vi Sheets cũ (ô trống = `''`).
  `js/models/Attendance.js` so sánh `data.distanceM === '' ? null :
  Number(...)`; nếu PHP trả `null` thay vì `''`, phép so sánh `=== ''` sai,
  `Number(null)` ra `0`, hiển thị nhầm khoảng cách 0m thay vì "không có GPS".
  Hàm mới `blank_if_null()` chuyển `null` → `''` CHỈ khi build JSON trả về;
  khi ghi CSDL (cột `DECIMAL NULL`) vẫn dùng `null` thật (PDO bind đúng
  `NULL`), không dùng `''` (MySQL sẽ báo lỗi "Incorrect decimal value").
- **`checkin` upsert vào `attendance`:** SELECT trước để quyết định
  `action` (`'INSERTED'`/`'UPDATED'`, giữ đúng chữ hoa như
  `gas/02-Repo.gs` `upsert()`), rồi INSERT/UPDATE tương ứng trong
  `db_transaction()`; nếu INSERT đụng `UNIQUE KEY uq_att_student_session`
  (SQLSTATE `23000`, hai request cùng MSSV/buổi lọt qua đồng thời — hiếm),
  bắt lỗi rồi UPDATE lại, coi như `'UPDATED'`. UNIQUE KEY là chốt chặn THẬT
  (D.8 lớp 3) — SELECT trước chỉ để đặt tên `action` cho đúng, không phải
  cơ chế chống trùng chính (khác `withLock_()` cũ dùng script lock toàn
  cục — MySQL không có tương đương rẻ, đây là lý do `api/lib/db.php` đã ghi
  chú "dựa vào UNIQUE KEY... thay vì tự cài khoá tiến trình" từ GĐ2).
- **`openAttendance` tính `startTime`/`lateAfter`/`endTime` bằng PHP
  (`DateTimeImmutable` khởi tạo từ `db_now()`)** thay vì `NOW()`/`DATE_ADD()`
  trong SQL — tránh phải SELECT lại sau INSERT để lấy giá trị đã tính, đồng
  thời vẫn dùng giờ CSDL (`db_now()`) làm mốc để tránh lệch giờ PHP/MySQL
  (đúng nguyên tắc mục 6, GĐ3 đã áp dụng cho `requestGradeCode`).
- **Test đã chạy trong phiên này:** `php -l` sạch trên toàn bộ file
  mới/sửa. Test thuần (không đụng CSDL) cho `haversine_meters()`,
  `evaluate_gps()` (5 nhánh: thiếu toạ độ, accuracy vượt hạn mức, lớp chưa
  khai toạ độ phòng, trong bán kính, ngoài bán kính), `class_has_lecturer()`
  (đủ các dấu phân cách `,;. ` + không khớp nhầm UserID), `db_stamp_to_iso()`,
  `blank_if_null()` — tất cả PASS. `generate_unique_code()` test bằng PDO
  SQLite trong bộ nhớ (chỉ SELECT đơn giản, không cú pháp riêng MySQL nên
  dùng SQLite thay MariaDB được ở mức này): 20 mã liên tiếp không trùng
  đúng bảng chữ/độ dài, mã `CLOSED` được phép tái sử dụng (không bị coi là
  "đang chiếm"), hết mã khi bảng chữ cạn → ném lỗi rõ ràng — tất cả PASS.
  Test định tuyến `api_dispatch()` (sai phương thức GET/POST, action lạ,
  action chưa triển khai, `checkin` sai định dạng mã) xác nhận các action
  mới trả đúng lỗi TRƯỚC khi chạm CSDL — tất cả PASS.
  **Sandbox phiên này vẫn KHÔNG có MariaDB/MySQL cục bộ** (không có mạng ra
  ngoài để cài, giống GĐ2/GĐ3) nên **chưa test được toàn luồng qua PDO thật**
  của `openAttendance`/`closeAttendance`/`checkin`/`liveRoster` (mở mã →
  check-in đúng/trễ/hết hạn → đóng đánh dấu vắng → danh sách real-time đúng
  số đếm) — tiêu chí "Xong khi" của GĐ4 trong PLAN
  ("`runSmokeTest` chuyển sang PHP, ca phải từ chối đều bị từ chối") **cần
  chạy trên host Hostinger hoặc máy có MariaDB trước khi coi GĐ4 hoàn tất
  đầy đủ.**

## 14. Cập nhật GĐ5 — `studentHistory`/`myGrades`/`listClasses`/`listSessions`

Port đúng theo mục 5 (bảng đối chiếu 13 action). `api/lib/queries.php`
(mới) — 4 action ĐỌC cuối cùng, tái dùng `identify_student()`/`require_role()`/
`assert_class_access()`/`class_has_lecturer()` đã có từ GĐ4
(`api/lib/roles.php`). `API_VERSION` → `php-0.4`. Vài quyết định triển khai
cụ thể, để phiên sau không phải đọc lại code:

- **`require_student()` (mới, `api/lib/roles.php`)** — xác thực token xem
  điểm (`Kind='GRADE'` trong `auth_tokens`, cấp bởi `verifyGradeCode` GĐ3),
  thay `GradeAuth.requireStudent()` cũ (`CacheService.get('gtok_'+token)`).
  Đặt cạnh `require_role()` thay vì trong `gradeauth.php` vì cùng là "xác
  thực danh tính từ token" — `gradeauth.php` chỉ còn phần *cấp* token
  (`requestGradeCode`/`verifyGradeCode`), `roles.php` giữ phần *đọc lại*
  token (cả LECTURER lẫn GRADE) cho các action sau.
- **`studentHistory` trả ĐỦ mọi buổi `ACTIVE` của lớp**, kể cả buổi sinh
  viên chưa check-in — không chỉ những buổi đã có dòng `attendance`. Buổi
  chưa check-in → `status: 'ABSENT'`, `checkInTime: ''` (đúng hành vi
  `byId[...]` trả `undefined` của bản gas cũ, gas/04-AttendanceService.gs
  dòng 294-305). `checkInTime` dùng lại `db_stamp_to_iso()` (GĐ4) để giữ
  ký tự `'T'` — cùng lý do định dạng ngày giờ đã ghi ở mục 13.
- **`myGrades` không nhận `mssv`** — danh tính lấy từ token GRADE
  (`require_student()`), đúng thiết kế "xác minh qua email" (mục 6). Điểm
  tra theo `UNIQUE KEY uq_grade (StudentID, GradeColumnID)` — không cần
  thêm điều kiện `ClassID` (đã ghi ở mục 4, dòng `10_GRADES`→`grades`).
  `average` chỉ tính trên các cột ĐÃ có điểm (`weightDone`), giữ đúng ngữ
  nghĩa "điểm tạm" của bản gas cũ (dòng 236) — cột chưa chấm trả
  `score: null`, không tính là 0.
- **`listClasses`/`listSessions` giữ nguyên [D.9]**: `listClasses` lọc theo
  `class_has_lecturer()` cho LECTURER (danh sách nhiều `UserID` cách nhau
  dấu phẩy trong `LecturerID`), ADMIN thấy hết; `listSessions` gọi
  `assert_class_access()` NGAY SAU `require_role()`, TRƯỚC khi đọc bất cứ
  gì — LECTURER không đứng tên lớp bị từ chối rõ ràng (`"Bạn không có
  quyền thao tác trên lớp này."`) thay vì âm thầm trả mảng rỗng.
- **Test đã chạy trong phiên này:** `php -l` sạch trên toàn bộ file
  mới/sửa. Sandbox phiên này **vẫn KHÔNG có MariaDB/MySQL cục bộ** (không
  có mạng ra ngoài để cài, giống GĐ2-GĐ4) nên test bằng PDO SQLite trong
  file tạm (không phải cú pháp riêng MySQL — 4 action GĐ5 chỉ dùng
  `SELECT`/`JOIN`/`ORDER BY`, không đụng `NOW()`/`ON DUPLICATE KEY` như các
  action ghi; đăng ký thêm hàm `NOW()` cho SQLite chỉ để test
  `require_role()`/`require_student()`): dữ liệu demo gồm 3 lớp (1 lớp
  nhiều giảng viên, 1 lớp INACTIVE), 1 buổi học INACTIVE, 2 sinh viên demo
  (1 chưa check-in buổi nào, 1 mới có điểm 1/2 cột) — **tất cả PASS**:
  `studentHistory` trả đúng buổi đã điểm danh/chưa điểm danh, đúng 3 lỗi
  MSSV (sai định dạng/không tồn tại/không ghi danh lớp), lọc đúng buổi
  INACTIVE; `myGrades` trả đúng điểm đã có + cột chưa chấm = null, đúng
  `average`/`weightDone`, từ chối đúng khi token hết hạn/thiếu, lọc đúng
  cột điểm INACTIVE; `listClasses` lọc đúng theo giảng viên (kể cả lớp
  nhiều giảng viên) và ẩn đúng lớp INACTIVE, ADMIN thấy hết; `listSessions`
  từ chối đúng khi LECTURER không đứng tên lớp, ADMIN truy cập được mọi
  lớp; định tuyến `api_dispatch()` xác nhận cả 13/13 action đã có code
  (không còn action nào trả "chưa triển khai"), action lạ/rỗng vẫn đúng lỗi
  cũ, sai phương thức GET/POST vẫn bị từ chối đúng thông báo. **Chưa chạy
  được smoke test 13/13 trên CSDL MySQL thật** (tiêu chí "Xong khi" của GĐ5
  trong PLAN) và **chưa có review bảo mật độc lập** (mục 11) — cần làm
  trên host Hostinger hoặc máy có MariaDB, và một phiên riêng cho review,
  trước khi coi GĐ5 hoàn tất đầy đủ theo PLAN.

## 15. Cập nhật GĐ6 — di dời dữ liệu (`tools/import.php`) + sao lưu (`tools/backup.php`) + xuất JSON (`gas/13-ExportJSON.gs`)

Giai đoạn 6/10 của PLAN. Ba phần, đúng mô tả PLAN ("hàm Apps Script xuất
JSON ra Drive riêng tư; `tools/import.php` CLI idempotent/dry-run/đối soát
số dòng; cron mysqldump sao lưu — Claude viết, THẦY chạy SSH"):

- **`gas/13-ExportJSON.gs`** (mới) — `exportAllToDriveJSON()`, chạy TAY từ
  trình soạn thảo Apps Script. Xuất cả 12 sheet vào MỘT file JSON (không
  phải 12 file rời — tránh lệch nhau nếu có người đang thao tác trên sheet
  lúc xuất) trong thư mục Drive riêng tư (`CONFIG.EXPORT_FOLDER_ID`, thêm
  vào `gas/00-Config.gs`, THẦY tự tạo thư mục rồi điền ID — để trống thì
  hàm báo lỗi rõ ràng thay vì ghi nhầm chỗ). Tên cột trong sheet đã khớp
  1-1 với tên cột MySQL từ đầu (chú thích đầu `db/schema.sql`) nên không
  cần ánh xạ lại tên trường, chỉ đổi tên SHEET → tên bảng MySQL (lowercase)
  theo đúng thứ tự phụ thuộc khoá ngoại. Không xuất `auth_tokens`/
  `grade_codes` (2 bảng mới của bản PHP, không có sheet nguồn).
- **`tools/import.php`** (mới) — CLI only. Đọc file JSON xuất ở trên, với
  mỗi bảng (đúng thứ tự phụ thuộc khoá ngoại): `--dry-run` chỉ SELECT khoá
  chính hiện có rồi so với file để báo "sẽ thêm mới / sẽ cập nhật" theo
  từng bảng, cộng cảnh báo tham chiếu khoá ngoại tới ID không có cả trong
  CSDL lẫn trong file (ví dụ `LecturerID` trỏ tới một `UserID` không tồn
  tại) — KHÔNG ghi gì. Khi chạy thật (`--yes`, bắt buộc có cờ này mới ghi):
  một `db_transaction()` DUY NHẤT bọc toàn bộ 12 bảng — INSERT ... ON
  DUPLICATE KEY UPDATE theo đúng khoá chính (ID đã gán sẵn từ
  `newId()`/Apps Script, KHÔNG sinh ID mới) nên **idempotent**: chạy lại
  cùng file nhiều lần không tạo dòng trùng, chỉ cập nhật. Nếu một dòng vi
  phạm khoá ngoại thật (MySQL từ chối), toàn bộ transaction rollback —
  "tất cả hoặc không gì cả", không để CSDL ở trạng thái nửa vời. Chuẩn hoá
  dữ liệu trước khi ghi: ngày/giờ GAS dạng `yyyy-MM-ddTHH:mm:ss` (ký tự
  `'T'`, xem `gas/02-Repo.gs` `SheetRepo.all()`) → `'Y-m-d H:i:s'` của
  MySQL; chuỗi rỗng ở cột số/ngày/giờ → `NULL`; chuỗi rỗng ở cột văn bản
  giữ nguyên `''` (không ép `NULL`, khớp các cột `NOT NULL` như
  `Username`/`FullName`). `--only=table1,table2` để chạy/kiểm từng phần.
- **`tools/backup.php`** (mới) — CLI only, `mysqldump --single-transaction
  --quick --routines --triggers`, nén gzip, lưu vào `../private/backups/`
  (ngoài `public_html`, rule 4). KHÔNG truyền mật khẩu qua `-p<mk>` trên
  dòng lệnh (lộ qua `ps aux`) — tự tạo file `--defaults-extra-file` tạm
  thời quyền `0600`, xoá ngay sau khi dump xong (kể cả khi dump lỗi).
  `--keep=N` (mặc định 14) tự xoá bản cũ quá hạn giữ theo tên file (có
  timestamp nên sort tên = sort thời gian). Mọi lần chạy (kể cả lỗi) ghi
  một dòng vào `../private/backups/backup.log`. Đặt lịch qua hPanel Cron
  Jobs — đường dẫn `php`/thư mục chính xác trên host thật ghi ở đầu file,
  **cần thầy xác nhận khi đặt cron**, giống cách `config.php` (mục 8) cần
  xác nhận cấu trúc thư mục.
- **`gas/00-Config.gs`** — thêm `CONFIG.EXPORT_FOLDER_ID` (placeholder rỗng).

### Giới hạn kiểm thử trong phiên này — CẦN THẦY XÁC NHẬN LẠI

- `php -l` sạch trên `tools/import.php` và `tools/backup.php`.
- Sandbox phiên này **vẫn không có MariaDB/MySQL cục bộ** (giống GĐ2–GĐ5)
  nên **chưa chạy được `tools/import.php` với CSDL MySQL thật**. Đã test
  tách riêng: (1) các hàm chuẩn hoá dữ liệu thuần (`import_norm_datetime`/
  `import_norm_date`/`import_norm_number`/`import_norm_text`) với các ca
  biên (rỗng, có `'T'`, chỉ ngày, `dd/MM/yyyy`) — PASS; (2) logic đối soát
  dry-run (đếm "sẽ thêm mới/sẽ cập nhật", cảnh báo khoá ngoại lạ, và tính
  idempotent khi chạy lại cùng dữ liệu) bằng PDO SQLite với dữ liệu demo rõ
  ràng (`USR_DEMO1`, `STD_DEMO1`...) mô phỏng đúng luồng 5 bảng có phụ
  thuộc khoá ngoại (`users`→`classes`→`enrollments`) — PASS, kể cả ca cố ý
  có một `LecturerID` trỏ tới ID không tồn tại (đúng 1 cảnh báo FK, dòng đó
  bị "chặn" không ghi ở lần mô phỏng ghi, dry-run lần sau vẫn báo đúng còn
  thiếu). Câu lệnh SQL thật dùng cú pháp riêng MySQL (`ON DUPLICATE KEY
  UPDATE`) — SQLite không hỗ trợ cú pháp này nên **chỉ kiểm được bằng
  `php -l` + đọc lại tay**, chưa chạy thật.
- `tools/backup.php` đã test với một `mysqldump` giả lập (demo, không đụng
  CSDL/mật khẩu thật) trong thư mục mô phỏng cấu trúc `public_html/`
  + `private/`: xác nhận nén gzip đúng, file option tạm bị xoá sau mỗi lần
  chạy (kể cả khi giả lập lỗi), dọn bản cũ theo `--keep=N` hoạt động đúng,
  và trường hợp `mysqldump` ghi lỗi ra stderr thì KHÔNG để lại file dump
  rỗng/hỏng. **Chưa chạy được với `mysqldump` thật trên host** (cần xác
  nhận `mysqldump` có sẵn trong PATH của SSH session trên Hostinger).
- Vì vậy tiêu chí "Xong khi" của GĐ6 trong PLAN (**"Dry-run trên demo khớp
  số dòng; có bản sao lưu"**) **chưa được xác nhận trên CSDL thật** — cần
  thầy chạy `tools/import.php --dry-run` và `tools/backup.php` qua SSH
  trên host Hostinger (hoặc máy có MariaDB) trước khi coi GĐ6 hoàn tất.

### Test plan

- [ ] Thầy tạo thư mục Drive riêng tư, điền `CONFIG.EXPORT_FOLDER_ID`
      (`gas/00-Config.gs`), chạy thử `previewExportTable('students')` rồi
      `exportAllToDriveJSON()` trong trình soạn thảo Apps Script.
- [ ] Tải file JSON xuất được lên host (SFTP) vào `../private/import/`,
      SSH vào host chạy `php tools/import.php --file=... --dry-run`, đọc
      kỹ bảng đối soát + cảnh báo FK trước khi quyết định chạy thật.
- [ ] Chạy thật (`--yes`) trên CSDL demo/thử trước (KHÔNG chạy thẳng lên
      CSDL có dữ liệu thật lần đầu) để xác nhận số dòng khớp Google Sheets
      gốc.
- [ ] Chạy thử `php tools/backup.php --dry-run` rồi `php tools/backup.php`
      qua SSH, xác nhận file `.sql.gz` giải nén được và có dữ liệu đúng,
      rồi mới đặt lịch cron trong hPanel.
- [ ] Sau khi merge, GĐ7 (quản trị web tối thiểu: CRUD môn/lớp/SV, nhập CSV
      danh sách lớp) sẽ dựa trên nền này.
## 16. Cập nhật GĐ7 — quản trị web tối thiểu (`api/lib/admin.php`, `pages/admin.html`)

Giai đoạn 7/10 của PLAN: "CRUD môn/lớp/SV, nhập CSV danh sách lớp". Hai câu
hỏi để ngỏ ở mục 10.2 thầy đã quyết ngày 01/10/2026:

- **Ranh giới rule 3:** tính năng có token LECTURER/ADMIN, kiểm vai trò và
  giới hạn đúng lớp mình dạy (`assert_class_access`) → được làm trên web.
  Tạo tài khoản giảng viên, di dời/nhập điểm hàng loạt không ràng buộc lớp,
  sửa dữ liệu thật một lần → vẫn CLI trong `tools/`.
- **Phân quyền:** ADMIN tạo/sửa mọi môn, lớp, sinh viên, buổi học và gán
  giảng viên. LECTURER chỉ lớp mình đứng tên: sửa thông tin lớp (không đổi
  môn/giảng viên), thêm/bớt sinh viên, nhập CSV, buổi học.

### 12 action mới (ngoài 13 action cũ, cùng phong bì, đều cần `token`)

| Action | Method | Vai trò | Ghi chú |
|---|---|---|---|
| `adminListCourses` | GET | LECTURER/ADMIN | Mọi môn, kể cả INACTIVE |
| `adminListLecturers` | GET | ADMIN | `UserID, Username, FullName, Role, Status` — không trả hash |
| `adminListClasses` | GET | LECTURER/ADMIN | Cả lớp INACTIVE, kèm `CourseCode/CourseName/LecturerNames`; [D.9] LECTURER chỉ thấy lớp mình |
| `adminListRoster` | GET | LECTURER/ADMIN | `classId`; ghi danh cả INACTIVE, có `EnrollStatus` |
| `adminListSessions` | GET | LECTURER/ADMIN | `classId`; cả buổi INACTIVE |
| `adminSaveCourse` | POST | ADMIN | `courseId` (rỗng = tạo), `courseCode*`, `courseName*`, `credits`, `theoryHours`, `practiceHours`, `status` |
| `adminSaveClass` | POST | LECTURER/ADMIN | `classId` (rỗng = tạo, chỉ ADMIN), `classCode*`, `semester`, `academicYear`, `roomLat`, `roomLng`, `allowedRadiusM`, `status`; ADMIN thêm `courseId`, `lecturerIds` (nhiều UserID cách dấu phẩy — đúng quy ước `class_has_lecturer()`) |
| `adminSaveSession` | POST | LECTURER/ADMIN | `classId*`, `sessionId` (rỗng = tạo), `sessionNo*`, `date` (YYYY-MM-DD), `startTime`/`endTime` (HH:MM), `content`, `status`; UPDATE kèm `ClassID` nên không sửa nhầm buổi lớp khác |
| `adminSaveStudent` | POST | LECTURER/ADMIN | `mssv*`, `fullName`, `email`, `status`; LECTURER chỉ sinh viên đang ghi danh ở lớp mình |
| `adminEnroll` | POST | LECTURER/ADMIN | `classId*`, `mssv*`, `fullName` (bắt buộc nếu SV chưa có), `email`; trả `student`/`enroll` = INSERTED/UPDATED/UNCHANGED/REACTIVATED |
| `adminUnenroll` | POST | LECTURER/ADMIN | `classId*`, `mssv*`; đổi `enrollments.Status` → INACTIVE, **không xoá** |
| `adminImportRoster` | POST | LECTURER/ADMIN | `classId*`, `csv*` (nguyên văn file), `dryRun`; xem dưới |

Mọi thao tác ghi đều ghi `audit_log` (`ADMIN_*`). Không action nào xoá dòng —
chỉ đổi `Status` (attendance/grades có khoá ngoại; giữ lịch sử để phân xử).

### Nhập CSV danh sách lớp (`adminImportRoster`)

Thay `previewImport()`/`runImport()` của `gas/07-Import.gs`, nhưng cho MỘT
lớp đã chọn và có xác thực. Hai bước đúng như bản cũ: `dryRun:true` chỉ phân
tích và trả báo cáo (số dòng hợp lệ, sẽ tạo bao nhiêu SV mới, bao nhiêu ghi
danh mới, danh sách lỗi theo số dòng, 20 dòng xem trước); `dryRun:false` chỉ
ghi khi **không còn dòng lỗi**, trong MỘT transaction. Chạy lại cùng file an
toàn (upsert theo MSSV; ghi danh đã có thì bỏ qua; ghi danh INACTIVE thì bật
lại). Tiêu đề cột nhận theo bí danh không dấu như `COLUMN_ALIASES` cũ (MSSV |
Họ tên hoặc Họ đệm + Tên | Email); tự nhận dấu phân cách `,` `;` tab và BOM
UTF-8 của Excel. Tối đa 1000 dòng/lần.

### Giao diện

`pages/admin.html` + `js/views/AdminView.js` + `js/controllers/AdminController.js`,
cùng `APIService` và kiểu Tailwind như các trang hiện có. Đăng nhập dùng tài
khoản giảng viên, dùng chung `sessionStorage` với `pages/lecturer.html` (thêm
khoá `dd_role` để ẩn/hiện phần chỉ ADMIN — máy chủ vẫn là nơi kiểm quyền
thật). Bốn tab: Lớp học, Sinh viên của lớp (thêm từng người / gỡ / nhập CSV
hai bước), Buổi học, Môn học.

### Test đã chạy trong phiên này

- `php -l` sạch; `node --check` sạch cho 3 file JS.
- `admin_parse_roster_csv()` chạy thật bằng PHP CLI với file có BOM, dấu `;`,
  cột Họ đệm + Tên, MSSV sai, MSSV lặp, email sai — nhận diện đúng.
- Trang `admin.html` chạy thật bằng Chromium (Playwright) với API giả lập:
  đăng nhập ADMIN, tạo lớp (gửi đúng `lecturerIds`), nhập CSV dry-run → nút
  "Nhập thật" mới bật → nhập thật → báo cáo; không lỗi JS.
- `tools/smoke_test.php` thêm 40 kiểm tra cho 12 action quản trị (phân
  quyền, LECTURER không đổi được môn/giảng viên, sửa buổi lớp khác bị từ
  chối, gỡ/ghi danh lại, CSV dry-run không ghi, có lỗi thì không ghi dòng
  nào, chạy lại idempotent, audit_log). **Chưa chạy trên MariaDB thật** —
  sandbox không cài được MariaDB (như GĐ5 nợ). Thầy chạy
  `php tools/smoke_test.php --config=../private/config.test.php` trên host.

### Kết quả chạy trên host (01/10/2026, CSDL thử)

Lần đầu: 80/100 PASS. 20 FAIL đều do MỘT lỗi: `adminSaveClass` tạo lớp có 2
giảng viên bị MariaDB từ chối vì `db/schema.sql` (GĐ1) đặt khoá ngoại
`fk_classes_lecturer` trên `LecturerID`, trong khi thiết kế (mục 4, D.9) lưu
cột này dạng danh sách `"1607,2015"`. Khoá ngoại đó cũng sẽ làm
`tools/import.php` thất bại với dữ liệu thật có lớp 2 giảng viên. Sửa:
`db/migrations/003-classes-lecturerid-csv.sql` bỏ khoá ngoại (giữ chỉ số),
`db/schema.sql` cập nhật cho CSDL mới, thêm `tools/migrate.php` để áp
migration lên CSDL thật/thử; `smoke_test.php` tự áp migrations mỗi lần chạy.
Host cấm `proc_open`/`exec` nên smoke test chạy ở chế độ in-process (PR #9).

**Bổ sung 01/10 (sau PR #10):** `tools/backup.php` bản GĐ6 gọi `mysqldump`
qua `exec`/`shell_exec` — host cấm cả hai nên chết im lặng. Viết lại bằng PHP
thuần: PDO đọc `SHOW CREATE TABLE` + `SELECT *` trong một snapshot
`REPEATABLE READ`, ghi `.sql.gz` bằng zlib (DROP/CREATE + INSERT lô 200
dòng, `FOREIGN_KEY_CHECKS=0`), tự đọc lại file tới dòng cuối để xác nhận.
Bật `display_errors` trong script để lỗi CLI không còn bị nuốt.

### Chưa làm / chờ thầy

- Nhánh này dựa trên `agent/web-g5b` (PR #7) vì dùng chung
  `tools/smoke_test.php` — **merge PR #7 trước**, PR GĐ7 sẽ chỉ còn phần của
  nó.
- Chưa có nút "tạo nhanh N buổi theo lịch tuần" — mỗi buổi tạo tay (GĐ8 có
  thể thêm nếu cần).
- Chữ hướng dẫn giảng viên trên trang quản trị do Claude viết tạm; PLAN ghi
  Gemini soạn — chưa gửi handoff vì phiên không có việc đủ lớn để tách.

## 17. Cập nhật GĐ8 — chuyên cần tự động, nhập điểm CSV, điểm danh tay (`api/lib/grading.php`)

Giai đoạn 8/10 của PLAN. Thay `gas/10-AttendanceScore.gs`, `09-GradeImport.gs`,
`12-ManualAttendance.gs`. Thầy quyết ngày 02/10/2026:

- **Công thức chuyên cần** (thay rubric ĐG1.6 của bản GAS): thang 10, trọng
  số 10%. Mỗi buổi vắng không phép −3, vắng có phép −1,5, trễ −1; thấp nhất
  0. **Cấm thi** khi "vắng không phép tương đương" ≥ 3, với tương đương =
  vắng + ⌊trễ/3⌋ + ⌊có phép/2⌋ (3 trễ = 1 vắng, 2 có phép = 1 vắng — khớp mức
  trừ điểm). Chỉ tính trên buổi **đã điểm danh** (có bản ghi `attendance`);
  sinh viên không có bản ghi ở buổi đó = vắng không phép. Hằng số đọc từ
  `app.attendance_rules` trong config (mẫu ở `db/config.sample.php`).
- **Trọng số điểm** lưu theo **phần trăm** trong `grade_columns.Weight`
  (đúng `parseGradeHeader_` cũ). Khuôn: Cuối kỳ 50, Giữa kỳ 20, Thường xuyên
  20, Điểm cộng 10, Chuyên cần 10 — tổng 110%. **Điểm tổng** = Σ điểm×trọng
  số/100 trên các cột đã chấm, **quy về tối đa 10** (`grading_total()`).
- Nhập điểm CSV và điểm danh tay đều trên web (token + đúng lớp), theo ranh
  giới rule 3 thầy đã quyết ở GĐ7.

### 6 action mới (cùng phong bì, đều cần `token` LECTURER/ADMIN + đúng lớp)

| Action | Method | Tham số | Ghi chú |
|---|---|---|---|
| `adminAttendanceReport` | GET | `classId` | Bảng chuyên cần tính thử: mặt/trễ/vắng/phép, điểm, vắng tương đương, cờ cấm thi, `rules`. Không ghi gì. |
| `adminApplyAttendanceScore` | POST | `classId` | Tạo/cập nhật cột "Chuyên cần" (10%) rồi upsert `grades` cho mọi SV ACTIVE. Idempotent. Từ chối nếu chưa có buổi nào điểm danh. |
| `adminGradesReport` | GET | `classId` | Ma trận SV × cột điểm, `total` (quy về 10), `weightDone/weightTotal`, `banned`, `attendanceScore`. |
| `adminImportGrades` | POST | `classId`, `csv`, `dryRun` | Tiêu đề `MSSV,Tên (NN%),…`; ô trống = chưa chấm (không ghi); điểm 0–10; cột đã có → cập nhật trọng số; dry-run trả báo cáo + `weightNote` khi tổng ≠ 100; ghi thật một transaction, chỉ khi hết lỗi. |
| `adminSessionAttendance` | GET | `sessionId` | Danh sách lớp + trạng thái hiện có của buổi (null = chưa có bản ghi). |
| `adminSetAttendance` | POST | `sessionId`, `marks:[{mssv,status}]` | status PRESENT/LATE/ABSENT/EXCUSED; rỗng = không đụng. Một SV một bản ghi (D.8-3); bản ghi check-in thật chỉ đổi `Status` + `Note` ("Nhập tay bởi …, trước: X"), giữ giờ/GPS/thiết bị. |

`myGrades` (sinh viên) thêm `total` (quy về 10) và `attendance` {sessionsCounted,
present, late, absent, excused, score, equivalentAbsences, banned} tính trực tiếp
từ điểm danh — luôn mới nhất, không phụ thuộc giảng viên đã bấm "Ghi vào bảng
điểm" hay chưa. `average` cũ giữ lại cho tương thích. `API_VERSION` → `php-0.6`.

### Giao diện

`pages/admin.html` thêm tab **Điểm & chuyên cần** (Tính thử → Ghi vào bảng
điểm; nhập điểm CSV hai bước với mẫu tiêu đề; bảng điểm hiện tại có tổng và cờ
cấm thi) và nút **Điểm danh tay** ở mỗi buổi trong tab Buổi học (bảng chọn
trạng thái từng SV, chỉ gửi dòng đã đổi). `pages/diem.html` (sinh viên) hiện
điểm tổng quy về 10 và khối Chuyên cần kèm cảnh báo cấm thi.

### Test đã chạy

- Sandbox: `php -l`, `node --check` sạch; công thức + `grading_total` +
  `grading_parse_csv` chạy thật bằng PHP CLI; trang quản trị chạy thật bằng
  Chromium với API giả lập (điểm danh tay gửi đúng `marks`, tính thử → ghi,
  CSV dry-run → nhập thật); không lỗi JS.
- `tools/smoke_test.php` thêm 33 kiểm tra (tổng 133) cho công thức và 6 action.
  Chạy trên host (CSDL thử) — xem STATE/RUN.

### Chưa làm / chờ thầy

- Chưa có "xuất bảng điểm ra CSV/Excel" — nếu cần, thêm ở GĐ9.
- Tên 5 cột điểm mặc định chỉ nằm trong mẫu CSV trên trang; không tự tạo cột
  khi tạo lớp (giảng viên tự nhập CSV hoặc bấm ghi chuyên cần).

## 18. Cập nhật GĐ9 — chạy thử song song, so khớp dữ liệu, review bảo mật lần 2

Giai đoạn 9/10 của PLAN. Chi tiết thao tác cho thầy: **`docs/06-GD9-staging-checklist.md`**
(cách bật `?api=php`, checklist 7 mục, việc cần làm trước cutover).

### Chạy thử song song — `?api=` có allowlist

`js/config/config.js` nhận `?api=gas` (mặc định, Apps Script đang chạy thật)
hoặc `?api=php` (backend PHP cùng domain). **Chỉ hai giá trị này** —
`API_TARGETS` là bảng cố định trong mã nguồn, KHÔNG nhận URL từ tham số: nếu
nhận, một đường link `?api=https://trang-la…` gửi cho sinh viên sẽ lấy được
MSSV, mã điểm danh và mật khẩu giảng viên trong khi địa chỉ trang vẫn là tên
miền thật. Lựa chọn ghi nhớ trong `sessionStorage` (tab hiện tại), và khi khác
mặc định thì mọi trang có dải băng vàng cảnh báo + liên kết quay lại bản thật.
Trang web mặc định **không đổi hành vi** — vẫn gọi Apps Script.

### So khớp dữ liệu — `tools/compare_gas_php.php`

CLI, CHỈ ĐỌC, PHP thuần. Đọc file JSON do `exportAllToDriveJSON()` tạo rồi so
với CSDL: số dòng từng bảng, ID thiếu/thừa, số dòng lệch nội dung kèm tên cột
lệch. So sánh "mềm" để không báo lệch oan (ngày giờ `T` vs khoảng trắng, `8` vs
`8.00` vs `8,0`, `''` vs `NULL`); bỏ qua `PasswordHash`/`Salt` (đã rehash là
đúng), `CreatedAt`/`UpdatedAt`, `audit_log.Data`. Mã thoát 0 khi khớp.

### Review bảo mật độc lập lần 2 (Claude Opus, subagent riêng) — đã sửa

| Mã | Mức | Vấn đề | Sửa |
|---|---|---|---|
| H3 | CAO | `admin_upsert_enroll` (dùng bởi `adminEnroll`, `adminImportRoster`) ghi đè họ tên/email/Status của SV **đã có** mà chỉ kiểm quyền trên LỚP. Ai dạy một lớp bất kỳ cũng đổi được email của mọi SV trong trường chỉ bằng MSSV → xin mã xem điểm về hộp thư mình → xem điểm mọi lớp của em đó. | Chỉ ĐIỀN vào chỗ trống, không ghi đè; không đụng `Status`. `adminSaveStudent`: đổi email của SV đã có email là việc của ADMIN. |
| H4 | CAO | Regex tiêu đề coi mọi số cuối tên là trọng số: `Bài tập 1`, `Bài tập 2`, `Bài tập 3` đều thành cột `Bài tập` → ghi đè nhau trong `grades`, báo cáo vẫn "thành công". | Trọng số chỉ nhận trong ngoặc `(20%)`/`(20)` hoặc có `%`; hai cột cùng tên → từ chối file. |
| M6 | TB | Trọng số không giới hạn: `Cuối kỳ (500%)` + điểm 5 → điểm tổng CẢ LỚP chạm trần 10. | Kẹp 0–100, báo lỗi rõ. |
| M7 | TB | Không chặn CSV khổng lồ; `errors[]` có thể hàng triệu phần tử rồi `json_encode` → hết bộ nhớ. | Chặn 512 KB và 1000 dòng cho cả hai loại CSV; `errors[]` cắt còn 200, thêm `errorCount`. |
| M8 | TB | `adminSaveClass/Session/Course` UPDATE mọi cột từ `$data`: request thiếu `roomLat/roomLng` là **xoá toạ độ phòng** → `evaluate_gps()` trả `VALID` cho mọi check-in, tức tắt kiểm GPS của lớp mà không ai hay. | Chỉ ghi cột có trong request; cột không gửi giữ giá trị cũ. |
| M9 | TB | `smoke_test.php` áp migration **trước** rào "đây có phải CSDL thử" → `--config` trỏ nhầm là đã kịp `DROP FOREIGN KEY` trên CSDL thật. | Đưa rào lên trước; bảng chưa tồn tại thì bỏ qua rào. |
| M10 | TB | `require_role` không kiểm `users.Status`: tài khoản bị khoá vẫn nhập điểm, sửa điểm danh được tới 6 giờ. | Thêm `AND u.Status = 'ACTIVE'`. **Đổi so với bản GAS** (bản cũ cố tình bỏ qua) — khoá tài khoản giờ có hiệu lực ngay. |
| L6 | THẤP | `grade_columns` không có UNIQUE `(ClassID, Name)` → hai lần bấm đồng thời có thể tạo hai cột "Chuyên cần", cộng 10% hai lần. | `db/migrations/004` + `schema.sql`. |
| L7 | THẤP | Một chỗ nội suy `$pdo->quote($classId)` trong `query()`. | Dùng tham số buộc. |
| L9 | THẤP | `LecturerID` `VARCHAR(40)` bị `mb_substr` cắt âm thầm → một giảng viên mất quyền. | Kiểm độ dài trước, báo lỗi rõ. |
| L10 | THẤP | `adminSetAttendance` ghi đè `Note`, xoá mất cảnh báo "Trùng thiết bị…" (D.8-4) khỏi `liveRoster`. | Nối thêm vào `Note`, không ghi đè. |
| L12 | THẤP | `tools/migrate.php` ghi vào CSDL thật không cần xác nhận; bộ tách câu SQL cắt nát `CREATE PROCEDURE`. | Bắt buộc `--yes` cho CSDL thật; tách câu hiểu `BEGIN…END`. |

**Review xác nhận đạt:** mọi câu SQL trong code mới đều tham số hoá; mọi action
ghi đều `require_role` → `admin_load_class`/`grading_load_session` trước khi
đụng dữ liệu; `sessionId`/`studentId` của lớp khác bị từ chối; token GRADE của
sinh viên không với được action quản trị; hai bộ nhập CSV đều một transaction,
không ghi gì khi còn lỗi, chạy lại an toàn; `API_INPROCESS` không thể kích hoạt
từ web; `backup.php` không gọi shell, tự kiểm file.

**Chưa sửa — chờ thầy quyết** (ghi trong `docs/06`, mục cuối): M2, L8 (thông
báo "không tìm thấy" vs "không có quyền"), L11 (SV ghi danh muộn bị tính vắng
các buổi trước; điểm tổng giữa kỳ hiển thị nhỏ), L13 (`import.php` chạy lại với
export thiếu cột sẽ làm trắng cột đó). ~~M4, M5~~ → **đã sửa ở mục 19** (02/10/2026).

### Test đã chạy

- `php -l`, `node --check` sạch. Bộ đọc tiêu đề CSV điểm và `cmp_norm` của
  `compare_gas_php.php` chạy thật bằng PHP CLI (ngày giờ `T`, `8`/`8.00`/`8,0`,
  `''`/`NULL`).
- Chromium: `?api=php` bật dải băng và đổi `CONFIG.API_URL`; `?api=gas` tắt;
  ghi nhớ trong tab; **`?api=https://ke-xau.invalid/x` và `?api=KHONGCO` bị bỏ
  qua, `API_URL` vẫn là Apps Script**; trang quản trị không lỗi JS.
- `tools/smoke_test.php` thêm 17 kiểm tra (tổng 152) cho đúng các mục H3, H4,
  M6, M7, M8, M10, L6, L9, L10 ở trên.
- `tools/migrate.php --dry-run` tách đúng `CREATE PROCEDURE` của migration 004.

## 19. Gia cố trước cutover (02/10/2026) — M4 giới hạn tần suất, M5 kẹp phút mở mã

PR riêng, **không tính giai đoạn mới** (STATE 02/10 01:06 mục "Việc tiếp theo"
số 3). Đóng hai mục mà review lần 1 (`docs/05`) và lần 2 (mục 18) đều khuyên làm
trước khi đổi `API_URL`. `API_VERSION` → `php-0.7`. Hai thay đổi đều **giữ
nguyên khuôn phong bì và mọi thông điệp cũ**; frontend không phải sửa gì.

### M4 — giới hạn tần suất theo IP / tên đăng nhập (`api/lib/ratelimit.php`)

Vì sao: mã điểm danh và mã xem điểm chỉ có 25^4 ≈ 390 000 khả năng; mật khẩu
giảng viên trước đây thử được không giới hạn. Một máy bắn request liên tục dò
ra mã đang mở trong vài phút — khi đó sáu lớp bù D.8 chỉ còn là hình thức.

Cách làm — bảng đếm `rate_limits` (`db/migrations/005-rate-limits.sql`, cũng
có trong `db/schema.sql` — bảng thứ 15): khoá `(Bucket, ClientKey)`, cửa sổ
**cố định** `WindowStart` + `Hits`. Một câu `INSERT … ON DUPLICATE KEY UPDATE`
nguyên tử nên request song song không đếm sót; dòng cũ hơn 1 ngày được code tự
dọn (≈ 1/50 lượt ghi), không cần cron. `ClientKey` là IP máy chủ thấy
(`REMOTE_ADDR` — không nhận IP tự khai, cùng tinh thần M3) hoặc tên đăng nhập
chữ thường; **không chứa dữ liệu sinh viên**.

| Bucket | Áp cho | Đếm gì | Mặc định |
|---|---|---|---|
| `login_ip` | `login` | lần **sai** / IP | 20 lần / 15 phút |
| `login_user` | `login` | lần **sai** / tên đăng nhập (chặn dò phân tán từ nhiều IP vào một tài khoản) | 10 lần / 15 phút |
| `checkin_ip` | `checkin` | mã **sai hoặc không mở** / IP (sai định dạng cũng tính) | 60 lần / 10 phút |
| `gradecode_req_ip` | `requestGradeCode` | **mọi** lượt / IP — mỗi lượt hợp lệ là một email | 30 lần / 15 phút |
| `gradecode_ver_ip` | `verifyGradeCode` | lần **sai** / IP (MSSV không có, mã hết hạn, mã sai, bị khoá) | 50 lần / 15 phút |

Quyết định thiết kế cần biết khi đọc số liệu:

- **Chỉ đếm thất bại** ở `login`/`checkin`/`verifyGradeCode`: cả lớp 100 em
  điểm danh **đúng** qua WiFi của trường (một IP NAT) không bị tính, nên không
  chặn oan buổi học thật; 60 lần gõ sai trong 10 phút từ một phòng là nhiều hơn
  mọi lớp thật nhưng chỉ cho kẻ dò 0,015 % không gian mã.
- **Thông báo chung** khi bị chặn: `Thao tác quá nhiều lần. Vui lòng đợi vài
  phút rồi thử lại.` — không nói là chặn vì mã sai hay vì MSSV sai, nên không
  dùng được để dò (đúng đề xuất trong `docs/05`).
- **Fail-open có chủ ý**: bảng chưa có (quên chạy migration 005) hay CSDL lỗi
  → ghi `error_log` và cho qua. Lớp gia cố không được làm sập đăng nhập/điểm
  danh của cả trường. Vì vậy **vẫn phải chạy migration 005 trên host** để lớp
  này có hiệu lực; `tools/smoke_test.php` kiểm bảng tồn tại.
- Ngưỡng ghi đè trong `../private/config.php` mục `app.rate_limits`
  (`db/config.sample.php`), `limit = 0` là tắt bucket đó. Hostinger không qua
  proxy nên `REMOTE_ADDR` là IP thật; nếu sau này đặt Cloudflare phía trước
  thì phải đổi nguồn IP — **không** đọc `X-Forwarded-For` khi không có proxy
  (client tự đặt được header này để né giới hạn).
- Giới hạn 5 lần đoán/SV và 5 lần gửi/24h/SV của GĐ3 **giữ nguyên**, lớp này
  nằm **thêm** phía trước theo IP.

### M5 — kẹp `presentMinutes`/`windowMinutes` trong 1–60 phút (`action_open_attendance`)

Trước đây chỉ `≤ 0` mới về mặc định; `windowMinutes = 99999` được chấp nhận →
mã sống gần như vô hạn, trái D.8 lớp 1 ("mã mới mỗi buổi, hạn giờ ngắn") và
cho kẻ dò cả ngày để vượt M4. Nay: kẹp cả hai vào `1…app.max_window_minutes`
(mặc định 60), và mốc "trễ" không muộn hơn mốc "hết hạn"
(`presentMin = min(presentMin, windowMin)`). Kẹp âm thầm, không từ chối — giao
diện giảng viên không đổi.

### Chạy trên host (thầy làm, SSH từ `public_html`)

```
php tools/migrate.php --dry-run                       # thấy 005-rate-limits.sql
php tools/migrate.php --yes                           # tạo bảng rate_limits trên CSDL thật
php tools/smoke_test.php --config=../private/config.test.php   # phải 0 FAIL
```

Sau đó `?action=ping` phải trả `version: php-0.7`.

### Test đã chạy

- `php -l` sạch trên mọi file đổi (`api/index.php`, `api/lib/*.php`,
  `tools/smoke_test.php`, `db/config.sample.php`).
- `tools/smoke_test.php` thêm **22 kiểm tra** (mục "M4 … + M5 …", tổng 174 nếu các mục cũ vẫn 152 PASS): đếm đúng lần
  sai/không đếm lần đúng cho cả 4 action; vượt ngưỡng → thông báo chung không
  lộ lý do; tên đăng nhập HOA/thường gộp một bộ đếm; cửa sổ hết hạn → đặt lại
  về 1 (không khoá vĩnh viễn); kẹp 999/99999 → 60 phút, 50/10 → trễ = hết hạn,
  ≤ 0 → mặc định. **Phiên này không có MySQL để chạy** (sandbox cloud không
  cài được MariaDB) — kết quả thật chờ thầy chạy trên host như bảng trên; các
  phiên trước đều kiểm theo cách này.

## 20. Gia cố trước cutover (02/10/2026) — M2 gửi lại không ghi đè bản ghi đầu

PR riêng, **không tính giai đoạn mới** — thầy chọn làm M2 sau PR #15 (M4, M5).
Đóng mục M2 của review lần 1 (`docs/05`): **đổi hành vi so với bản GAS** (bản
cũ `SheetRepo.upsert` ghi đè toàn bộ dòng khi cùng MSSV gửi lần 2).

### Vấn đề

`checkin` lần 2 của cùng MSSV trong cùng buổi UPDATE **mọi cột** từ request
mới: `DeviceHash`, `Status`, `CheckInTime`, GPS, `Note`. Hệ quả:

- Gửi lại với `deviceHash` rỗng → dấu vết thiết bị của lần đầu bị xoá, cảnh báo
  "trùng thiết bị" biến mất khỏi `liveRoster` (D.8 lớp 4 chỉ còn trong
  `audit_log`, giảng viên trên lớp không thấy).
- Ai biết MSSV của bạn cùng lớp và mã đang mở là gửi lại được sau mốc "trễ" →
  PRESENT của người khác bị hạ thành LATE.

### Cách sửa (`action_checkin` + `checkin_merge_resend()`)

Khi đã có bản ghi (SELECT … FOR UPDATE, hoặc bắt lỗi trùng khoá 23000 khi hai
request song song): **giữ bản ghi đầu**, chỉ gộp:

| Cột | Lần 2+ |
|---|---|
| `CheckInTime`, GPS (`GpsLat/Lng/Accuracy`, `DistanceM`, `GpsFlag`), `IP`, `OS`, `Browser`, `DeviceType` | **giữ nguyên** lần đầu |
| `DeviceHash` | giữ lần đầu; chỉ điền khi lần đầu trống |
| `Status` | chỉ **nâng** (ABSENT < EXCUSED < LATE < PRESENT), không hạ |
| `Note` | **nối** `Gửi lại lúc HH:MM[, lần này LATE, giữ PRESENT][, thiết bị khác lần đầu][, trùng thiết bị với N MSSV khác]`, cắt 500 ký tự |

Phản hồi cho sinh viên trả `status`/`checkInTime` **đang lưu** (không phải của
lần gửi lại) nên em thấy đúng những gì giảng viên thấy; `action` vẫn là
`UPDATED` như cũ. `audit_log` ghi thêm `attemptStatus` (trạng thái tính cho lần
gửi này) bên cạnh `status` (đang lưu) để tra lại được khi có khiếu nại.

Không cần migration. Khuôn phong bì, thông điệp, `liveRoster`,
`adminSetAttendance` (L10 — cũng nối Note) không đổi.

### Test đã chạy

- `php -l` sạch. `tools/smoke_test.php` thêm **7 kiểm tra** trong mục `checkin`:
  gửi lại với hash rỗng giữ hash đầu; giờ/GPS/IP giữ nguyên; Note nối "Gửi lại
  lúc"; phản hồi trả giờ đang lưu; gửi từ thiết bị khác → giữ hash đầu + Note
  "thiết bị khác"; quá mốc trễ → PRESENT không bị hạ; vẫn đúng 1 dòng sau 4 lần
  gửi. **Chưa chạy được trong sandbox (không có MySQL)** — thầy chạy trên host
  cùng lượt với PR #15 (nhánh này xây trên nhánh của #15).

