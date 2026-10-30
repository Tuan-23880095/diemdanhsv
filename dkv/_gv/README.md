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
| `HS_DeCuong_ChiTiet_C4_C5_Muc6-3.html`, `HS_GiaoAn_…`, `HS_GiaoTrinh_…` (.html, .pdf), `HS_BoTriFile_README.html` | Hồ sơ phần lý thuyết C4, C5, 6.3 | `D:\2026-Da&KV\_v1.1\` (bản kiểm định 22/9/2026), đổi `6.3` → `6-3` trong tên tệp |
| `DG_KiemTra_ThuongXuyen_KTTX.html`, `DG_KiemTra_GiuaKy_phanC4.html`, `DG_Thi_CuoiKy_…`, `DG_PhieuHocTap_…`, `DG_PhieuThucHanh_…`, `DG_Rubric_Bloom_DanhGia.html` | Đề kiểm tra kèm đáp án, phiếu, rubric | `D:\2026-Da&KV\_v1.1\04_DanhGia\` |

4 PDF của bộ môn (bảng tra, hướng dẫn thực hành, danh sách Bài 2–5, phiếu giấy) đã chuyển sang `dkv/tai-lieu/` (công khai, có trong Git) từ 31/10/2026 để sinh viên tham khảo.

**Tải lên / cập nhật:** hPanel → diemdanhsv.com → File Manager → `public_html/dkv/_gv/` →
Upload gói `dkv_gv.zip` → Extract (ghi đè). Giữ nguyên `.htaccess` của thư mục.
Sửa `dkv_phieu.json` thì dựng lại hai tệp “Dựng tự động” rồi tải lên lại.

Tên tệp chỉ dùng chữ không dấu, số, `_`, `-` (không có dấu chấm ngoài đuôi), nếu không `tai-lieu.php` từ chối.

**Không** đặt bảng điểm hay tệp có tên sinh viên vào đây.
