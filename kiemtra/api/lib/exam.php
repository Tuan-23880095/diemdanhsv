<?php
declare(strict_types=1);

/**
 * api/lib/exam.php — tạo đề, chọn câu (thủ công / rút theo ma trận CLO × độ khó),
 * vòng đời phòng thi DRAFT → LOBBY (5 phút chuẩn bị) → RUNNING → CLOSED,
 * bảng giám sát và các thao tác của giám thị.
 */

const KT_DEFAULT_SETTINGS = [
    'shuffleQuestions'  => true,   // xáo thứ tự câu theo từng SV
    'shuffleOptions'    => true,   // xáo phương án theo từng SV
    'allowBack'         => true,   // được quay lại câu trước
    'maxViolations'     => 3,      // số vi phạm tối đa trước khi xử lý
    'onLimit'           => 'lock', // lock = khoá chờ giám thị | submit = tự nộp bài
    'blurGraceSec'      => 3,      // mất tiêu điểm ngắn hơn ngưỡng này không tính (kéo thanh thông báo…)
    'requireFullscreen' => true,   // yêu cầu toàn màn hình (laptop/Android; iPhone không hỗ trợ)
    'lateJoinMinutes'   => 10,     // vào muộn tối đa sau giờ bắt đầu
    'showScore'         => true,   // hiện điểm ngay khi nộp
    'partialCredit'     => false,  // câu nhiều đáp án: chấm từng phần
    'watermark'         => true,   // hình mờ MSSV trên màn hình làm bài
];

function exam_settings(array $e): array
{
    $s = array_merge(KT_DEFAULT_SETTINGS, jdec($e['SettingsJSON'] ?? null));
    $s['maxViolations'] = max(1, min(20, (int) $s['maxViolations']));
    $s['blurGraceSec'] = max(0, min(30, (int) $s['blurGraceSec']));
    $s['lateJoinMinutes'] = max(0, min(120, (int) $s['lateJoinMinutes']));
    $s['onLimit'] = $s['onLimit'] === 'submit' ? 'submit' : 'lock';
    foreach (['shuffleQuestions', 'shuffleOptions', 'allowBack', 'requireFullscreen', 'showScore', 'partialCredit', 'watermark'] as $k) {
        $s[$k] = (bool) $s[$k];
    }
    return $s;
}

function clean_settings(array $in): array
{
    $s = [];
    foreach (KT_DEFAULT_SETTINGS as $k => $def) {
        if (array_key_exists($k, $in)) {
            $s[$k] = is_bool($def) ? (bool) $in[$k] : (is_int($def) ? (int) $in[$k] : (string) $in[$k]);
        } else {
            $s[$k] = $def;
        }
    }
    return exam_settings(['SettingsJSON' => jenc($s)]);
}

function unique_exam_code(): string
{
    for ($i = 0; $i < 20; $i++) {
        $c = random_code(6);
        if (!q_val("SELECT 1 FROM kt_exams WHERE Code = :c AND Status <> 'CLOSED'", ['c' => $c])) {
            return $c;
        }
    }
    fail('Không sinh được mã phòng, thử lại.');
}

/* ----------------------------- Danh sách / lưu ---------------------------- */

