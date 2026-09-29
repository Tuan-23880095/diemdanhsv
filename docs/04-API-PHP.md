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

## 11. Việc tiếp theo (GĐ6)

GĐ2 (nền PHP + `ping`), GĐ3 (`login`/`logout`/`requestGradeCode`/
`verifyGradeCode`, mục 12), GĐ4 (`openAttendance`/`closeAttendance`/
`checkin`/`liveRoster`, mục 13) và GĐ5 (`studentHistory`/`myGrades`/
`listClasses`/`listSessions`, mục 14) đã xong — **đủ 13/13 action cũ có
code PHP**, không còn action nào trả "chưa triển khai". GĐ6 (PLAN): script
di dời dữ liệu (`tools/import.php`, CLI, idempotent, dry-run, đối soát số
dòng) + hàm xuất JSON từ Apps Script + cron mysqldump sao lưu — THẦY chạy
qua SSH. Review bảo mật độc lập của GĐ5 (có thể giao Abacus DeepAgent,
trước 17/10/2026) **chưa thực hiện** trong phiên GĐ5 này — để dành cho một
phiên riêng hoặc gộp vào trước GĐ9 (checklist staging).

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
