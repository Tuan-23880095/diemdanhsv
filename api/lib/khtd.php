<?php
/**
 * api/lib/khtd.php — Module "Phiếu học tập online" cho thực tập Khoa học Trái đất (khtd/).
 *
 * Luồng:
 *  1. GV đăng nhập (token LECTURER) → khtdSeedWorksheets(classId) tạo 6 phiếu mặc định cho lớp;
 *     mở phiên điểm danh như bình thường (openAttendance) để có mã 4 ký tự.
 *  2. SV: khtdLogin(mssv, code) — mã 4 ký tự của phiên điểm danh đang OPEN → token Kind 'KHTD' (3 giờ).
 *     Dự phòng: token GRADE (mã gửi qua email, verifyGradeCode) cũng được chấp nhận.
 *  3. SV: khtdListWorksheets → khtdGetWorksheet → khtdSaveDraft (tự lưu) → khtdSubmit (một lần).
 *  4. khtdSubmit gọi Gemini chấm theo rubric trong SchemaJSON → lưu AiScore/AiJSON → gửi email cho SV
 *     (students.Email) với ghi chú "điểm tạm tính, GV duyệt". Không có key Gemini thì chỉ lưu SUBMITTED.
 *  5. GV: khtdLecturerList(classId) → khtdLecturerGrade(submissionId, finalScore, note, sendEmail)
 *     → khtdExportCsv(classId) để dán vào bảng điểm.
 *
 * Cấu hình thêm trong ../private/config.php:
 *   'gemini' => ['api_key' => '...', 'model' => 'gemini-2.0-flash'],
 *   'app' => ['rate_limits' => ['khtd_login_ip' => ['limit'=>30,'window_sec'=>600]]] (tuỳ chọn)
 */

const KHTD_TOKEN_TTL = 3 * 3600;

/* ------------------------------------------------------------------ */
/* Xác thực sinh viên                                                  */
/* ------------------------------------------------------------------ */

/** Chấp nhận token Kind KHTD hoặc GRADE. Trả về studentId, mssv, fullName, email. */
function khtd_require_student(string $token): array
{
    $token = trim($token);
    $msg = 'Phiên làm phiếu đã hết hạn. Vui lòng đăng nhập lại bằng MSSV và mã của buổi học.';
    if ($token === '') throw new RuntimeException($msg);
    $stmt = db()->prepare(
        "SELECT s.StudentID, s.MSSV, s.FullName, s.Email FROM auth_tokens t " .
        "JOIN students s ON s.StudentID = t.SubjectID " .
        "WHERE t.Token = :token AND t.Kind IN ('KHTD','GRADE') AND t.ExpiresAt > NOW() LIMIT 1"
    );
    $stmt->execute(['token' => $token]);
    $row = $stmt->fetch();
    if (!$row) throw new RuntimeException($msg);
    return ['studentId' => (string) $row['StudentID'], 'mssv' => (string) $row['MSSV'],
            'fullName' => (string) $row['FullName'], 'email' => (string) ($row['Email'] ?? '')];
}

/** SV phải ghi danh lớp của phiếu. */
function khtd_assert_enrolled(string $studentId, string $classId): void
{
    $stmt = db()->prepare("SELECT 1 FROM enrollments WHERE StudentID = :s AND ClassID = :c AND Status = 'ACTIVE' LIMIT 1");
    $stmt->execute(['s' => $studentId, 'c' => $classId]);
    if (!$stmt->fetch()) throw new RuntimeException('Bạn không có trong danh sách lớp của phiếu này.');
}

/** POST khtdLogin {mssv, code} — mã 4 ký tự của phiên điểm danh đang mở. */
function action_khtd_login(array $params): void
{
    $ip = rate_limit_client_ip();
    rate_limit_guard('khtd_login_ip', $ip);
    $mssv = trim((string) ($params['mssv'] ?? ''));
    $code = strtoupper(trim((string) ($params['code'] ?? '')));
    if (!preg_match('/^[A-Z0-9]{4}$/', $code)) {
        rate_limit_record('khtd_login_ip', $ip);
        api_fail('Mã buổi học phải gồm 4 ký tự.'); return;
    }
    $stmt = db()->prepare(
        "SELECT k.*, se.ClassID FROM attendance_keys k JOIN sessions se ON se.SessionID = k.SessionID " .
        "WHERE k.Code = :code AND k.Status = 'OPEN' AND k.EndTime > NOW() ORDER BY k.CreatedAt DESC LIMIT 1"
    );
    $stmt->execute(['code' => $code]);
    $key = $stmt->fetch();
    if (!$key) {
        rate_limit_record('khtd_login_ip', $ip);
        api_fail('Mã không đúng hoặc buổi học chưa mở. Hỏi giảng viên mã của buổi hôm nay.'); return;
    }
    $who = identify_student($mssv, (string) $key['ClassID']);
    if (!$who['ok']) {
        rate_limit_record('khtd_login_ip', $ip);
        api_fail($who['reason']); return;
    }
    $st = $who['student'];
    $token = issue_token('KHTD', (string) $st['StudentID'], KHTD_TOKEN_TTL);
    log_audit((string) $st['StudentID'], 'STUDENT', 'khtdLogin', 'class', (string) $key['ClassID'], ['sessionId' => $key['SessionID']]);
    api_ok(['token' => $token, 'fullName' => $st['FullName'], 'mssv' => $st['MSSV'],
            'email' => $st['Email'] ?? '', 'classId' => $key['ClassID'], 'sessionId' => $key['SessionID'],
            'expiresIn' => KHTD_TOKEN_TTL]);
}

/* ------------------------------------------------------------------ */
/* Phiếu                                                                */
/* ------------------------------------------------------------------ */