function action_listExams(array $p): void
{
    $me = require_teacher($p);
    $ids = array_column(my_classes($me), null, 'ClassID');
    if (!$ids) {
        api_ok([]);
        return;
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $rows = q_all("SELECT e.*, (SELECT COUNT(*) FROM kt_exam_items i WHERE i.ExamID = e.ExamID) AS ItemCount, " .
        "(SELECT COUNT(*) FROM kt_attempts a WHERE a.ExamID = e.ExamID) AS AttemptCount FROM kt_exams e WHERE e.ClassID IN ($in) ORDER BY e.CreatedAt DESC", array_keys($ids));
    foreach ($rows as &$r) {
        tick_exam($r);
        $c = $ids[$r['ClassID']];
        $r['ClassCode'] = $c['ClassCode'];
        $r['CourseID'] = $c['CourseID'];
        $r['CourseName'] = $c['CourseName'];
        $r['Settings'] = exam_settings($r);
        unset($r['SettingsJSON']);
    }
    api_ok($rows);
}

function action_getExam(array $p): void
{
    $me = require_teacher($p);
    $e = load_exam_for_teacher($me, str_param($p, 'examId', 40));
    $items = q_all('SELECT QuestionID, Ord, Points FROM kt_exam_items WHERE ExamID = :id ORDER BY Ord', ['id' => $e['ExamID']]);
    $e['Settings'] = exam_settings($e);
    $e['CourseID'] = $e['_class']['CourseID'];
    $e['ClassCode'] = $e['_class']['ClassCode'];
    unset($e['_class'], $e['SettingsJSON']);
    $e['Items'] = $items;
    api_ok($e);
}

function action_saveExam(array $p): void
{
    $me = require_teacher($p);
    $title = str_param($p, 'title', 200);
    if ($title === '') {
        fail('Cần nhập tên bài kiểm tra.');
    }
    $dur = max(1, min(300, (int) ($p['durationMinutes'] ?? 30)));
    $prep = max(0, min(30, (int) ($p['prepMinutes'] ?? 5)));
    $settings = clean_settings((array) ($p['settings'] ?? []));
    $id = str_param($p, 'examId', 40);
    if ($id) {
        $e = load_exam_for_teacher($me, $id);
        if ($e['Status'] === 'DRAFT') {
            q('UPDATE kt_exams SET Title = :t, DurationMinutes = :d, PrepMinutes = :p, SettingsJSON = :s WHERE ExamID = :id',
                ['t' => $title, 'd' => $dur, 'p' => $prep, 's' => jenc($settings), 'id' => $id]);
        } else {
            // đang/đã thi: chỉ đổi tên và thiết lập giám sát, không đổi thời lượng (dùng "Cộng giờ")
            q('UPDATE kt_exams SET Title = :t, SettingsJSON = :s WHERE ExamID = :id', ['t' => $title, 's' => jenc($settings), 'id' => $id]);
        }
    } else {
        $classId = str_param($p, 'classId', 40);
        assert_class($me, $classId);
        $id = new_id('EXM');
        q('INSERT INTO kt_exams (ExamID, ClassID, Title, Code, Status, PrepMinutes, DurationMinutes, SettingsJSON, SupervisorPin, CreatedBy, CreatedAt) ' .
            "VALUES (:id, :c, :t, :code, 'DRAFT', :p, :d, :s, :pin, :by, :n)", [
            'id' => $id, 'c' => $classId, 't' => $title, 'code' => unique_exam_code(), 'p' => $prep, 'd' => $dur,
            's' => jenc($settings), 'pin' => sprintf('%06d', random_int(0, 999999)), 'by' => $me['userId'], 'n' => now(),
        ]);
    }
    api_ok(['examId' => $id]);
}

function action_deleteExam(array $p): void
{
    $me = require_teacher($p);
    $e = load_exam_for_teacher($me, str_param($p, 'examId', 40));
    if (q_val('SELECT COUNT(*) FROM kt_attempts WHERE ExamID = :id', ['id' => $e['ExamID']]) > 0) {
        fail('Đề đã có sinh viên làm bài — không xoá được (giữ làm minh chứng khảo thí).');
    }
    tx(function () use ($e) {
        q('DELETE FROM kt_exam_items WHERE ExamID = :id', ['id' => $e['ExamID']]);
        q('DELETE FROM kt_events WHERE ExamID = :id', ['id' => $e['ExamID']]);
        q('DELETE FROM kt_exams WHERE ExamID = :id', ['id' => $e['ExamID']]);
    });
    api_ok(['ok' => true]);
}

/** Nhân bản đề (dùng cho lớp khác / thi lại): bản mới ở trạng thái DRAFT, mã phòng mới. */
function action_duplicateExam(array $p): void
{
    $me = require_teacher($p);
    $e = load_exam_for_teacher($me, str_param($p, 'examId', 40));
    $classId = str_param($p, 'classId', 40) ?: (string) $e['ClassID'];
    assert_class($me, $classId);
    $new = new_id('EXM');
    tx(function () use ($e, $new, $classId, $me) {
        q('INSERT INTO kt_exams (ExamID, ClassID, Title, Code, Status, PrepMinutes, DurationMinutes, SettingsJSON, SupervisorPin, CreatedBy, CreatedAt) ' .
            "VALUES (:id, :c, :t, :code, 'DRAFT', :p, :d, :s, :pin, :by, :n)", [
            'id' => $new, 'c' => $classId, 't' => $e['Title'] . ' (bản sao)', 'code' => unique_exam_code(), 'p' => $e['PrepMinutes'],
            'd' => $e['DurationMinutes'], 's' => $e['SettingsJSON'], 'pin' => sprintf('%06d', random_int(0, 999999)), 'by' => $me['userId'], 'n' => now(),
        ]);
        q('INSERT INTO kt_exam_items (ExamID, QuestionID, Ord, Points) SELECT :new, i.QuestionID, i.Ord, i.Points FROM kt_exam_items i ' .
            "JOIN kt_questions qq ON qq.QuestionID = i.QuestionID AND qq.Status = 'ACTIVE' WHERE i.ExamID = :old", ['new' => $new, 'old' => $e['ExamID']]);
    });
    api_ok(['examId' => $new]);
}

/** Đặt danh sách câu của đề (chỉ khi DRAFT). items = [{questionId, points}] theo thứ tự. */
function action_setExamItems(array $p): void
{
    $me = require_teacher($p);
    $e = load_exam_for_teacher($me, str_param($p, 'examId', 40));
    if ($e['Status'] !== 'DRAFT') {
        fail('Đề đã mở phòng — không đổi câu hỏi được nữa. Hãy nhân bản đề nếu cần sửa.');
    }
    $courseId = $e['_class']['CourseID'];
    $items = (array) ($p['items'] ?? []);
    if (count($items) > 200) {
        fail('Tối đa 200 câu mỗi đề.');
    }
    $valid = array_column(q_all("SELECT QuestionID, Points FROM kt_questions WHERE CourseID = :c AND Status = 'ACTIVE'", ['c' => $courseId]), 'Points', 'QuestionID');
    tx(function () use ($items, $valid, $e) {
        q('DELETE FROM kt_exam_items WHERE ExamID = :id', ['id' => $e['ExamID']]);
        $seen = [];
        $ord = 0;
        foreach ($items as $it) {
            $qid = (string) ($it['questionId'] ?? '');
            if (!isset($valid[$qid]) || isset($seen[$qid])) {
                continue;
            }
            $seen[$qid] = 1;
            $pts = isset($it['points']) && (float) $it['points'] > 0 ? (float) $it['points'] : (float) $valid[$qid];
            q('INSERT INTO kt_exam_items (ExamID, QuestionID, Ord, Points) VALUES (:e, :q, :o, :p)', ['e' => $e['ExamID'], 'q' => $qid, 'o' => ++$ord, 'p' => $pts]);
        }
    });
    api_ok(['count' => (int) q_val('SELECT COUNT(*) FROM kt_exam_items WHERE ExamID = :id', ['id' => $e['ExamID']])]);
}

/**
 * Rút ngẫu nhiên theo ma trận: matrix = [{cloId|null, difficulty|0, count}].
 * Trả danh sách QuestionID đề xuất (client hiển thị để GV xem rồi mới lưu).
 */
function action_drawQuestions(array $p): void
{
    $me = require_teacher($p);
    $courseId = str_param($p, 'courseId', 40);
    assert_course($me, $courseId);
    $pool = q_all("SELECT QuestionID, CloID, Difficulty FROM kt_questions WHERE CourseID = :c AND Status = 'ACTIVE'", ['c' => $courseId]);
    shuffle($pool);
    $picked = [];
    $short = [];
    foreach ((array) ($p['matrix'] ?? []) as $cell) {
        $clo = (string) ($cell['cloId'] ?? '');
        $diff = (int) ($cell['difficulty'] ?? 0);
        $need = max(0, (int) ($cell['count'] ?? 0));
        $got = 0;
        foreach ($pool as $qq) {
            if ($got >= $need) {
                break;
            }
            if (isset($picked[$qq['QuestionID']])) {
                continue;
            }
            if ($clo !== '' && $qq['CloID'] !== $clo) {
                continue;
            }
            if ($diff && (int) $qq['Difficulty'] !== $diff) {
                continue;
            }
            $picked[$qq['QuestionID']] = 1;
            $got++;
        }
        if ($got < $need) {
            $short[] = ['cloId' => $clo, 'difficulty' => $diff, 'need' => $need, 'got' => $got];
        }
    }
    api_ok(['questionIds' => array_keys($picked), 'shortages' => $short]);
}

/* ------------------------------ Vòng đời phòng thi ----------------------------- */

/** Chuyển trạng thái "lười": LOBBY quá giờ chuẩn bị → RUNNING; RUNNING quá hạn → chấm bài quá giờ, rồi đóng. */
function tick_exam(array &$e): void
{
    $now = ts(now());
    if ($e['Status'] === 'LOBBY' && $e['StartAt'] && $now >= ts($e['StartAt'])) {
        $start = ts($e['StartAt']);
        q("UPDATE kt_exams SET Status = 'RUNNING', StartedAt = :s, EndsAt = :end WHERE ExamID = :id AND Status = 'LOBBY'",
            ['s' => dt($start), 'end' => dt($start + 60 * (int) $e['DurationMinutes']), 'id' => $e['ExamID']]);
        $e = array_merge($e, q_one('SELECT Status, StartedAt, EndsAt FROM kt_exams WHERE ExamID = :id', ['id' => $e['ExamID']]));
    }
    if ($e['Status'] === 'RUNNING' && $e['EndsAt']) {
        $ends = ts($e['EndsAt']);
        if ($now > $ends) {
            finalize_overdue($e, $now);
            $maxExtra = (int) q_val("SELECT COALESCE(MAX(ExtraMinutes),0) FROM kt_attempts WHERE ExamID = :id AND Status <> 'SUBMITTED'", ['id' => $e['ExamID']]);
            if ($now > $ends + 60 * $maxExtra + 120) {
                close_exam($e, 'het_gio');
                $e['Status'] = 'CLOSED';
            }
        }
    }
}

function attempt_deadline(array $exam, array $att): ?int
{
    if (!$exam['EndsAt']) {
        return null;
    }
    return ts($exam['EndsAt']) + 60 * (int) $att['ExtraMinutes'];
}

/** Chấm mọi bài đã quá hạn (thêm 30 giây cho gói tin cuối). */
function finalize_overdue(array $e, int $now): void
{
    $rows = q_all("SELECT * FROM kt_attempts WHERE ExamID = :id AND Status <> 'SUBMITTED'", ['id' => $e['ExamID']]);
    foreach ($rows as $a) {
        if ($now > attempt_deadline($e, $a) + 30) {
            submit_attempt($e, $a, 'het_gio');
        }
    }
}

function close_exam(array $e, string $reason): void
{
    tx(function () use ($e, $reason) {
        foreach (q_all("SELECT * FROM kt_attempts WHERE ExamID = :id AND Status <> 'SUBMITTED'", ['id' => $e['ExamID']]) as $a) {
            submit_attempt($e, $a, $reason);
        }
        q("UPDATE kt_exams SET Status = 'CLOSED', ClosedAt = :n WHERE ExamID = :id", ['n' => now(), 'id' => $e['ExamID']]);
    });
}

function action_openRoom(array $p): void
{
    $me = require_teacher($p);
    $e = load_exam_for_teacher($me, str_param($p, 'examId', 40));
    if ($e['Status'] !== 'DRAFT') {
        fail('Phòng thi đã được mở trước đó.');
    }
    $items = q_all('SELECT i.QuestionID, i.Points, qq.* FROM kt_exam_items i JOIN kt_questions qq ON qq.QuestionID = i.QuestionID WHERE i.ExamID = :id ORDER BY i.Ord', ['id' => $e['ExamID']]);
    if (!$items) {
        fail('Đề chưa có câu hỏi nào.');
    }
    $prep = isset($p['prepMinutes']) ? max(0, min(30, (int) $p['prepMinutes'])) : (int) $e['PrepMinutes'];
    $clos = array_column(q_all('SELECT CloID, Code FROM kt_clos WHERE CourseID = :c', ['c' => $e['_class']['CourseID']]), 'Code', 'CloID');
    tx(function () use ($e, $items, $prep, $clos) {
        foreach ($items as $it) {
            $snap = [
                'type' => $it['Type'], 'stem' => $it['Stem'], 'imageId' => $it['ImageID'], 'options' => jdec($it['OptionsJSON']),
                'answer' => jdec($it['AnswerJSON']), 'explanation' => (string) $it['Explanation'], 'cloId' => $it['CloID'],
                'cloCode' => $it['CloID'] ? ($clos[$it['CloID']] ?? null) : null, 'bloom' => $it['Bloom'] === null ? null : (int) $it['Bloom'],
                'difficulty' => (int) $it['Difficulty'], 'topic' => (string) $it['Topic'],
            ];
            q('UPDATE kt_exam_items SET SnapshotJSON = :s WHERE ExamID = :e AND QuestionID = :q', ['s' => jenc($snap), 'e' => $e['ExamID'], 'q' => $it['QuestionID']]);
        }
        $n = ts(now());
        q("UPDATE kt_exams SET Status = 'LOBBY', Code = :code, PrepMinutes = :p, OpenedAt = :o, StartAt = :s WHERE ExamID = :id", [
            'code' => unique_exam_code(), 'p' => $prep, 'o' => dt($n), 's' => dt($n + 60 * $prep), 'id' => $e['ExamID'],
        ]);
    });
    api_ok(['ok' => true]);
}

function action_startNow(array $p): void
{
    $me = require_teacher($p);
    $e = load_exam_for_teacher($me, str_param($p, 'examId', 40));
    if ($e['Status'] !== 'LOBBY') {
        fail('Chỉ bắt đầu được khi phòng đang ở giai đoạn chuẩn bị.');
    }
    $n = ts(now());
    q("UPDATE kt_exams SET Status = 'RUNNING', StartAt = :s, StartedAt = :s2, EndsAt = :end WHERE ExamID = :id",
        ['s' => dt($n), 's2' => dt($n), 'end' => dt($n + 60 * (int) $e['DurationMinutes']), 'id' => $e['ExamID']]);
    log_event($e['ExamID'], null, 'gv_bat_dau', 'Giám thị bắt đầu sớm', 0);
    api_ok(['ok' => true]);
}

/** Cộng/bớt giờ: cả phòng (attemptId rỗng) — dời giờ chuẩn bị hoặc giờ kết thúc; một SV — ExtraMinutes. */
function action_addTime(array $p): void
{
    $me = require_teacher($p);
    $e = load_exam_for_teacher($me, str_param($p, 'examId', 40));
    $mins = (int) ($p['minutes'] ?? 0);
    if ($mins === 0 || abs($mins) > 120) {
        fail('Số phút không hợp lệ.');
    }
    $attId = str_param($p, 'attemptId', 40);
    if ($attId) {
        $a = q_one('SELECT ExtraMinutes, FullName FROM kt_attempts WHERE AttemptID = :a AND ExamID = :e', ['a' => $attId, 'e' => $e['ExamID']]);
        if (!$a) {
            fail('Không tìm thấy bài làm.');
        }
        $extra = max(0, min(180, (int) $a['ExtraMinutes'] + $mins));
        q('UPDATE kt_attempts SET ExtraMinutes = :x WHERE AttemptID = :a', ['x' => $extra, 'a' => $attId]);
        log_event($e['ExamID'], $attId, 'gv_cong_gio', ($mins > 0 ? '+' : '') . $mins . ' phút', 0);
    } elseif ($e['Status'] === 'LOBBY') {
        $s = max(ts(now()), ts($e['StartAt']) + 60 * $mins);
        q('UPDATE kt_exams SET StartAt = :s WHERE ExamID = :id', ['s' => dt($s), 'id' => $e['ExamID']]);
    } elseif ($e['Status'] === 'RUNNING') {
        $end = max(ts(now()) + 60, ts($e['EndsAt']) + 60 * $mins);
        q('UPDATE kt_exams SET EndsAt = :x WHERE ExamID = :id', ['x' => dt($end), 'id' => $e['ExamID']]);
        log_event($e['ExamID'], null, 'gv_cong_gio', 'Cả phòng ' . ($mins > 0 ? '+' : '') . $mins . ' phút', 0);
    } else {
        fail('Phòng thi chưa mở hoặc đã đóng.');
    }
    api_ok(['ok' => true]);
}

function action_closeExam(array $p): void
{
    $me = require_teacher($p);
    $e = load_exam_for_teacher($me, str_param($p, 'examId', 40));
    if (!in_array($e['Status'], ['LOBBY', 'RUNNING'], true)) {
        fail('Phòng thi không ở trạng thái đang mở.');
    }
    close_exam($e, 'gv_thu');
    log_event($e['ExamID'], null, 'gv_thu_bai', 'Giám thị thu bài, đóng phòng', 0);
    api_ok(['ok' => true]);
}

function action_publishResults(array $p): void
{
    $me = require_teacher($p);
    $e = load_exam_for_teacher($me, str_param($p, 'examId', 40));
    $on = !empty($p['published']) ? 1 : 0;
    if ($on && $e['Status'] !== 'CLOSED') {
        fail('Chỉ công bố đáp án sau khi đã thu bài (đóng phòng).');
    }
    q('UPDATE kt_exams SET ResultsPublished = :v WHERE ExamID = :id', ['v' => $on, 'id' => $e['ExamID']]);
    api_ok(['published' => $on]);
}

/* ------------------------------ Giám sát ------------------------------- */

function log_event(string $examId, ?string $attemptId, string $type, string $detail, int $counted, ?string $clientAt = null): void
{
    q('INSERT INTO kt_events (ExamID, AttemptID, Type, Detail, Counted, ClientAt, CreatedAt) VALUES (:e, :a, :t, :d, :c, :ca, :n)', [
        'e' => $examId, 'a' => $attemptId, 't' => mb_substr($type, 0, 30), 'd' => mb_substr($detail, 0, 255), 'c' => $counted,
        'ca' => $clientAt ? mb_substr($clientAt, 0, 30) : null, 'n' => now(),
    ]);
}

/** Bảng giám sát: GV gọi mỗi ~3 giây. sinceEvent = EventID lớn nhất đã nhận. */
function action_monitor(array $p): void
{
    $me = require_teacher($p);
    $e = load_exam_for_teacher($me, str_param($p, 'examId', 40));
    tick_exam($e);
    $now = now();
    $atts = q_all('SELECT AttemptID, StudentID, MSSV, FullName, SeatNo, Status, Violations, Unlocks, LockReason, ExtraMinutes, AllowRejoin, ' .
        'DeviceInfo, IP, JoinedAt, ReadyAt, LastSeenAt, SubmittedAt, SubmitReason, Score10, AnswersJSON FROM kt_attempts WHERE ExamID = :id ORDER BY SeatNo', ['id' => $e['ExamID']]);
    $total = (int) q_val('SELECT COUNT(*) FROM kt_exam_items WHERE ExamID = :id', ['id' => $e['ExamID']]);
    foreach ($atts as &$a) {
        $ans = jdec($a['AnswersJSON']);
        $a['Answered'] = count(array_filter($ans, fn($v) => $v !== null && $v !== '' && $v !== []));
        unset($a['AnswersJSON']);
        $a['SecondsSinceSeen'] = $a['LastSeenAt'] ? ts($now) - ts($a['LastSeenAt']) : null;
    }
    unset($a);
    $joined = array_column($atts, 'StudentID');
    $roster = q_all("SELECT s.StudentID, s.MSSV, s.FullName FROM enrollments en JOIN students s ON s.StudentID = en.StudentID " .
        "WHERE en.ClassID = :c AND en.Status = 'ACTIVE' AND s.Status = 'ACTIVE' ORDER BY s.MSSV", ['c' => $e['ClassID']]);
    $missing = array_values(array_filter($roster, fn($s) => !in_array($s['StudentID'], $joined, true)));
    $since = (int) ($p['sinceEvent'] ?? 0);
    $events = q_all('SELECT ev.EventID, ev.AttemptID, ev.Type, ev.Detail, ev.Counted, ev.CreatedAt, a.MSSV, a.FullName, a.SeatNo FROM kt_events ev ' .
        'LEFT JOIN kt_attempts a ON a.AttemptID = ev.AttemptID WHERE ev.ExamID = :id AND ev.EventID > :s ORDER BY ev.EventID DESC LIMIT 200', ['id' => $e['ExamID'], 's' => $since]);
    api_ok([
        'exam' => [
            'ExamID' => $e['ExamID'], 'Title' => $e['Title'], 'Code' => $e['Code'], 'Status' => $e['Status'], 'SupervisorPin' => $e['SupervisorPin'],
            'PrepMinutes' => (int) $e['PrepMinutes'], 'DurationMinutes' => (int) $e['DurationMinutes'], 'OpenedAt' => $e['OpenedAt'],
            'StartAt' => $e['StartAt'], 'StartedAt' => $e['StartedAt'], 'EndsAt' => $e['EndsAt'], 'ClosedAt' => $e['ClosedAt'],
            'ResultsPublished' => (int) $e['ResultsPublished'], 'ClassCode' => $e['_class']['ClassCode'], 'CourseName' => $e['_class']['CourseName'],
            'Settings' => exam_settings($e), 'ItemCount' => $total,
        ],
        'serverNow' => $now,
        'attempts' => $atts,
        'notJoined' => $missing,
        'rosterCount' => count($roster),
        'events' => $events,
    ]);
}

function load_attempt_for_teacher(array $me, array $p): array
{
    $e = load_exam_for_teacher($me, str_param($p, 'examId', 40));
    $a = q_one('SELECT * FROM kt_attempts WHERE AttemptID = :a AND ExamID = :e', ['a' => str_param($p, 'attemptId', 40), 'e' => $e['ExamID']]);
    if (!$a) {
        fail('Không tìm thấy bài làm.');
    }
    return [$e, $a];
}

/** Mở khoá từ bảng giám sát (mỗi lần mở cho phép thêm 1 vi phạm trước khi khoá lại). */
function action_unlockAttempt(array $p): void
{
    $me = require_teacher($p);
    [$e, $a] = load_attempt_for_teacher($me, $p);
    if ($a['Status'] !== 'LOCKED') {
        fail('Bài làm không ở trạng thái bị khoá.');
    }
    unlock_attempt($e, $a, 'Giám thị mở khoá từ bảng giám sát');
    api_ok(['ok' => true]);
}

function unlock_attempt(array $e, array $a, string $how): void
{
    $next = $e['Status'] === 'RUNNING' ? 'DOING' : 'READY';
    q("UPDATE kt_attempts SET Status = :s, Unlocks = Unlocks + 1, LockReason = NULL, PinFails = 0 WHERE AttemptID = :a AND Status = 'LOCKED'",
        ['s' => $next, 'a' => $a['AttemptID']]);
    log_event($e['ExamID'], $a['AttemptID'], 'mo_khoa', $how, 0);
}

/** Cho phép SV vào lại trên thiết bị khác (máy hết pin, hỏng…). */
function action_allowRejoin(array $p): void
{
    $me = require_teacher($p);
    [$e, $a] = load_attempt_for_teacher($me, $p);
    if ($a['Status'] === 'SUBMITTED') {
        fail('Sinh viên đã nộp bài.');
    }
    q('UPDATE kt_attempts SET AllowRejoin = 1 WHERE AttemptID = :a', ['a' => $a['AttemptID']]);
    log_event($e['ExamID'], $a['AttemptID'], 'gv_cho_doi_may', 'Cho phép vào lại trên thiết bị khác', 0);
    api_ok(['ok' => true]);
}

/** Thu bài một SV (vd vi phạm quy chế phát hiện trực tiếp). */
function action_forceSubmit(array $p): void
{
    $me = require_teacher($p);
    [$e, $a] = load_attempt_for_teacher($me, $p);
    if ($a['Status'] === 'SUBMITTED') {
        fail('Sinh viên đã nộp bài.');
    }
    $note = str_param($p, 'note', 200);
    tx(fn() => submit_attempt($e, $a, 'gv_thu'));
    log_event($e['ExamID'], $a['AttemptID'], 'gv_thu_bai_sv', $note ?: 'Giám thị thu bài', 0);
    api_ok(['ok' => true]);
}

/** Xoá lượt (chỉ khi phòng chưa bắt đầu) — vd SV vào nhầm lớp. */
function action_removeAttempt(array $p): void
{
    $me = require_teacher($p);
    [$e, $a] = load_attempt_for_teacher($me, $p);
    if ($e['Status'] !== 'LOBBY') {
        fail('Chỉ xoá được lượt vào phòng khi chưa bắt đầu làm bài.');
    }
    q('DELETE FROM kt_attempts WHERE AttemptID = :a', ['a' => $a['AttemptID']]);
    log_event($e['ExamID'], null, 'gv_xoa_luot', $a['MSSV'] . ' ' . $a['FullName'], 0);
    api_ok(['ok' => true]);
}

function action_attemptLog(array $p): void
{
    $me = require_teacher($p);
    [$e, $a] = load_attempt_for_teacher($me, $p);
    $ev = q_all('SELECT EventID, Type, Detail, Counted, ClientAt, CreatedAt FROM kt_events WHERE AttemptID = :a ORDER BY EventID', ['a' => $a['AttemptID']]);
    api_ok(['attempt' => array_diff_key($a, ['TokenHash' => 1, 'LayoutJSON' => 1]), 'events' => $ev]);
}
