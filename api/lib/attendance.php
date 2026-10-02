<?php
declare(strict_types=1);

/**
 * api/lib/attendance.php — openAttendance/closeAttendance/checkin/liveRoster
 * (gas/04-AttendanceService.gs → AttendanceService). Đối chiếu đầy đủ tại
 * docs/04-API-PHP.md mục 5, mục 13. Port đủ SÁU lớp bù D.8 — mỗi lớp đánh
 * dấu [D.8-n] trong code, KHÔNG cắt lớp nào (docblock gốc gas nhấn mạnh điều
 * này — xác minh Mức 1 yếu, sáu lớp này mới tạo ra kiểm chứng).
 */

/**
 * openAttendance — POST. role LECTURER/ADMIN + assertClassAccess
 * (gas/04-AttendanceService.gs dòng 18-63).
 */
function action_open_attendance(array $params): void
{
    $me = require_role((string) ($params['token'] ?? ''), ['LECTURER', 'ADMIN']);
    $sessionId = trim((string) ($params['sessionId'] ?? ''));

    $stmt = db()->prepare('SELECT ClassID FROM sessions WHERE SessionID = :id LIMIT 1');
    $stmt->execute(['id' => $sessionId]);
    $session = $stmt->fetch();
    if (!$session) {
        api_fail('Không tìm thấy buổi học ' . $sessionId . '.');
        return;
    }

    // [D.9] Chỉ giảng viên đứng tên lớp (hoặc ADMIN) mới mở được điểm danh.
    assert_class_access($me, (string) $session['ClassID']);

    $appCfg = app_config()['app'] ?? [];

    $presentMin = (int) ($params['presentMinutes'] ?? 0);
    if ($presentMin <= 0) {
        $presentMin = (int) ($appCfg['present_minutes'] ?? 5);
    }
    $windowMin = (int) ($params['windowMinutes'] ?? 0);
    if ($windowMin <= 0) {
        $windowMin = (int) ($appCfg['window_minutes'] ?? 15);
    }

    // M5 (docs/05-GD5-smoke-review.md; docs/04 mục 19): kẹp 1–60 phút
    // (app.max_window_minutes). Trước đây giảng viên gửi windowMinutes=99999 là
    // mã sống gần như vô hạn — trái với D.8 lớp 1 "mã mới mỗi buổi, hạn giờ
    // ngắn" và làm vô hiệu giới hạn tần suất M4 (kẻ dò có cả ngày để thử).
    // Mốc "trễ" không được muộn hơn mốc "hết hạn".
    $maxMin = (int) ($appCfg['max_window_minutes'] ?? 60);
    if ($maxMin < 1) {
        $maxMin = 60;
    }
    $windowMin  = max(1, min($maxMin, $windowMin));
    $presentMin = max(1, min($windowMin, $presentMin));

    $alphabet = (string) ($appCfg['code_alphabet'] ?? 'ACDEFGHJKMNPQRTUVWXY34679');
    $length   = (int) ($appCfg['code_length'] ?? 4);

    // [D.8-1] Mã sinh mới mỗi buổi, ngẫu nhiên, hạn giờ ngắn. Mã rò rỉ qua
    // Zalo hết giá trị sau windowMin phút.
    $now = new DateTimeImmutable(db_now());
    $startTime = $now->format('Y-m-d H:i:s');
    $lateAfter = $now->modify('+' . $presentMin . ' minutes')->format('Y-m-d H:i:s');
    $endTime   = $now->modify('+' . $windowMin . ' minutes')->format('Y-m-d H:i:s');
    $code = null;

    db_transaction(function (PDO $pdo) use ($sessionId, $me, $startTime, $lateAfter, $endTime, $alphabet, $length, &$code): void {
        // Đóng mọi mã còn mở của buổi này — một buổi chỉ có một mã sống.
        $pdo->prepare("UPDATE attendance_keys SET Status = 'CLOSED' WHERE SessionID = :sid AND Status = 'OPEN'")
            ->execute(['sid' => $sessionId]);

        $code = generate_unique_code($pdo, $alphabet, $length);

        $pdo->prepare(
            'INSERT INTO attendance_keys (KeyID, SessionID, Code, StartTime, LateAfter, EndTime, Status, CreatedBy) ' .
            "VALUES (:id, :sid, :code, :start, :late, :end, 'OPEN', :by)"
        )->execute([
            'id'    => new_id('KEY'),
            'sid'   => $sessionId,
            'code'  => $code,
            'start' => $startTime,
            'late'  => $lateAfter,
            'end'   => $endTime,
            'by'    => $me['userId'],
        ]);
    });

    // [D.8-6]
    log_audit($me['userId'], $me['role'], 'ATTENDANCE_OPEN', 'SESSION', $sessionId, [
        'code' => $code, 'endTime' => $endTime,
    ]);

    api_ok([
        'code'      => $code,
        'sessionId' => $sessionId,
        'startTime' => db_stamp_to_iso($startTime),
        'lateAfter' => db_stamp_to_iso($lateAfter),
        'endTime'   => db_stamp_to_iso($endTime),
    ]);
}

