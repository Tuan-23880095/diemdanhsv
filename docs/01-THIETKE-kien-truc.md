# 01 — THIẾT KẾ: Kiến trúc hệ thống điểm danh & quản lý học tập

Dự án: **Web điểm danh sinh viên có kiểm chứng + xem điểm**
Chủ nhiệm: Đinh Quốc Tuấn — Trường ĐH Khoa học Tự nhiên, ĐHQG-HCM
Thư mục mã nguồn: `D:\2026-Web-DayKemToan\Web-diem-danh`
Ngày chốt bản này: 12/09/2026

> Đây là nguồn chân lý cho mọi quyết định thiết kế. Khi có quyết định mới, sửa vào đây trước, rồi mới sửa code.

---

## PHẦN A — Yêu cầu gốc của chủ nhiệm đề tài (nguyên văn)

Tạo trang web điểm danh sinh viên, có kiểm chứng. Người dùng: giảng viên, sinh viên.

**Chức năng giảng viên:** nhập mã 4 ký tự để làm key xác minh cho sinh viên điểm danh; thêm, sửa, xóa môn học, thông tin môn học, danh sách lớp; xuất danh sách lớp, xuất danh sách sinh viên trễ, xuất danh sách sinh viên vắng.

**Chức năng xuất ra màn hình thông tin lớp:** tên môn học, mã môn học, số tín chỉ, số tiết lý thuyết, số tiết thực hành; thông tin điểm danh sinh viên (số lượng, trễ, vắng); điểm, các cột điểm, điểm từng cá nhân; thời khóa biểu môn học (thứ, giờ, ngày tháng, thứ tự buổi, nội dung vắn tắt của buổi học).

**Chức năng sinh viên:** nhập MSSV, lựa chọn khiếu nại, nhập nội dung khiếu nại, xem danh sách điểm danh qua các buổi, xem điểm.

**Chức năng web:** trang chủ thể hiện thông tin, danh sách menu, mô tả ngắn gọn, có header, footer, logo trường. Kết nối dữ liệu Google Sheet qua API để lấy thông tin các môn học và danh sách sinh viên của các môn khác nhau. Ghi về Google Sheet: thông tin sinh viên, thời gian nhập, mã 4 ký tự giáo viên cho trên lớp để xác minh, vị trí GPS, IP, hệ điều hành. Có trang phụ là trang quản lý điểm danh và điểm. Có chức năng render ra trang HTML cho các thông tin xuất như danh sách vắng, danh sách trễ, danh sách điểm. Google Sheet có các tab tương ứng phục vụ lưu trữ, ghi, xóa dữ liệu, tìm kiếm, trích xuất.

**Ràng buộc kỹ thuật:** cấu trúc web theo lập trình hướng đối tượng. Logic xuất của web là xuất ra trang HTML, sau đó hiện nút in; bấm nút in là gọi dùng chức năng save PDF của trình duyệt để save PDF.

---

## PHẦN B — Kiến trúc đã chọn

### B.1 Sơ đồ tầng

```
        FRONTEND
        HTML + Tailwind CSS + JavaScript ES6 (OOP)
                  │
                  │  REST API
                  ▼
        GOOGLE APPS SCRIPT
        Controllers / Services / Validators / Auth
                  │
                  ▼
        GOOGLE SHEETS  (Data layer)
```

**Nguyên tắc bắt buộc:** frontend KHÔNG truy cập Google Sheets API trực tiếp. Mọi lời gọi đi qua tầng Apps Script.

Mô hình áp dụng: **MVC + Service Layer**.

```
USER → VIEW → CONTROLLER → SERVICE → API → APPS SCRIPT → SHEETS
```

### B.2 Cấu trúc thư mục

```
Web-diem-danh/
├── index.html
├── pages/        login, lecturer, student, attendance, grade, report
├── css/          main.css, dashboard.css, print.css
├── js/
│   ├── config/       config.js
│   ├── models/       Student, Lecturer, Course, AcademicClass,
│   │                 ClassSession, Attendance, Grade, Complaint
│   ├── services/     APIService, AttendanceService, StudentService,
│   │                 ReportService
│   ├── controllers/  LecturerController, StudentController,
│   │                 AttendanceController
│   ├── views/        AttendanceView, GradeView, ReportView
│   └── app.js
├── templates/    classReport, absentReport, lateReport, gradeReport
├── assets/       images/, logo/
└── docs/         tài liệu thiết kế (thư mục này)
```

### B.3 Quy tắc code

