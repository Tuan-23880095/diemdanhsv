# Nền Tảng Bồi Dưỡng Văn Hóa & Khảo Thí ĐGNL — HCMUS
**Tên miền chính thức:** [https://bdvh.diemdanhsv.com](https://bdvh.diemdanhsv.com)  
**Chủ nhiệm & Giảng viên phụ trách:** ThS. Đinh Quốc Tuấn (Trường ĐH Khoa học Tự nhiên, ĐHQG-HCM)

---

## 1. Giới thiệu hệ thống
Hệ thống ôn luyện thi trắc nghiệm bấm giờ, quản lý lớp học và khảo thí đánh giá năng lực tư duy trực tuyến dành cho học sinh từ **Lớp 6 đến Lớp 12** và thí sinh chuẩn bị cho kỳ thi **Đánh Giá Năng Lực ĐHQG-HCM**.

### Các tính năng cốt lõi:
- **Cổng thông tin & Mục lục bài học (`index.html`):** Phân chia chương trình GDPT 2018 theo tuần học, hiển thị lý thuyết cốt lõi, bài tập rèn luyện và đề thi trắc nghiệm tương ứng.
- **Phòng thi trực tuyến (`thi.html`):** Làm bài trắc nghiệm tính giờ theo nhịp độ 3 chặng (Khởi động ➔ Tăng tốc ➔ Về đích), tích hợp MathJax hiển thị công thức toán học sắc nét.
- **Phiếu báo điểm & Đánh giá tư duy (`ket-qua.html`):** Chuẩn biểu mẫu in A4 gửi phụ huynh, chấm điểm tự động và nhận xét sư phạm chi tiết.
- **Lớp học trực tiếp (`giao-vien.html`):** Phục vụ giáo viên trình chiếu trên lớp học (ngày, thứ, mã vào lớp và slide bài dạy chuẩn 3-slide).
- **Bảng điều khiển Giảng viên (`thay-tuan.html`):** Quản lý điểm số, xem chi tiết bài làm của từng học sinh, quản lý mã lớp học.
- **Backend API (`api.php`):** Kiến trúc RESTful hỗ trợ kiểm tra mã lớp, nộp bài, chấm điểm và seed ngân hàng đề thi chuẩn.

---

## 2. Cấu trúc thư mục mã nguồn
```text
bdvh/
├── assets/
│   └── logo-sen.svg           # Logo nhận diện thương hiệu ThS. Đinh Quốc Tuấn
├── index.html                 # Cổng thông tin, bộ lọc khối lớp & mục lục chương trình
├── thi.html                   # Phòng thi trắc nghiệm trực tuyến
├── ket-qua.html               # Báo cáo kết quả bài thi & phân tích tư duy (A4)
├── giao-vien.html             # Lớp học trực tiếp & chiếu slide bài dạy lên bảng
├── thay-tuan.html             # Bảng điều khiển quản lý giảng viên
├── slide-toan6.html           # Slide bài giảng Toán 6 chuẩn 3-slide
├── slide-khtn6.html           # Slide bài giảng KHTN 6 chuẩn 3-slide
├── slide-toan7.html           # Slide bài giảng Toán 7
├── standard_exams.json        # 11 bộ đề thi chuẩn các khối lớp
├── api.php                    # REST API backend & CSDL handler
├── db.sql                     # Cấu trúc CSDL MySQL
├── .htaccess                  # Cấu hình máy chủ LiteSpeed / Apache
└── README.md
```

---

## 3. Triển khai lên Hostinger hPanel
1. Tạo Subdomain `bdvh.diemdanhsv.com` với Document Root trỏ tới thư mục cài đặt của subdomain.
2. Vào **Git** trên hPanel, kết nối với repository:
   - **Repository:** `https://github.com/Tuan-23880095/bdvh.git`
   - **Branch:** `main`
   - **Install directory:** Thư mục subdomain của `bdvh.diemdanhsv.com`.
3. Bật **Auto-Deployment** để mỗi lần push commit mới, website sẽ tự động cập nhật ngay lập tức.

---

## 4. Phòng thi trực tiếp có giám sát (thi tại lớp)

Kiểm tra trên lớp bằng điện thoại/laptop của học sinh — dùng chung cơ chế giám sát với hệ thống KIEMTRA (diemdanhsv.com/kiemtra).

| Trang | Ai dùng | Việc |
|---|---|---|
| `giam-sat.html` (nút **Phòng Thi Trực Tiếp** ở thay-tuan.html) | Giáo viên | Nhập **đề kín** từ Excel, tạo phòng, chiếu QR, bảng giám sát, kết quả & phân tích câu |
| `phong-thi.html?c=MÃPHÒNG` | Học sinh | Quét QR → đăng nhập tài khoản BDVH (họ tên + SĐT) → 5 phút chuẩn bị → làm bài → nộp |
| `phong-thi-api.php` | — | Các action `room_*`, được `api.php` nạp (không gọi trực tiếp) |

- **Giám sát**: rời trang/chuyển ứng dụng, mất tiêu điểm lâu, chia đôi màn hình, thoát toàn màn hình, copy/dán, tải lại trang, đăng nhập tài khoản trên máy thứ hai. Vượt ngưỡng → khoá bài, GV nhập PIN tại máy HS hoặc mở trên bảng giám sát.
- **Đề kín** (`bdvh_exams.status = 'DRAFT'`): không hiện ở trang luyện tập, `get_exam`/`submit_exam` từ chối. Khi một đề đang dùng trong phòng (LOBBY/RUNNING), đề đó cũng bị khoá ở chế độ luyện tập; phiếu `ket-qua.html` của bài trong phòng chỉ mở sau khi GV **Công bố đáp án**.
- **Kết quả** ghi vào `bdvh_submissions` như bài thường (có phiếu báo điểm, nhận xét, hiện ở bảng điều khiển cũ); thêm phổ điểm, KR-20, độ khó p, độ phân biệt D, phân bố lựa chọn, xuất CSV.
- **Bảng mới** (tự tạo khi gọi action `room_*` lần đầu): `bdvh_rooms`, `bdvh_room_attempts`, `bdvh_room_events`.
- **Nhập đề kín**: cột `loai, cau_hoi, A, B, C, D, dap_an, giai_thich, phan` — `choice` (đáp án A–D), `true_false` (4 ý ở cột A–D, đáp án `Đ,S,Đ,S`), `short` (đáp số). Công thức LaTeX giữa hai dấu `$`.

Kiểm thử trên máy: xem `tools/phong-thi-test.py` trong repo bdvh.
