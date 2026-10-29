<?php
declare(strict_types=1);

/**
 * api/lib/student.php — phía sinh viên: quét QR → nhập MSSV → phòng chờ 5 phút
 * → "Sẵn sàng" → làm bài → nộp. Kèm nhật ký giám sát (chuyển tab, rời ứng
 * dụng, copy…), khoá bài khi vượt ngưỡng, mở khoá bằng PIN giám thị.
 *
 * Nguyên tắc an toàn:
 *  - Đáp án KHÔNG BAO GIỜ gửi xuống máy SV trước khi công bố.
 *  - Một MSSV chỉ gắn với MỘT thiết bị; thiết bị thứ hai bị chặn và ghi vi phạm
 *    cho tới khi giám thị bấm "Cho đổi máy".
 *  - Mọi thời gian tính theo đồng hồ máy chủ.
 */

/** Sự kiện được tính là vi phạm (các loại khác chỉ ghi nhật ký). */
const KT_COUNTED_EVENTS = [
    'hidden'       => 'Rời trang (chuyển tab / mở ứng dụng khác / tắt màn hình)',
    'blur'         => 'Cửa sổ mất tiêu điểm quá lâu',
    'fs_exit'      => 'Thoát chế độ toàn màn hình',
    'split'        => 'Thu nhỏ / chia đôi màn hình / cửa sổ nổi',
    'copy'         => 'Sao chép nội dung',
    'cut'          => 'Cắt nội dung',
    'paste'        => 'Dán nội dung',
    'devtools'     => 'Mở công cụ nhà phát triển',
    'printscreen'  => 'Chụp màn hình (phím PrintScreen)',
    'reload'       => 'Tải lại / mở lại trang khi đang làm bài',
    'second_device' => 'Đăng nhập MSSV này từ thiết bị khác',
];
const KT_LOG_EVENTS = ['contextmenu', 'shortcut', 'offline', 'online', 'fs_unsupported', 'back_visible', 'focus_back', 'drag', 'select'];

function ex_public(array $e): array
{
    $s = exam_settings($e);
    return [
        'title' => $e['Title'], 'status' => $e['Status'], 'code' => $e['Code'],
        'prepMinutes' => (int) $e['PrepMinutes'], 'durationMinutes' => (int) $e['DurationMinutes'],
        'startAt' => $e['StartAt'], 'startedAt' => $e['StartedAt'], 'endsAt' => $e['EndsAt'],
        'resultsPublished' => (int) $e['ResultsPublished'],
        'rules' => [
            'maxViolations' => $s['maxViolations'], 'onLimit' => $s['onLimit'], 'blurGraceSec' => $s['blurGraceSec'],
            'requireFullscreen' => $s['requireFullscreen'], 'allowBack' => $s['allowBack'], 'watermark' => $s['watermark'],
            'lateJoinMinutes' => $s['lateJoinMinutes'], 'showScore' => $s['showScore'],
        ],
    ];
}

function exam_by_code(string $code): array
{
    $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
    if (strlen($code) !== 6) {
        fail('Mã phòng thi gồm 6 ký tự.');
    }
    $e = q_one("SELECT * FROM kt_exams WHERE Code = :c AND Status <> 'DRAFT' ORDER BY CreatedAt DESC LIMIT 1", ['c' => $code]);
    if (!$e) {
        fail('Không tìm thấy phòng thi với mã ' . $code . '.');
    }
    tick_exam($e);
    return $e;
}

function action_peek(array $p): void
{
    $e = exam_by_code((string) ($p['code'] ?? ''));
    $cls = q_one('SELECT c.ClassCode, co.CourseName FROM classes c JOIN courses co ON co.CourseID = c.CourseID WHERE c.ClassID = :id', ['id' => $e['ClassID']]);
    api_ok(['exam' => ex_public($e), 'classCode' => $cls['ClassCode'] ?? '', 'courseName' => $cls['CourseName'] ?? '',
        'questionCount' => (int) q_val('SELECT COUNT(*) FROM kt_exam_items WHERE ExamID = :id', ['id' => $e['ExamID']]), 'serverNow' => now()]);
}