- Không viết logic trong thuộc tính `onclick` của HTML. Dùng `addEventListener` từ controller.
- Mỗi thực thể là một class trong `js/models/`.
- Mobile First — 80–95% sinh viên dùng điện thoại.

---

## PHẦN C — Mô hình dữ liệu

### C.1 Quy tắc nền tảng

**Course ≠ Class.** `Course` là môn học trong chương trình đào tạo (GEO10001 — Trầm tích Đệ tứ, 3 tín chỉ, 30 tiết LT, 15 tiết TH). `Class` là một lớp mở trong một học kỳ cụ thể (GEO10001_01, năm học 2026–2027, HK1, giảng viên Đinh Quốc Tuấn).

**Student ≠ Enrollment.** Không lưu danh sách sinh viên trực tiếp trong Course. Quan hệ Student ↔ Class là nhiều-nhiều, đi qua bảng `ENROLLMENTS`.

**Attendance gắn với SessionID, không gắn với ngày.** Khóa là cặp `StudentID + SessionID`.

**Không dùng số dòng làm ID.** Xóa một dòng là toàn bộ tham chiếu sai. Dùng UUID (`Utilities.getUuid()`) hoặc mã có cấu trúc (`STD_20260909_A8F2`).

**Không viết cứng chỉ số cột.** Bài học từ `XemDiem` hiện tại: `data[i][44]` nghĩa là chèn thêm một cột vào giữa sheet là toàn bộ bảng điểm lệch. Đọc theo tên cột từ hàng header.

### C.2 Danh sách sheet (đánh số để giữ thứ tự)

| Sheet | Cột chính |
|---|---|
| `01_USERS` | UserID, Username, Role, Status |
| `02_COURSES` | CourseID, CourseCode, CourseName, Credits, TheoryHours, PracticeHours |
| `03_CLASSES` | ClassID, CourseID, LecturerID, Semester, AcademicYear |
| `04_STUDENTS` | StudentID, MSSV, FullName, Email |
| `05_ENROLLMENTS` | EnrollmentID, StudentID, ClassID |
| `06_SESSIONS` | SessionID, ClassID, SessionNo, Date, DayOfWeek, Start, End, Content |
| `07_ATTENDANCE` | AttendanceID, StudentID, SessionID, Status, CheckInTime, GPS_Lat, GPS_Lng, GPS_Accuracy, IP, OS, Browser, DeviceHash |
| `08_ATTENDANCE_KEYS` | KeyID, SessionID, Code, StartTime, EndTime, Status |
| `09_GRADE_COLUMNS` | GradeColumnID, ClassID, Name, Weight |
| `10_GRADES` | GradeID, StudentID, ClassID, GradeColumnID, Score |
| `11_COMPLAINTS` | ComplaintID, StudentID, Type, Content, Time, Status |
| `12_AUDIT_LOG` | LogID, User, Action, Time, Data |

`12_AUDIT_LOG` là bắt buộc, không phải tùy chọn — nó là bằng chứng khi xảy ra tranh chấp về điểm danh hoặc điểm.

**Định nghĩa chính thức của schema nằm trong code**, tại đối tượng `SCHEMA` của `gas/01-Schema.gs`. Bảng trên chỉ là bản tóm tắt. Khi cần thêm cột: sửa `SCHEMA` rồi chạy lại `initializeSpreadsheet()`.

Các cột bổ sung so với phác thảo ban đầu, kèm lý do:

| Sheet | Cột thêm | Lý do |
|---|---|---|
| `03_CLASSES` | `RoomLat`, `RoomLng`, `AllowedRadiusM` | Mỗi lớp học ở một phòng khác nhau, nên bán kính GPS phải theo lớp chứ không theo hệ thống (D.3) |
| `07_ATTENDANCE` | `DistanceM`, `GpsFlag` | Lưu khoảng cách và cờ hợp lệ thay vì chỉ toạ độ thô — vừa dễ báo cáo vừa tốt cho quyền riêng tư (D.3) |
| `08_ATTENDANCE_KEYS` | `LateAfter` | Mốc chuyển PRESENT → LATE, tách khỏi `EndTime` là lúc đóng hẳn (D.2) |
| `09_GRADE_COLUMNS` | `SortOrder` | Thứ tự hiển thị cột điểm trên bảng điểm |
| Mọi sheet | `Status`, `CreatedAt` | Xoá mềm thay vì xoá cứng; giữ dấu vết thời gian |

