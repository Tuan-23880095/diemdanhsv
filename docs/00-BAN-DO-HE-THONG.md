# 00 — Bản đồ hệ thống diemdanhsv.com

Cập nhật: 29/10/2026. **Đọc file này đầu tiên** — người hay công cụ AI nào mở repo
cũng biết ngay trang có những mục gì, mã nằm đâu, việc gì đang treo.

> Quy ước: **thêm, đổi tên hay gỡ một mục thì sửa bảng ở mục 2 trong cùng pull request.**
> Bản trực quan (tìm kiếm, đánh dấu việc): trang artifact "Bản đồ diemdanhsv.com" của chủ nhiệm.
> Việc còn treo được nhắc hằng ngày qua Hộp việc (dự án "Web điểm danh sinh viên (diemdanhsv)").

---

## 1. Ba website trên gói hosting (đã dùng 3/3)

| Website | Mã nguồn | Ghi chú |
|---|---|---|
| `diemdanhsv.com` | repo `diemdanhsv`, nhánh `main` → Deployments tự động vào `public_html` | Mọi mục ở bảng 2, kể cả `bdvh/` và `kiemtra/` |
| `hopviec.diemdanhsv.com` | repo `hop-viec` | Hộp việc của đội agent (PHP + SQLite, có MCP) |
| `dqtuanchatgpt.diemdanhsv.com` | repo `dqtuanMETAchatgpt` | Website riêng — bổ sung mô tả khi rà |

Subdomain tạo trong mục *Subdomains* của diemdanhsv.com (không tốn suất website) trỏ vào
**thư mục con** của `public_html`. `bdvh.diemdanhsv.com` chạy từ thư mục `bdvh/` của repo này
(cần xác nhận một lần trong hPanel → Subdomains). Repo `bdvh` riêng là bản sao — xem mục 4.

**Mục mới**: đặt thành thư mục con (như `/kiemtra`), không tạo website mới.

---

## 2. Các mục trên trang

### Nền tảng điểm danh & điểm

| Mục | Học viên | Giảng viên | Mã chính · tài liệu |
|---|---|---|---|
| Điểm danh | `pages/student.html` | `pages/lecturer.html` | `api/lib/attendance.php`, `roles.php` · docs/04 mục 13 |
| Xem điểm (mã qua email) | `pages/diem.html` | — | `grading.php`, `gradeauth.php`, `mailer.php` · mục 14, 17, 25 |
| Quản trị môn, lớp, SV, nhập điểm | — | `pages/admin.html` | `admin.php` · mục 16 |
| Lưu trữ cuối học kỳ | — | `pages/luu-tru.html` | `archive.php`, `api/luu-tru.php` · mục 32 |

### Học phần & phiếu online

| Mục | Học viên | Giảng viên | Mã chính · tài liệu |
|---|---|---|---|
| Thực tập Khoa học Trái đất | `khtd/index.html`, `khtd/online.html` | `khtd/gv.html`, `khtd/quanly.html`, `khtd/Rubric_R1-R4.html` | `khtd*.php` · mục 26–33 |
| Thực tập Cơ học đất (GEO10404) | `chd-tt/index.html`, `chd-tt/online.html` | `chd-tt/gv.html`, `chd-tt/quanly.html` | `chd.php`, `chd_phieu.json` · mục 34 |
| Đá và Khoáng vật (LEC10001) | `dkv/index.html`, `dkv/online.html`, `dkv/sv.html` | `dkv/gv.html`, `dkv/trinhchieu.html`, `dkv/quanly.html` | `dkv.php`, `liveroom.php`, `dkv/tai-lieu.php` · mục 35–37 |
| Kiểm tra trực tuyến | `kiemtra/`, `kiemtra/sv.html?c=MÃ` | `kiemtra/gv.html` | `kiemtra/api/` · bảng `kt_*` · gốc ở repo `KIEMTRA` |

### Tuyển sinh & sự kiện

