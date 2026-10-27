<?php
declare(strict_types=1);

/**
 * api/lib/liveroom.php — Phòng tương tác trực tiếp cho trang trình chiếu bài giảng
 * (dkv/trinhchieu.html + dkv/deck-engine.js ↔ dkv/sv.html). Đối chiếu docs/04-API-PHP.md mục 36,
 * bảng ở db/migrations/010-live-rooms.sql.
 *
 * Vì sao: trước đây sv.html chỉ phát qua BroadcastChannel/localStorage nên câu trả lời
 * chỉ đi giữa các tab trên CÙNG một trình duyệt — điện thoại sinh viên không bao giờ tới
 * máy chiếu. Nay đi qua máy chủ; BroadcastChannel giữ làm dự phòng khi chạy file://.
 *
 * Luồng:
 *   1. roomOpen (POST, trang trình chiếu)  → sinh mã phòng 5 ký tự KHÔNG trùng phòng đang mở,
 *      gắn bài giảng (lessonCode), hết hạn sau app.live_room_hours giờ (mặc định 4). Trả kèm
 *      hostKey — khoá bí mật chỉ trang trình chiếu giữ (CSDL chỉ lưu SHA-256). Tuỳ chọn
 *      attendanceCode = mã điểm danh 4 ký tự đang OPEN → phòng gắn lớp/buổi đó.
 *   2. roomInfo (GET, sinh viên) → mã phòng còn mở không, bài giảng nào, lớp nào.
 *   3. roomSubmit (POST, sinh viên) → ghi câu trả lời kèm MSSV + họ tên.
 *   4. roomPoll (GET, trang trình chiếu, 3–5 giây/lần) → code + hostKey + since: chỉ trả bản
 *      ghi của ĐÚNG phòng đó có ResponseID > since. Hai lớp song song không bao giờ lẫn vì
 *      mọi truy vấn lọc theo RoomID tra từ (Code, hostKey).
 *   5. roomSetLesson / roomClose (POST, trang trình chiếu, cần hostKey).
 *
 * Không cần đăng nhập: trang trình chiếu mở ở máy chiếu của phòng học, thường không có
 * phiên giảng viên. Bù lại: hostKey 128 bit mới đọc được dữ liệu, mở phòng bị giới hạn theo
 * IP, nhập mã phòng/khoá sai bị đếm như checkin (chặn dò mã).
 */

/** Độ dài mã phòng — khác mã điểm danh (4) để sinh viên không nhập lẫn. */
const LIVE_CODE_LENGTH = 5;
/** Số ngày giữ phòng + câu trả lời trước khi tự xoá. */
const LIVE_KEEP_DAYS = 14;
/** Số bản ghi tối đa mỗi lượt roomPoll (trang trình chiếu tự hỏi tiếp nếu đủ trang). */
const LIVE_POLL_LIMIT = 300;
/** Loại câu trả lời nhận được — khớp ENUM Kind. */
const LIVE_KINDS = ['quiz', 'group', 'qa'];

/** Bảng chữ của mã phòng: dùng chung code_alphabet với mã điểm danh (bỏ ký tự dễ nhầm). */
function live_alphabet(): string
{
    $a = (string) (app_config()['app']['code_alphabet'] ?? '');
    return preg_match('/^[A-Z0-9]{10,}$/', $a) ? $a : 'ACDEFGHJKMNPQRTUVWXY34679';
}

function live_norm_code($raw): string
{
    return strtoupper(preg_replace('/\s+/', '', (string) $raw) ?? '');
}

function live_norm_lesson($raw): string
{
    $l = trim((string) $raw);
    if (!preg_match('/^[0-9A-Za-z][0-9A-Za-z._-]{0,19}$/', $l)) {
        throw new RuntimeException('Mã bài giảng không hợp lệ.');
    }
    return $l;
}

/** Cắt chuỗi người dùng nhập: bỏ ký tự điều khiển, gộp khoảng trắng nếu $oneLine. */
function live_clean_text($raw, int $max, bool $oneLine = true): string
{
    $s = (string) $raw;
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '';
    if ($oneLine) {
        $s = preg_replace('/\s+/u', ' ', $s) ?? '';
    }
    $s = trim($s);
    return mb_substr($s, 0, $max);
}