function khtd_worksheet(string $id): array
{
    $stmt = db()->prepare('SELECT * FROM khtd_worksheets WHERE WorksheetID = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $ws = $stmt->fetch();
    if (!$ws) throw new RuntimeException('Không tìm thấy phiếu.');
    $ws['schema'] = json_decode((string) $ws['SchemaJSON'], true) ?: [];
    return $ws;
}

function khtd_is_open(array $ws): bool
{
    if ($ws['Status'] !== 'OPEN') return false;
    $now = db_now();
    if ($ws['OpenAt'] && $now < $ws['OpenAt']) return false;
    if ($ws['CloseAt'] && $now > $ws['CloseAt']) return false;
    return true;
}

/** GET khtdListWorksheets {token} — phiếu của các lớp SV đang ghi danh + trạng thái bài làm. */
function action_khtd_list_worksheets(array $params): void
{
    $me = khtd_require_student((string) ($params['token'] ?? ''));
    $stmt = db()->prepare(
        "SELECT w.WorksheetID, w.ClassID, c.ClassCode, w.No, w.Title, w.Status, w.OpenAt, w.CloseAt, " .
        "sub.Status AS SubStatus, sub.SubmittedAt, sub.AiScore, sub.FinalScore " .
        "FROM khtd_worksheets w JOIN classes c ON c.ClassID = w.ClassID " .
        "JOIN enrollments e ON e.ClassID = w.ClassID AND e.StudentID = :sid AND e.Status = 'ACTIVE' " .
        "LEFT JOIN khtd_submissions sub ON sub.WorksheetID = w.WorksheetID AND sub.StudentID = :sid2 " .
        "WHERE w.Status <> 'HIDDEN' ORDER BY c.ClassCode, w.No"
    );
    $stmt->execute(['sid' => $me['studentId'], 'sid2' => $me['studentId']]);
    api_ok(['student' => $me, 'worksheets' => $stmt->fetchAll()]);
}

/** GET khtdGetWorksheet {token, worksheetId} — cấu trúc phiếu (bỏ rubric/đáp án) + bài làm hiện có. */
function action_khtd_get_worksheet(array $params): void
{
    $me = khtd_require_student((string) ($params['token'] ?? ''));
    $ws = khtd_worksheet((string) ($params['worksheetId'] ?? ''));
    khtd_assert_enrolled($me['studentId'], (string) $ws['ClassID']);
    $schema = $ws['schema'];
    unset($schema['answer_hints']);                       // không lộ gợi ý đáp án
    $stmt = db()->prepare('SELECT SubmissionID, AnswersJSON, Status, SubmittedAt, AiScore, AiJSON, FinalScore, FinalNote FROM khtd_submissions WHERE WorksheetID = :w AND StudentID = :s LIMIT 1');
    $stmt->execute(['w' => $ws['WorksheetID'], 's' => $me['studentId']]);
    $sub = $stmt->fetch() ?: null;
    if ($sub) {
        $sub['answers'] = json_decode((string) $sub['AnswersJSON'], true) ?: new stdClass();
        $sub['ai'] = $sub['AiJSON'] ? json_decode((string) $sub['AiJSON'], true) : null;
        unset($sub['AnswersJSON'], $sub['AiJSON']);
    }
    api_ok(['worksheet' => ['id' => $ws['WorksheetID'], 'no' => (int) $ws['No'], 'title' => $ws['Title'],
            'classId' => $ws['ClassID'], 'open' => khtd_is_open($ws), 'closeAt' => $ws['CloseAt'], 'schema' => $schema],
            'submission' => $sub, 'student' => $me]);
}

function khtd_clean_answers($raw): array
{
    if (is_string($raw)) $raw = json_decode($raw, true);
    if (!is_array($raw)) throw new RuntimeException('Dữ liệu bài làm không hợp lệ.');
    $json = json_encode($raw, JSON_UNESCAPED_UNICODE);
    if ($json === false || strlen($json) > 200000) throw new RuntimeException('Bài làm quá dài.');
    return $raw;
}

/** POST khtdSaveDraft {token, worksheetId, answers} */
function action_khtd_save_draft(array $params): void
{
    $me = khtd_require_student((string) ($params['token'] ?? ''));
    $ws = khtd_worksheet((string) ($params['worksheetId'] ?? ''));
    khtd_assert_enrolled($me['studentId'], (string) $ws['ClassID']);
    if (!khtd_is_open($ws)) { api_fail('Phiếu đã đóng, không lưu được nữa.'); return; }
    $answers = khtd_clean_answers($params['answers'] ?? null);
    $stmt = db()->prepare('SELECT SubmissionID, Status FROM khtd_submissions WHERE WorksheetID = :w AND StudentID = :s LIMIT 1');
    $stmt->execute(['w' => $ws['WorksheetID'], 's' => $me['studentId']]);
    $sub = $stmt->fetch();
    if ($sub && $sub['Status'] !== 'DRAFT') { api_fail('Bạn đã nộp phiếu này, không sửa được nữa.'); return; }
    $json = json_encode($answers, JSON_UNESCAPED_UNICODE);
    if ($sub) {
        db()->prepare('UPDATE khtd_submissions SET AnswersJSON = :a WHERE SubmissionID = :id')->execute(['a' => $json, 'id' => $sub['SubmissionID']]);
        $id = $sub['SubmissionID'];
    } else {
        $id = new_id('SUB');
        db()->prepare('INSERT INTO khtd_submissions (SubmissionID, WorksheetID, StudentID, AnswersJSON, Status) VALUES (:id, :w, :s, :a, \'DRAFT\')')
            ->execute(['id' => $id, 'w' => $ws['WorksheetID'], 's' => $me['studentId'], 'a' => $json]);
    }
    api_ok(['submissionId' => $id, 'savedAt' => db_now()]);
}

/** POST khtdSubmit {token, worksheetId, answers} — nộp một lần, chấm AI, gửi email. */
function action_khtd_submit(array $params): void
{
    $me = khtd_require_student((string) ($params['token'] ?? ''));
    $ws = khtd_worksheet((string) ($params['worksheetId'] ?? ''));
    khtd_assert_enrolled($me['studentId'], (string) $ws['ClassID']);
    if (!khtd_is_open($ws)) { api_fail('Phiếu đã đóng, không nộp được nữa.'); return; }
    $ip = rate_limit_client_ip();
    rate_limit_guard('khtd_submit_ip', $ip);
    rate_limit_record('khtd_submit_ip', $ip);
    $answers = khtd_clean_answers($params['answers'] ?? null);
    $json = json_encode($answers, JSON_UNESCAPED_UNICODE);

    $stmt = db()->prepare('SELECT SubmissionID, Status FROM khtd_submissions WHERE WorksheetID = :w AND StudentID = :s LIMIT 1');
    $stmt->execute(['w' => $ws['WorksheetID'], 's' => $me['studentId']]);
    $sub = $stmt->fetch();
    if ($sub && $sub['Status'] !== 'DRAFT') { api_fail('Bạn đã nộp phiếu này rồi.'); return; }
    $id = $sub ? $sub['SubmissionID'] : new_id('SUB');
    if ($sub) {
        db()->prepare("UPDATE khtd_submissions SET AnswersJSON = :a, Status = 'SUBMITTED', SubmittedAt = NOW() WHERE SubmissionID = :id")->execute(['a' => $json, 'id' => $id]);
    } else {
        db()->prepare("INSERT INTO khtd_submissions (SubmissionID, WorksheetID, StudentID, AnswersJSON, Status, SubmittedAt) VALUES (:id, :w, :s, :a, 'SUBMITTED', NOW())")
            ->execute(['id' => $id, 'w' => $ws['WorksheetID'], 's' => $me['studentId'], 'a' => $json]);
    }
    log_audit($me['studentId'], 'STUDENT', 'khtdSubmit', 'worksheet', $ws['WorksheetID'], ['submissionId' => $id]);

    // Chấm bằng Gemini (fail-open: lỗi AI không làm hỏng lượt nộp).
    $ai = null; $aiErr = null;
    try {
        $ai = khtd_gemini_grade($ws, $answers, $me);
        if ($ai) {
            db()->prepare("UPDATE khtd_submissions SET Status = 'AI_GRADED', AiScore = :sc, AiJSON = :j, AiModel = :m, AiGradedAt = NOW() WHERE SubmissionID = :id")
                ->execute(['sc' => $ai['total'], 'j' => json_encode($ai, JSON_UNESCAPED_UNICODE), 'm' => $ai['model'], 'id' => $id]);
        }
    } catch (Throwable $e) {
        $aiErr = $e->getMessage();
        error_log('khtd gemini: ' . $aiErr);
    }
    // Email kết quả tạm tính.
    $mailed = false;
    if ($ai && $me['email'] !== '') {
        $mailed = khtd_send_result_mail($me, $ws, $ai, null, null);
        if ($mailed) db()->prepare('UPDATE khtd_submissions SET EmailSentAt = NOW(), EmailTo = :e WHERE SubmissionID = :id')->execute(['e' => $me['email'], 'id' => $id]);
    }
    api_ok(['submissionId' => $id, 'status' => $ai ? 'AI_GRADED' : 'SUBMITTED', 'ai' => $ai,
            'emailSent' => $mailed, 'emailTo' => $me['email'], 'aiError' => $ai ? null : ($aiErr ?: 'Chưa cấu hình Gemini — giảng viên sẽ chấm tay.')]);
}

/* ------------------------------------------------------------------ */
/* Gemini                                                               */
/* ------------------------------------------------------------------ */

function khtd_gemini_config(): array
{
    $cfg = app_config();
    $g = is_array($cfg['gemini'] ?? null) ? $cfg['gemini'] : [];
    $models = $g['models'] ?? null;                       // danh sách dự phòng; thiếu thì suy từ 'model'
    if (!is_array($models) || !$models) $models = [(string) ($g['model'] ?? 'gemini-2.0-flash'), 'gemini-2.5-flash', 'gemini-2.0-flash-lite'];
    return ['key' => (string) ($g['api_key'] ?? ''), 'models' => array_values(array_unique(array_filter($models)))];
}

/** Trả về null nếu chưa cấu hình; ném RuntimeException nếu gọi lỗi. */
function khtd_gemini_grade(array $ws, array $answers, array $student): ?array
{
    $g = khtd_gemini_config();
    if ($g['key'] === '') return null;
    $schema = $ws['schema'];
    $rubric = $schema['rubric'] ?? ['criteria' => []];
    $max = 0; foreach ($rubric['criteria'] as $c) $max += (float) $c['max'];

    $system = "Bạn là trợ giảng môn Khoa học Trái đất (ĐH Khoa học Tự nhiên, ĐHQG-HCM), chấm phiếu học tập thực tập của sinh viên ngành Kinh tế đất đai (không chuyên địa chất). "
            . "Chấm theo đúng rubric, công bằng, khuyến khích; nhận xét ngắn gọn bằng tiếng Việt, chỉ ra 1–2 điểm tốt và 1–2 điểm cần sửa cụ thể, không viết lại đáp án đầy đủ. "
            . "Nếu ô để trống hoặc sao chép nguyên văn gợi ý thì trừ điểm theo rubric. Điểm từng tiêu chí là bội số của 0,25 và không vượt mức tối đa. "
            . "Chỉ trả về JSON hợp lệ theo mẫu: {\"criteria\":[{\"id\":\"...\",\"score\":0,\"comment\":\"...\"}],\"feedback\":\"...\",\"flags\":[\"...\"]}.";
    $user = [
        'phieu' => ['so' => (int) $ws['No'], 'tieu_de' => $ws['Title'], 'cau_truc' => array_map(function ($s) {
            return ['id' => $s['id'], 'type' => $s['type'], 'title' => $s['title'] ?? '', 'columns' => $s['columns'] ?? null, 'items' => $s['items'] ?? null, 'prompt' => $s['prompt'] ?? null];
        }, $schema['sections'] ?? [])],
        'rubric' => $rubric,
        'goi_y_dap_an_cho_nguoi_cham' => $schema['answer_hints'] ?? '',
        'bai_lam_cua_sinh_vien' => $answers,
    ];
    $body = [
        'systemInstruction' => ['parts' => [['text' => $system]]],
        'contents' => [['role' => 'user', 'parts' => [['text' => json_encode($user, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)]]]],
        'generationConfig' => ['temperature' => 0.2, 'responseMimeType' => 'application/json', 'maxOutputTokens' => 2048],
    ];
    // Thử lần lượt các model: 404 (model không tồn tại), 429 (hết hạn mức), 5xx → chuyển model kế tiếp;
    // 400/401/403 (key sai, bị khoá, thiếu quyền) → dừng ngay, báo rõ để GV sửa cấu hình.
    $data = null; $used = null; $lastErr = '';
    foreach ($g['models'] as $model) {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . $g['key']],
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
        ]);
        $resp = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);
        if ($resp === false) { $lastErr = 'Không gọi được Gemini: ' . $cerr; continue; }
        $d = json_decode((string) $resp, true);
        if ($http === 200) { $data = $d; $used = $model; break; }
        $lastErr = 'Gemini ' . $model . ' HTTP ' . $http . ': ' . substr((string) ($d['error']['message'] ?? $resp), 0, 200);
        if (in_array($http, [400, 401, 403], true)) break;   // lỗi cấu hình key — đổi model cũng vô ích
    }
    if ($data === null) throw new RuntimeException($lastErr ?: 'Gemini không phản hồi.');
    $text = (string) ($data['candidates'][0]['content']['parts'][0]['text'] ?? '');
    $out = json_decode($text, true);
    if (!is_array($out) || !isset($out['criteria'])) throw new RuntimeException('Gemini trả về không đúng JSON.');

    // Chuẩn hoá điểm theo rubric.
    $byId = []; foreach ($rubric['criteria'] as $c) $byId[$c['id']] = $c;
    $crit = []; $total = 0.0;
    foreach ($out['criteria'] as $c) {
        $id = (string) ($c['id'] ?? ''); if (!isset($byId[$id])) continue;
        $mx = (float) $byId[$id]['max'];
        $sc = round(max(0, min($mx, (float) ($c['score'] ?? 0))) * 4) / 4;
        $crit[] = ['id' => $id, 'name' => $byId[$id]['name'], 'max' => $mx, 'score' => $sc, 'comment' => mb_substr((string) ($c['comment'] ?? ''), 0, 600)];
        $total += $sc;
    }
    foreach ($byId as $id => $c) {          // tiêu chí AI bỏ sót → 0 điểm, ghi chú
        if (!in_array($id, array_column($crit, 'id'), true)) $crit[] = ['id' => $id, 'name' => $c['name'], 'max' => (float) $c['max'], 'score' => 0, 'comment' => 'Chưa chấm được — GV xem lại.'];
    }
    $total10 = $max > 0 ? round($total / $max * 10 * 4) / 4 : 0;
    return ['model' => $used, 'criteria' => $crit, 'raw_total' => $total, 'max' => $max, 'total' => $total10,
            'feedback' => mb_substr((string) ($out['feedback'] ?? ''), 0, 2000), 'flags' => array_slice((array) ($out['flags'] ?? []), 0, 5)];
}

