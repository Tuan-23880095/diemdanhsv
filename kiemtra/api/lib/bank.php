<?php
declare(strict_types=1);

/**
 * api/lib/bank.php — CLO của học phần, ngân hàng câu hỏi, ảnh minh hoạ, nhập hàng loạt.
 */

const BLOOM_NAMES = [1 => 'Nhớ', 2 => 'Hiểu', 3 => 'Vận dụng', 4 => 'Phân tích', 5 => 'Đánh giá', 6 => 'Sáng tạo'];

/* ------------------------------- CLO ------------------------------- */

function action_listClos(array $p): void
{
    $me = require_teacher($p);
    $courseId = str_param($p, 'courseId', 40);
    assert_course($me, $courseId);
    api_ok(clos_of($courseId));
}

function clos_of(string $courseId): array
{
    return q_all("SELECT CloID, Code, Description, PassThreshold, TargetRate, SortOrder FROM kt_clos WHERE CourseID = :c AND Status = 'ACTIVE' ORDER BY SortOrder, Code", ['c' => $courseId]);
}

function action_saveClo(array $p): void
{
    $me = require_teacher($p);
    $courseId = str_param($p, 'courseId', 40);
    assert_course($me, $courseId);
    $code = strtoupper(preg_replace('/\s+/', '', str_param($p, 'code', 20)));
    $desc = str_param($p, 'description', 2000);
    if ($code === '' || $desc === '') {
        fail('Cần nhập mã CLO và mô tả.');
    }
    $pass = max(0, min(100, (float) ($p['passThreshold'] ?? 50)));
    $target = max(0, min(100, (float) ($p['targetRate'] ?? 70)));
    $order = (int) ($p['sortOrder'] ?? 0);
    $id = str_param($p, 'cloId', 40);
    $dup = q_one("SELECT CloID FROM kt_clos WHERE CourseID = :c AND Code = :k AND Status = 'ACTIVE'", ['c' => $courseId, 'k' => $code]);
    if ($dup && $dup['CloID'] !== $id) {
        fail('Mã ' . $code . ' đã có trong học phần này.');
    }
    if ($id) {
        q('UPDATE kt_clos SET Code = :k, Description = :d, PassThreshold = :p, TargetRate = :t, SortOrder = :o WHERE CloID = :id AND CourseID = :c',
            ['k' => $code, 'd' => $desc, 'p' => $pass, 't' => $target, 'o' => $order, 'id' => $id, 'c' => $courseId]);
    } else {
        // mã đã xoá mềm trước đó → dùng lại dòng cũ (giữ UNIQUE CourseID+Code)
        $old = q_one("SELECT CloID FROM kt_clos WHERE CourseID = :c AND Code = :k", ['c' => $courseId, 'k' => $code]);
        if ($old) {
            $id = $old['CloID'];
            q("UPDATE kt_clos SET Description = :d, PassThreshold = :p, TargetRate = :t, SortOrder = :o, Status = 'ACTIVE' WHERE CloID = :id",
                ['d' => $desc, 'p' => $pass, 't' => $target, 'o' => $order, 'id' => $id]);
        } else {
            $id = new_id('CLO');
            q('INSERT INTO kt_clos (CloID, CourseID, Code, Description, PassThreshold, TargetRate, SortOrder) VALUES (:id, :c, :k, :d, :p, :t, :o)',
                ['id' => $id, 'c' => $courseId, 'k' => $code, 'd' => $desc, 'p' => $pass, 't' => $target, 'o' => $order]);
        }
    }
    api_ok(['cloId' => $id]);
}

function action_deleteClo(array $p): void
{
    $me = require_teacher($p);
    $clo = q_one('SELECT * FROM kt_clos WHERE CloID = :id', ['id' => str_param($p, 'cloId', 40)]);
    if (!$clo) {
        fail('Không tìm thấy CLO.');
    }
    assert_course($me, (string) $clo['CourseID']);
    q("UPDATE kt_clos SET Status = 'INACTIVE' WHERE CloID = :id", ['id' => $clo['CloID']]);
    api_ok(['ok' => true]);
}

/* ---------------------------- Câu hỏi ------------------------------ */

