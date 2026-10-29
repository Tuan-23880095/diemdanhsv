# KIEMTRA — Kiểm tra trực tuyến tại lớp

**Địa chỉ chạy thật:** `https://diemdanhsv.com/kiemtra/`
**Chủ nhiệm:** ThS. Đinh Quốc Tuấn — Khoa Địa chất, Trường ĐH Khoa học Tự nhiên, ĐHQG-HCM

Hệ thống thi trắc nghiệm **trực tiếp trên lớp** bằng điện thoại/laptop của sinh viên:
quét QR → 5 phút chuẩn bị → làm bài có giám sát thiết bị → chấm tự động →
phân tích khảo thí và **mức đạt chuẩn đầu ra (CLO)** → chuyển điểm vào bảng điểm diemdanhsv.com.

## Các trang

| Trang | Ai dùng | Việc |
|---|---|---|
| `index.html` | SV | Nhập mã phòng (khi không quét QR) |
| `sv.html?c=MÃPHÒNG` | SV | Vào phòng bằng MSSV, chuẩn bị, làm bài, nộp, xem lại đáp án + giải thích |
| `gv.html` | GV | CLO, ngân hàng câu hỏi (ảnh, giải thích, nhập từ Excel), soạn đề theo ma trận, chiếu QR, giám sát, kết quả, khảo thí, báo cáo CLO |
| `api/index.php` | — | API JSON (PHP 8.1+, PDO MySQL) |

## Dùng chung với diemdanhsv.com

KIEMTRA **không có CSDL hay tài khoản riêng**:

- đọc `domains/diemdanhsv.com/private/config.php` (file bí mật sẵn có của site chính);
- GV đăng nhập bằng tài khoản `users` của hệ thống điểm danh; chỉ thấy lớp mình phụ trách (`classes.LecturerID`);
- SV phải có trong `enrollments` của lớp mới vào được phòng thi;
- điểm thang 10 được ghi vào `grade_columns`/`grades` (mặc định cột “Giữa kỳ”) — SV xem ở trang Xem điểm;
- chỉ thêm 7 bảng tiền tố `kt_` (`db/schema.mysql.sql`), không sửa bảng nào của site chính.

## Triển khai lên Hostinger

Gói Premium cho tối đa **3 website** và đã dùng hết (diemdanhsv.com, hopviec, bdvh), nên KIEMTRA **không chạy
như website riêng**. Thay vào đó, GitHub Action `.github/workflows/dong-bo-diemdanhsv.yml` chép KIEMTRA vào
thư mục `kiemtra/` của repo **diemdanhsv** mỗi khi đẩy code; Hostinger đang auto-deploy diemdanhsv nên trang
chạy tại **`https://diemdanhsv.com/kiemtra/`**.

1. **Tạo bảng**: hPanel → Databases → phpMyAdmin → chọn CSDL của diemdanhsv → *Import* → `db/schema.mysql.sql`.
2. **Tạo token**: GitHub → Settings → Developer settings → Fine-grained tokens → *Generate new token*:
   chỉ repo `diemdanhsv`, quyền **Contents: Read and write**.
3. **Gắn token vào KIEMTRA**: repo KIEMTRA → Settings → Secrets and variables → Actions → *New repository secret*
   `DIEMDANHSV_TOKEN` = token vừa tạo.
4. **Chạy đồng bộ**: KIEMTRA → Actions → “Dong bo sang diemdanhsv/kiemtra” → *Run workflow* (các lần sau tự chạy).
   Nếu nhánh `main` của diemdanhsv bật bảo vệ (bắt buộc PR), cho phép token này bỏ qua hoặc đổi workflow sang tạo PR.
5. **Kiểm tra**: `https://diemdanhsv.com/kiemtra/api/index.php?action=ping` → `{"status":"success",…}`;
   mở `https://diemdanhsv.com/kiemtra/gv.html` → đăng nhập bằng tài khoản điểm danh.

Nếu sau này có website trống (nâng gói hoặc bỏ một website), có thể triển khai thẳng lên subdomain
`kiemtra.diemdanhsv.com` bằng Deployments của hPanel — mã chạy được cả hai cách.

Mã tự tìm file bí mật dùng chung `domains/diemdanhsv.com/private/config.php` ở cả hai cách bố trí thư mục của
subdomain trên Hostinger, nên **không cần** tạo config mới.

Ảnh câu hỏi lưu ở `private/kiemtra-uploads/` (ngoài `public_html`, không bị auto-deploy xoá).