function make_layout(string $examId, array $settings): array
{
    $items = exam_items_snapshot($examId);
    $order = array_keys($items);
    if ($settings['shuffleQuestions']) {
        shuffle($order);
    }
    $opt = [];
    foreach ($items as $qid => $it) {
        if ($it['type'] === 'short') {
            continue;
        }
        $keys = array_column((array) $it['options'], 'key');
        if ($settings['shuffleOptions'] && !options_have_fixed_order($it['options'])) {
            shuffle($keys);
        }
        $opt[$qid] = $keys;
    }
    return ['order' => $order, 'opt' => $opt];
}

/** Không xáo khi phương án có kiểu "Cả A và B", "Tất cả các ý trên"… */
function options_have_fixed_order(array $options): bool
{
    foreach ($options as $o) {
        if (preg_match('/(tất cả|cả (hai|ba|bốn)|đều (đúng|sai)|không có (đáp án|phương án|ý) nào|các (ý|phương án|đáp án) trên|\b[A-H] và [A-H]\b)/iu', (string) $o['text'])) {
            return true;
        }
    }
    return false;
}

function action_join(array $p): void
{
    $e = exam_by_code((string) ($p['code'] ?? ''));
    $ip = client_ip();
    rate_guard('kt_join_ip', $ip, 400, 600); // cả lớp chung một Wi-Fi
    if ($e['Status'] === 'CLOSED') {
        fail('Phòng thi đã đóng.');
    }
    $mssv = preg_replace('/\D/', '', (string) ($p['mssv'] ?? ''));
    if (!preg_match('/^\d{6,10}$/', $mssv)) {
        fail('MSSV không hợp lệ (6–10 chữ số).');
    }
    $deviceId = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($p['deviceId'] ?? ''));
    if (strlen($deviceId) < 16) {
        fail('Trình duyệt không hỗ trợ lưu dữ liệu — tắt chế độ ẩn danh rồi thử lại.');
    }
    $devInfo = mb_substr((string) ($p['deviceInfo'] ?? ''), 0, 200);
    $st = q_one("SELECT * FROM students WHERE MSSV = :m AND Status = 'ACTIVE' LIMIT 1", ['m' => $mssv]);
    $enrolled = $st && q_val("SELECT 1 FROM enrollments WHERE StudentID = :s AND ClassID = :c AND Status = 'ACTIVE'", ['s' => $st['StudentID'], 'c' => $e['ClassID']]);
    if (!$enrolled) {
        rate_hit('kt_join_ip', $ip, 600);
        fail('MSSV ' . $mssv . ' không có trong danh sách lớp của bài kiểm tra này.');
    }
    $settings = exam_settings($e);
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $now = now();

    $result = tx(function () use ($e, $st, $deviceId, $devInfo, $ip, $hash, $now, $settings, $p) {
        $a = q_one('SELECT * FROM kt_attempts WHERE ExamID = :e AND StudentID = :s' . for_update(), ['e' => $e['ExamID'], 's' => $st['StudentID']]);
        if ($a) {
            if ($a['Status'] === 'SUBMITTED') {
                return ['submitted' => true, 'attemptId' => $a['AttemptID']];
            }
            $sameDevice = hash_equals((string) $a['DeviceID'], $deviceId);
            if (!$sameDevice && !(int) $a['AllowRejoin']) {
                $counted = $a['Status'] === 'DOING' ? 1 : 0;
                log_event($e['ExamID'], $a['AttemptID'], 'second_device', 'Thiết bị khác cố vào: ' . $devInfo, $counted);
                if ($counted) {
                    add_violation($e, $a, 'second_device');
                }
                return ['blocked' => true];
            }
            q('UPDATE kt_attempts SET TokenHash = :h, DeviceID = :d, DeviceInfo = :di, IP = :ip, AllowRejoin = 0, LastSeenAt = :n WHERE AttemptID = :a',
                ['h' => $hash, 'd' => $deviceId, 'di' => $devInfo, 'ip' => $ip, 'n' => $now, 'a' => $a['AttemptID']]);
            log_event($e['ExamID'], $a['AttemptID'], $sameDevice ? 'vao_lai' : 'doi_may', $devInfo, 0);
            return ['attemptId' => $a['AttemptID'], 'resumed' => true];
        }
        // vào mới
        if ($e['Status'] === 'RUNNING') {
            $late = ts($now) - ts($e['StartedAt']);
            if ($late > 60 * $settings['lateJoinMinutes']) {
                fail('Đã quá ' . $settings['lateJoinMinutes'] . ' phút kể từ giờ bắt đầu — không vào phòng được nữa. Gặp giám thị.');
            }
        }
        $id = new_id('ATM');
        $seat = (int) q_val('SELECT COALESCE(MAX(SeatNo),0) FROM kt_attempts WHERE ExamID = :e', ['e' => $e['ExamID']]) + 1;
        q('INSERT INTO kt_attempts (AttemptID, ExamID, StudentID, MSSV, FullName, SeatNo, TokenHash, DeviceID, DeviceInfo, IP, Status, LayoutJSON, AnswersJSON, JoinedAt, LastSeenAt) ' .
            "VALUES (:id, :e, :s, :m, :f, :seat, :h, :d, :di, :ip, 'JOINED', :lay, '{}', :n, :n2)", [
            'id' => $id, 'e' => $e['ExamID'], 's' => $st['StudentID'], 'm' => $st['MSSV'], 'f' => $st['FullName'], 'seat' => $seat, 'h' => $hash,
            'd' => $deviceId, 'di' => $devInfo, 'ip' => $ip, 'lay' => jenc(make_layout((string) $e['ExamID'], $settings)), 'n' => $now, 'n2' => $now,
        ]);
        log_event($e['ExamID'], $id, 'vao_phong', $devInfo, 0);
        return ['attemptId' => $id, 'resumed' => false];
    });

    if (!empty($result['blocked'])) {
        fail('MSSV này đang làm bài trên một thiết bị khác. Giơ tay báo giám thị nếu bạn cần đổi máy.');
    }
    if (!empty($result['submitted'])) {
        fail('Bạn đã nộp bài này rồi.');
    }
    api_ok(['token' => $token, 'resumed' => $result['resumed']]);
}