function question_row_out(array $r): array
{
    return [
        'QuestionID' => $r['QuestionID'], 'Type' => $r['Type'], 'Stem' => $r['Stem'], 'ImageID' => $r['ImageID'],
        'Options' => jdec($r['OptionsJSON']), 'Answer' => jdec($r['AnswerJSON']), 'Explanation' => (string) $r['Explanation'],
        'CloID' => $r['CloID'], 'Bloom' => $r['Bloom'] === null ? null : (int) $r['Bloom'], 'Difficulty' => (int) $r['Difficulty'],
        'Points' => (float) $r['Points'], 'Topic' => (string) $r['Topic'], 'UpdatedAt' => $r['UpdatedAt'],
    ];
}

function action_listQuestions(array $p): void
{
    $me = require_teacher($p);
    $courseId = str_param($p, 'courseId', 40);
    assert_course($me, $courseId);
    $rows = q_all("SELECT * FROM kt_questions WHERE CourseID = :c AND Status = 'ACTIVE' ORDER BY CreatedAt, QuestionID", ['c' => $courseId]);
    // số lần đã dùng trong đề
    $used = [];
    foreach (q_all("SELECT i.QuestionID, COUNT(*) n FROM kt_exam_items i JOIN kt_questions qq ON qq.QuestionID = i.QuestionID WHERE qq.CourseID = :c GROUP BY i.QuestionID", ['c' => $courseId]) as $u) {
        $used[$u['QuestionID']] = (int) $u['n'];
    }
    api_ok(array_map(function ($r) use ($used) {
        $o = question_row_out($r);
        $o['UsedIn'] = $used[$r['QuestionID']] ?? 0;
        return $o;
    }, $rows));
}

/** Kiểm tra + chuẩn hoá một câu hỏi. Trả mảng cột để ghi, hoặc ném UserError. */
function normalize_question(array $q, array $closByCodeOrId): array
{
    $type = in_array($q['type'] ?? '', ['single', 'multi', 'short'], true) ? $q['type'] : 'single';
    $stem = trim((string) ($q['stem'] ?? ''));
    if ($stem === '') {
        fail('Thiếu nội dung câu hỏi.');
    }
    if (mb_strlen($stem) > 5000) {
        fail('Nội dung câu hỏi quá dài (tối đa 5000 ký tự).');
    }
    $options = [];
    $answer = [];
    if ($type === 'short') {
        $raw = $q['answer'] ?? [];
        if (is_string($raw)) {
            $raw = explode('|', $raw);
        }
        foreach ((array) $raw as $a) {
            $a = trim((string) $a);
            if ($a !== '') {
                $answer[] = mb_substr($a, 0, 200);
            }
        }
        if (!$answer) {
            fail('Câu trả lời ngắn cần ít nhất một đáp án chấp nhận.');
        }
    } else {
        $keys = 'ABCDEFGH';
        $i = 0;
        foreach ((array) ($q['options'] ?? []) as $opt) {
            $text = trim((string) (is_array($opt) ? ($opt['text'] ?? '') : $opt));
            if ($text === '') {
                continue;
            }
            if ($i >= 8) {
                fail('Tối đa 8 phương án.');
            }
            $options[] = ['key' => $keys[$i], 'text' => mb_substr($text, 0, 1000), '_old' => is_array($opt) ? ($opt['key'] ?? null) : null];
            $i++;
        }
        if (count($options) < 2) {
            fail('Cần ít nhất 2 phương án.');
        }
        // đáp án tham chiếu theo khoá CŨ (trước khi bỏ dòng trống) → ánh xạ sang khoá mới
        $raw = $q['answer'] ?? [];
        if (is_string($raw)) {
            $raw = preg_split('/[\s,;]+/', strtoupper($raw)) ?: [];
        }
        $map = [];
        foreach ($options as $o) {
            $map[strtoupper((string) ($o['_old'] ?? $o['key']))] = $o['key'];
        }
        foreach ((array) $raw as $a) {
            $a = strtoupper(trim((string) $a));
            if ($a !== '' && isset($map[$a]) && !in_array($map[$a], $answer, true)) {
                $answer[] = $map[$a];
            }
        }
        sort($answer);
        if (!$answer) {
            fail('Chưa chọn đáp án đúng (hoặc đáp án không khớp phương án nào).');
        }
        if ($type === 'single' && count($answer) !== 1) {
            fail('Câu một đáp án phải có đúng 1 đáp án đúng (đổi loại sang "nhiều đáp án" nếu cần).');
        }
        $options = array_map(fn($o) => ['key' => $o['key'], 'text' => $o['text']], $options);
    }
    $cloId = null;
    $cloRaw = trim((string) ($q['clo'] ?? ''));
    if ($cloRaw !== '') {
        $cloId = $closByCodeOrId[strtoupper($cloRaw)] ?? $closByCodeOrId[$cloRaw] ?? null;
        if (!$cloId) {
            fail('CLO "' . $cloRaw . '" chưa được khai báo cho học phần.');
        }
    }
    $bloom = parse_bloom($q['bloom'] ?? null);
    $diff = parse_difficulty($q['difficulty'] ?? 2);
    $points = (float) ($q['points'] ?? 1);
    if ($points <= 0 || $points > 100) {
        $points = 1;
    }
    return [
        'Type' => $type, 'Stem' => $stem, 'OptionsJSON' => $type === 'short' ? null : jenc($options), 'AnswerJSON' => jenc($answer),
        'Explanation' => mb_substr(trim((string) ($q['explanation'] ?? '')), 0, 5000), 'CloID' => $cloId, 'Bloom' => $bloom,
        'Difficulty' => $diff, 'Points' => $points, 'Topic' => mb_substr(trim((string) ($q['topic'] ?? '')), 0, 120),
        'ImageID' => preg_match('/^IMG_[0-9a-f]{12}$/', (string) ($q['imageId'] ?? '')) ? $q['imageId'] : null,
    ];
}