/* ------------------------------------------------------------------ */
/* Email                                                                */
/* ------------------------------------------------------------------ */

function khtd_send_result_mail(array $student, array $ws, ?array $ai, ?float $final, ?string $note): bool
{
    $to = trim((string) $student['email']);
    if ($to === '') return false;
    $lines = [];
    $lines[] = 'Chào ' . $student['fullName'] . ' (' . $student['mssv'] . '),';
    $lines[] = '';
    $lines[] = 'Kết quả Phiếu học tập số ' . $ws['No'] . ' – ' . $ws['Title'] . ' (Khoa học Trái đất, thực tập):';
    if ($final !== null) {
        $lines[] = '• ĐIỂM CHÍNH THỨC (giảng viên duyệt): ' . number_format($final, 2) . '/10';
        if ($note) { $lines[] = '• Nhận xét của giảng viên: ' . $note; }
    } elseif ($ai) {
        $lines[] = '• Điểm tạm tính (trợ lý AI chấm theo rubric R2, giảng viên sẽ duyệt lại): ' . number_format((float) $ai['total'], 2) . '/10';
    }
    if ($ai) {
        $lines[] = '';
        $lines[] = 'Chi tiết theo tiêu chí:';
        foreach ($ai['criteria'] as $c) $lines[] = '  - ' . $c['name'] . ': ' . $c['score'] . '/' . $c['max'] . ($c['comment'] ? ' — ' . $c['comment'] : '');
        if (!empty($ai['feedback'])) { $lines[] = ''; $lines[] = 'Nhận xét chung: ' . $ai['feedback']; }
    }
    $lines[] = '';
    $lines[] = 'Bạn có thể xem lại bài làm tại https://diemdanhsv.com/khtd/online.html (đăng nhập bằng MSSV + mã buổi học). Thắc mắc về điểm: phản hồi trong 7 ngày với giảng viên.';
    $lines[] = '';
    $lines[] = 'ThS. Đinh Quốc Tuấn – Khoa Địa chất, Trường ĐH Khoa học Tự nhiên, ĐHQG-HCM';
    $lines[] = '(Email tự động từ hệ thống diemdanhsv.com — vui lòng không trả lời thư này.)';
    $subject = '[KHTĐ] Kết quả Phiếu học tập số ' . $ws['No'] . ($final !== null ? ' (chính thức)' : ' (tạm tính)');
    return mail_send($to, $subject, implode("\n", $lines));
}

