<?php
/**
 * api/lib/dkv.php — Bộ phiếu online "Thực tập Đá và Khoáng vật" (LEC10001) chạy trên module phiếu học tập khtd
 * (docs/04-API-PHP.md mục 35). Giống bộ CHĐ (api/lib/chd.php): không thêm bảng, không thêm action, không migration.
 *
 *  - Nội dung phiếu ở api/lib/dkv_phieu.json (thư mục api/lib bị .htaccess chặn web nên đáp án không lộ).
 *    Số 1–5 = 5 tuần thực tập: tuần 1–2 mô tả 14 khoáng vật, tuần 3 đá magma, tuần 4 đá trầm tích, tuần 5 đá biến chất.
 *    Tuần 6 thi: hình thức do giảng viên công bố, không có phiếu.
 *  - khtdSeedWorksheets {token, classId, bo: 'dkv'} tạo các phiếu này cho lớp (bỏ qua số đã có).
 *  - SchemaJSON có 'mon.nhan_cham': cách gọi điểm tạm tính gửi sinh viên (email, trang làm phiếu). Với bộ Đ&KV là
 *    "phần mềm chấm tự động, giảng viên chưa chốt điểm" — không ghi AI/trí tuệ nhân tạo ở nội dung gửi sinh viên.
 */

/** Bộ phiếu Đ&KV: [No => định nghĩa] như khtd_default_worksheets(). */
function dkv_default_worksheets(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $raw = @file_get_contents(__DIR__ . '/dkv_phieu.json');
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($data)) throw new RuntimeException('Thiếu hoặc hỏng tệp dkv_phieu.json.');
    $out = [];
    foreach ($data as $no => $def) {
        if (!is_array($def) || !isset($def['title'], $def['sections'], $def['rubric'])) continue;
        $out[(int) $no] = $def;
    }
    ksort($out);
    return $cache = $out;
}

/** Bỏ các câu nhắc tới AI/chatbot/Gemini trong nhận xét gửi sinh viên (dùng khi phiếu khai báo mon.nhan_cham). */
function dkv_loc_ai(string $text): string
{
    // Phân biệt hoa thường để không bắt chữ “ai” (đại từ) tiếng Việt.
    $re = '/(?<!\pL)(AI|A\.I\.|[Cc]hatbot|[Cc]hat ?GPT|Gemini|LLM|[Tt]rí tuệ nhân tạo|[Tt]rợ lý ảo|[Mm]ô hình ngôn ngữ)(?!\pL)/u';
    if (!preg_match($re, $text)) return $text;
    $parts = preg_split('/(?<=[.!?;])\s+/u', $text) ?: [$text];
    return trim(implode(' ', array_filter($parts, static fn ($p) => !preg_match($re, $p))));
}

/** Lọc chữ AI khỏi các nhận xét trong kết quả chấm trước khi gửi email cho sinh viên. */
function dkv_loc_ai_ket_qua(array $ai): array
{
    foreach ((array) ($ai['criteria'] ?? []) as $i => $c) {
        if (is_array($c) && isset($c['comment'])) $ai['criteria'][$i]['comment'] = dkv_loc_ai((string) $c['comment']);
    }
    foreach ((array) ($ai['sua_bai'] ?? []) as $i => $f) {
        if (!is_array($f)) continue;
        foreach (['muc', 'van_de', 'cach_sua'] as $k) if (isset($f[$k])) $ai['sua_bai'][$i][$k] = dkv_loc_ai((string) $f[$k]);
    }
    if (isset($ai['feedback'])) $ai['feedback'] = dkv_loc_ai((string) $ai['feedback']);
    return $ai;
}