function parse_bloom($v): ?int
{
    if ($v === null || $v === '') {
        return null;
    }
    if (is_numeric($v)) {
        $n = (int) $v;
        return $n >= 1 && $n <= 6 ? $n : null;
    }
    $s = mb_strtolower(trim((string) $v));
    foreach (BLOOM_NAMES as $n => $name) {
        if (mb_strtolower($name) === $s) {
            return $n;
        }
    }
    $alias = ['remember' => 1, 'understand' => 2, 'apply' => 3, 'analyze' => 4, 'analyse' => 4, 'evaluate' => 5, 'create' => 6, 'biết' => 1, 'nhận biết' => 1, 'thông hiểu' => 2, 'vận dụng cao' => 4];
    return $alias[$s] ?? null;
}

function parse_difficulty($v): int
{
    if (is_numeric($v)) {
        return max(1, min(3, (int) $v));
    }
    $s = mb_strtolower(trim((string) $v));
    if (in_array($s, ['dễ', 'de', 'easy'], true)) {
        return 1;
    }
    if (in_array($s, ['khó', 'kho', 'hard'], true)) {
        return 3;
    }
    return 2;
}

function clo_lookup(string $courseId): array
{
    $m = [];
    foreach (clos_of($courseId) as $c) {
        $m[strtoupper($c['Code'])] = $c['CloID'];
        $m[$c['CloID']] = $c['CloID'];
    }
    return $m;
}

function action_saveQuestion(array $p): void
{
    $me = require_teacher($p);
    $courseId = str_param($p, 'courseId', 40);
    assert_course($me, $courseId);
    $data = normalize_question((array) ($p['question'] ?? []), clo_lookup($courseId));
    $id = str_param($p, 'questionId', 40);
    if ($id) {
        $old = q_one("SELECT QuestionID FROM kt_questions WHERE QuestionID = :id AND CourseID = :c AND Status = 'ACTIVE'", ['id' => $id, 'c' => $courseId]);
        if (!$old) {
            fail('Không tìm thấy câu hỏi.');
        }
        $set = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($data)));
        q("UPDATE kt_questions SET $set, UpdatedAt = :u WHERE QuestionID = :id", $data + ['u' => now(), 'id' => $id]);
    } else {
        $id = new_id('QST');
        insert_question($id, $courseId, $me['userId'], $data);
    }
    api_ok(['questionId' => $id]);
}

function insert_question(string $id, string $courseId, string $by, array $data): void
{
    $cols = array_keys($data);
    $n = now();
    q('INSERT INTO kt_questions (QuestionID, CourseID, CreatedBy, CreatedAt, UpdatedAt, ' . implode(', ', $cols) . ') VALUES (:id, :c, :by, :n1, :n2, :' . implode(', :', $cols) . ')',
        $data + ['id' => $id, 'c' => $courseId, 'by' => $by, 'n1' => $n, 'n2' => $n]);
}