/** Dạng trả về công khai của một phòng (không có hostKey/IP). */
function live_room_public(array $room): array
{
    return [
        'code'       => (string) $room['Code'],
        'lessonCode' => (string) $room['LessonCode'],
        'label'      => (string) ($room['Label'] ?? ''),
        'linked'     => !empty($room['ClassID']),
        'status'     => (string) $room['Status'],
        'expiresAt'  => db_stamp_to_iso((string) $room['ExpiresAt']),
    ];
}

/**
 * Tìm phòng ĐANG MỞ theo mã. Phòng quá hạn được đóng luôn tại đây (không cần cron).
 * @return array|null
 */
function live_find_open_room(string $code): ?array
{
    if (!preg_match('/^[A-Z0-9]{' . LIVE_CODE_LENGTH . '}$/', $code)) {
        return null;
    }
    $stmt = db()->prepare("SELECT * FROM live_rooms WHERE Code = :c AND Status = 'OPEN' ORDER BY CreatedAt DESC LIMIT 1");
    $stmt->execute(['c' => $code]);
    $room = $stmt->fetch();
    if (!$room) {
        return null;
    }
    if (db_now() > (string) $room['ExpiresAt']) {
        db()->prepare("UPDATE live_rooms SET Status = 'CLOSED', ClosedAt = NOW() WHERE RoomID = :id")
            ->execute(['id' => $room['RoomID']]);
        return null;
    }
    return $room;
}

/**
 * Phòng đang mở + đúng hostKey (so sánh hằng thời gian). Sai → đếm vào live_code_ip và
 * báo lỗi chung (không lộ là sai mã hay sai khoá).
 */
function live_require_host(array $params): array
{
    $ip = rate_limit_client_ip();
    rate_limit_guard('live_code_ip', $ip);
    $code = live_norm_code($params['code'] ?? '');
    $key  = trim((string) ($params['hostKey'] ?? ''));
    $room = live_find_open_room($code);
    if (!$room || !preg_match('/^[0-9a-f]{32}$/', $key) || !hash_equals((string) $room['HostKeyHash'], hash('sha256', $key))) {
        rate_limit_record('live_code_ip', $ip);
        throw new RuntimeException('Phòng tương tác đã đóng hoặc hết hạn. Hãy mở phòng mới.');
    }
    return $room;
}

