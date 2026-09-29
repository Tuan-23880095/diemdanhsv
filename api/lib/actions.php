<?php
declare(strict_types=1);

/**
 * api/lib/actions.php — Router 13 action cũ (gas/05-Api.gs) theo đúng bảng
 * đối chiếu docs/04-API-PHP.md mục 5.
 *
 * GĐ2 (PLAN) chỉ triển khai `ping`, để kiểm `php -l` sạch + xác nhận bản
 * deploy đang chạy. 12 action còn lại trả lỗi rõ ràng "chưa triển khai" —
 * KHÁC với "Action không hợp lệ" (dành cho tên action không nằm trong danh
 * sách 13 action cũ, giữ đúng nguyên văn gas/05-Api.gs dòng 76 để không
 * phải sửa frontend). Việc triển khai từng action nằm ở GĐ3–GĐ5.
 */

const API_VERSION = 'php-0.1';

/** action => 'GET' | 'POST', đúng danh sách 13 action + phương thức cũ. */
const KNOWN_ACTIONS = [
    'ping'             => 'GET',
    'studentHistory'   => 'GET',
    'myGrades'         => 'GET',
    'liveRoster'       => 'GET',
    'listClasses'      => 'GET',
    'listSessions'     => 'GET',
    'login'            => 'POST',
    'logout'           => 'POST',
    'requestGradeCode' => 'POST',
    'verifyGradeCode'  => 'POST',
    'openAttendance'   => 'POST',
    'closeAttendance'  => 'POST',
    'checkin'          => 'POST',
];

/** Điều hướng theo $action, gọi thẳng api_ok()/api_fail() (hai hàm này tự exit). */
function api_dispatch(string $action, string $method, array $params): void
{
    if ($action === '') {
        // Nguyên văn gas/05-Api.gs dòng 73.
        api_fail('Thiếu tham số action.');
        return;
    }

    if (!array_key_exists($action, KNOWN_ACTIONS)) {
        // Nguyên văn gas/05-Api.gs dòng 76 — không đổi để APIService.js không
        // phải sửa (rule 5).
        api_fail('Action không hợp lệ: ' . $action);
        return;
    }

    $expectedMethod = KNOWN_ACTIONS[$action];
    if ($method !== $expectedMethod) {
        api_fail('Action "' . $action . '" phải gọi bằng ' . $expectedMethod . '.');
        return;
    }

    switch ($action) {
        case 'ping':
            action_ping();
            return;

        default:
            // 12 action còn lại: xem docs/04-API-PHP.md mục 11 + PLAN GĐ3–GĐ5.
            api_fail('Action "' . $action . '" chưa được triển khai (sẽ có ở GĐ3–GĐ5).');
            return;
    }
}

/**
 * GET ?action=ping — không đụng CSDL/config bí mật, chỉ xác nhận bản deploy
 * đang chạy đã có code mới (cùng mục đích API_VERSION cũ, gas/05-Api.gs).
 */
function action_ping(): void
{
    api_ok([
        'time'    => date("Y-m-d\TH:i:s"),
        'version' => API_VERSION,
    ]);
}
