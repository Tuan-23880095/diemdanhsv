# Triển khai lên GitHub Pages để chạy thử

## Trước khi push — bốn việc bắt buộc

### 1. Triển khai backend và điền API_URL

Chưa có bước này thì trang web chỉ là cái vỏ. Thứ tự:

1. Apps Script → **Deploy → New deployment → Web app**
   (Execute as: **Me**, Who has access: **Anyone**)
2. Chép **Web app URL**
3. Mở `js/config/config.js`, thay `PASTE_WEB_APP_URL_HERE` bằng URL đó

### 2. Xoá mật khẩu khỏi `gas/03-Auth.gs`

Mở file, tìm hàm `createLecturerAccount`, đặt lại:

```javascript
const password = '';
```

Tài khoản đã tạo trong Sheet rồi, biến này không còn tác dụng gì ngoài việc
làm lộ mật khẩu. Repo công khai nghĩa là **cả lịch sử commit cũng công khai** —
đẩy lên rồi mới sửa thì mật khẩu vẫn nằm trong lịch sử mãi mãi.

Tương tự với `runSmokeTest` trong `gas/06-Seed.gs`.

### 3. KHÔNG commit danh sách sinh viên

Họ tên và MSSV của sinh viên là dữ liệu cá nhân. Chúng chỉ được nằm trong
**Google Sheet**, không bao giờ nằm trong repo.

File `.gitignore` kèm theo đã chặn sẵn `*.csv`, `*.xlsx` và các thư mục
`data/`, `danh-sach/`. Trước khi commit lần đầu, chạy:

```bash
git status
```

và soát từng file một. Nếu thấy bất kỳ file nào chứa MSSV thật — dừng lại.

### 4. Hiểu điều này: URL backend sẽ công khai

Repo công khai nghĩa là ai cũng đọc được `config.js` và thấy Web app URL.
Đây là bản chất của web tĩnh, không tránh được — URL vốn đã nằm trong trình
duyệt của mọi sinh viên.

Hệ quả thật: bất kỳ ai cũng có thể gửi request `checkin` tới backend. Chặn
họ là việc của sáu lớp bù D.8, không phải của việc giấu URL:

- không biết mã 4 ký tự của buổi đó → bị từ chối
- gửi ngoài cửa sổ thời gian → bị từ chối
- MSSV không có trong `05_ENROLLMENTS` của lớp → bị từ chối

Các action khác (`liveRoster`, `listClasses`, `openAttendance`) đều đòi token
giảng viên, nên không đọc được dữ liệu lớp nếu không đăng nhập.

---

## Các bước push

```bash
cd D:\2026-Web-DayKemToan\Web-diem-danh

git init
git add .gitignore
git add .
git status          # SOÁT LẠI — không được có file nào chứa MSSV thật
git commit -m "Phase 1: he thong diem danh"
git branch -M main
git remote add origin https://github.com/<tên-tài-khoản>/<tên-repo>.git
git push -u origin main
```

Trên GitHub: **Settings → Pages → Source: Deploy from a branch → main / (root) → Save**

Sau 1–2 phút site chạy tại:

```
https://<tên-tài-khoản>.github.io/<tên-repo>/
```

Đường dẫn trong code đều là đường dẫn tương đối nên chạy đúng trong thư mục
con, không cần sửa gì.

---

## Vì sao nên dùng repo công khai

GitHub Pages phát từ repo **riêng tư** đòi gói trả phí. Với gói Free, muốn
có Pages thì repo phải công khai.

Điều đó chấp nhận được **với điều kiện** mục 2 và 3 ở trên được làm đúng:
code công khai không sao, dữ liệu sinh viên và mật khẩu thì không.

---

## Kiểm tra sau khi lên

1. Mở `https://.../` — trang chủ hiện đúng
2. Vào trang Sinh viên → trình duyệt **hỏi quyền vị trí** (đây là dấu hiệu
   HTTPS đã hoạt động; qua `file://` sẽ không hỏi)
3. Vào trang Giảng viên → đăng nhập → chọn lớp và buổi
4. Bấm **Mở điểm danh** → mã 4 ký tự hiện ra
5. Mở trang Sinh viên trên điện thoại → nhập MSSV thật và mã → gửi
6. Quay lại màn hình giảng viên → tên sinh viên đó xuất hiện trong vòng 10 giây

Bước 6 là bài kiểm tra thật sự. Nếu nó chạy, toàn bộ chuỗi
frontend → Apps Script → Google Sheets đã thông.

---

## Nếu hỏng

| Triệu chứng | Nguyên nhân thường gặp |
|---|---|
| "Không kết nối được máy chủ" | Chưa điền `API_URL`, hoặc deployment để "Who has access" ≠ Anyone |
| Sửa code backend mà không thấy đổi | Chưa **Deploy → Manage deployments → Edit → New version** |
| Không hỏi quyền vị trí | Đang mở bằng `file://` hoặc `http://` thay vì `https://` |
| "MSSV không có trong danh sách lớp này" | Chưa nhập `05_ENROLLMENTS` cho lớp đó |
| Trang hiện ra không có định dạng | CDN Tailwind bị chặn hoặc mạng chậm |