/** roomOpen — POST {lessonCode, attendanceCode?} → {code, hostKey, lessonCode, label, linked, expiresAt}. */
function action_room_open(array $params): void
{
    $ip = rate_limit_client_ip();
    rate_limit_guard('live_open_ip', $ip);
    rate_limit_record('live_open_ip', $ip);

    $lesson = live_norm_lesson($params['lessonCode'] ?? '');
    $appCfg = app_config()['app'] ?? [];
    $hours  = (int) ($appCfg['live_room_hours'] ?? 4);
    $hours  = max(1, min(12, $hours));

    // Tuỳ chọn: gắn lớp qua mã điểm danh đang mở (người mở phòng phải đang có mã của lớp).
    $classId = null;
    $sessionId = null;
    $label = live_clean_text($params['label'] ?? '', 120);
    $attCode = live_norm_code($params['attendanceCode'] ?? '');
    if ($attCode !== '') {
        $stmt = db()->prepare(
            "SELECT k.SessionID, s.ClassID, s.SessionNo, c.ClassCode, co.CourseName
               FROM attendance_keys k
               JOIN sessions s ON s.SessionID = k.SessionID
               JOIN classes c ON c.ClassID = s.ClassID
               LEFT JOIN courses co ON co.CourseID = c.CourseID
              WHERE k.Code = :c AND k.Status = 'OPEN' AND k.EndTime >= NOW()
              LIMIT 1"
        );
        $stmt->execute(['c' => $attCode]);
        $row = $stmt->fetch();
        if (!$row) {
            rate_limit_record('live_code_ip', $ip);
            api_fail('Mã điểm danh không đúng hoặc đã đóng. Bỏ trống ô này nếu không cần gắn lớp.');
            return;
        }
        $classId = (string) $row['ClassID'];
        $sessionId = (string) $row['SessionID'];
        $label = live_clean_text('Lớp ' . $row['ClassCode'] . ' · buổi ' . $row['SessionNo'], 120);
    }

    $hostKey = bin2hex(random_bytes(16));
    $roomId  = new_id('LRM');
    $pdo = db();

    // Khoá tên: hai giảng viên bấm "Mở phòng" cùng lúc vẫn không thể nhận cùng một mã.
    $got = (int) $pdo->query("SELECT GET_LOCK('live_room_open', 5)")->fetchColumn();
    if ($got !== 1) {
        api_fail('Máy chủ đang bận, vui lòng bấm lại.');
        return;
    }
    try {
        // Đóng phòng quá hạn để mã của chúng được dùng lại.
        $pdo->exec("UPDATE live_rooms SET Status = 'CLOSED', ClosedAt = NOW() WHERE Status = 'OPEN' AND ExpiresAt < NOW()");
        $taken = [];
        foreach ($pdo->query("SELECT Code FROM live_rooms WHERE Status = 'OPEN'")->fetchAll() as $r) {
            $taken[(string) $r['Code']] = true;
        }
        $alphabet = live_alphabet();
        $max = strlen($alphabet) - 1;
        $code = '';
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $code = '';
            for ($i = 0; $i < LIVE_CODE_LENGTH; $i++) {
                $code .= $alphabet[random_int(0, $max)];
            }
            if (!isset($taken[$code])) {
                break;
            }
            $code = '';
        }
        if ($code === '') {
            throw new RuntimeException('Không sinh được mã phòng mới, vui lòng bấm lại.');
        }
        $stmt = $pdo->prepare(
            'INSERT INTO live_rooms (RoomID, Code, HostKeyHash, LessonCode, ClassID, SessionID, Label, Status, CreatedIp, ExpiresAt) ' .
            "VALUES (:id, :code, :hk, :lesson, :cls, :ses, :label, 'OPEN', :ip, NOW() + INTERVAL :h HOUR)"
        );
        $stmt->bindValue('id', $roomId);
        $stmt->bindValue('code', $code);
        $stmt->bindValue('hk', hash('sha256', $hostKey));
        $stmt->bindValue('lesson', $lesson);
        $stmt->bindValue('cls', $classId);
        $stmt->bindValue('ses', $sessionId);
        $stmt->bindValue('label', $label !== '' ? $label : null);
        $stmt->bindValue('ip', $ip);
        $stmt->bindValue('h', $hours, PDO::PARAM_INT);
        $stmt->execute();
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('live_room_open')");
    }

    // Dọn phòng cũ (≈ 1/20 lượt mở) — câu trả lời đi theo nhờ ON DELETE CASCADE.
    if (random_int(1, 20) === 1) {
        $pdo->exec('DELETE FROM live_rooms WHERE CreatedAt < NOW() - INTERVAL ' . LIVE_KEEP_DAYS . ' DAY');
    }

    $stmt = $pdo->prepare('SELECT * FROM live_rooms WHERE RoomID = :id');
    $stmt->execute(['id' => $roomId]);
    $room = $stmt->fetch();
    api_ok(live_room_public($room) + ['hostKey' => $hostKey]);
}

/** roomInfo — GET {code} → thông tin công khai của phòng đang mở. */
function action_room_info(array $params): void
{
    $ip = rate_limit_client_ip();
    rate_limit_guard('live_code_ip', $ip);
    $room = live_find_open_room(live_norm_code($params['code'] ?? ''));
    if (!$room) {
        rate_limit_record('live_code_ip', $ip);
        api_fail('Mã phòng không đúng hoặc phòng đã đóng. Hỏi lại giảng viên mã đang chiếu trên màn hình.');
        return;
    }
    api_ok(live_room_public($room));
}

