<?php
/**
 * api/lib/gtn.php — Bộ phiếu online "Seminar Giới thiệu ngành Địa chất" (tân sinh viên) chạy trên module phiếu
 * học tập khtd (docs/04-API-PHP.md mục 41). Giống bộ CHĐ/Đ&KV: không thêm bảng, không thêm action, không migration;
 * dùng lại mã vào lớp, mã ôn tập, chấm theo rubric, phiếu điểm PDF, thống kê, lưu trữ cuối học kỳ.
 *
 *  - Nội dung phiếu ở api/lib/gtn_phieu.json (thư mục api/lib bị .htaccess chặn web).
 *    Số 1 = Thẻ khám phá bản thân (cá nhân) · 2 = Họp nhóm lần đầu · 3 = Đề cương (mốc tuần 1) ·
 *    4 = Tiến độ tuần 2 · 5 = Phản biện nhóm bạn (tuần 3) · 6 = Đánh giá chéo trong nhóm và tự nhìn lại.
 *  - khtdSeedWorksheets {token, classId, bo: 'gtn'} tạo các phiếu này cho lớp (bỏ qua số đã có).
 *  - Trang: gtn-dc/index.html (cẩm nang + khám phá bản thân, chạy trên máy SV, không gửi dữ liệu),
 *    gtn-dc/online.html (SV làm phiếu), gtn-dc/quanly.html (GV, KHTD_BO='gtn').
 */

/** Bộ phiếu GTN: [No => định nghĩa] như khtd_default_worksheets(). */
function gtn_default_worksheets(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $raw = @file_get_contents(__DIR__ . '/gtn_phieu.json');
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($data)) throw new RuntimeException('Thiếu hoặc hỏng tệp gtn_phieu.json.');
    $out = [];
    foreach ($data as $no => $def) {
        if (!is_array($def) || !isset($def['title'], $def['sections'], $def['rubric'])) continue;
        $out[(int) $no] = $def;
    }
    ksort($out);
    return $cache = $out;
}