/**
 * closeAttendance — POST. Đóng mã và đánh dấu ABSENT cho SV ghi danh mà
 * không check-in (gas/04-AttendanceService.gs dòng 72-114). Không có bước
 * này thì "vắng" chỉ là sự thiếu vắng của một dòng — không xuất báo cáo
 * được, và không phân xử được khi sinh viên khiếu nại.
 */
function action_close_attendance(array $params): void
{
    $me = require_role((string) ($params['token'] ?? ''), ['LECTURER', 'ADMIN']);
    $sessionId = trim((string) ($params['sessionId'] ?? ''));

    $stmt = db()->prepare('SELECT ClassID FROM sessions WHERE SessionID = :id LIMIT 1');
    $stmt->execute(['id' => $sessionId]);
    $session = $stmt->fetch();
    if (!$session) {
        api_fail('Không tìm thấy buổi học ' . $sessionId . '.');
        return;
    }

    // [D.9] Chỉ giảng viên đứng tên lớp (hoặc ADMIN) mới đóng được điểm danh.
    assert_class_access($me, (string) $session['ClassID']);

    $classId = (string) $session['ClassID'];

    $absent = db_transaction(function (PDO $pdo) use ($sessionId, $classId): int {
        $pdo->prepare("UPDATE attendance_keys SET Status = 'CLOSED' WHERE SessionID = :sid AND Status = 'OPEN'")
            ->execute(['sid' => $sessionId]);

        $stmt = $pdo->prepare('SELECT StudentID FROM attendance WHERE SessionID = :sid');
        $stmt->execute(['sid' => $sessionId]);
        $checkedIn = [];
        foreach ($stmt->fetchAll() as $r) {
            $checkedIn[trim((string) $r['StudentID'])] = true;
        }

        $stmt = $pdo->prepare("SELECT StudentID FROM enrollments WHERE ClassID = :cid AND Status = 'ACTIVE'");
        $stmt->execute(['cid' => $classId]);
        $enrolled = $stmt->fetchAll();

        $insert = $pdo->prepare(
            'INSERT INTO attendance (AttendanceID, StudentID, SessionID, Status, CheckInTime, GpsFlag, Note) ' .
            "VALUES (:id, :student, :session, 'ABSENT', NULL, 'NO_GPS', 'Tự đánh dấu khi đóng điểm danh')"
        );

        $count = 0;
        foreach ($enrolled as $en) {
            $sid = trim((string) $en['StudentID']);
            if (isset($checkedIn[$sid])) {
                continue;
            }
            $insert->execute(['id' => new_id('ATT'), 'student' => $sid, 'session' => $sessionId]);
            $count++;
        }
        return $count;
    });

    log_audit($me['userId'], $me['role'], 'ATTENDANCE_CLOSE', 'SESSION', $sessionId, ['markedAbsent' => $absent]);

    api_ok(['sessionId' => $sessionId, 'markedAbsent' => $absent]);
}

/**
 * checkin — POST. KHÔNG cần token (xác minh Mức 1 — MSSV + mã 4 ký tự).
 * Trả kết quả THẬT (gas/04-AttendanceService.gs dòng 123-208) — đây là chỗ
 * trị nợ kỹ thuật số 2 của bản cũ: frontend đọc phản hồi này qua
 * js/services/APIService.js (KHÔNG dùng mode:'no-cors').
 */