/* ------------------------------------------------------------------ */
/* Giảng viên                                                           */
/* ------------------------------------------------------------------ */

/** POST khtdSeedWorksheets {token, classId} — tạo 6 phiếu mặc định cho lớp (bỏ qua phiếu đã có). */
function action_khtd_seed_worksheets(array $params): void
{
    $me = require_role((string) ($params['token'] ?? ''), ['LECTURER', 'ADMIN']);
    $classId = trim((string) ($params['classId'] ?? ''));
    assert_class_access($me, $classId);
    $created = [];
    foreach (khtd_default_worksheets() as $no => $def) {
        $stmt = db()->prepare('SELECT WorksheetID FROM khtd_worksheets WHERE ClassID = :c AND No = :n LIMIT 1');
        $stmt->execute(['c' => $classId, 'n' => $no]);
        if ($stmt->fetch()) continue;
        $id = new_id('WSH');
        db()->prepare('INSERT INTO khtd_worksheets (WorksheetID, ClassID, No, Title, SchemaJSON, CreatedBy) VALUES (:id, :c, :n, :t, :s, :by)')
            ->execute(['id' => $id, 'c' => $classId, 'n' => $no, 't' => $def['title'], 's' => json_encode($def, JSON_UNESCAPED_UNICODE), 'by' => $me['userId'] ?? ($me['UserID'] ?? null)]);
        $created[] = ['no' => $no, 'id' => $id];
    }
    log_audit((string) ($me['userId'] ?? ($me['UserID'] ?? '')), 'LECTURER', 'khtdSeedWorksheets', 'class', $classId, ['created' => count($created)]);
    api_ok(['created' => $created]);
}

/** POST khtdSetWorksheetStatus {token, worksheetId, status, closeAt?} */
function action_khtd_set_worksheet_status(array $params): void
{
    $me = require_role((string) ($params['token'] ?? ''), ['LECTURER', 'ADMIN']);
    $ws = khtd_worksheet((string) ($params['worksheetId'] ?? ''));
    assert_class_access($me, (string) $ws['ClassID']);
    $status = strtoupper(trim((string) ($params['status'] ?? 'OPEN')));
    if (!in_array($status, ['OPEN', 'CLOSED', 'HIDDEN'], true)) { api_fail('Trạng thái không hợp lệ.'); return; }
    $closeAt = trim((string) ($params['closeAt'] ?? '')) ?: null;
    db()->prepare('UPDATE khtd_worksheets SET Status = :s, CloseAt = :c WHERE WorksheetID = :id')->execute(['s' => $status, 'c' => $closeAt, 'id' => $ws['WorksheetID']]);
    api_ok(['worksheetId' => $ws['WorksheetID'], 'status' => $status, 'closeAt' => $closeAt]);
}

/** GET khtdLecturerList {token, classId} — phiếu + bài nộp của cả lớp. */
function action_khtd_lecturer_list(array $params): void
{
    $me = require_role((string) ($params['token'] ?? ''), ['LECTURER', 'ADMIN']);
    $classId = trim((string) ($params['classId'] ?? ''));
    assert_class_access($me, $classId);
    $ws = db()->prepare('SELECT WorksheetID, No, Title, Status, OpenAt, CloseAt FROM khtd_worksheets WHERE ClassID = :c ORDER BY No');
    $ws->execute(['c' => $classId]);
    $subs = db()->prepare(
        "SELECT sub.SubmissionID, sub.WorksheetID, w.No, s.MSSV, s.FullName, s.Email, sub.Status, sub.SubmittedAt, sub.AiScore, sub.FinalScore, sub.FinalNote, sub.EmailSentAt " .
        "FROM khtd_submissions sub JOIN khtd_worksheets w ON w.WorksheetID = sub.WorksheetID JOIN students s ON s.StudentID = sub.StudentID " .
        "WHERE w.ClassID = :c ORDER BY w.No, s.FullName"
    );
    $subs->execute(['c' => $classId]);
    api_ok(['worksheets' => $ws->fetchAll(), 'submissions' => $subs->fetchAll()]);
}