**Quy ước định dạng:** mọi cột kiểu văn bản bị ép định dạng `@` (văn bản thuần). Không phải chi tiết làm đẹp — nếu không ép, Sheets biến mã điểm danh `3479` thành số, và MSSV bắt đầu bằng `0` bị cắt mất số 0 đầu.

### C.3 Bảng mã trạng thái

Dùng mã tiếng Anh trong dữ liệu, dịch sang tiếng Việt ở tầng hiển thị.

| Miền | Giá trị |
|---|---|
| Trạng thái điểm danh | `PRESENT` (có mặt), `LATE` (trễ), `ABSENT` (vắng), `EXCUSED` (vắng có phép) |
| Vai trò | `ADMIN`, `LECTURER`, `STUDENT` — có `ADMIN` ngay từ đầu |
| Loại khiếu nại | `ATTENDANCE`, `GRADE`, `OTHER` |
| Trạng thái khiếu nại | `PENDING` → `PROCESSING` → `RESOLVED` / `REJECTED` |

### C.4 Cột điểm động

Không viết cứng "CC, Quiz, GK, CK" trong code. `09_GRADE_COLUMNS` cho phép giảng viên thêm cột điểm bất kỳ kèm trọng số. Ví dụ cấu hình: Chuyên cần 10%, Quiz 20%, Giữa kỳ 30%, Cuối kỳ 40%. Điểm tổng kết tính từ cấu hình này, không từ công thức viết cứng.

---

## PHẦN D — Cơ chế điểm danh có kiểm chứng

### D.1 CHỐT: mã 4 ký tự, xác minh Mức 1 (MSSV + mã)

**Quyết định ngày 12/09/2026:** giữ đúng yêu cầu gốc — mã **4 ký tự**, sinh viên xác minh bằng **MSSV + mã**, không dùng Google Login ở Phase 1–2.

Đây là lựa chọn ưu tiên tính đơn giản và tốc độ triển khai. Phải hiểu rõ giới hạn của nó:

- Không gian mã 36^4 ~ 1,68 triệu tổ hợp — **đủ** chống đoán mò.
- Mã **không** chống được chia sẻ: chụp màn hình gửi qua Zalo là bạn ở nhà điểm danh được.
- MSSV **không** phải bí mật: sinh viên A biết MSSV của B là điểm danh hộ B được.

Nghĩa là hai lớp này một mình không tạo ra "kiểm chứng". Kiểm chứng đến từ các lớp bù ở D.8, và chúng trở thành **bắt buộc**, không còn là tùy chọn:

```
MSSV + Session Code (4 ký tự)     <- lớp nhận dạng, yếu
   + Time Window                  <- lớp bù
   + Enrollment check             <- lớp bù
   + GPS + Accuracy               <- lớp bù
   + DeviceHash                   <- lớp bù, quan trọng nhất
   + Audit Log                    <- lớp truy vết
```

### D.2 Cửa sổ thời gian

Mã có `StartTime` và `EndTime`. Hệ thống tự phân loại theo thời điểm check-in:

| Khoảng | Kết quả |
|---|---|
| 08:00 – 08:05 | `PRESENT` |
| 08:05 – 08:15 | `LATE` |
| Sau 08:15 | Đóng, không cho điểm danh |

### D.3 GPS — lưu, nhưng không coi là bằng chứng tuyệt đối

GPS sai số 10–100 m, kém trong nhà, và sinh viên có thể không cấp quyền. Luôn lưu kèm `accuracy`. Nếu accuracy quá lớn thì đánh dấu `GPS_LOW_ACCURACY` thay vì loại thẳng sinh viên.

Bán kính cho phép: 100 m tính từ vị trí lớp học. Về quyền riêng tư, nên lưu **khoảng cách** và cờ hợp lệ thay vì tọa độ chi tiết.

### D.4 IP và thiết bị

IP chỉ dùng để phát hiện bất thường, không dùng làm điều kiện duy nhất — IP nội bộ (192.168.x.x) không nói lên vị trí.

Device: lưu OS, Browser, Device Type. Thêm `DeviceHash` để phát hiện một điện thoại điểm danh cho nhiều MSSV, và cảnh báo giảng viên. Không dùng fingerprint để định danh tuyệt đối.

### D.5 Xác minh danh tính sinh viên — ba mức

