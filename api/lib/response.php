<?php
declare(strict_types=1);

/**
 * api/lib/response.php — Bọc phong bì phản hồi chung, GIỮ NGUYÊN 100%
 * (docs/04-API-PHP.md mục 1, rule 5 của dự án — không được đổi khuôn này).
 *
 *   { status: 'success' | 'error', message: string, data: any }
 *
 * status:'error' luôn kèm data:null; js/services/APIService.js._unwrap()
 * ném Error(message) khi gặp status==='error', nên PHP phải trả đúng field
 * này thay vì dùng mã lỗi HTTP riêng cho lỗi nghiệp vụ.
 */

/** Trả {status:'success', message:'', data}. Kết thúc request ngay. */
function api_ok($data = null): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status'  => 'success',
        'message' => '',
        'data'    => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Trả {status:'error', message, data:null}. Kết thúc request ngay.
 * Giữ HTTP 200 cho lỗi nghiệp vụ (đúng mục 1) — chỉ đổi mã HTTP ở nơi gọi
 * TRƯỚC khi gọi hàm này, cho trường hợp bản thân request hỏng (vd sai method).
 */
function api_fail(string $message): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status'  => 'error',
        'message' => $message !== '' ? $message : 'Lỗi không xác định.',
        'data'    => null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