/** GET khtdLecturerSubmission {token, submissionId} — bài làm đầy đủ + chấm AI. */
function action_khtd_lecturer_submission(array $params): void
{
    $me = require_role((string) ($params['token'] ?? ''), ['LECTURER', 'ADMIN']);
    $stmt = db()->prepare('SELECT sub.*, s.MSSV, s.FullName, s.Email, w.ClassID, w.No, w.Title, w.SchemaJSON FROM khtd_submissions sub JOIN students s ON s.StudentID = sub.StudentID JOIN khtd_worksheets w ON w.WorksheetID = sub.WorksheetID WHERE sub.SubmissionID = :id LIMIT 1');
    $stmt->execute(['id' => trim((string) ($params['submissionId'] ?? ''))]);
    $row = $stmt->fetch();
    if (!$row) { api_fail('Không tìm thấy bài nộp.'); return; }
    assert_class_access($me, (string) $row['ClassID']);
    $row['answers'] = json_decode((string) $row['AnswersJSON'], true);
    $row['ai'] = $row['AiJSON'] ? json_decode((string) $row['AiJSON'], true) : null;
    $row['schema'] = json_decode((string) $row['SchemaJSON'], true);
    unset($row['AnswersJSON'], $row['AiJSON'], $row['SchemaJSON']);
    api_ok($row);
}

/** POST khtdLecturerGrade {token, submissionId, finalScore, note, sendEmail} */
function action_khtd_lecturer_grade(array $params): void
{
    $me = require_role((string) ($params['token'] ?? ''), ['LECTURER', 'ADMIN']);
    $stmt = db()->prepare('SELECT sub.*, s.MSSV, s.FullName, s.Email, w.ClassID, w.No, w.Title FROM khtd_submissions sub JOIN students s ON s.StudentID = sub.StudentID JOIN khtd_worksheets w ON w.WorksheetID = sub.WorksheetID WHERE sub.SubmissionID = :id LIMIT 1');
    $stmt->execute(['id' => trim((string) ($params['submissionId'] ?? ''))]);
    $row = $stmt->fetch();
    if (!$row) { api_fail('Không tìm thấy bài nộp.'); return; }
    assert_class_access($me, (string) $row['ClassID']);
    $score = (float) ($params['finalScore'] ?? 0);
    if ($score < 0 || $score > 10) { api_fail('Điểm phải trong 0–10.'); return; }
    $score = round($score * 4) / 4;
    $note = mb_substr(trim((string) ($params['note'] ?? '')), 0, 2000);
    $uid = (string) ($me['userId'] ?? ($me['UserID'] ?? ''));
    db()->prepare("UPDATE khtd_submissions SET Status = 'FINAL', FinalScore = :sc, FinalNote = :n, GradedBy = :by, GradedAt = NOW() WHERE SubmissionID = :id")
        ->execute(['sc' => $score, 'n' => $note, 'by' => $uid, 'id' => $row['SubmissionID']]);
    log_audit($uid, 'LECTURER', 'khtdLecturerGrade', 'submission', $row['SubmissionID'], ['score' => $score]);
    $mailed = false;
    if (!empty($params['sendEmail'])) {
        $ai = $row['AiJSON'] ? json_decode((string) $row['AiJSON'], true) : null;
        $student = ['email' => $row['Email'], 'fullName' => $row['FullName'], 'mssv' => $row['MSSV']];
        $mailed = khtd_send_result_mail($student, $row, $ai, $score, $note);
        if ($mailed) db()->prepare('UPDATE khtd_submissions SET EmailSentAt = NOW(), EmailTo = :e WHERE SubmissionID = :id')->execute(['e' => $row['Email'], 'id' => $row['SubmissionID']]);
    }
    api_ok(['submissionId' => $row['SubmissionID'], 'finalScore' => $score, 'emailSent' => $mailed]);
}

/** POST khtdRegrade {token, submissionId} — GV yêu cầu AI chấm lại (sau khi sửa key/model hoặc hết hạn mức). */
function action_khtd_regrade(array $params): void
{
    $me = require_role((string) ($params['token'] ?? ''), ['LECTURER', 'ADMIN']);
    $stmt = db()->prepare('SELECT sub.*, s.MSSV, s.FullName, s.Email, w.ClassID, w.No, w.Title, w.SchemaJSON FROM khtd_submissions sub JOIN students s ON s.StudentID = sub.StudentID JOIN khtd_worksheets w ON w.WorksheetID = sub.WorksheetID WHERE sub.SubmissionID = :id LIMIT 1');
    $stmt->execute(['id' => trim((string) ($params['submissionId'] ?? ''))]);
    $row = $stmt->fetch();
    if (!$row) { api_fail('Không tìm thấy bài nộp.'); return; }
    assert_class_access($me, (string) $row['ClassID']);
    if ($row['Status'] === 'DRAFT') { api_fail('Sinh viên chưa nộp.'); return; }
    $ws = ['No' => $row['No'], 'Title' => $row['Title'], 'schema' => json_decode((string) $row['SchemaJSON'], true) ?: []];
    $student = ['email' => $row['Email'], 'fullName' => $row['FullName'], 'mssv' => $row['MSSV']];
    try {
        $ai = khtd_gemini_grade($ws, json_decode((string) $row['AnswersJSON'], true) ?: [], $student);
    } catch (Throwable $e) { api_fail('Chấm AI lỗi: ' . $e->getMessage()); return; }
    if (!$ai) { api_fail('Chưa cấu hình key Gemini trong private/config.php.'); return; }
    $newStatus = $row['Status'] === 'FINAL' ? 'FINAL' : 'AI_GRADED';
    db()->prepare("UPDATE khtd_submissions SET Status = :st, AiScore = :sc, AiJSON = :j, AiModel = :m, AiGradedAt = NOW() WHERE SubmissionID = :id")
        ->execute(['st' => $newStatus, 'sc' => $ai['total'], 'j' => json_encode($ai, JSON_UNESCAPED_UNICODE), 'm' => $ai['model'], 'id' => $row['SubmissionID']]);
    log_audit((string) ($me['userId'] ?? ''), 'LECTURER', 'khtdRegrade', 'submission', $row['SubmissionID'], ['model' => $ai['model']]);
    api_ok(['submissionId' => $row['SubmissionID'], 'ai' => $ai]);
}

/** GET khtdExportCsv {token, classId} — MSSV, Họ tên, Phiếu 1..6 (điểm chính thức, hoặc AI nếu chưa duyệt). */
function action_khtd_export_csv(array $params): void
{
    $me = require_role((string) ($params['token'] ?? ''), ['LECTURER', 'ADMIN']);
    $classId = trim((string) ($params['classId'] ?? ''));
    assert_class_access($me, $classId);
    $stmt = db()->prepare(
        "SELECT s.MSSV, s.FullName, w.No, COALESCE(sub.FinalScore, sub.AiScore) AS Score, sub.Status " .
        "FROM enrollments e JOIN students s ON s.StudentID = e.StudentID " .
        "LEFT JOIN khtd_worksheets w ON w.ClassID = e.ClassID " .
        "LEFT JOIN khtd_submissions sub ON sub.WorksheetID = w.WorksheetID AND sub.StudentID = s.StudentID " .
        "WHERE e.ClassID = :c AND e.Status = 'ACTIVE' ORDER BY s.FullName, w.No"
    );
    $stmt->execute(['c' => $classId]);
    $rows = [];
    foreach ($stmt->fetchAll() as $r) {
        $k = $r['MSSV'];
        $rows[$k] = $rows[$k] ?? ['MSSV' => $r['MSSV'], 'HoTen' => $r['FullName']];
        if ($r['No'] !== null) $rows[$k]['Phieu' . $r['No']] = $r['Score'] === null ? '' : (float) $r['Score'];
    }
    api_ok(['rows' => array_values($rows)]);
}