| Mức | Cơ chế | Trạng thái |
|---|---|---|
| 1 | MSSV + mã 4 ký tự | **ĐÃ CHỌN** cho Phase 1–2. Bảo mật thấp, bù bằng D.8 |
| 2 | MSSV + PIN/ngày sinh + mã | Không dùng |
| 3 | Google Login bằng email trường (`22123456@student.hcmus.edu.vn`) + mã | Đường nâng cấp Phase 3 |

**Ràng buộc kiến trúc kèm theo:** mọi kiểm tra danh tính đi qua một `AuthService` duy nhất. Controller không được gọi thẳng vào logic xác minh. Nhờ vậy, khi nâng lên Mức 3 chỉ phải thay phần thân của `AuthService`, không phải viết lại luồng điểm danh.

### D.6 Chống ghi trùng và ghi đồng thời

100 sinh viên bấm gửi cùng lúc lúc 08:00 sẽ gây tranh chấp ghi trên Google Sheet. Bắt buộc dùng `LockService`:

```javascript
const lock = LockService.getScriptLock();
lock.waitLock(30000);
try { /* ghi dữ liệu */ } finally { lock.releaseLock(); }
```

Trước khi ghi, kiểm tra cặp `StudentID + SessionID` đã tồn tại chưa: có thì UPDATE hoặc REJECT, chưa thì INSERT.

### D.7 Luồng điểm danh

```
GIẢNG VIÊN                          SINH VIÊN
Chọn môn → chọn lớp → chọn buổi     Đăng nhập / nhập MSSV
  → MỞ ĐIỂM DANH                      → nhập mã xác minh
  → hệ thống sinh mã + hạn giờ        → cho phép GPS
  → (hiện mã / QR trên màn hình)      → gửi
  → ĐÓNG ĐIỂM DANH                    → hệ thống kiểm tra nhiều lớp
  → xuất báo cáo                      → báo kết quả THẬT (không giả)
```

### D.8 Các lớp bù BẮT BUỘC khi dùng Mức 1

Vì lớp nhận dạng yếu, sáu điều sau không được cắt bớt để tiết kiệm công:

1. **Mã sinh mới mỗi buổi, ngẫu nhiên, hạn giờ ngắn.** Không đặt mã cố định kiểu tên lớp. Cửa sổ hiệu lực gắn với D.2 — mã rò rỉ hết giá trị sau 15 phút.
2. **Kiểm tra ghi danh.** MSSV phải có trong `05_ENROLLMENTS` của đúng `ClassID` đang mở điểm danh. MSSV lạ bị từ chối, không ghi dòng rác.
3. **Một MSSV chỉ một bản ghi cho một `SessionID`.** Lần gửi thứ hai là UPDATE hoặc REJECT, không bao giờ INSERT thêm.
4. **DeviceHash là lớp phòng thủ chính.** Không có Authentication thì đây là thứ duy nhất phát hiện một điện thoại điểm danh cho nhiều MSSV. Hệ thống phải cảnh báo giảng viên ngay trên màn hình điểm danh khi thấy trùng thiết bị, chứ không chỉ ghi âm thầm vào sheet.
5. **Giảng viên thấy danh sách check-in theo thời gian thực.** Đây là "kiểm chứng" cuối cùng và hiệu quả nhất: giảng viên nhìn màn hình, đối chiếu với lớp trước mặt.
6. **Ghi `12_AUDIT_LOG` cho mọi lần điểm danh và mọi lần sửa điểm danh.** Khi sinh viên khiếu nại, đây là thứ duy nhất phân xử được.

Lớp 4 và lớp 5 là hai thứ thực sự thay thế cho Authentication. Nếu bỏ, hệ thống trở thành sổ điểm danh tự khai.

### D.9 CHỐT: phân quyền khi có nhiều giảng viên

**Quyết định ngày 12/09/2026:** khi `01_USERS` có nhiều tài khoản vai trò `LECTURER`, mỗi giảng viên **chỉ được thấy và thao tác trên lớp mình đứng tên `LecturerID`** trong `03_CLASSES`. Vai trò `ADMIN` không bị giới hạn — thấy và thao tác được mọi lớp.

Phạm vi áp dụng: `listClasses`, `listSessions` (`gas/05-Api.gs`), và `AttendanceService.open/close/liveRoster` (`gas/04-AttendanceService.gs`). Cả bốn đi qua một điểm kiểm tra duy nhất — `AuthService.assertClassAccess(me, classId)` — theo đúng ràng buộc D.5 ("mọi kiểm tra danh tính đi qua `AuthService`"). Giảng viên gọi API cho lớp không phải của mình sẽ nhận lỗi rõ ràng, không trả về danh sách rỗng gây hiểu lầm là "lớp chưa có dữ liệu".