/** Xác thực token lượt làm bài. @return array{0:array,1:array} [exam, attempt] */
function require_attempt(array $p): array
{
    $t = (string) ($p['t'] ?? '');
    if (!preg_match('/^[0-9a-f]{64}$/', $t)) {
        fail('Phiên làm bài không hợp lệ. Hãy quét lại mã QR.');
    }
    $a = q_one('SELECT * FROM kt_attempts WHERE TokenHash = :h', ['h' => hash('sha256', $t)]);
    if (!$a) {
        fail('Phiên làm bài đã chuyển sang thiết bị khác hoặc không còn hiệu lực.');
    }
    $e = q_one('SELECT * FROM kt_exams WHERE ExamID = :id', ['id' => $a['ExamID']]);
    tick_exam($e);
    // tick_exam có thể vừa chấm bài quá giờ → đọc lại trạng thái lượt làm
    $a = q_one('SELECT * FROM kt_attempts WHERE AttemptID = :id', ['id' => $a['AttemptID']]);
    return [$e, $a];
}

/** Ghi vi phạm vào bài, khoá/tự nộp nếu vượt ngưỡng. Gọi trong transaction. */
function add_violation(array $e, array $a, string $type): array
{
    q('UPDATE kt_attempts SET Violations = Violations + 1 WHERE AttemptID = :a', ['a' => $a['AttemptID']]);
    $a = q_one('SELECT * FROM kt_attempts WHERE AttemptID = :a', ['a' => $a['AttemptID']]);
    $s = exam_settings($e);
    $limit = $s['maxViolations'] + (int) $a['Unlocks'];
    if ($a['Status'] === 'DOING' && (int) $a['Violations'] >= $limit) {
        $reason = KT_COUNTED_EVENTS[$type] ?? $type;
        if ($s['onLimit'] === 'submit') {
            submit_attempt($e, $a, 'vi_pham');
            log_event($e['ExamID'], $a['AttemptID'], 'tu_nop_vi_pham', 'Vượt ' . $s['maxViolations'] . ' vi phạm — tự nộp bài', 0);
        } else {
            q("UPDATE kt_attempts SET Status = 'LOCKED', LockReason = :r WHERE AttemptID = :a", ['r' => mb_substr($reason, 0, 120), 'a' => $a['AttemptID']]);
            log_event($e['ExamID'], $a['AttemptID'], 'khoa_bai', 'Khoá bài sau vi phạm: ' . $reason, 0);
        }
        $a = q_one('SELECT * FROM kt_attempts WHERE AttemptID = :a', ['a' => $a['AttemptID']]);
    }
    return $a;
}

