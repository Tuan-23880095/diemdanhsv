# 02 — Bàn giao trạng thái dự án

Cập nhật: 12/09/2026

Tài liệu này để **bất kỳ ai hoặc công cụ AI nào** (Antigravity, Claude Code,
Cursor, hoặc chính tác giả sau vài tuần) mở thư mục dự án lên là làm tiếp
được ngay, không cần đọc lại lịch sử hội thoại.

---

## 1. Dự án là gì

Hệ thống điểm danh sinh viên có kiểm chứng + xem điểm.
Chủ nhiệm: Đinh Quốc Tuấn — Trường ĐH Khoa học Tự nhiên, ĐHQG-HCM.

Kiến trúc: HTML + Tailwind + JavaScript ES6 OOP (MVC + Service Layer)
→ Google Apps Script (API) → Google Sheets (database).

**Đọc trước khi sửa bất cứ thứ gì:** `docs/01-THIETKE-kien-truc.md`.
Đó là nguồn chân lý. Mọi quyết định kiến trúc, bảng mã trạng thái, và lý do
đằng sau từng lựa chọn đều ở đó.

---

## 2. Vị trí mọi thứ

| Thứ | Ở đâu |
|---|---|
| Mã nguồn | `D:\2026-Web-DayKemToan\Web-diem-danh` |
| Thiết kế | `docs/01-THIETKE-kien-truc.md` |
| Backend | `gas/*.gs` (bản gốc), chạy thật trong Apps Script của Google Sheet |
| Google Sheet | ID `1ZmgdFwiHNGxCW3OFIOtoPYOZ5EoIjkS1qrL4n0luWFU` |
| Kho tri thức | NotebookLM notebook **web-diemdanh-xemdiem** |
| Mã cũ (tham chiếu) | `..\diemdanh-main`, `..\XemDiem-main` |

NotebookLM notebook id: `9bbd1bc4-90c8-4bb9-9b17-bbbf26c20906`

---

## 3. Đã xong

**Phase 1 — hoàn tất phần code.**

| Bước | Nội dung | File |
|---|---|---|
| 1 | Schema 12 sheet + lớp truy cập theo tên cột | `gas/00-Config.gs`, `01-Schema.gs`, `02-Repo.gs` |
| 2 | Router API, AuthService, luồng điểm danh đủ 6 lớp bù D.8 | `gas/03-Auth.gs`, `04-AttendanceService.gs`, `05-Api.gs`, `06-Seed.gs` |
| 3 | Frontend OOP: trang chủ, trang sinh viên, dashboard giảng viên | `index.html`, `pages/`, `js/`, `css/` |

Kiểm thử đã chạy: `runSmokeTest()` trong Apps Script (12 mục, gồm cả các ca
phải bị từ chối), và kiểm tra nạp trang headless ở bề rộng 390px (0 lỗi JS,
không tràn ngang).

---

## 4. Chưa xong — việc tiếp theo theo thứ tự

1. **Nhập dữ liệu lớp thật** vào `04_STUDENTS` và `05_ENROLLMENTS`.
   Chưa có bước này thì sinh viên bị từ chối ngay tại lớp (lớp bù D.8-2).
   Cần viết hàm import từ CSV/Excel.
2. **Đưa lên GitHub Pages** — xem `TRIEN-KHAI-GITHUB.md`.
3. **Chạy thử một buổi thật** với vài sinh viên.
4. **Phase 2** — cột điểm động, bảng điểm, khiếu nại, báo cáo in PDF.
5. **Phase 3** — Google Login, QR code, email, dashboard phân tích.

---

## 5. Quyết định đã chốt — đừng tự đổi

| Quyết định | Chốt ngày | Ghi chú |
|---|---|---|
| Mã điểm danh **4 ký tự** | 12/09/2026 | Theo yêu cầu gốc. Không nâng 6 ký tự, không QR ở Phase 1 |
| Xác minh **Mức 1** (MSSV + mã) | 12/09/2026 | Không dùng Google Login. Để dành Phase 3 |
| Sáu lớp bù D.8 là **bắt buộc** | 12/09/2026 | Vì Mức 1 yếu, sáu lớp này mới tạo ra kiểm chứng |
| Mọi xác minh qua `AuthService` | — | Để nâng lên Mức 3 chỉ sửa thân `identifyStudent()` |

---

## 6. Ràng buộc kỹ thuật — vi phạm là hỏng

- **Không viết cứng chỉ số cột.** Không bao giờ `data[i][44]`. Dùng
  `SheetRepo` và tên cột. Đây là nợ kỹ thuật số 5 của bản cũ.
- **Mọi thao tác ghi trong `withLock_()`.** 100 sinh viên bấm gửi lúc 08:00
  sẽ tranh chấp ghi nếu thiếu.
- **Không dùng `mode:'no-cors'` ở frontend.** Phải đọc phản hồi thật. Nợ kỹ
  thuật số 2 của bản cũ là sinh viên thấy "Thành công" kể cả khi server lỗi.
- **POST phải gửi `Content-Type: text/plain`**, không phải `application/json`.
  Apps Script không trả lời được preflight OPTIONS.
- **Không có `onclick` trong HTML.** Nối sự kiện bằng `addEventListener`
  từ controller.
- **Mọi dữ liệu từ máy chủ phải qua `_esc()`** trước khi vào `innerHTML`.
- **Chỉ `02-Repo.gs` được gọi `SpreadsheetApp`.**
- **Không commit danh sách sinh viên lên Git.** Dữ liệu cá nhân chỉ nằm
  trong Google Sheet.
- **Mỗi lần sửa backend phải Deploy → New version**, nếu không URL cũ vẫn
  chạy code cũ.

---

## 7. Sáu lớp bù D.8 nằm ở đâu

| Lớp | Nội dung | Vị trí trong code |
|---|---|---|
| 1 | Mã mới mỗi buổi, hạn giờ ngắn | `AttendanceService.open`, `generateUniqueCode_` |
| 2 | Kiểm tra ghi danh | `AuthService.identifyStudent` |
| 3 | Một MSSV một bản ghi mỗi buổi | `SheetRepo.upsert` |
| 4 | DeviceHash phát hiện điểm danh hộ | `checkin` + `RosterView._renderAlerts` |
| 5 | Danh sách thời gian thực | `AttendanceService.liveRoster` + polling 10s |
| 6 | Nhật ký mọi thao tác | `logAudit` |

Cắt bất kỳ lớp nào là hệ thống trở thành sổ điểm danh tự khai.

---

## 8. Nếu làm tiếp bằng Antigravity

1. Mở thư mục `D:\2026-Web-DayKemToan\Web-diem-danh` làm workspace.
2. Bảo agent đọc `docs/01-THIETKE-kien-truc.md` và tài liệu này **trước**.
3. Nối NotebookLM MCP cho Antigravity — xem
   `docs/03-NOI-NOTEBOOKLM-ANTIGRAVITY.md`.
4. Hỏi notebook `web-diemdanh-xemdiem` khi cần tra lại quyết định thiết kế,
   thay vì dán cả tài liệu vào context.

Ngữ cảnh hội thoại **không** chuyển được giữa các công cụ. Thứ chuyển được
là: mã nguồn trên đĩa, tài liệu thiết kế, và notebook. Đó là lý do ba thứ đó
tồn tại.