| Mục | Học viên | Giảng viên | Mã chính |
|---|---|---|---|
| La bàn Hướng nghiệp 1 | `/tvts01` | — | `TVTS01/index.htm`, `.htaccess` |
| La bàn Hướng nghiệp 2 | `TVTS02.html` | `tvts02/admin.html` | `tvts02/api.php`, `buoc1.php`, `kb.php` |
| Workshop Tân sinh viên K2026 | `workshop/sv.html`, `cam-nang.html`, `nghien-cuu.html`, `theo-doi.html` | `workshop/index.html` | `workshop/api.php` |

### BDVH — Bồi dưỡng văn hoá & ĐGNL (`bdvh/`, chạy tại bdvh.diemdanhsv.com)

| Trang | Ai dùng | Việc |
|---|---|---|
| `index.html`, `thi.html`, `ket-qua.html` | Học sinh | Luyện đề, phiếu báo điểm |
| `thay-tuan.html`, `giao-vien.html`, `slide-*.html` | Giáo viên | Mã lớp, kết quả, trình chiếu |
| `giam-sat.html`, `phong-thi.html` | GV / HS | Phòng thi trực tiếp có giám sát (từ 29/10/2026) |

Backend riêng `bdvh/api.php` (+ `phong-thi-api.php`), dùng chung CSDL qua `../api/lib`; bảng `bdvh_*`.

---

## 3. Hạ tầng

| Thứ | Ở đâu |
|---|---|
| CSDL | `u464424582_diemdanh` (thật), `u464424582_ddtest` (smoke test) — `db/schema.sql` + `db/migrations/`; KIEMTRA thêm `kt_*`, BDVH dùng `bdvh_*` |
| Bí mật | `domains/diemdanhsv.com/private/config.php` (ngoài public_html) — CSDL, SMTP, Gemini |
| Sao lưu | cron hằng ngày `tools/backup.php` → `private/backups/` |
| SSH | `ssh -p 65002 u464424582@<host>` rồi `cd domains/diemdanhsv.com/public_html` |
| Email | `noreply@diemdanhsv.com` (SMTP Hostinger) |
| Tài liệu | `docs/01` thiết kế · `docs/02` bàn giao trạng thái · `docs/04` hợp đồng API · `docs/05–06` bảo mật, checklist |
| Kho tri thức | NotebookLM `web-diemdanh-xemdiem` · Drive `Claude-Agent/diemdanhsv/` (STATE mới nhất) |
| Repo liên quan | `KIEMTRA` (gốc của `kiemtra/`) · `bdvh` (bản sao của `bdvh/`) · `hop-viec` · `dqtuanMETAchatgpt` |

---

## 4. Việc còn treo (29/10/2026)

| Ưu tiên | Việc | Ghi chú |
|---|---|---|
| Cao | Merge nhánh `bdvh-phong-thi` | Phòng thi trực tiếp cho BDVH lên trang thật |
| Cao | Chạy thử KIEMTRA bằng đề 3 câu trước buổi giữa kỳ; rà 18 câu mẫu Đá & khoáng vật | |
| Sớm | Chọn một bản BDVH làm gốc | Thư mục `bdvh/` (đang chạy) và repo `bdvh` dễ lệch; đề xuất: sửa ở `bdvh/`, repo `bdvh` để lưu trữ |
| Sớm | Gỡ đường lùi Apps Script `?api=gas` | Kế hoạch sau ≥ 1 tuần từ cutover 03/10; đổi `?v=` ở 4 trang `pages/*.html` |
| Khi rảnh | Liên kết "Kiểm tra trực tuyến" trên trang chủ | Trang chủ chưa trỏ tới `/kiemtra/` |
| Khi rảnh | Giới hạn xem `bdvh/ket-qua.html?sub_id=` | Bài luyện tập xem được nếu đoán số; bài trong phòng thi đã chặn |
| Khi rảnh | Ẩn/hiện link "Quản trị" trên trang chủ; xuất bảng điểm CSV/Excel | docs/02 mục 4 |
| Khi rảnh | Giữ hay xoá dữ liệu demo `DEMO101` | |

Việc xong thì xoá dòng ở đây và đóng việc trong Hộp việc.