## Chống gian lận — làm được gì, không làm được gì

Trình duyệt **phát hiện và ghi nhận**, không điều khiển được hệ điều hành:

| Phát hiện (tính vi phạm) | Ghi chú |
|---|---|
| Rời trang: chuyển tab, mở app khác, về màn hình chính, tắt màn hình | `visibilitychange` — mọi thiết bị |
| Mất tiêu điểm lâu hơn N giây: thanh thông báo, cửa sổ nổi, cửa sổ khác | `blur` — có ngưỡng bỏ qua để tránh tính oan |
| Chia đôi màn hình / thu nhỏ cửa sổ | so diện tích cửa sổ với lúc bắt đầu (bỏ qua khi bàn phím ảo bật) |
| Thoát toàn màn hình | laptop, Android; **iPhone không có API toàn màn hình** |
| Sao chép / cắt / dán, F12, Ctrl+Shift+I, PrintScreen | bị chặn + ghi |
| Tải lại / mở lại trang khi đang thi | |
| Cùng MSSV vào từ thiết bị thứ hai | bị **chặn**; GV bấm “Cho đổi máy” khi máy hỏng/hết pin |

Vượt ngưỡng (mặc định 3) → **khoá bài** (GV nhập PIN 6 số tại máy SV hoặc mở trên bảng giám sát; mỗi lần mở
cho thêm 1 lượt) hoặc **tự nộp** (tuỳ thiết lập). Các tín hiệu cách nhau < 2 giây gộp làm một vi phạm.
Lúc chờ trong phòng chuẩn bị, rời trang **không** bị tính.

Bổ trợ: xáo câu và phương án theo từng SV (giữ thứ tự với phương án “Tất cả các ý trên”, “Cả A và B”…),
hình mờ MSSV + họ tên, đáp án **không bao giờ** gửi xuống máy SV trước khi công bố, đồng hồ theo máy chủ,
câu hỏi đóng băng khi mở phòng, nhật ký sự kiện từng SV.

**Không phát hiện được** (giám thị giám sát trực tiếp): điện thoại/thiết bị thứ hai, chụp bằng phím cứng
trên điện thoại, nhìn bài, trao đổi, AI trên máy khác.

## Khảo thí & CLO

- Phổ điểm, TB, SD, trung vị, tỉ lệ ≥ 5; **Cronbach α** (= KR-20 khi chấm 0/1), **SEM**.
- Từng câu: độ khó **p**, độ phân biệt **D** (nhóm 27% cao/thấp), **r_pb** câu–tổng (đã loại câu đó),
  phân tích phương án nhiễu (số SV chọn, nhóm cao/thấp), cảnh báo câu quá dễ/khó, phân biệt âm, phương án không ai chọn.
- CLO: SV **đạt** khi % điểm phần CLO ≥ ngưỡng (mặc định 50%); lớp **đạt** khi % SV đạt ≥ mục tiêu (mặc định 70%).
  Báo cáo CLO theo lớp gộp nhiều bài kiểm tra.
- Xuất CSV (UTF-8, mở thẳng bằng Excel): bảng điểm + % CLO từng SV; phân tích câu + CLO + thống kê.

## Nhập câu hỏi từ Excel

Cột (dòng đầu là tiêu đề): `loai, cau_hoi, A, B, C, D, E, F, dap_an, giai_thich, clo, bloom, do_kho, diem, chu_de`

- `loai`: `single` | `multi` | `short` — `dap_an`: `B` hoặc `A,C`; câu `short`: `thạch anh|quartz`
- `bloom`: 1–6 hoặc Nhớ/Hiểu/Vận dụng/Phân tích/Đánh giá/Sáng tạo — `do_kho`: 1–3 hoặc Dễ/TB/Khó
- Mẫu 18 câu Đá & khoáng vật: `docs/mau-cau-hoi-da-khoang-vat.csv` (**nội dung mẫu — thầy rà soát trước khi dùng**).

## Chạy thử trên máy

```bash
php tools/dev-setup.php .dev                       # SQLite + dữ liệu mẫu: gv / 123456, lớp 25DC1, 40 SV
KIEMTRA_CONFIG=$PWD/.dev/config.php php -S 127.0.0.1:8090 -t .
php tools/e2e-test.php http://127.0.0.1:8090       # kiểm thử trọn luồng (≈ 90 bước)
```

`tools/`, `db/`, `docs/`, `api/lib/`, `.git` bị `.htaccess` chặn trên host thật.