/* ------------------------------------------------------------------ */
/* 6 phiếu mặc định (bám theo bản in khtd/phieu/*.html)                 */
/* ------------------------------------------------------------------ */

function khtd_default_worksheets(): array
{
    $rubric = function (array $items) {
        return ['criteria' => array_map(function ($i) { return ['id' => $i[0], 'name' => $i[1], 'max' => $i[2]]; }, $items)];
    };
    $self = function (array $items) { return ['id' => 'tdg', 'type' => 'self', 'title' => 'Tự đánh giá (Chưa / Tạm được / Tự tin)', 'items' => $items]; };
    $sdg = function (string $p) { return ['id' => 'sdg', 'type' => 'text', 'title' => 'Liên hệ SDGs / Net Zero', 'prompt' => $p]; };
    $qs = function (array $q) { $out = []; foreach ($q as $i => $t) $out[] = ['id' => 'q' . ($i + 1), 'text' => $t]; return ['id' => 'vandung', 'type' => 'questions', 'title' => 'Câu hỏi vận dụng', 'items' => $out]; };
    $tbl = function (string $id, string $title, array $cols, int $n, string $prefix = 'Mẫu') {
        return ['id' => $id, 'type' => 'table', 'title' => $title, 'columns' => $cols, 'rows' => array_map(fn($i) => $prefix . ' ' . $i, range(1, $n))];
    };
    $R = $rubric([['hd1', 'Hoạt động mở đầu', 2], ['bang', 'Bảng mô tả mẫu: đủ ô, thuật ngữ đúng', 3], ['ten', 'Gọi tên đúng', 2], ['vd', 'Câu hỏi vận dụng & SDG', 2], ['td', 'Thái độ, trình bày', 1]]);

    return [
        1 => ['title' => 'Khoáng vật – tính chất vật lý, 8 khoáng vật tạo đá, thang Mohs',
            'sections' => [
                $tbl('mohs', 'HĐ1 – Trạm Mohs: xếp 5 mẫu ẩn danh M1–M5', ['Móng tay 2,5 (✓/✗)', 'Đồng xu 3,5', 'Đinh/kính 5,5', 'Dũa 6,5', 'Khoảng độ cứng', 'Có thể là KV Mohs số…'], 5, 'M'),
                $tbl('mota', 'HĐ2 – Mô tả & gọi tên 8 khoáng vật tạo đá', ['Màu', 'Ánh', 'Độ cứng', 'Cát khai / vết vỡ', 'HCl', 'Dấu hiệu khác', 'Tên khoáng vật'], 8),
                $qs(['Hai mẫu đều trắng, ánh thủy tinh. X vạch được kính; Y bị đồng xu vạch và sủi bọt HCl. Gọi tên X, Y và nêu phép thử quyết định.',
                     'Vì sao biotit và muscovit tách thành lá mỏng dẻo, còn thạch anh thì không?',
                     'Mẫu có 2 hướng cát khai ~90°, màu hồng, độ cứng 6: orthoclase hay plagioclase? Cần quan sát gì thêm?',
                     'Thạch anh và olivin đều cứng 6,5–7. Phân biệt bằng dấu hiệu nào ngoài màu?']),
                $sdg('Olivin và silicat Mg phản ứng với CO₂ tạo carbonat bền (khoáng hóa carbon). Tính chất nào của khoáng vật quyết định tốc độ phản ứng này?'),
                $self(['Thực hiện phép thử độ cứng đúng cách', 'Phân biệt cát khai với vết vỡ', 'Gọi tên 8 khoáng vật với 2 dấu hiệu', 'Giải thích vì sao màu không đủ để nhận diện'])],
            'rubric' => $R,
            'answer_hints' => 'X = thạch anh (H7, vạch kính, vết vỡ vỏ sò); Y = calcit (H3, sủi HCl, cát khai khối thoi). Mica: cấu trúc lớp silicat, liên kết yếu giữa lớp → cát khai 1 hướng hoàn hảo; thạch anh khung 3 chiều → không cát khai. Hồng + 2 cát khai 90° + H6 → orthoclase; cần kiểm tra không có sọc song sinh (plagioclase có sọc). Thạch anh vs olivin: thạch anh vết vỡ vỏ sò, không cát khai, thường trong/trắng, tinh thể lăng trụ; olivin lục oliu, dạng hạt, ánh mỡ, trong đá mafic. SDG: diện tích bề mặt/độ hạt, hàm lượng Mg–Fe, độ bền hóa học (olivin phong hóa nhanh), nhiệt độ – pH.'],
        2 => ['title' => 'Đá magma – kiến trúc, thành phần, 8 mẫu phổ biến',
            'sections' => [
                $tbl('cap', 'HĐ1 – So sánh 4 cặp mẫu cùng thành phần, khác kiến trúc', ['Giống nhau ở…', 'Khác nhau ở…', 'Mẫu nào nguội nhanh hơn? Vì sao?'], 4, 'Cặp'),
                $tbl('mota', 'HĐ2 – Mô tả & định danh 8 mẫu đá magma', ['Màu / chỉ số màu (%)', 'Kiến trúc', 'Khoáng vật nhận ra', 'Xâm nhập / phun trào', 'Nhóm thành phần', 'Tên đá', 'Điều kiện thành tạo'], 8),
                $qs(['Hai mẫu đen: một ẩn tinh nặng, một thủy tinh bóng vết vỡ vỏ sò. Tên và vì sao cùng đen nhưng khác nhóm thành phần?',
                     'Vì sao magma giàu SiO₂ thường phun nổ (rhyolit, đá bọt, tro), còn magma bazơ tạo dòng chảy basalt?',
                     'Mẫu ban trạng: ban tinh feldspar hồng 1 cm trên nền hạt mịn xám nhạt. Lịch sử nguội gồm mấy giai đoạn? Gọi tên đá.',
                     'Mô tả chu trình đá và vị trí của granit, basalt trong đó.']),
                $sdg('Basalt được dùng khoáng hóa CO₂ (CarbFix, Iceland). Vì sao basalt phù hợp hơn granit?'),
                $self(['Phân biệt 5 kiểu kiến trúc', 'Ước lượng chỉ số màu', 'Định danh 8 mẫu đá magma', 'Liên hệ kiến trúc với tốc độ nguội'])],
            'rubric' => $R,
            'answer_hints' => 'Basalt (mafic, ẩn tinh, nặng) vs obsidian (felsic, thủy tinh): màu đen của obsidian do tạp chất/thủy tinh, không phản ánh thành phần. SiO₂ cao → độ nhớt cao → khí khó thoát → phun nổ; bazơ ít nhớt → chảy tràn. Ban trạng: 2 giai đoạn (nguội chậm dưới sâu tạo ban tinh, rồi phun trào nguội nhanh) → rhyolit porphyr. Chu trình đá: magma→kết tinh→đá magma→phong hóa, vận chuyển, lắng, thành đá→trầm tích→biến chất (T,P)→nóng chảy→magma; granit xâm nhập, basalt phun trào. Basalt giàu Ca, Mg, Fe và có lỗ hổng/thấm tốt → kết tủa carbonat nhanh; granit nghèo cation hóa trị 2.'],
        3 => ['title' => 'Đá trầm tích – vụn cơ học, hóa học, sinh hóa, môi trường thành tạo',
            'sections' => [
                $tbl('hat', 'HĐ1 – Đọc hạt (T1-a, T1-b, T2-a, T2-b)', ['Cỡ hạt chủ đạo (mm) / cấp hạt', 'Chọn lọc (kém/vừa/tốt)', 'Mài tròn (góc cạnh…tròn)', 'Suy luận năng lượng & quãng đường'], 4, 'Trạm'),
                $tbl('mota', 'HĐ2 – Mô tả & định danh 7 mẫu đá trầm tích', ['Màu', 'Nhóm nguồn gốc', 'Cỡ hạt / tinh thể', 'Chọn lọc – mài tròn', 'HCl', 'Cấu tạo, hóa thạch, khác', 'Tên đá', 'Môi trường thành tạo'], 7),
                $qs(['Cát kết hạt tròn, chọn lọc tốt, chỉ thạch anh vs cát kết hạt góc cạnh, nhiều feldspar và mảnh đá: mẫu nào trưởng thành hơn, vì sao, gợi ý nguồn/khí hậu?',
                     'Vì sao đá vôi Hà Tiên – Kiên Lương chứa san hô, huệ biển? Điều kiện môi trường lúc thành tạo?',
                     'Laterit (đá ong) Củ Chi, Bình Dương hình thành thế nào? Vì sao thường ở đồi thấp, mực nước ngầm dao động?',
                     'Đá trầm tích chiếm ~75% diện tích bề mặt nhưng ~5% thể tích vỏ Trái đất. Giải thích.']),
                $sdg('Đá vôi là nguyên liệu xi măng (~0,8 t CO₂/t clinker). Đề xuất 1 cách giảm phát thải liên quan vật liệu địa chất.'),
                $self(['Dùng thang Wentworth gọi tên cấp hạt', 'Đánh giá chọn lọc, mài tròn', 'Định danh 7 mẫu trầm tích', 'Suy luận môi trường thành tạo'])],
            'rubric' => $R,
            'answer_hints' => 'Cát kết thạch anh tròn, chọn lọc tốt = trưởng thành (vận chuyển xa, phong hóa hóa học mạnh, khí hậu ẩm; môi trường gió/bãi biển); arkose/greywacke góc cạnh = chưa trưởng thành, nguồn gần, khí hậu khô/kiến tạo nâng nhanh. Đá vôi Kiên Lương: biển nông ấm, trong, Permi, rạn san hô. Laterit: phong hóa nhiệt đới rửa trôi Si, kiềm, tích tụ Fe–Al; kết vón khi mực nước ngầm dao động (oxi hóa), lộ ra cứng hóa. 75%/5%: trầm tích là lớp phủ mỏng trên bề mặt; vỏ chủ yếu magma + biến chất. Giảm phát thải: thay clinker bằng puzolan, tro bay, xỉ, đất sét nung (LC3), đá vôi nghiền mịn.'],
        4 => ['title' => 'Đá biến chất – tác nhân, có phiến/không phiến, đá mẹ, chu trình đá',
            'sections' => [
                $tbl('ghep', 'HĐ1 – Ghép đá mẹ → đá biến chất (3 cặp)', ['Đá mẹ (tên, nhóm)', 'Đá biến chất', 'Điều gì thay đổi (khoáng vật / hạt / cấu tạo)', 'Tác nhân chính'], 3, 'Cặp'),
                $tbl('mota', 'HĐ2 – Mô tả & định danh 7 mẫu đá biến chất', ['Có phiến / không', 'Kích thước hạt', 'Khoáng vật nhận ra', 'HCl / độ cứng', 'Đá mẹ', 'Mức độ, kiểu biến chất', 'Tên đá'], 7),
                $qs(['Vì sao mica trong schist xếp song song? Lực nào gây ra, theo phương nào so với mặt phiến?',
                     'Mẫu sủi HCl, tinh thể lấp lánh, không hóa thạch, không phân lớp: đá vôi hay marble? Lập luận.',
                     'Mô tả chu trình đá đầy đủ, ghi tên quá trình trên mỗi mũi tên (kể cả đường tắt).',
                     'Marble Thanh Hóa, Yên Bái làm đá ốp lát: liên hệ với khối xâm nhập / biến chất khu vực của vùng?']),
                $sdg('Serpentinit và đá siêu mafic biến chất là bể chứa tiềm năng khoáng hóa CO₂ (vd Núi Nưa, Thanh Hóa). Cần dữ liệu gì để đánh giá tiềm năng?'),
                $self(['Phân biệt phiến – không phiến', 'Xếp chuỗi slate → gneiss theo mức độ', 'Xác định đá mẹ của 7 mẫu', 'Vẽ/mô tả chu trình đá đầy đủ'])],
            'rubric' => $R,
            'answer_hints' => 'Mica định hướng vuông góc với ứng suất nén cực đại (áp suất định hướng) trong biến chất khu vực; mặt phiến vuông góc σ1. Marble: tái kết tinh xóa hóa thạch và phân lớp, tinh thể calcit khít lấp lánh; đá vôi hạt mịn, có hóa thạch/phân lớp. Chu trình: nóng chảy, kết tinh, nâng–phong hóa–vận chuyển–lắng đọng, thành đá (nén, xi măng), biến chất (T, P, dung dịch), đường tắt: đá magma→biến chất, trầm tích→phong hóa lại, biến chất→phong hóa. Marble Yên Bái/Thanh Hóa: biến chất khu vực đá vôi Paleozoi dọc đới Sông Hồng/các khối xâm nhập. Dữ liệu: thành phần khoáng (olivin, serpentin), độ rỗng/thấm, khối lượng đá, nguồn CO₂ gần, địa chất thủy văn, chi phí.'],
        5 => ['title' => 'Bản đồ địa hình (1) – tỷ lệ, đường đồng mức, độ cao, độ dốc, dạng địa hình',
            'sections' => [
                ['id' => 'diem', 'type' => 'table', 'title' => 'Bài tập 1 – 6 điểm A–F trên bản đồ Suối Kiết 1:25.000', 'columns' => ['Tọa độ lưới cục bộ (km Đông; km Bắc)', 'Độ cao đọc được (m) & cách suy ra', 'Dạng địa hình'], 'rows' => ['A', 'B', 'C', 'D', 'E', 'F']],
                ['id' => 'doc', 'type' => 'table', 'title' => 'Bài tập 2 – Khoảng cách và độ dốc', 'columns' => ['Khoảng cách bản đồ (cm)', 'Khoảng cách thực (m)', 'Chênh cao Δh (m)', 'Độ dốc (%)', 'Góc dốc (°)', 'Nhận xét'], 'rows' => ['A → B', 'A → D', 'B → C', 'E → F']],
                $qs(['Sông Dinh chảy theo hướng nào? Dựa vào quy tắc nào?',
                     'Nêu vị trí sườn dốc nhất (S) và vùng thoải nhất (G); giải thích bằng khoảng cách giữa các đường đồng mức.',
                     'Đồi 252 m (A) và đồi 299 m (D): đồi nào có sườn phía nam dốc hơn? Chứng minh bằng số.',
                     'Vì sao mảnh bản đồ có thêm đường phụ nét đứt 110 m, 130 m ở vùng thấp phía nam?']),
                $sdg('Với ngành Kinh tế đất đai: vùng nào trên bản đồ phù hợp canh tác/định cư, vùng nào nên giữ rừng phòng hộ? Vì sao?'),
                $self(['Đổi tỷ lệ và đo khoảng cách thực', 'Nội suy độ cao giữa 2 đường đồng mức', 'Nhận diện 5 dạng địa hình', 'Tính độ dốc % và góc dốc'])],
            'rubric' => $rubric([['hd1', 'Bảng 6 điểm: tọa độ, độ cao, dạng địa hình', 3], ['bang', 'Bảng khoảng cách – độ dốc', 3], ['vd', 'Câu hỏi 1–4 & SDG', 3], ['td', 'Trình bày, lập luận', 1]]),
            'answer_hints' => 'Tỷ lệ 1:25.000: 1 cm = 250 m. A (1,24; 1,86) đỉnh 252 m; B (2,60; 0,35) bãi bồi ~105–110 m (giữa 100 và 110); C (3,34; 0,52) điểm độ cao 136 m; D (2,82; 3,02) đỉnh 299 m; E (1,90; 2,50) sườn bắc ~190–200 m; F (3,60; 1,90) sườn đông ~150–160 m (chấp nhận ±1 khoảng cao đều nếu lập luận nội suy đúng). Khoảng cách thực: AB 2.030 m (8,1 cm), AD 1.965 m, BC 758 m, EF 1.803 m. Độ dốc AB ≈ (252−107)/2030 ≈ 7% ≈ 4°; AD ≈ 47/1965 ≈ 2,4%; BC ≈ 29/758 ≈ 3,8%. Sông Dinh chảy về phía tây-nam/nam (chữ V của đường đồng mức chỉ ngược dòng về đông bắc; độ cao giảm về phía nam). Sườn dốc nhất: sườn nam đồi 299 m / đồi 252 m nơi đường dày; thoải: vùng 100–120 m ven sông. Đường phụ 10 m vì vùng thấp quá thoải, 20 m không đủ thể hiện. SDG: thềm thoải 110–140 m gần nước phù hợp canh tác/định cư nhưng tránh bãi bồi ngập; đồi dốc >15% giữ rừng chống xói mòn.'],
        6 => ['title' => 'Bản đồ địa hình (2) – mặt cắt A–B, phóng đại đứng & ôn tập tổng hợp',
            'sections' => [
                ['id' => 'giao', 'type' => 'table', 'title' => 'Mặt cắt A–B: giao điểm với đường đồng mức', 'columns' => ['Khoảng cách từ A (m)', 'Độ cao (m)', 'Ghi chú (đỉnh, sườn, suối…)'], 'rows' => ['1', '2', '3', '4', '5', '6', '7', '8']],
                $qs(['Hệ số phóng đại đứng của mặt cắt (ngang 1:25.000, đứng 1 cm = 20 m) là bao nhiêu? Nếu không phóng đại, chiều cao cả mặt cắt còn bao nhiêu mm?',
                     'Đoạn nào trên tuyến dốc nhất? Tính độ dốc trung bình đoạn đó và so với toàn tuyến A–B.',
                     'Mô tả 3–4 câu địa hình dọc A–B từ A đến B (đỉnh, sườn, chân sườn, bậc thềm, bãi bồi, lòng sông).']),
                ['id' => 'ontap', 'type' => 'table', 'title' => 'Ôn tập – thi thử 10 mẫu', 'columns' => ['Khoáng vật / Đá', 'Nhóm', '2 dấu hiệu quyết định', 'Tên'], 'rows' => array_map(fn($i) => 'Mẫu ' . $i, range(1, 10))],
                ['id' => 'sododd', 'type' => 'text', 'title' => 'Sơ đồ quyết định nhanh', 'prompt' => 'Điền tên đá cho các nhánh: magma xâm nhập sáng/muối tiêu/sẫm; phun trào sáng/xám/đen nặng/đen bóng/nhẹ nổi; trầm tích >2 mm tròn/góc, cát, mịn, hóa thạch+HCl; biến chất phiến mịn, ánh lụa, mica lớn, dải, HCl, cứng 7.'],
                $self(['Dựng mặt cắt từ bản đồ đồng mức', 'Tính phóng đại đứng', 'Định danh 10 mẫu bất kỳ trong 30 phút', 'Nêu 2 dấu hiệu quyết định mỗi mẫu'])],
            'rubric' => $rubric([['hd1', 'Bảng giao điểm mặt cắt A–B', 3], ['vd', 'Câu hỏi 1–3', 3], ['bang', 'Thi thử 10 mẫu + sơ đồ quyết định', 3], ['td', 'Trình bày', 1]]),
            'answer_hints' => 'Giao điểm từ A (252 m): 118 m→240; 175→220; 242→200; 334→180; 418→160; 636→140; 704→120; rồi dao động 100–120 (939, 1218, 1259, 1322, 1889 m đều 120) đến B ~105–110 m; tổng 2.030 m. VE = 25.000/2.000 = 12,5×; không phóng đại: 150 m chênh cao → 6 mm. Dốc nhất: 0–700 m từ A (≈132 m/700 m ≈ 19% ≈ 11°) so với toàn tuyến ≈ 7%. Mô tả: đỉnh A → sườn dốc đều hướng ĐN → chân sườn ~140 m → bậc thềm thoải 110–120 m lượn sóng → bãi bồi và lòng sông Dinh tại B. Sơ đồ: granit/diorit/gabro; rhyolit/andesit/basalt/obsidian/đá bọt; cuội kết/dăm kết, cát kết, bột–sét kết, đá vôi hóa thạch; slate, phyllit, schist, gneiss, marble, quartzit. Thi thử: chấm theo tên đúng + 2 dấu hiệu quan sát được.'],
    ];
}