/** Nhận lô sự kiện từ máy SV. Các vi phạm liền nhau trong 2 giây gộp làm một (một lần chuyển app sinh nhiều tín hiệu). */
function ingest_events(array $e, array $a, array $events): array
{
    if (!$events) {
        return $a;
    }
    $events = array_slice($events, 0, 30);
    return tx(function () use ($e, $a, $events) {
        $a = q_one('SELECT * FROM kt_attempts WHERE AttemptID = :a' . for_update(), ['a' => $a['AttemptID']]);
        $lastCounted = ts((string) q_val('SELECT MAX(CreatedAt) FROM kt_events WHERE AttemptID = :a AND Counted = 1', ['a' => $a['AttemptID']])) ?? 0;
        $nowT = ts(now());
        foreach ($events as $ev) {
            $type = preg_replace('/[^a-z_]/', '', (string) ($ev['type'] ?? ''));
            $detail = mb_substr((string) ($ev['detail'] ?? ''), 0, 200);
            $at = mb_substr((string) ($ev['at'] ?? ''), 0, 30);
            if (isset(KT_COUNTED_EVENTS[$type])) {
                $active = $e['Status'] === 'RUNNING' && $a['Status'] === 'DOING';
                $dup = $nowT - $lastCounted < 2;
                $counted = $active && !$dup ? 1 : 0;
                log_event($e['ExamID'], $a['AttemptID'], $type, ($detail ?: KT_COUNTED_EVENTS[$type]) . ($active && $dup ? ' (gộp)' : ''), $counted, $at);
                if ($counted) {
                    $lastCounted = $nowT;
                    $a = add_violation($e, $a, $type);
                }
            } elseif (in_array($type, KT_LOG_EVENTS, true)) {
                log_event($e['ExamID'], $a['AttemptID'], $type, $detail, 0, $at);
            }
        }
        return $a;
    });
}

function attempt_state(array $e, array $a): array
{
    $s = exam_settings($e);
    $deadline = attempt_deadline($e, $a);
    $out = [
        'serverNow' => now(),
        'exam' => ex_public($e),
        'me' => ['mssv' => $a['MSSV'], 'name' => $a['FullName'], 'seat' => (int) $a['SeatNo'], 'status' => $a['Status'],
            'violations' => (int) $a['Violations'], 'limit' => $s['maxViolations'] + (int) $a['Unlocks'], 'lockReason' => $a['LockReason'],
            'deadline' => $deadline ? dt($deadline) : null, 'readyAt' => $a['ReadyAt']],
        'readyCount' => (int) q_val("SELECT COUNT(*) FROM kt_attempts WHERE ExamID = :e AND Status <> 'JOINED'", ['e' => $e['ExamID']]),
        'joinedCount' => (int) q_val('SELECT COUNT(*) FROM kt_attempts WHERE ExamID = :e', ['e' => $e['ExamID']]),
    ];
    if ($a['Status'] === 'SUBMITTED') {
        $out['result'] = ['submittedAt' => $a['SubmittedAt'], 'reason' => $a['SubmitReason']];
        if ($s['showScore'] || (int) $e['ResultsPublished']) {
            $out['result'] += ['score10' => (float) $a['Score10'], 'raw' => (float) $a['RawScore'], 'max' => (float) $a['MaxScore']];
        }
    }
    return $out;
}

/** Nhịp tim + nhận sự kiện + trạng thái (máy SV gọi 5–10 giây/lần, và ngay khi có vi phạm). */
function action_state(array $p): void
{
    [$e, $a] = require_attempt($p);
    if ($a['Status'] !== 'SUBMITTED') {
        $a = ingest_events($e, $a, (array) ($p['events'] ?? []));
        q('UPDATE kt_attempts SET LastSeenAt = :n WHERE AttemptID = :a', ['n' => now(), 'a' => $a['AttemptID']]);
    }
    api_ok(attempt_state($e, $a));
}