/**
 * roomSubmit — POST {code, kind, lessonCode, mssv, name, qIdx?, choice?, group?, text?}.
 * Quiz: mỗi MSSV một đáp án / câu / phòng (đáp án đầu tiên được giữ).
 */
function action_room_submit(array $params): void
{
    $ip = rate_limit_client_ip();
    rate_limit_guard('live_submit_ip', $ip);
    rate_limit_guard('live_code_ip', $ip);

    $room = live_find_open_room(live_norm_code($params['code'] ?? ''));
    if (!$room) {
        rate_limit_record('live_code_ip', $ip);
        api_fail('Mã phòng không đúng hoặc phòng đã đóng. Hỏi lại giảng viên mã đang chiếu trên màn hình.');
        return;
    }

    $mssv = strtoupper(live_clean_text($params['mssv'] ?? '', 15));
    if (!preg_match('/^[0-9A-Z]{5,15}$/', $mssv)) {
        api_fail('MSSV không hợp lệ (5–15 chữ số/chữ cái, không dấu cách).');
        return;
    }
    $name = live_clean_text($params['name'] ?? '', 80);
    if (mb_strlen($name) < 2) {
        api_fail('Vui lòng nhập họ tên.');
        return;
    }

    // Mỗi sinh viên tối đa N lượt / cửa sổ trong một phòng — một máy không thể dội cả lớp.
    $studentKey = rate_limit_key($room['Code'] . '|' . $mssv);
    rate_limit_guard('live_submit_student', $studentKey);

    $kind = (string) ($params['kind'] ?? '');
    if (!in_array($kind, LIVE_KINDS, true)) {
        api_fail('Loại câu trả lời không hợp lệ.');
        return;
    }
    $lesson = isset($params['lessonCode']) && $params['lessonCode'] !== ''
        ? live_norm_lesson($params['lessonCode'])
        : (string) $room['LessonCode'];

    $qIdx = null;
    $choice = null;
    $group = null;
    $body = null;
    if ($kind === 'quiz') {
        $qIdx = filter_var($params['qIdx'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 199]]);
        $choice = strtoupper(trim((string) ($params['choice'] ?? '')));
        if ($qIdx === false || $qIdx === null || !preg_match('/^[A-F]$/', $choice)) {
            api_fail('Câu trả lời trắc nghiệm không hợp lệ.');
            return;
        }
    } elseif ($kind === 'group') {
        $group = live_clean_text($params['group'] ?? '', 80);
        $body = live_clean_text($params['text'] ?? '', 1500, false);
        if ($group === '') {
            $group = 'Nhóm của ' . $name;
        }
        if (mb_strlen($body) < 2) {
            api_fail('Vui lòng nhập kết quả thảo luận của nhóm.');
            return;
        }
    } else {
        $body = live_clean_text($params['text'] ?? '', 600, false);
        if (mb_strlen($body) < 2) {
            api_fail('Vui lòng nhập câu hỏi.');
            return;
        }
    }

    // Phòng gắn lớp → đánh dấu MSSV có trong danh sách lớp hay không (không chặn: giảng viên tự xem).
    $inRoster = null;
    if (!empty($room['ClassID'])) {
        $stmt = db()->prepare(
            "SELECT 1 FROM students s JOIN enrollments e ON e.StudentID = s.StudentID
              WHERE s.MSSV = :m AND e.ClassID = :c AND e.Status = 'ACTIVE' LIMIT 1"
        );
        $stmt->execute(['m' => $mssv, 'c' => $room['ClassID']]);
        $inRoster = $stmt->fetchColumn() ? 1 : 0;
    }

    rate_limit_record('live_submit_ip', $ip);
    rate_limit_record('live_submit_student', $studentKey);

    $stmt = db()->prepare(
        'INSERT INTO live_responses (RoomID, Kind, LessonCode, MSSV, StudentName, InRoster, QIndex, Choice, GroupName, Body, ClientIp) ' .
        'VALUES (:room, :kind, :lesson, :mssv, :name, :inr, :q, :ch, :grp, :body, :ip)'
    );
    $stmt->bindValue('room', $room['RoomID']);
    $stmt->bindValue('kind', $kind);
    $stmt->bindValue('lesson', $lesson);
    $stmt->bindValue('mssv', $mssv);
    $stmt->bindValue('name', $name);
    $stmt->bindValue('inr', $inRoster, $inRoster === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $stmt->bindValue('q', $qIdx, $qIdx === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $stmt->bindValue('ch', $choice);
    $stmt->bindValue('grp', $group);
    $stmt->bindValue('body', $body);
    $stmt->bindValue('ip', $ip);
    try {
        $stmt->execute();
    } catch (PDOException $e) {
        if ($e->getCode() === '23000' && $kind === 'quiz') {
            api_ok(['duplicate' => true, 'message' => 'Bạn đã trả lời câu này rồi — đáp án đầu tiên được giữ.']);
            return;
        }
        throw $e;
    }
    api_ok(['duplicate' => false, 'id' => (int) db()->lastInsertId()]);
}

/** roomPoll — GET {code, hostKey, since} → {room, items[], last, more}. Chỉ dữ liệu của đúng phòng này. */
function action_room_poll(array $params): void
{
    $ip = rate_limit_client_ip();
    rate_limit_guard('live_poll_ip', $ip);
    rate_limit_record('live_poll_ip', $ip);
    $room = live_require_host($params);

    $since = filter_var($params['since'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    if ($since === false) {
        $since = 0;
    }
    $stmt = db()->prepare(
        'SELECT ResponseID, Kind, LessonCode, MSSV, StudentName, InRoster, QIndex, Choice, GroupName, Body, CreatedAt ' .
        'FROM live_responses WHERE RoomID = :room AND ResponseID > :since ORDER BY ResponseID ASC LIMIT ' . LIVE_POLL_LIMIT
    );
    $stmt->bindValue('room', $room['RoomID']);
    $stmt->bindValue('since', $since, PDO::PARAM_INT);
    $stmt->execute();
    $items = [];
    $last = $since;
    foreach ($stmt->fetchAll() as $r) {
        $id = (int) $r['ResponseID'];
        $last = max($last, $id);
        $items[] = [
            'id'       => $id,
            'kind'     => (string) $r['Kind'],
            'lesson'   => (string) $r['LessonCode'],
            'mssv'     => (string) $r['MSSV'],
            'name'     => (string) $r['StudentName'],
            'inRoster' => $r['InRoster'] === null ? null : (bool) $r['InRoster'],
            'qIdx'     => $r['QIndex'] === null ? null : (int) $r['QIndex'],
            'choice'   => (string) ($r['Choice'] ?? ''),
            'group'    => (string) ($r['GroupName'] ?? ''),
            'text'     => (string) ($r['Body'] ?? ''),
            'time'     => db_stamp_to_iso((string) $r['CreatedAt']),
        ];
    }
    api_ok([
        'room'  => live_room_public($room),
        'items' => $items,
        'last'  => $last,
        'more'  => count($items) >= LIVE_POLL_LIMIT,
    ]);
}

/** roomSetLesson — POST {code, hostKey, lessonCode}: giảng viên đổi bài trên trang trình chiếu. */
function action_room_set_lesson(array $params): void
{
    $room = live_require_host($params);
    $lesson = live_norm_lesson($params['lessonCode'] ?? '');
    db()->prepare('UPDATE live_rooms SET LessonCode = :l WHERE RoomID = :id')
        ->execute(['l' => $lesson, 'id' => $room['RoomID']]);
    $room['LessonCode'] = $lesson;
    api_ok(live_room_public($room));
}

/** roomClose — POST {code, hostKey}: đóng phòng; sinh viên không gửi thêm được. */
function action_room_close(array $params): void
{
    $room = live_require_host($params);
    db()->prepare("UPDATE live_rooms SET Status = 'CLOSED', ClosedAt = NOW() WHERE RoomID = :id")
        ->execute(['id' => $room['RoomID']]);
    api_ok(['closed' => true]);
}
