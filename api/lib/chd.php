<?php
/**
 * api/lib/chd.php — Bộ phiếu online "Thực tập Cơ học đất" (GEO10404) chạy trên module phiếu học tập khtd
 * (docs/04-API-PHP.md mục 34). Không thêm bảng, không thêm action: dùng lại khtd_worksheets/khtd_submissions,
 * ảnh minh chứng (khtd_photos), chấm Gemini, phiếu điểm PDF.
 *
 *  - Nội dung phiếu nằm ở api/lib/chd_phieu.json (sinh từ nguồn chung cùng bản in A4; thư mục api/lib bị .htaccess
 *    chặn web nên đáp án/dữ liệu chuẩn không lộ). Số 1–6: phiếu thực hành; 11–15: các mốc chuyên đề nhóm.
 *  - khtdSeedWorksheets {token, classId, bo: 'chd'} tạo các phiếu này cho lớp (bỏ qua số đã có).
 *  - SchemaJSON có thêm: 'mon' (tên môn, mã gắn tiêu đề email, link trang làm phiếu), 'cham' (vai trò và quy tắc
 *    chấm riêng cho Gemini — khtd_grade_system()), mục kiểu 'info' (chỉ hiển thị, không có ô nhập),
 *    'photo_label'/'photo_hint' cho bảng có cột ảnh.
 */

/** Thông tin môn của phiếu (mặc định: KHTĐ như trước). */
function khtd_mon(array $schema): array
{
    $m = is_array($schema['mon'] ?? null) ? $schema['mon'] : [];
    return [
        'ma'     => (string) ($m['ma'] ?? 'KHTĐ'),
        'ten'    => (string) ($m['ten'] ?? 'Khoa học Trái đất, thực tập'),
        'rubric' => (string) ($m['rubric'] ?? (isset($m['ma']) ? 'rubric của phiếu' : 'rubric R2')),
        'url'    => (string) ($m['url'] ?? 'https://diemdanhsv.com/khtd/online.html'),
        // Cách gọi điểm tạm tính trong nội dung gửi SV; rỗng = câu cũ "trợ lý AI chấm theo …" (bộ Đ&KV: api/lib/dkv.php).
        'nhan_cham' => (string) ($m['nhan_cham'] ?? ''),
    ];
}

/** Bộ phiếu CHĐ: [No => định nghĩa] như khtd_default_worksheets(). */
function chd_default_worksheets(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $raw = @file_get_contents(__DIR__ . '/chd_phieu.json');
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($data)) throw new RuntimeException('Thiếu hoặc hỏng tệp chd_phieu.json.');
    $out = [];
    foreach ($data as $no => $def) {
        if (!is_array($def) || !isset($def['title'], $def['sections'], $def['rubric'])) continue;
        $out[(int) $no] = $def;
    }
    ksort($out);
    return $cache = $out;
}
