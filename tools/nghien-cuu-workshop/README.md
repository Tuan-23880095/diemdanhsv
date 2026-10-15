# Bộ phân tích dữ liệu nghiên cứu — Workshop K2026 “Làm chủ thời gian”

Thư mục này nằm trong `tools/` nên bị `.htaccess` chặn truy cập từ web. Ở đây chỉ có mã, **không có dữ liệu**. Đừng commit tệp CSV dữ liệu thật vào repo.

## Lấy dữ liệu

Mở bảng diễn giả (phím H trên slide), đăng nhập PIN, rồi bấm **Tải dữ liệu nghiên cứu (CSV)**. Mỗi dòng trong tệp là một mã ẩn danh. Tệp không chứa địa chỉ IP.

## Chạy phân tích (Windows)

```powershell
pip install pandas scipy matplotlib python-docx openpyxl
python phan_tich.py "D:\...\du_lieu_nghien_cuu.csv"
```

Kết quả được ghi vào thư mục `ket_qua_<ngày giờ>`, đặt cạnh tệp CSV:

| Tệp | Nội dung |
|---|---|
| `bao_cao_phan_tich.docx` | Báo cáo tiếng Việt, gồm 17 bảng, 4 hình và các câu kết quả viết sẵn theo APA |
| `ket_qua_phan_tich.xlsx` | Toàn bộ bảng số, dữ liệu đã chấm điểm và trang `Tra_loi_mo` để mã hóa câu trả lời mở |
| `du_lieu_da_cham.csv` | Dữ liệu đã đảo chiều và tính điểm thang, dùng được cho SPSS, R, AMOS |
| `cfa_tmq.R` | CFA 3 nhân tố của TMQ, chạy bằng R và lavaan với ước lượng WLSMV |
| `bieu_do/*.png` | Biểu đồ dùng cho bài báo |

Tùy chọn:

- `--thap-phan .` — dùng dấu chấm thập phân. Mặc định là dấu phẩy, theo thói quen của tạp chí trong nước.
- `--loai-mot-kieu` — loại những người trả lời cả 18 câu TMQ cùng một mức, dùng cho phân tích độ nhạy.
- `--ra <thư mục>` — chọn thư mục kết quả.

## Chạy thử bằng dữ liệu giả lập

```powershell
python phan_tich.py --gia-lap GIA_LAP_du_lieu.csv
python phan_tich.py GIA_LAP_du_lieu.csv
```

Mã ẩn danh trong dữ liệu giả lập luôn bắt đầu bằng `ZZ`, một tổ hợp không có trong tên tiếng Việt. Gặp những mã này, bộ phân tích tự đóng dấu **DỮ LIỆU GIẢ LẬP** lên báo cáo, tệp Excel và biểu đồ.

## Khóa chấm và phép phân tích

- **TMQ** (Britton & Tesser, 1991, Bảng 1): 18 câu, chia thành lập kế hoạch ngắn hạn (SRP, câu 1–7), thái độ với thời gian (TA, câu 8–13) và lập kế hoạch dài hạn (LRP, câu 14–18).
  - Câu **8, 10, 11, 13, 16** chấm đảo (6 − x). Trong tệp CSV, cột của các câu này có hậu tố `_R`.
  - Điểm thang là trung bình các câu; điểm cao nghĩa là quản lý thời gian tốt hơn.
  - Các nghiên cứu trước báo cáo α thấp cho TA và LRP (khoảng 0,5). Vì vậy cần báo cáo α/ω của từng thang và CFA.
- **Kiến thức**: 8 câu, đáp án [2, 1, 2, 0, 1, 1, 2, 2] (chỉ số bắt đầu từ 0).
- **Các phép kiểm định**:
  - So sánh trước–sau: t ghép cặp kèm d_z và 95% KTC, đối chiếu bằng Wilcoxon.
  - Từng câu kiến thức: McNemar chính xác.
  - Ý định → vận dụng: tương quan Spearman.
  - Người tham dự so với không tham dự: Mann–Whitney kèm Hedges g.
  - Hao hụt mẫu: so sánh điểm nền giữa người ở lại và người rời đi.
  - Đa kiểm định: hiệu chỉnh Holm trong từng họ giả thuyết.

Khi nc-lib.php đổi cột, phải cập nhật `csv_header()` trong `phan_tich.py` cho khớp. Bộ phân tích sẽ báo lỗi nếu tệp CSV thiếu cột.
