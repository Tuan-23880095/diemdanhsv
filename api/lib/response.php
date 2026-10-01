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

/**
 * Chỉ dành cho tools/smoke_test.php chạy API "trong cùng tiến trình" (host
 * shared hosting cấm proc_open nên không bật được máy chủ thử php -S). Khi
 * hằng API_INPROCESS được định nghĩa = true TRƯỚC khi nạp file này, api_ok()/
 * api_fail() KHÔNG echo + exit mà ném ApiResponse mang phong bì; smoke test
 * bắt lại. api/index.php không bao giờ định nghĩa hằng này → request web
 * giữ nguyên hành vi cũ 100%.
 */
final class ApiResponse extends Exception
{
    /** @var array{status:string, message:string, data:mixed} */
    public array $payload;

    public function __construct(array $payload)
    {
        parent::__construct((string) $payload['message']);
        $this->payload = $payload;
    }
}

/** Xuất phong bì rồi kết thúc request (hoặc ném ApiResponse khi chạy in-process). */
function api_emit(array $payload): void
{
    if (defined('API_INPROCESS') && API_INPROCESS === true) {
        throw new ApiResponse($payload);
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Trả {status:'success', message:'', data}. Kết thúc request ngay. */
function api_ok($data = null): void
{
    api_emit([
        'status'  => 'success',
        'message' => '',
        'data'    => $data,
    ]);
}

/**
 * Trả {status:'error', message, data:null}. Kết thúc request ngay.
 * Giữ HTTP 200 cho lỗi nghiệp vụ (đúng mục 1) — chỉ đổi mã HTTP ở nơi gọi
 * TRƯỚC khi gọi hàm này, cho trường hợp bản thân request hỏng (vd sai method).
 */
function api_fail(string $message): void
{
    api_emit([
        'status'  => 'error',
        'message' => $message !== '' ? $message : 'Lỗi không xác định.',
        'data'    => null,
    ]);
}