function action_checkin(array $params): void
{
    $appCfg = app_config()['app'] ?? [];
    $codeLength = (int) ($appCfg['code_length'] ?? 4);
    $code = strtoupper(trim((string) ($params['code'] ?? '')));

    // M4 (api/lib/ratelimit.php, docs/04 mục 19): chặn dò mã 4 ký tự bằng máy
    // (25^4 ≈ 390 000 khả năng). Chỉ đếm lần mã SAI/không mở — cả lớp điểm
    // danh ĐÚNG trên WiFi chung (một IP NAT) không bị tính, nên không chặn
    // oan buổi học thật. Khoá theo IP máy chủ thấy, không nhận IP tự khai (M3).
    $ip = rate_limit_client_ip();
    rate_limit_guard('checkin_ip', $ip);

    if (!preg_match('/^[A-Z0-9]{' . $codeLength . '}$/', $code)) {
        rate_limit_record('checkin_ip', $ip);
        api_fail('Mã điểm danh phải gồm ' . $codeLength . ' ký tự chữ và số.');
        return;
    }

    $stmt = db()->prepare("SELECT * FROM attendance_keys WHERE Code = :code AND Status = 'OPEN' LIMIT 1");
    $stmt->execute(['code' => $code]);
    $key = $stmt->fetch();
    if (!$key) {
        rate_limit_record('checkin_ip', $ip);
        api_fail('Mã không đúng hoặc buổi điểm danh đã đóng.');
        return;
    }

    // [D.8-1] Cửa sổ thời gian (D.2). So sánh CHUỖI 'Y-m-d H:i:s' — độ rộng
    // cố định nên so sánh chuỗi cũng ra đúng thứ tự thời gian, giữ đúng cách
    // né bẫy múi giờ của nowStamp() cũ (gas/04-AttendanceService.gs dòng 9-11).
    $now = db_now();
    if ($now > (string) $key['EndTime']) {
        db()->prepare("UPDATE attendance_keys SET Status = 'CLOSED' WHERE KeyID = :id")
            ->execute(['id' => $key['KeyID']]);
        api_fail('Đã hết hạn điểm danh cho buổi này.');
        return;
    }
    $status = ($now <= (string) $key['LateAfter']) ? 'PRESENT' : 'LATE';

    $stmt = db()->prepare('SELECT * FROM sessions WHERE SessionID = :id LIMIT 1');
    $stmt->execute(['id' => $key['SessionID']]);
    $session = $stmt->fetch();
    if (!$session) {
        api_fail('Dữ liệu buổi học không hợp lệ.');
        return;
    }

    // [D.8-2] Nhận diện + kiểm tra ghi danh — qua identify_student(), không
    // tự so sánh MSSV ở đây.
    $identify = identify_student((string) ($params['mssv'] ?? ''), (string) $session['ClassID']);
    if (!$identify['ok']) {
        api_fail((string) $identify['reason']);
        return;
    }
    $student = $identify['student'];

    $stmt = db()->prepare('SELECT * FROM classes WHERE ClassID = :id LIMIT 1');
    $stmt->execute(['id' => $session['ClassID']]);
    $cls = $stmt->fetch() ?: null;

    $gps = evaluate_gps($params, $cls, $appCfg);

    // [D.8-4] DeviceHash — lớp phòng thủ chính khi không có Authentication thật.
    $deviceHash = trim((string) ($params['deviceHash'] ?? ''));
    $conflicts = 0;
    if ($deviceHash !== '') {
        $stmt = db()->prepare(
            'SELECT COUNT(*) FROM attendance WHERE SessionID = :sid AND DeviceHash = :dh AND StudentID <> :sid2'
        );
        $stmt->execute(['sid' => $key['SessionID'], 'dh' => $deviceHash, 'sid2' => $student['StudentID']]);
        $conflicts = (int) $stmt->fetchColumn();
    }
    $note = $conflicts > 0 ? ('Trùng thiết bị với ' . $conflicts . ' MSSV khác') : '';
    // GĐ5 review bảo mật (docs/05-GD5-smoke-review.md, M3): IP lấy từ phía
    // máy chủ (đúng chú thích cột IP trong db/schema.sql), KHÔNG tin tham số
    // 'ip' do client tự khai — client gửi gì cũng bị bỏ qua.
    $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);

    $mutable = [
        'Status'      => $status,
        'CheckInTime' => $now,
        'GpsLat'      => $gps['lat'],
        'GpsLng'      => $gps['lng'],
        'GpsAccuracy' => $gps['accuracy'],
        'DistanceM'   => $gps['distance'],
        'GpsFlag'     => $gps['flag'],
        'IP'          => $ip,
        'OS'          => (string) ($params['os'] ?? ''),
        'Browser'     => (string) ($params['browser'] ?? ''),
        'DeviceType'  => (string) ($params['deviceType'] ?? ''),
        'DeviceHash'  => $deviceHash,
        'Note'        => $note,
    ];

    // [D.8-3] Một MSSV chỉ một bản ghi cho một SessionID. UNIQUE KEY
    // uq_att_student_session (db/schema.sql) là chốt chặn THẬT — SELECT dưới
    // đây chỉ để quyết định action trả về; nếu hai request cùng MSSV/buổi
    // lọt qua đồng thời (đụng độ hiếm), bắt lỗi trùng khoá rồi coi như
    // UPDATED — thay withLock_() cũ (xem api/lib/db.php db_transaction()).
    $action = db_transaction(function (PDO $pdo) use ($mutable, $student, $key): string {
        $updateSql = 'UPDATE attendance SET ' .
            implode(', ', array_map(static fn ($k) => "$k = :$k", array_keys($mutable))) .
            ' WHERE AttendanceID = :id';

        $sel = $pdo->prepare('SELECT AttendanceID FROM attendance WHERE StudentID = :sid AND SessionID = :ssid');
        $sel->execute(['sid' => $student['StudentID'], 'ssid' => $key['SessionID']]);
        $existing = $sel->fetch();

        if ($existing) {
            $pdo->prepare($updateSql)->execute($mutable + ['id' => $existing['AttendanceID']]);
            return 'UPDATED';
        }

        $insertFields = $mutable + [
            'AttendanceID' => new_id('ATT'),
            'StudentID'    => $student['StudentID'],
            'SessionID'    => $key['SessionID'],
        ];
        $cols = implode(', ', array_keys($insertFields));
        $ph   = implode(', ', array_map(static fn ($k) => ":$k", array_keys($insertFields)));

        try {
            $pdo->prepare("INSERT INTO attendance ($cols) VALUES ($ph)")->execute($insertFields);
            return 'INSERTED';
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }
            $updateByKeySql = 'UPDATE attendance SET ' .
                implode(', ', array_map(static fn ($k) => "$k = :$k", array_keys($mutable))) .
                ' WHERE StudentID = :sid AND SessionID = :ssid';
            $pdo->prepare($updateByKeySql)
                ->execute($mutable + ['sid' => $student['StudentID'], 'ssid' => $key['SessionID']]);
            return 'UPDATED';
        }
    });

    // [D.8-6] Nhật ký cho MỌI lần điểm danh.
    log_audit((string) $student['StudentID'], 'STUDENT', 'CHECKIN_' . $action, 'SESSION', (string) $key['SessionID'], [
        'mssv'            => $student['MSSV'],
        'status'          => $status,
        'gpsFlag'         => $gps['flag'],
        'distance'        => $gps['distance'],
        'deviceConflicts' => $conflicts,
    ]);

    api_ok([
        'mssv'        => $student['MSSV'],
        'fullName'    => $student['FullName'],
        'classId'     => $session['ClassID'],
        'status'      => $status,
        'statusText'  => $status === 'PRESENT' ? 'Có mặt' : 'Trễ',
        'checkInTime' => db_stamp_to_iso($now),
        'action'      => $action,
        'gpsFlag'     => $gps['flag'],
        'distanceM'   => blank_if_null($gps['distance']),
    ]);
}

