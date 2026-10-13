# api/lib/pdf — thư viện tạo PDF (docs/04-API-PHP.md mục 31)

- `tfpdf.php`, `font/unifont/ttfonts.php`: **tFPDF 1.33** (2022-12-20, dựa trên FPDF 1.85) — https://github.com/Setasign/tFPDF, giấy phép **LGPL-2.1**. Chép nguyên văn, không sửa (tFPDF 1.33 trông vào autoload của composer nên `api/lib/khtd_pdf.php` tự `require` `ttfonts.php`).
- `font/unifont/LiberationSans-*.ttf`: **Liberation Sans 2.x** (Red Hat, tương thích số đo Arial, đủ dấu tiếng Việt) — **SIL Open Font License 1.1**, xem `LICENSE-LiberationFonts-OFL.txt`.
- Các tệp `*.mtx.php`, `*.cw.dat`, `*.cw127.php` do tFPDF tự sinh lần đầu dùng (chứa đường dẫn tuyệt đối) — đã `.gitignore`. Thư mục `api/lib/` bị `.htaccess` chặn truy cập web.
