<?php
declare(strict_types=1);

/**
 * api/lib/actions.php — Router 13 action cũ (gas/05-Api.gs) theo đúng bảng
 * đối chiếu docs/04-API-PHP.md mục 5.
 *
 * GĐ2 (PLAN) triển khai `ping`. GĐ3 thêm `login`/`logout`/`requestGradeCode`/
 * `verifyGradeCode` (api/lib/auth.php, api/lib/gradeauth.php). GĐ4 thêm
 * `openAttendance`/`closeAttendance`/`checkin`/`liveRoster`
 * (api/lib/attendance.php, api/lib/roles.php). GĐ5 thêm 4 action ĐỌC cuối
 * cùng — `studentHistory`, `myGrades`, `listClasses`, `listSessions`
 * (api/lib/queries.php) — đủ 13/13 action cũ, không còn nhánh "chưa triển
 * khai" nào trong router này.
 */

const API_VERSION = 'php-0.4';

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

        case 'login':
            action_login($params);
            return;

        case 'logout':
            action_logout($params);
            return;

        case 'requestGradeCode':
            action_request_grade_code($params);
            return;

        case 'verifyGradeCode':
            action_verify_grade_code($params);
            return;

        case 'openAttendance':
            action_open_attendance($params);
            return;

        case 'closeAttendance':
            action_close_attendance($params);
            return;

        case 'checkin':
            action_checkin($params);
            return;

        case 'liveRoster':
            action_live_roster($params);
            return;

        case 'studentHistory':
            action_student_history($params);
            return;

        case 'myGrades':
            action_my_grades($params);
            return;

        case 'listClasses':
            action_list_classes($params);
            return;

        case 'listSessions':
            action_list_sessions($params);
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
