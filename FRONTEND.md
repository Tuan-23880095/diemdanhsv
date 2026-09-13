# Frontend — Web điểm danh

Kiến trúc: HTML + Tailwind + JavaScript ES6 hướng đối tượng, theo MVC +
Service Layer (thiết kế PHẦN B).

## Cấu trúc

```
index.html              Trang chủ: header, menu, logo, mô tả, footer
pages/student.html      Điểm danh sinh viên (mobile first)
pages/lecturer.html     Bảng điều khiển giảng viên
css/main.css            Bổ sung cho Tailwind
css/print.css           Dành cho báo cáo in (PHẦN E)
js/config/config.js     API_URL và hằng số
js/models/              Student, AcademicClass, ClassSession, Attendance
js/services/            APIService (gọi mạng), DeviceService (GPS/thiết bị/IP)
js/views/               StudentView, RosterView — chỉ đụng DOM
js/controllers/         StudentController, LecturerController — điều phối
assets/logo/logo.png    Logo trường (tự thêm; thiếu thì header vẫn chạy)
```

## Cài đặt

1. Mở `js/config/config.js`, thay `PASTE_WEB_APP_URL_HERE` bằng Web app URL
   lấy từ bước triển khai Apps Script.
2. Mở `index.html` bằng trình duyệt để thử, hoặc đưa cả thư mục lên hosting
   tĩnh (GitHub Pages, Netlify, hosting của trường).

> **GPS cần HTTPS.** `navigator.geolocation` chỉ chạy trên `https://` hoặc
> `localhost`. Mở bằng `file://` hoặc `http://` trên máy khác thì trình duyệt
> chặn định vị, và mọi lượt điểm danh sẽ mang cờ `NO_GPS`.

## Quy tắc code (thiết kế B.3)

- **Không có `onclick` trong HTML.** Mọi sự kiện nối bằng `addEventListener`
  từ controller. Hai thẻ `<script>` inline trong trang chỉ làm một việc: khởi
  tạo controller khi DOM sẵn sàng.
- **View chỉ đụng DOM.** Không gọi mạng, không quyết định nghiệp vụ.
- **Controller không gọi `fetch`.** Mọi lời gọi mạng đi qua `APIService`.
- **Mọi dữ liệu từ máy chủ đều qua `_esc()`** trước khi vào `innerHTML`.
  Họ tên sinh viên là dữ liệu người khác nhập; không thoát ký tự là mở cửa XSS.

## Hai nợ kỹ thuật của bản cũ đã được trị ở đây

**Số 2 — `mode:'no-cors'`.** `APIService.post()` gửi với
`Content-Type: text/plain` (không kích hoạt preflight, thứ mà Apps Script
không trả lời được) và **đọc phản hồi thật**. `_unwrap()` ném Error khi
`status === 'error'`, controller bắt và hiện thông báo đúng. Sinh viên không
còn thấy "Thành công" khi máy chủ lỗi.

**Số 3 — danh sách sinh viên viết cứng trong HTML.** Không còn biến
`studentDatabase`. Frontend không biết trước MSSV nào; việc đối chiếu do
backend làm trên `04_STUDENTS` và `05_ENROLLMENTS`.

## Ghi chú về Tailwind CDN

Hai trang dùng `https://cdn.tailwindcss.com`. Tiện cho giai đoạn dựng, nhưng
nếu mạng chậm hoặc CDN bị chặn thì trang hiện ra **không có định dạng** —
vẫn dùng được nhưng xấu. Sinh viên điểm danh bằng 4G trong giảng đường là
đúng tình huống dễ gặp nhất.

Trước khi dùng thật với lớp đông, nên thay bằng một file CSS build sẵn đặt
trong `css/`. Không gấp ở Phase 1, nhưng đừng để tới hôm đi dạy mới phát hiện.