/**
 * liveRoster — GET. [D.8-5] Kiểm chứng hiệu quả nhất: giảng viên nhìn màn
 * hình, đối chiếu với lớp đang ngồi trước mặt. Cảnh báo trùng thiết bị và
 * GPS bất thường phải hiện Ở ĐÂY, không chỉ ghi âm thầm vào CSDL
 * (gas/04-AttendanceService.gs dòng 217-282).
 */
function action_live_roster(array $params): void
{
    $me = require_role((string) ($params['token'] ?? ''), ['LECTURER', 'ADMIN']);
    $sessionId = trim((string) ($params['sessionId'] ?? ''));

    $stmt = db()->prepare('SELECT ClassID FROM sessions WHERE SessionID = :id LIMIT 1');
    $stmt->execute(['id' => $sessionId]);
    $session = $stmt->fetch();
    if (!$session) {
        api_fail('Không tìm thấy buổi học ' . $sessionId . '.');
        return;
    }

    // [D.9] Chỉ giảng viên đứng tên lớp (hoặc ADMIN) mới xem được danh sách.
    assert_class_access($me, (string) $session['ClassID']);

    $stmt = db()->query('SELECT StudentID, MSSV, FullName FROM students');
    $studentById = [];
    foreach ($stmt->fetchAll() as $s) {
        $studentById[trim((string) $s['StudentID'])] = $s;
    }

    $stmt = db()->prepare('SELECT * FROM attendance WHERE SessionID = :sid');
    $stmt->execute(['sid' => $sessionId]);
    $records = $stmt->fetchAll();

    $seen = [];
    $deviceMap = [];
    $rows = [];
    foreach ($records as $r) {
        $sid = trim((string) $r['StudentID']);
        $seen[$sid] = true;
        $s = $studentById[$sid] ?? ['MSSV' => $sid, 'FullName' => ''];
        $dh = trim((string) ($r['DeviceHash'] ?? ''));
        if ($dh !== '') {
            $deviceMap[$dh][] = $s['MSSV'] ?? $sid;
        }
        $rows[] = [
            'mssv'        => $s['MSSV'] ?? $sid,
            'fullName'    => $s['FullName'] ?? '',
            'status'      => $r['Status'],
            'checkInTime' => db_stamp_to_iso($r['CheckInTime'] !== null ? (string) $r['CheckInTime'] : ''),
            'gpsFlag'     => $r['GpsFlag'] ?? '',
            'distanceM'   => blank_if_null($r['DistanceM'] !== null ? (float) $r['DistanceM'] : null),
            'deviceHash'  => $dh,
        ];
    }

    $stmt = db()->prepare("SELECT StudentID FROM enrollments WHERE ClassID = :cid AND Status = 'ACTIVE'");
    $stmt->execute(['cid' => $session['ClassID']]);
    $absent = [];
    foreach ($stmt->fetchAll() as $en) {
        $sid = trim((string) $en['StudentID']);
        if (isset($seen[$sid])) {
            continue;
        }
        $s = $studentById[$sid] ?? ['MSSV' => $sid, 'FullName' => ''];
        $absent[] = ['mssv' => $s['MSSV'] ?? $sid, 'fullName' => $s['FullName'] ?? ''];
    }

    // Cảnh báo: một thiết bị điểm danh cho nhiều MSSV.
    $deviceAlerts = [];
    foreach ($deviceMap as $hash => $mssvList) {
        if (count($mssvList) > 1) {
            $deviceAlerts[] = ['deviceHash' => mb_substr($hash, 0, 10) . '…', 'mssvList' => $mssvList];
        }
    }

    $gpsAlerts = [];
    foreach ($rows as $r) {
        if ($r['gpsFlag'] === 'OUT_OF_RANGE' || $r['gpsFlag'] === 'NO_GPS') {
            $gpsAlerts[] = ['mssv' => $r['mssv'], 'gpsFlag' => $r['gpsFlag'], 'distanceM' => $r['distanceM']];
        }
    }

    $present = 0;
    $late = 0;
    foreach ($rows as $r) {
        if ($r['status'] === 'PRESENT') {
            $present++;
        } elseif ($r['status'] === 'LATE') {
            $late++;
        }
    }

    api_ok([
        'sessionId' => $sessionId,
        'counts' => [
            'enrolled' => count($rows) + count($absent),
            'present'  => $present,
            'late'     => $late,
            'absent'   => count($absent),
        ],
        'rows'         => $rows,
        'absent'       => $absent,
        'deviceAlerts' => $deviceAlerts,
        'gpsAlerts'    => $gpsAlerts,
    ]);
}