function action_ready(array $p): void
{
    [$e, $a] = require_attempt($p);
    if ($a['Status'] === 'JOINED') {
        q("UPDATE kt_attempts SET Status = 'READY', ReadyAt = :n WHERE AttemptID = :a AND Status = 'JOINED'", ['n' => now(), 'a' => $a['AttemptID']]);
        $checks = mb_substr(jenc((array) ($p['checks'] ?? [])), 0, 200);
        log_event($e['ExamID'], $a['AttemptID'], 'san_sang', $checks, 0);
        $a['Status'] = 'READY';
    }
    api_ok(attempt_state($e, $a));
}

/** Đề của riêng SV (đã xáo), KHÔNG kèm đáp án. */
function action_paper(array $p): void
{
    [$e, $a] = require_attempt($p);
    if ($e['Status'] !== 'RUNNING' && $a['Status'] !== 'SUBMITTED') {
        fail($e['Status'] === 'LOBBY' ? 'Chưa đến giờ làm bài.' : 'Phòng thi đã đóng.');
    }
    if ($a['Status'] === 'SUBMITTED') {
        fail('Bạn đã nộp bài.');
    }
    if (in_array($a['Status'], ['JOINED', 'READY'], true)) {
        q("UPDATE kt_attempts SET Status = 'DOING' WHERE AttemptID = :a AND Status IN ('JOINED','READY')", ['a' => $a['AttemptID']]);
        log_event($e['ExamID'], $a['AttemptID'], 'bat_dau_lam', '', 0);
        $a['Status'] = 'DOING';
    } elseif (!empty($p['reloaded']) && $a['Status'] === 'DOING') {
        // trang được mở lại giữa giờ thi (tải lại, đóng/mở trình duyệt)
        $a = ingest_events($e, $a, [['type' => 'reload', 'detail' => 'Mở lại trang khi đang làm bài']]);
    }
    $items = exam_items_snapshot((string) $e['ExamID']);
    $lay = jdec($a['LayoutJSON']);
    $qs = [];
    $n = 0;
    foreach ((array) ($lay['order'] ?? array_keys($items)) as $qid) {
        if (!isset($items[$qid])) {
            continue;
        }
        $it = $items[$qid];
        $opts = [];
        if ($it['type'] !== 'short') {
            $byKey = array_column((array) $it['options'], 'text', 'key');
            foreach ((array) ($lay['opt'][$qid] ?? array_keys($byKey)) as $k) {
                if (isset($byKey[$k])) {
                    $opts[] = ['id' => $k, 'text' => $byKey[$k]];
                }
            }
        }
        $qs[] = ['id' => $qid, 'n' => ++$n, 'type' => $it['type'], 'stem' => $it['stem'], 'image' => $it['imageId'] ?: null,
            'options' => $opts, 'points' => (float) $it['points'], 'nCorrect' => $it['type'] === 'multi' ? count((array) $it['answer']) : null];
    }
    $st = attempt_state($e, $a);
    $st['questions'] = $qs;
    $st['answers'] = (object) jdec($a['AnswersJSON']);
    api_ok($st);
}

function action_save(array $p): void
{
    [$e, $a] = require_attempt($p);
    if ($a['Status'] === 'LOCKED') {
        fail('Bài đang bị khoá — giơ tay báo giám thị.');
    }
    if ($a['Status'] !== 'DOING' || $e['Status'] !== 'RUNNING') {
        fail('Không còn trong giờ làm bài.');
    }
    if (ts(now()) > attempt_deadline($e, $a) + 30) {
        fail('Đã hết giờ làm bài.');
    }
    $items = exam_items_snapshot((string) $e['ExamID']);
    $changes = isset($p['answers']) && is_array($p['answers']) ? $p['answers'] : [(string) ($p['qid'] ?? '') => $p['answer'] ?? null];
    tx(function () use ($a, $items, $changes) {
        $cur = jdec((string) q_val('SELECT AnswersJSON FROM kt_attempts WHERE AttemptID = :a' . for_update(), ['a' => $a['AttemptID']]));
        foreach ($changes as $qid => $val) {
            if (!isset($items[$qid])) {
                continue;
            }
            $it = $items[$qid];
            $keys = array_column((array) $it['options'], 'key');
            if ($val === null || $val === '' || $val === []) {
                unset($cur[$qid]);
            } elseif ($it['type'] === 'single') {
                if (in_array($val, $keys, true)) {
                    $cur[$qid] = $val;
                }
            } elseif ($it['type'] === 'multi') {
                $v = array_values(array_intersect($keys, array_map('strval', (array) $val)));
                if ($v) {
                    $cur[$qid] = $v;
                } else {
                    unset($cur[$qid]);
                }
            } else {
                $cur[$qid] = mb_substr((string) $val, 0, 300);
            }
        }
        q('UPDATE kt_attempts SET AnswersJSON = :j, LastSeenAt = :n WHERE AttemptID = :a', ['j' => jenc((object) $cur), 'n' => now(), 'a' => $a['AttemptID']]);
    });
    api_ok(['saved' => true, 'serverNow' => now()]);
}

