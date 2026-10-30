# dkv/_gv — tài liệu giảng viên Đá và khoáng vật (KHÔNG có trong Git)

Repo `diemdanhsv` công khai trên GitHub, nên đáp án và PDF của bộ môn **không commit** vào đây
(`.gitignore` chỉ giữ `.htaccess` và file này). Trên máy chủ, thư mục `public_html/dkv/_gv/`
chứa các tệp dưới đây; `dkv/gv.html` mở chúng qua `dkv/tai-lieu.php` (kiểm tra token
LECTURER/ADMIN). Truy cập trực tiếp bị chặn bởi `.htaccess` ở gốc và trong thư mục này.
Hostinger Git deploy chỉ kéo tệp có trong Git, không xoá tệp bị bỏ qua (giống `api/lib/config.php`).

| Tệp | Nội dung | Nguồn |
|---|---|---|
| `KeHoach_HuongDan_ThucTap_DKV.html` | Lịch, chuẩn bị, diễn tiến buổi, trình tự xác định khoáng vật và đá, lỗi hay gặp, đánh giá, soát lỗi tài liệu | Soạn từ Tài liệu thực tập Địa chất đại cương (BM Địa chất cơ sở, 1999), phiếu dự kiến học phần, `dkv_phieu.json` |
| `DapAn_Phieu_Tuan1_den_Tuan5.html` | Đáp án câu hỏi vận dụng, rubric, nguyên tắc chấm 5 phiếu | Dựng tự động từ `api/lib/dkv_phieu.json` |
| `BangDoiChieu_KhoangVat_Da.html` | 52 khoáng vật, 27 loại đá — dữ liệu phần mềm dùng để chấm | Dựng tự động từ trường `reference` của `dkv_phieu.json` |
| `BangTra_KhoangVat_HoanChinh.pdf` | Bảng tra khoáng vật 22 cột (32 tr.) | Bộ môn, bản gốc TCVN3 |
| `HuongDan_ThucHanh_MauKhoangVat_2023.pdf` | Hướng dẫn thực hành mẫu khoáng vật (23 tr.) | BM Thạch học và Khoáng sản, 2023 |
| `DanhSach_KhoangVat_Bai2-5.pdf` | Danh sách khoáng vật thực hành Bài 2–5 | Bộ môn |
| `Phieu_MoTa_KhoangVat_BanGiay.pdf` | Phiếu mô tả bản giấy (dự phòng mất mạng) | Bộ môn |

**Tải lên / cập nhật:** hPanel → diemdanhsv.com → File Manager → `public_html/dkv/_gv/` →
Upload gói `dkv_gv.zip` → Extract (ghi đè). Giữ nguyên `.htaccess` của thư mục.
Sửa `dkv_phieu.json` thì dựng lại hai tệp “Dựng tự động” rồi tải lên lại.

**Không** đặt bảng điểm hay tệp có tên sinh viên vào đây.