/* ------------------------------------------------------------------ */
/*  HÀM PHỤ                                                            */
/* ------------------------------------------------------------------ */

/** generateUniqueCode_ (gas/04-AttendanceService.gs dòng 316-330) — mã không trùng với bất kỳ mã nào đang mở. */
function generate_unique_code(PDO $pdo, string $alphabet, int $length): string
{
    $stmt = $pdo->query("SELECT Code FROM attendance_keys WHERE Status = 'OPEN'");
    $taken = [];
    foreach ($stmt->fetchAll() as $row) {
        $taken[strtoupper((string) $row['Code'])] = true;
    }

    $max = strlen($alphabet) - 1;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= $alphabet[random_int(0, $max)];
        }
        if (!isset($taken[$code])) {
            return $code;
        }
    }
    throw new RuntimeException('Không sinh được mã mới. Hãy đóng bớt các buổi điểm danh đang mở.');
}

/**
 * evaluateGps_ (thiết kế D.3, gas/04-AttendanceService.gs dòng 344-372).
 * QUAN TRỌNG: KHÔNG loại thẳng sinh viên. GPS sai số 10–100 m và kém trong
 * nhà; loại theo GPS là đuổi oan người ngồi trong lớp. Ghi cờ, để giảng viên
 * quyết định — cờ này hiện lên trong liveRoster().
 *
 * Trả PHP null (không phải '') cho các trường thiếu — dùng thẳng để bind
 * NULL vào cột DECIMAL khi ghi CSDL; chuyển sang '' bằng blank_if_null() khi
 * build JSON trả về (frontend js/models/Attendance.js so sánh === '').
 */