function action_pinUnlock(array $p): void
{
    [$e, $a] = require_attempt($p);
    if ($a['Status'] !== 'LOCKED') {
        api_ok(attempt_state($e, $a));
        return;
    }
    if ((int) $a['PinFails'] >= 5) {
        fail('Nhập sai PIN quá 5 lần — giám thị mở khoá trên bảng giám sát.');
    }
    $pin = preg_replace('/\D/', '', (string) ($p['pin'] ?? ''));
    if (!hash_equals((string) $e['SupervisorPin'], $pin)) {
        q('UPDATE kt_attempts SET PinFails = PinFails + 1 WHERE AttemptID = :a', ['a' => $a['AttemptID']]);
        log_event($e['ExamID'], $a['AttemptID'], 'pin_sai', 'Nhập sai PIN giám thị', 0);
        fail('PIN không đúng.');
    }
    unlock_attempt($e, $a, 'Giám thị nhập PIN tại chỗ');
    $a = q_one('SELECT * FROM kt_attempts WHERE AttemptID = :a', ['a' => $a['AttemptID']]);
    api_ok(attempt_state($e, $a));
}

function action_submit(array $p): void
{
    [$e, $a] = require_attempt($p);
    if ($a['Status'] === 'SUBMITTED') {
        api_ok(attempt_state($e, $a));
        return;
    }
    if ($a['Status'] === 'LOCKED') {
        fail('Bài đang bị khoá — giơ tay báo giám thị.');
    }
    if ($e['Status'] !== 'RUNNING') {
        fail('Chưa đến giờ làm bài.');
    }
    tx(function () use ($e, $a) {
        submit_attempt($e, $a, 'sv_nop');
    });
    log_event($e['ExamID'], $a['AttemptID'], 'nop_bai', '', 0);
    $a = q_one('SELECT * FROM kt_attempts WHERE AttemptID = :a', ['a' => $a['AttemptID']]);
    api_ok(attempt_state($e, $a));
}

/** Xem lại bài sau khi GV công bố đáp án: đáp án, lựa chọn của SV, giải thích. */
function action_review(array $p): void
{
    [$e, $a] = require_attempt($p);
    if ($a['Status'] !== 'SUBMITTED' || !(int) $e['ResultsPublished']) {
        fail('Giảng viên chưa công bố đáp án.');
    }
    $items = exam_items_snapshot((string) $e['ExamID']);
    $lay = jdec($a['LayoutJSON']);
    $ans = jdec($a['AnswersJSON']);
    $det = jdec($a['DetailJSON']);
    $out = [];
    $n = 0;
    foreach ((array) ($lay['order'] ?? array_keys($items)) as $qid) {
        if (!isset($items[$qid])) {
            continue;
        }
        $it = $items[$qid];
        $byKey = array_column((array) $it['options'], 'text', 'key');
        $opts = [];
        foreach ((array) ($lay['opt'][$qid] ?? array_keys($byKey)) as $k) {
            if (isset($byKey[$k])) {
                $opts[] = ['id' => $k, 'text' => $byKey[$k]];
            }
        }
        $out[] = ['n' => ++$n, 'type' => $it['type'], 'stem' => $it['stem'], 'image' => $it['imageId'] ?: null, 'options' => $opts,
            'answer' => $it['answer'], 'given' => $ans[$qid] ?? null, 'explanation' => $it['explanation'], 'clo' => $it['cloCode'] ?? null,
            'earned' => $det[$qid]['e'] ?? 0, 'points' => (float) $it['points'], 'status' => $det[$qid]['s'] ?? 'blank'];
    }
    api_ok(['score10' => (float) $a['Score10'], 'raw' => (float) $a['RawScore'], 'max' => (float) $a['MaxScore'], 'items' => $out]);
}
