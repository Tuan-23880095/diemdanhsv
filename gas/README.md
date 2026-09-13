# Backend Google Apps Script

Google Sheet: `1ZmgdFwiHNGxCW3OFIOtoPYOZ5EoIjkS1qrL4n0luWFU`

## Các file

| File | Vai trò |
|---|---|
| `00-Config.gs` | Sheet ID, hằng số, bảng mã trạng thái, hàm sinh ID |
| `01-Schema.gs` | Định nghĩa 12 sheet + `initializeSpreadsheet()` + `verifySchema()` |
| `02-Repo.gs` | `SheetRepo` — đọc/ghi theo TÊN CỘT, `withLock_()`, `logAudit()` |
| `03-Auth.gs` | `AuthService` — nhận diện sinh viên (Mức 1), đăng nhập giảng viên, token |
| `04-AttendanceService.gs` | Mở / gửi / đóng điểm danh, danh sách thời gian thực — đủ 6 lớp bù D.8 |
| `05-Api.gs` | `doGet` / `doPost` + router, phong bì JSON |
| `06-Seed.gs` | Dữ liệu mẫu + `runSmokeTest()` kiểm thử đầu-cuối |

Apps Script nạp tất cả file cùng lúc nên thứ tự không ảnh hưởng khi chạy,
nhưng đánh số giúp người đọc biết đọc từ đâu.

---

## Cài đặt

### Bước 1 — Dán code

Mở Google Sheet → **Extensions → Apps Script** → tạo 7 file đúng tên trên
(Apps Script tự thêm đuôi `.gs`) → dán nội dung → **Save**.

### Bước 2 — Khởi tạo schema

Chạy `initializeSpreadsheet`.

Lần đầu Google hỏi cấp quyền: Review permissions → chọn tài khoản → Advanced
→ Go to project (unsafe) → Allow. Cảnh báo "unsafe" là bình thường với script
chưa xác minh của chính mình.

> **Nếu đã chạy ở bước trước:** chạy lại. Bước 2 thêm hai cột `PasswordHash`
> và `Salt` vào `01_USERS`. Hàm chỉ thêm cột thiếu vào cuối, không đụng dữ liệu.

### Bước 3 — Tạo tài khoản giảng viên

Mở `03-Auth.gs`, sửa 4 biến đầu hàm `createLecturerAccount` (nhất là
`password`), chạy hàm đó, rồi **xoá mật khẩu khỏi file ngay**.

### Bước 4 — Kiểm thử

```
seedDemoData     -> tạo 1 môn, 1 lớp, 4 sinh viên, 3 ghi danh, 1 buổi học
runSmokeTest     -> chạy trọn vòng điểm danh và kiểm chứng 6 lớp bù
```

Trước khi chạy `runSmokeTest`, sửa `USERNAME` / `PASSWORD` ở đầu hàm cho khớp
tài khoản vừa tạo. Kết quả mong đợi: 12 dòng `PASS` và `TẤT CẢ ĐỀU PASS`.

Smoke test kiểm cả các trường hợp **phải bị từ chối**: sinh viên không có
trong danh sách lớp, mã sai, mã đã đóng. Một hệ thống điểm danh mà chỉ test
đường thành công thì chưa test gì cả.

### Bước 5 — Triển khai Web App

**Deploy → New deployment → Type: Web app**

| Thiết lập | Giá trị |
|---|---|
| Execute as | **Me** (tài khoản Google của chủ sở hữu script) |
| Who has access | **Anyone** |

"Anyone" là bắt buộc: sinh viên điểm danh không đăng nhập Google (xác minh
Mức 1). Bù lại, API không có action nào đọc được dữ liệu nhạy cảm mà không
có token, và `checkin` đã có đủ 6 lớp bù.

Chép **Web app URL** — đó là `APPS_SCRIPT_URL` cho frontend.

Mỗi lần sửa code phải **Deploy → Manage deployments → Edit → Version: New
version**. Không tạo version mới thì URL cũ vẫn chạy code cũ.

---

## API

Phong bì chung cho mọi phản hồi:

```json
{ "status": "success" | "error", "message": "...", "data": {...} }
```

### GET — chỉ đọc

| action | Tham số | Quyền |
|---|---|---|
| `ping` | — | công khai |
| `studentHistory` | `mssv`, `classId` | công khai |
| `liveRoster` | `token`, `sessionId` | giảng viên |
| `listClasses` | `token` | giảng viên |
| `listSessions` | `token`, `classId` | giảng viên |

### POST — mọi thao tác ghi

| action | Tham số | Quyền |
|---|---|---|
| `login` | `username`, `password` | công khai |
| `logout` | `token` | — |
| `openAttendance` | `token`, `sessionId`, `presentMinutes?`, `windowMinutes?` | giảng viên |
| `closeAttendance` | `token`, `sessionId` | giảng viên |
| `checkin` | `mssv`, `code`, `lat`, `lng`, `accuracy`, `ip`, `os`, `browser`, `deviceType`, `deviceHash` | công khai |

Mật khẩu chỉ đi qua POST, không bao giờ qua GET — nợ kỹ thuật số 4 của bản
`XemDiem` cũ là mật khẩu nằm trong query string, bị ghi vào log Google và
lịch sử trình duyệt.

### Cách gọi POST cho đúng

```javascript
const res = await fetch(APPS_SCRIPT_URL, {
  method: 'POST',
  headers: { 'Content-Type': 'text/plain;charset=utf-8' },
  body: JSON.stringify({ action: 'checkin', mssv, code, lat, lng, accuracy })
});
const json = await res.json();
if (json.status === 'error') showError(json.message);
```

Hai điều bắt buộc:

1. **`Content-Type: text/plain`**, không phải `application/json`. Apps Script
   không trả lời được request preflight `OPTIONS`; `text/plain` là một trong
   các kiểu không kích hoạt preflight.
2. **Không dùng `mode: 'no-cors'`.** Với `no-cors` trình duyệt luôn trả
   response rỗng, nên trang báo "Thành công" kể cả khi server lỗi — đúng nợ
   kỹ thuật số 2 của bản cũ. Phải đọc `json.status` rồi mới báo cho sinh viên.

---

## Sáu lớp bù D.8 nằm ở đâu trong code

| Lớp | Nội dung | Vị trí |
|---|---|---|
| 1 | Mã mới mỗi buổi, ngẫu nhiên, hạn giờ ngắn | `AttendanceService.open`, `generateUniqueCode_`, kiểm `EndTime` trong `checkin` |
| 2 | Kiểm tra ghi danh | `AuthService.identifyStudent` |
| 3 | Một MSSV một bản ghi mỗi buổi | `SheetRepo.upsert` trong `checkin` |
| 4 | DeviceHash phát hiện điểm danh hộ | `checkin` dò trùng + `liveRoster.deviceAlerts` |
| 5 | Danh sách thời gian thực cho giảng viên | `AttendanceService.liveRoster` |
| 6 | Nhật ký mọi thao tác | `logAudit` trong open / checkin / close |

---

## Quy tắc cho các bước sau

- Chỉ `02-Repo.gs` được gọi `SpreadsheetApp`. Service và controller đi qua `Repos.*`.
- Mọi thao tác ghi phải nằm trong `withLock_()`.
- Mọi kiểm tra danh tính phải đi qua `AuthService`. Nâng lên Google Login ở
  Phase 3 chỉ sửa thân `identifyStudent()`, luồng điểm danh không đổi.
- Không bao giờ viết `data[i][44]`. Dùng `row.TenCot`.