function evaluate_gps(array $p, ?array $cls, array $appCfg): array
{
    $lat = (!isset($p['lat']) || $p['lat'] === '') ? null : (float) $p['lat'];
    $lng = (!isset($p['lng']) || $p['lng'] === '') ? null : (float) $p['lng'];
    $acc = (!isset($p['accuracy']) || $p['accuracy'] === '') ? null : (float) $p['accuracy'];

    if ($lat === null || $lng === null) {
        return ['lat' => null, 'lng' => null, 'accuracy' => null, 'distance' => null, 'flag' => 'NO_GPS'];
    }

    $accLimit = (float) ($appCfg['gps_accuracy_limit_m'] ?? 150);
    if ($acc !== null && $acc > $accLimit) {
        return ['lat' => $lat, 'lng' => $lng, 'accuracy' => $acc, 'distance' => null, 'flag' => 'LOW_ACCURACY'];
    }

    $roomLat = ($cls && $cls['RoomLat'] !== null && $cls['RoomLat'] !== '') ? (float) $cls['RoomLat'] : null;
    $roomLng = ($cls && $cls['RoomLng'] !== null && $cls['RoomLng'] !== '') ? (float) $cls['RoomLng'] : null;
    if ($roomLat === null || $roomLng === null) {
        // Lớp chưa khai toạ độ phòng — không có cơ sở so sánh, coi như hợp lệ.
        return ['lat' => $lat, 'lng' => $lng, 'accuracy' => $acc, 'distance' => null, 'flag' => 'VALID'];
    }

    $radius = ($cls && $cls['AllowedRadiusM'] !== null && $cls['AllowedRadiusM'] !== '')
        ? (float) $cls['AllowedRadiusM']
        : (float) ($appCfg['default_radius_m'] ?? 100);
    $dist = round(haversine_meters($lat, $lng, $roomLat, $roomLng), 1);

    return [
        'lat' => $lat, 'lng' => $lng, 'accuracy' => $acc, 'distance' => $dist,
        'flag' => $dist <= $radius ? 'VALID' : 'OUT_OF_RANGE',
    ];
}

/** haversineMeters_ — khoảng cách hai điểm trên mặt cầu, đơn vị mét. */
function haversine_meters(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $r = 6371000.0;
    $toRad = static fn (float $d): float => $d * M_PI / 180;
    $dLat = $toRad($lat2 - $lat1);
    $dLng = $toRad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos($toRad($lat1)) * cos($toRad($lat2)) * sin($dLng / 2) ** 2;
    return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

/**
 * Chuyển DATETIME MySQL ('Y-m-d H:i:s') sang định dạng có 'T' — giữ đúng
 * khuôn nowStamp() cũ (gas/00-Config.gs: "yyyy-MM-ddTHH:mm:ss"), vì
 * js/models/Attendance.js (timeOnly(): indexOf('T')) và
 * js/views/StudentView.js (checkInTime.replace('T',' ')) đọc theo ký tự này.
 * KHÔNG bỏ bước này — trả nguyên dạng "Y-m-d H:i:s" của MySQL sẽ làm
 * timeOnly() hiển thị cả ngày lẫn giờ thay vì chỉ "HH:mm".
 */
function db_stamp_to_iso(string $value): string
{
    if ($value === '') {
        return '';
    }
    return str_replace(' ', 'T', $value);
}

/** '' cho JSON khi giá trị là null — đúng quy ước Sheets cũ (ô trống = '');
 *  js/models/Attendance.js: `data.distanceM === '' ? null : Number(...)`. */
function blank_if_null($value)
{
    return $value === null ? '' : $value;
}