function action_deleteQuestion(array $p): void
{
    $me = require_teacher($p);
    $q = q_one('SELECT CourseID FROM kt_questions WHERE QuestionID = :id', ['id' => str_param($p, 'questionId', 40)]);
    if (!$q) {
        fail('Không tìm thấy câu hỏi.');
    }
    assert_course($me, (string) $q['CourseID']);
    // xoá mềm: đề đã thi vẫn giữ bản đóng băng (SnapshotJSON)
    q("UPDATE kt_questions SET Status = 'INACTIVE' WHERE QuestionID = :id", ['id' => str_param($p, 'questionId', 40)]);
    api_ok(['ok' => true]);
}

/**
 * Nhập hàng loạt: client đã tách bảng (dán từ Excel / CSV) thành mảng đối tượng
 * {type, stem, options[], answer, explanation, clo, bloom, difficulty, points, topic}.
 * dryRun = true chỉ kiểm tra, trả lỗi từng dòng.
 */
function action_importQuestions(array $p): void
{
    $me = require_teacher($p);
    $courseId = str_param($p, 'courseId', 40);
    assert_course($me, $courseId);
    $rows = (array) ($p['rows'] ?? []);
    if (count($rows) > 500) {
        fail('Tối đa 500 câu mỗi lần nhập.');
    }
    $lookup = clo_lookup($courseId);
    $okRows = [];
    $errors = [];
    foreach ($rows as $i => $r) {
        try {
            $okRows[] = normalize_question((array) $r, $lookup);
        } catch (UserError $e) {
            $errors[] = ['row' => $i + 1, 'message' => $e->getMessage()];
        }
    }
    if (!empty($p['dryRun']) || $errors) {
        api_ok(['valid' => count($okRows), 'errors' => $errors, 'imported' => 0]);
        return;
    }
    tx(function () use ($okRows, $courseId, $me) {
        foreach ($okRows as $d) {
            insert_question(new_id('QST'), $courseId, $me['userId'], $d);
        }
    });
    api_ok(['valid' => count($okRows), 'errors' => [], 'imported' => count($okRows)]);
}

/* ------------------------------ Ảnh -------------------------------- */

function action_uploadImage(array $p): void
{
    $me = require_teacher($p);
    $data = (string) ($p['dataUrl'] ?? '');
    if (!preg_match('#^data:(image/(jpeg|png|webp|gif));base64,(.+)$#s', $data, $m)) {
        fail('Ảnh không hợp lệ (chỉ nhận JPG/PNG/WebP/GIF).');
    }
    $bin = base64_decode($m[3], true);
    if ($bin === false || strlen($bin) > 3 * 1024 * 1024) {
        fail('Ảnh quá lớn (tối đa 3 MB sau khi nén).');
    }
    if (@getimagesizefromstring($bin) === false) {
        fail('Tệp không phải ảnh.');
    }
    $ext = ['jpeg' => 'jpg', 'png' => 'png', 'webp' => 'webp', 'gif' => 'gif'][$m[2]];
    $id = new_id('IMG');
    $file = $id . '.' . $ext;
    if (file_put_contents(kt_upload_dir() . '/' . $file, $bin) === false) {
        fail('Không lưu được ảnh trên máy chủ.');
    }
    q('INSERT INTO kt_images (ImageID, FileName, Mime, Bytes, CreatedBy, CreatedAt) VALUES (:id, :f, :m, :b, :by, :n)',
        ['id' => $id, 'f' => $file, 'm' => $m[1], 'b' => strlen($bin), 'by' => $me['userId'], 'n' => now()]);
    api_ok(['imageId' => $id]);
}

/** GET ?action=img&id=IMG_xxx — trả bytes ảnh (ID ngẫu nhiên 48 bit, không đoán được). */
function serve_image(string $id): void
{
    if (!preg_match('/^IMG_[0-9a-f]{12}$/', $id)) {
        http_response_code(404);
        return;
    }
    $row = q_one('SELECT FileName, Mime FROM kt_images WHERE ImageID = :id', ['id' => $id]);
    $path = $row ? kt_upload_dir() . '/' . basename((string) $row['FileName']) : '';
    if (!$row || !is_file($path)) {
        http_response_code(404);
        return;
    }
    header('Content-Type: ' . $row['Mime']);
    header('Cache-Control: private, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
}
