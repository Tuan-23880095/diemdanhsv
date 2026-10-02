# 06 — GĐ9: chạy thử song song + checklist 7 mục trước cutover

Giai đoạn 9/10 của PLAN. Mục tiêu: chạy backend PHP **song song** với Apps
Script trên dữ liệu thật, đối chiếu từng số liệu, chỉ khi 7/7 mục dưới đây đạt
thì mới sang GĐ10 (đổi `API_URL`, cutover).

Trang web đang chạy thật **không đổi gì** khi làm checklist này: mặc định vẫn
gọi Apps Script. Bản PHP chỉ được gọi khi thêm `?api=php` vào địa chỉ.

## Cách bật chế độ chạy thử

Thêm `?api=php` vào cuối địa chỉ bất kỳ trang nào:

| Trang | Địa chỉ chạy thử |
|---|---|
| Điểm danh (sinh viên) | `https://diemdanhsv.com/pages/student.html?api=php` |
| Xem điểm | `https://diemdanhsv.com/pages/diem.html?api=php` |
| Giảng viên | `https://diemdanhsv.com/pages/lecturer.html?api=php` |
| Quản trị | `https://diemdanhsv.com/pages/admin.html?api=php` |

Khi đang ở chế độ thử, mọi trang có **dải băng vàng** trên cùng ("CHẾ ĐỘ CHẠY
THỬ…") kèm liên kết quay lại bản thật. Lựa chọn được ghi nhớ trong tab đang
mở (`sessionStorage`), nên bấm qua trang khác vẫn còn; mở tab mới là về bản
thật. Về bản thật ngay bằng `?api=gas`.

Chỉ nhận đúng hai giá trị `gas` và `php` (`API_TARGETS` trong
`js/config/config.js`). **Không** nhận URL tuỳ ý trong tham số — nếu nhận, ai
gửi cho sinh viên một đường link `?api=https://trang-la...` là lấy được MSSV,
mã điểm danh và mật khẩu giảng viên, mà địa chỉ trang vẫn là tên miền thật nên
không ai nghi.

## Chuẩn bị (làm một lần, trước khi vào checklist)

1. **Xuất dữ liệu thật từ Apps Script.** Tạo một thư mục Drive **riêng tư**,
   dán ID vào `CONFIG.EXPORT_FOLDER_ID` (`gas/00-Config.gs`), rồi chạy
   `exportAllToDriveJSON()` trong trình soạn thảo Apps Script. Hàm in ra tên
   file và số dòng từng bảng — **chụp lại số dòng này**, mục 2 cần đối chiếu.
2. **Tải file JSON lên host** (SFTP/File Manager) vào `../private/import/`,
   tức `domains/diemdanhsv.com/private/import/` — ngoài `public_html`.
3. **Nạp vào CSDL thật** (SSH, từ `public_html`):
   ```
   php tools/import.php --file=../private/import/<tên file>.json --dry-run
   php tools/import.php --file=../private/import/<tên file>.json --yes
   ```
   Đọc kỹ bảng đối soát của `--dry-run` trước khi chạy `--yes`. Có cảnh báo
   khoá ngoại thì **dừng**, gửi Quản gia xem.
4. **Sao lưu ngay sau khi nạp:** `php tools/backup.php`.

## Checklist 7 mục

Đánh dấu khi đạt. Mục nào không đạt thì ghi lại nguyên văn lỗi và gửi Quản gia
— **không sang GĐ10 khi còn mục chưa đạt**.

> **Tiến độ (cập nhật 02/10/2026, 22:55):** chuẩn bị 1–4 **xong** (xuất
> `diemdanhsv-export-20261002-133752.json` từ dự án Apps Script gắn Sheet
> "diemdanh"; nạp bằng bản `.clean.json` bỏ 3 `attendance_keys` mồ côi; sao
> lưu `diemdanhsv-backup-20261002-074308.sql.gz`). **Mục 2 ĐẠT** (`KẾT LUẬN:
> KHỚP`, sau hai lần sửa `import.php`/`compare` — docs/04 mục 23). **Mục 3
> ĐẠT** (22:35 — sau khi đặt lại mật khẩu 1607 bằng `tools/set_password.php
> --legacy`, PR #23; đăng nhập/sai mật khẩu/đăng xuất-đăng nhập lại/chỉ thấy lớp
> mình đều đúng; PHP đã rehash). **Mục 1 ĐẠT** (22:50 — ping `php-0.7`; ba
> địa chỉ `api/lib/db.php`, `tools/smoke_test.php`, `db/schema.sql` đều 403).
> **Mục 7** đạt 4/5 dòng: sao lưu OK, `gunzip -t` OK, smoke **204/204** trên
> CSDL thử (sau PR #24), `migrate.php` không `--yes` từ chối đúng; **còn đặt
> cron** sao lưu trong hPanel. Mục 4, 6 chưa làm. Mục 5 **"chưa kiểm được"** (chưa có hộp thư
> `noreply@`). Sau checklist, nhớ xoá file JSON dữ liệu thật trong
> `../private/import/` và trong thư mục Drive xuất.

### ☑ 1. Hạ tầng và phiên bản đang chạy — ĐẠT 02/10/2026

- `https://diemdanhsv.com/api/index.php?action=ping` trả
  `{"status":"success",...,"version":"php-0.7"}` (hoặc mới hơn; `php-0.7` = đã có
  giới hạn tần suất M4). Mở trong trình duyệt là đủ.
- `https://diemdanhsv.com/api/lib/db.php` trả **403** (không lộ mã nguồn).
- `https://diemdanhsv.com/tools/smoke_test.php` trả **403**.
- `https://diemdanhsv.com/db/schema.sql` trả **403**.

### ☑ 2. Dữ liệu khớp từng bảng — ĐẠT 02/10/2026

```
php tools/compare_gas_php.php --file=../private/import/<tên file>.json
```

Dòng cuối phải là **`KẾT LUẬN: KHỚP`**. Cột "Thiếu" và "Lệch" đều 0; số dòng
từng bảng khớp con số đã chụp ở bước chuẩn bị 1. Cột "Thừa" chỉ được khác 0 nếu
thầy đã tự nhập thêm trên trang quản trị sau khi xuất — khi đó xem danh sách ID
in ra để chắc đúng là dòng mình vừa thêm.

### ☑ 3. Đăng nhập giảng viên và mật khẩu cũ — ĐẠT 02/10/2026

Mở `pages/lecturer.html?api=php`, đăng nhập bằng **mật khẩu cũ** đang dùng với
Apps Script. (Quên mật khẩu → `php tools/set_password.php --user=<id> --generate
--legacy --yes`, dán Salt/PasswordHash vào sheet 01_USERS để Apps Script cũng
nhận — rồi kiểm mục này với mật khẩu mới; luồng rehash vẫn đúng như dưới.)

- Đăng nhập được, hiện đúng tên.
- Chỉ thấy đúng các lớp mình đứng tên (giảng viên khác không hiện).
- Sai mật khẩu → báo "Sai tên đăng nhập hoặc mật khẩu."
- Sau lần đăng nhập đầu, mật khẩu đã được chuyển sang dạng mã hoá mới; đăng
  xuất rồi đăng nhập lại vẫn vào được (và Apps Script vẫn dùng được như cũ).

### ☐ 4. Một buổi điểm danh thật, so với Apps Script

Chọn **một buổi học thật** (hoặc một buổi thử ngoài giờ dạy):

1. Mở điểm danh trên `lecturer.html?api=php`, lấy mã 4 ký tự.
2. Dùng 2–3 điện thoại khác nhau điểm danh ở `student.html?api=php`: một em
   đúng giờ, một em cố tình điểm danh hộ (cùng một máy, hai MSSV).
3. Màn hình giảng viên phải: đếm đúng số có mặt, hiện **cảnh báo trùng thiết
   bị**, hiện cờ GPS nếu ngoài bán kính, tự làm mới sau mỗi ~10 giây.
4. Đóng điểm danh → các em không điểm danh bị đánh **Vắng**.
5. Thử lại sau khi đóng → bị từ chối.
6. Thử một MSSV không thuộc lớp → bị từ chối.
7. Mở `pages/diem.html?api=php` → nhập MSSV đó, xem lịch sử điểm danh khớp với
   những gì vừa làm.

So số liệu buổi này với Apps Script (mở bản thật, cùng buổi): số có mặt, trễ,
vắng phải **giống nhau**.

### ☐ 5. Xem điểm hai bước và điểm chuyên cần

Trên `diem.html?api=php`, với **một MSSV thật có email thật**:

- Xin mã → nhận được email (mục này cần hộp thư `noreply@diemdanhsv.com` đã
  tạo và `api/lib/mailer.php` đã thay stub; **chưa có thì ghi "chưa kiểm được"
  và để lại mục này**, không coi là đạt).
- Nhập mã đúng → thấy bảng điểm; nhập sai 5 lần → mã bị huỷ.
- Điểm tổng và điểm chuyên cần khớp với bảng điểm trên trang quản trị
  (`admin.html?api=php` → tab Điểm & chuyên cần).
- Em nào đủ ngưỡng cấm thi thì cả hai trang đều báo cấm thi.

### ☐ 6. Quản trị và nhập liệu hàng loạt

Trên `admin.html?api=php`:

- Tạo một môn và một lớp **demo** (đặt tên rõ là demo), nhập CSV danh sách lớp
  demo: kiểm tra trước → nhập thật → số dòng khớp.
- Nhập điểm CSV demo (tiêu đề có trọng số trong ngoặc): kiểm tra trước → nhập
  thật → bảng điểm hiện đúng.
- Bấm "Tính thử" chuyên cần rồi "Ghi vào bảng điểm"; chạy lại lần nữa phải
  **không** sinh thêm cột "Chuyên cần" thứ hai.
- Vào tab Buổi học, bấm "Điểm danh tay" một buổi, đổi một em từ Vắng sang Vắng
  có phép → bảng chuyên cần đổi theo.
- Đăng nhập bằng tài khoản **giảng viên thường**: không thấy nút "Môn mới",
  không đổi được môn/giảng viên của lớp, không thấy lớp người khác.
- Xoá dữ liệu demo sau khi xong (hoặc đặt trạng thái Ngưng).

### ☐ 7. Sao lưu, phục hồi và rào an toàn — 4/5 (02/10/2026), còn cron

- `php tools/backup.php` → dòng `OK (...)`, file trong `../private/backups/`.
- Giải nén thử: `gunzip -t ../private/backups/<file>.sql.gz` không báo lỗi. ✓ 02/10
- Đã đặt cron sao lưu hằng ngày trong hPanel (Advanced → Cron Jobs), lệnh mẫu ở
  đầu `tools/backup.php`. (Từ PR #24 cron này cũng dọn `auth_tokens` hết hạn.) **☐ chưa**
- `php tools/smoke_test.php --config=../private/config.test.php` → **0 FAIL**
  (chạy trên CSDL **thử**, không phải CSDL thật). 02/10: 193/193 trước PR #24;
  **204/204** sau PR #24 (22:20) — dòng này ĐẠT.
- Thử `php tools/migrate.php` (không có `--yes`) → chỉ in hướng dẫn, không chạy. ✓ 02/10
  (nhận đúng CSDL thật, in "thiếu --yes — KHÔNG chạy gì").

## Sau khi 7/7 đạt — chuẩn bị GĐ10

Quản gia sẽ làm, thầy duyệt PR:

1. Đổi `API_TARGETS.gas` → URL PHP (hoặc `DEFAULT_API = 'php'`) trong
   `js/config/config.js`, giữ `?api=gas` trỏ Apps Script thêm ≥ 1 tuần để còn
   đường lùi. **Đồng thời đổi `?v=` của `config.js` trong 4 trang `pages/*.html`**
   (ví dụ `?v=20261010`) để mọi máy tải lại cấu hình mới — 02/10 phát hiện
   host/trình duyệt vẫn trả `config.js` bản cũ dù đã deploy; `.htaccess` nay
   đặt `Cache-Control: no-cache` cho `config.js` và `*.html` nhưng cache đã có
   sẵn trên máy sinh viên chỉ bị bỏ khi tên file (query `?v=`) đổi.
2. Tắt workflow Firebase (chuyển sang `workflow_dispatch`).
3. Cập nhật `docs/02-BAN-GIAO-TRANG-THAI.md`.
4. Theo dõi 1 tuần điểm danh thật không sự cố.

**Nên làm trước cutover** (xem `docs/05-GD5-smoke-review.md`):

- ~~**M4 — giới hạn số lần gọi (rate limit)**~~ **ĐÃ LÀM 02/10/2026** (PR gia cố,
  `docs/04-API-PHP.md` mục 19): `login` (theo IP + theo tên đăng nhập), `checkin`,
  `requestGradeCode`, `verifyGradeCode` theo IP; chỉ đếm lần sai nên lớp học
  thật trên WiFi chung không bị chặn oan. **Cần chạy `php tools/migrate.php
  --yes` (migration 005) trên host** — code fail-open khi chưa có bảng.
- ~~**M5 —** chưa kẹp số phút mở mã điểm danh.~~ **ĐÃ LÀM 02/10/2026** — kẹp 1–60
  phút, mốc trễ ≤ mốc hết hạn.
- ~~**M2 —** check-in lần hai của cùng MSSV ghi đè dấu vết thiết bị và trạng thái.~~
  **ĐÃ LÀM 02/10/2026** (thầy duyệt; PR riêng, `docs/04` mục 20): lần 2+ giữ bản
  ghi đầu, trạng thái không hạ, chỉ nối Note. Không cần migration.
- ~~**L8 —** thông báo "không tìm thấy" vs "không có quyền" lộ ID tồn tại; **L13 —**
  `import.php` chạy lại với export thiếu cột làm trắng cột.~~ **ĐÃ LÀM 02/10/2026**
  (`docs/04` mục 21).
- ~~**L11 —** SV ghi danh muộn bị tính vắng các buổi trước → cấm thi oan; điểm
  tổng giữa kỳ hiển thị nhỏ.~~ **ĐÃ LÀM 02/10/2026** (thầy chọn; `docs/04` mục 22):
  bỏ qua buổi trước ngày ghi danh (có rào cho dữ liệu import cùng ngày); trang
  xem điểm thêm "Tính riêng trên phần đã chấm". Hết mục chờ quyết trước cutover.

## Việc chỉ thầy quyết, đã ghi nhận trong GĐ9

- ~~Sinh viên ghi danh muộn bị tính vắng các buổi đã điểm danh trước đó.~~ **ĐÃ
  SỬA 02/10/2026** — chỉ tính từ ngày ghi danh (`docs/04` mục 22).
- ~~Điểm tổng giữa kỳ hiển thị nhỏ.~~ **ĐÃ SỬA 02/10/2026** — trang xem điểm thêm
  dòng "Tính riêng trên phần đã chấm (x%): …/10".
