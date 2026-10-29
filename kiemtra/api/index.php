<?php
/**
 * KIEMTRA — api/index.php: điểm vào duy nhất.
 *   GET  ?action=img&id=IMG_xxx           → ảnh câu hỏi
 *   GET  ?action=ping                      → kiểm tra triển khai
 *   POST body JSON {action, …}             → mọi thao tác khác
 * Phong bì giống site chính: {status:'success'|'error', message, data}.
 */
declare(strict_types=1);

ini_set('display_errors', '0');
date_default_timezone_set('Asia/Ho_Chi_Minh');
mb_internal_encoding('UTF-8');

require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/bank.php';
require __DIR__ . '/lib/grading.php';
require __DIR__ . '/lib/exam.php';
require __DIR__ . '/lib/student.php';
require __DIR__ . '/lib/analytics.php';

const KT_VERSION = '1.0.0 (2026-10-29)';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'GET') {
    $params = $_GET;
} elseif ($method === 'POST') {
    $raw = (string) file_get_contents('php://input');
    if (strlen($raw) > 6 * 1024 * 1024) {
        http_response_code(413);
        exit;
    }
    $params = json_decode($raw, true);
    if (!is_array($params)) {
        $params = [];
    }
} else {
    http_response_code(405);
    exit;
}
$action = preg_replace('/[^A-Za-z]/', '', (string) ($params['action'] ?? ''));

if ($action === 'img') {
    try {
        serve_image((string) ($params['id'] ?? ''));
    } catch (Throwable $e) {
        error_log('[kt img] ' . $e->getMessage());
        http_response_code(500);
    }
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

// thao tác GHI chỉ qua POST; GET chỉ cho ping/peek
$getAllowed = ['ping', 'peek'];
$routes = [
    // chung
    'ping' => fn($p) => api_ok(['version' => KT_VERSION, 'serverNow' => now()]),
    // giảng viên
    'login' => 'action_login', 'logout' => 'action_logout', 'me' => 'action_me',
    'listClos' => 'action_listClos', 'saveClo' => 'action_saveClo', 'deleteClo' => 'action_deleteClo',
    'listQuestions' => 'action_listQuestions', 'saveQuestion' => 'action_saveQuestion', 'deleteQuestion' => 'action_deleteQuestion',
    'importQuestions' => 'action_importQuestions', 'uploadImage' => 'action_uploadImage',
    'listExams' => 'action_listExams', 'getExam' => 'action_getExam', 'saveExam' => 'action_saveExam', 'deleteExam' => 'action_deleteExam',
    'duplicateExam' => 'action_duplicateExam', 'setExamItems' => 'action_setExamItems', 'drawQuestions' => 'action_drawQuestions',
    'openRoom' => 'action_openRoom', 'startNow' => 'action_startNow', 'addTime' => 'action_addTime', 'closeExam' => 'action_closeExam',
    'publishResults' => 'action_publishResults', 'monitor' => 'action_monitor', 'unlockAttempt' => 'action_unlockAttempt',
    'allowRejoin' => 'action_allowRejoin', 'forceSubmit' => 'action_forceSubmit', 'removeAttempt' => 'action_removeAttempt',
    'attemptLog' => 'action_attemptLog', 'results' => 'action_results', 'pushGrades' => 'action_pushGrades', 'cloReport' => 'action_cloReport',
    // sinh viên
    'peek' => 'action_peek', 'join' => 'action_join', 'state' => 'action_state', 'ready' => 'action_ready', 'paper' => 'action_paper',
    'save' => 'action_save', 'pinUnlock' => 'action_pinUnlock', 'submit' => 'action_submit', 'review' => 'action_review',
];

try {
    if (!isset($routes[$action])) {
        api_fail('Thao tác không hợp lệ.');
        exit;
    }
    if ($method === 'GET' && !in_array($action, $getAllowed, true)) {
        api_fail('Thao tác này cần gửi bằng POST.');
        exit;
    }
    $fn = $routes[$action];
    $fn($params);
} catch (UserError $e) {
    api_fail($e->getMessage());
} catch (Throwable $e) {
    error_log('[kiemtra] ' . $action . ': ' . get_class($e) . ': ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
    $msg = $e instanceof RuntimeException && !($e instanceof PDOException) ? $e->getMessage() : 'Lỗi máy chủ. Vui lòng thử lại sau.';
    api_fail($msg);
}