Tạo nhiều tài khoản giảng viên qua `createLecturerAccounts()` (số nhiều) trong `gas/03-Auth.gs` — điền mảng, chạy một lần, ghi lại từng `UserID` được log ra để điền đúng vào cột `LecturerID` của `03_CLASSES`, rồi xoá sạch mảng khỏi mã nguồn.

---

## PHẦN E — Báo cáo và in ấn

Luồng: `DATA → RENDER HTML (từ template) → PREVIEW → window.print() → trình duyệt Save as PDF`.

Mỗi loại báo cáo có một template riêng trong `templates/`, gọi qua `ReportRenderer.render("absentReport", data)`.

Các báo cáo cần có: danh sách lớp, danh sách vắng, danh sách trễ, báo cáo chuyên cần (có % ), bảng điểm, hồ sơ sinh viên.

`css/print.css` phải ẩn navbar và mọi phần tử `.no-print` trong `@media print`.

---

## PHẦN F — Hiệu năng và giới hạn Google Sheets

Google Sheets không phải database thực thụ. Ước tính: 100 sinh viên × 20 buổi × 50 lớp = 100.000 bản ghi điểm danh — vẫn chạy được nhưng phải tối ưu.

- **Không** gọi `sheet.getDataRange().getValues()` trong mọi request. Truy vấn theo ClassID / StudentID / SessionID.
- Dùng `CacheService` cho dữ liệu ít đổi: Courses, Classes, Students.

---

## PHẦN G — Bảo mật API

Không để API URL nhận POST từ bất kỳ đâu. Cần có: Authentication, Authorization, Validation, Rate Limiting, Audit Log.

Validation tối thiểu:

```javascript
function validateStudentId(mssv) { return /^[0-9]{8}$/.test(mssv); }
function validateCode(code)      { return /^[A-Z0-9]{4}$/.test(code); }
```

Phân quyền: giảng viên được CRUD môn/lớp/sinh viên, điểm danh, điểm, xuất báo cáo. Sinh viên chỉ được xem hồ sơ, xem điểm danh, xem điểm, gửi khiếu nại — **không** được sửa điểm danh hay điểm.

---

## PHẦN H — Nợ kỹ thuật của bản đang chạy (phải sửa)

Ghi lại từ việc đọc mã thật trong `diemdanh-main` và `XemDiem-main`:

1. **`doPost` không kiểm mã xác minh nào cả.** Nhận `mssv`, `name`, `lat`, `lng`, `time` rồi `appendRow` thẳng. Ai biết URL Web App đều POST được một dòng điểm danh cho bất kỳ MSSV nào, từ bất kỳ đâu.
2. **`mode: 'no-cors'` che mất lỗi.** Trình duyệt luôn trả response rỗng, nên không biết lần ghi thành công hay thất bại. Sinh viên thấy "Thành công" kể cả khi server lỗi.
3. **Danh sách sinh viên viết cứng trong HTML.** Biến `studentDatabase` nằm ngay trong `index.html` — ai xem source cũng thấy toàn bộ MSSV và họ tên.
4. **Mật khẩu xem điểm lưu thô và đi trong query string.** `XemDiem` so sánh `e.parameter.pass` với cột B của sheet `web`; vì đi qua GET nên mật khẩu nằm trong URL, được ghi vào log Google và lịch sử trình duyệt.
5. **Chỉ số cột viết cứng** (`data[i][44]`).

---

## PHẦN I — Lộ trình ba giai đoạn

**PHASE 1 — MVP.** Giảng viên: quản lý môn học, lớp, danh sách sinh viên, tạo buổi học, mở điểm danh, sinh mã. Sinh viên: nhập MSSV, nhập mã, GPS, điểm danh. Hệ thống: Sheets + Apps Script + báo cáo điểm danh.

**PHASE 2.** Quản lý điểm, bảng điểm động, khiếu nại, thời khóa biểu, danh sách vắng/trễ, HTML report + in.

**PHASE 3.** Google Login, QR Code, email thông báo, dashboard phân tích, phát hiện bất thường thiết bị.

---

## PHẦN J — Thứ tự thiết kế trước khi code

1. Database schema Google Sheets
2. Use Case Diagram
3. Class Diagram OOP
4. API Specification
5. Cấu trúc thư mục dự án
6. UI/UX Wireframe
7. Code MVP

Ba mục đầu quyết định nền tảng của toàn bộ hệ thống.
