<?php
/**
 * tools/e2e-test.php — kiểm thử trọn luồng qua HTTP (CHỈ dùng với CSDL chạy thử).
 *   php tools/e2e-test.php http://127.0.0.1:8090
 * GV đăng nhập → CLO → nhập 18 câu mẫu → tạo đề → mở phòng → 4 SV vào, sẵn sàng →
 * bắt đầu → làm bài, vi phạm (chuyển tab, copy), bị khoá, mở khoá bằng PIN,
 * thiết bị thứ hai bị chặn → nộp / thu bài → kết quả, phân tích câu, CLO → chuyển điểm.
 */
if (PHP_SAPI !== 'cli') {
    exit;
}
$base = rtrim($argv[1] ?? 'http://127.0.0.1:8090', '/') . '/api/index.php';
$fails = 0;

function call(string $action, array $data = []): array
{
    global $base;
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: text/plain;charset=utf-8\r\n",
        'content' => json_encode(['action' => $action] + $data, JSON_UNESCAPED_UNICODE), 'ignore_errors' => true, 'timeout' => 20]]);
    $raw = file_get_contents($base, false, $ctx);
    $j = json_decode((string) $raw, true);
    if (!is_array($j)) {
        echo "!! $action: phản hồi không phải JSON: " . substr((string) $raw, 0, 300) . "\n";
        exit(1);
    }
    return $j;
}
function ok(array $r, string $label): array
{
    global $fails;
    if ($r['status'] !== 'success') {
        $fails++;
        echo "✗ $label — " . $r['message'] . "\n";
        return [];
    }
    echo "✓ $label\n";
    return (array) $r['data'];
}
function expect_fail(array $r, string $label): void
{
    global $fails;
    if ($r['status'] === 'success') {
        $fails++;
        echo "✗ $label — lẽ ra phải bị từ chối\n";
    } else {
        echo "✓ $label (bị từ chối đúng: {$r['message']})\n";
    }
}
function check(bool $c, string $label): void
{
    global $fails;
    echo ($c ? '✓ ' : '✗ ') . $label . "\n";
    if (!$c) {
        $fails++;
    }
}

// --- Giảng viên
expect_fail(call('login', ['username' => 'gv', 'password' => 'sai']), 'Đăng nhập sai mật khẩu');
$tok = ok(call('login', ['username' => 'gv', 'password' => '123456']), 'Đăng nhập GV')['token'] ?? '';
$me = ok(call('me', ['token' => $tok]), 'Danh sách lớp/học phần');
$course = $me['courses'][0]['CourseID'];
$class = $me['classes'][0]['ClassID'];
$clos = [
    ['CLO1', 'Trình bày được các tính chất vật lý, hoá học dùng để nhận biết khoáng vật'],
    ['CLO2', 'Nhận diện và phân loại các khoáng vật tạo đá chủ yếu theo cấu trúc tinh thể – hoá học'],
    ['CLO3', 'Phân loại, mô tả đá magma, trầm tích, biến chất theo thành phần, kiến trúc, cấu tạo'],
    ['CLO4', 'Vận dụng quan hệ thành tạo đá – khoáng vật (dãy Bowen, chu trình đá) để giải thích hiện tượng địa chất'],
];
foreach ($clos as $i => [$c, $d]) {
    ok(call('saveClo', ['token' => $tok, 'courseId' => $course, 'code' => $c, 'description' => $d, 'sortOrder' => $i + 1]), "Tạo $c");
}
expect_fail(call('saveClo', ['token' => $tok, 'courseId' => $course, 'code' => 'CLO1', 'description' => 'trùng']), 'Chặn CLO trùng mã');

// nhập CSV mẫu (giống bộ tách bảng ở client)
$fh = fopen(__DIR__ . '/../docs/mau-cau-hoi-da-khoang-vat.csv', 'r');
$head = fgetcsv($fh, 0, ',', '"', '');
$rows = [];
while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
    $o = array_combine($head, $r);
    $rows[] = ['type' => $o['loai'], 'stem' => $o['cau_hoi'], 'options' => array_values(array_filter([$o['A'], $o['B'], $o['C'], $o['D'], $o['E'], $o['F']], 'strlen')),
        'answer' => $o['dap_an'], 'explanation' => $o['giai_thich'], 'clo' => $o['clo'], 'bloom' => $o['bloom'], 'difficulty' => $o['do_kho'], 'points' => $o['diem'], 'topic' => $o['chu_de']];
}
$dry = ok(call('importQuestions', ['token' => $tok, 'courseId' => $course, 'rows' => $rows, 'dryRun' => true]), 'Kiểm tra trước file CSV');
check(($dry['valid'] ?? 0) === 18 && !$dry['errors'], 'CSV mẫu: 18 câu hợp lệ, 0 lỗi');
ok(call('importQuestions', ['token' => $tok, 'courseId' => $course, 'rows' => $rows]), 'Nhập 18 câu');
$bad = call('importQuestions', ['token' => $tok, 'courseId' => $course, 'rows' => [['type' => 'single', 'stem' => 'x', 'options' => ['a', 'b'], 'answer' => 'A,B']]]);
check(($bad['data']['errors'][0]['row'] ?? 0) === 1, 'Báo lỗi câu một đáp án nhưng có 2 đáp án');
$qs = ok(call('listQuestions', ['token' => $tok, 'courseId' => $course]), 'Đọc ngân hàng câu hỏi');
check(count($qs) === 18, 'Ngân hàng có 18 câu');

// tạo đề, rút theo ma trận
$exam = ok(call('saveExam', ['token' => $tok, 'classId' => $class, 'title' => 'Kiểm tra giữa kỳ — Đá và khoáng vật', 'durationMinutes' => 20, 'prepMinutes' => 5,
    'settings' => ['maxViolations' => 2]]), 'Tạo đề')['examId'];
$cloIds = array_column(ok(call('listClos', ['token' => $tok, 'courseId' => $course]), 'Đọc CLO'), 'CloID', 'Code');
$draw = ok(call('drawQuestions', ['token' => $tok, 'courseId' => $course, 'matrix' => [
    ['cloId' => $cloIds['CLO1'], 'count' => 4], ['cloId' => $cloIds['CLO2'], 'count' => 4], ['cloId' => $cloIds['CLO3'], 'count' => 5], ['cloId' => $cloIds['CLO4'], 'count' => 4]]]), 'Rút câu theo ma trận CLO');
check(count($draw['questionIds']) === 17 && empty($draw['shortages']), 'Rút đủ 17 câu (4/4/5/4), không thiếu');
ok(call('setExamItems', ['token' => $tok, 'examId' => $exam, 'items' => array_map(fn($q) => ['questionId' => $q], $draw['questionIds'])]), 'Lưu câu vào đề');
ok(call('openRoom', ['token' => $tok, 'examId' => $exam]), 'Mở phòng (5 phút chuẩn bị)');
expect_fail(call('setExamItems', ['token' => $tok, 'examId' => $exam, 'items' => []]), 'Không đổi được câu sau khi mở phòng');
$mon = ok(call('monitor', ['token' => $tok, 'examId' => $exam]), 'Bảng giám sát');
$code = $mon['exam']['Code'];
$pin = $mon['exam']['SupervisorPin'];
check($mon['exam']['Status'] === 'LOBBY' && $mon['rosterCount'] === 40, "Phòng LOBBY, mã $code, sĩ số 40");

// --- Sinh viên
$peek = ok(call('peek', ['code' => $code]), 'SV quét QR: xem thông tin phòng');
check($peek['questionCount'] === 17, 'Thông tin phòng: 17 câu');
expect_fail(call('join', ['code' => $code, 'mssv' => '99999999', 'deviceId' => str_repeat('x', 20)]), 'MSSV ngoài danh sách lớp bị chặn');
$sv = [];
foreach (['25160001', '25160002', '25160003', '25160004', '25160005', '25160006'] as $i => $m) {
    $sv[$m] = ['dev' => 'dev' . $m . 'abcdefghij', 't' => ok(call('join', ['code' => $code, 'mssv' => $m, 'deviceId' => 'dev' . $m . 'abcdefghij', 'deviceInfo' => 'Test']), "SV $m vào phòng")['token'] ?? ''];
}
expect_fail(call('paper', ['t' => $sv['25160001']['t']]), 'Chưa đến giờ thì không lấy được đề');
foreach ($sv as $m => $s) {
    $m = (string) $m;
    ok(call('ready', ['t' => $s['t'], 'checks' => ['rules' => true]]), "SV $m sẵn sàng");
}
// SV vi phạm trong lúc chờ: chỉ ghi nhật ký, không tính
$st = ok(call('state', ['t' => $sv['25160001']['t'], 'events' => [['type' => 'hidden', 'detail' => 'test']]]), 'Rời trang lúc chờ');
check($st['me']['violations'] === 0, 'Lúc chờ: rời trang KHÔNG tính vi phạm');
ok(call('startNow', ['token' => $tok, 'examId' => $exam]), 'GV bắt đầu ngay');

// SV làm bài: SV1 làm đúng hết, SV2 sai hết, SV3-6 ngẫu nhiên
$key = [];
foreach (call('listQuestions', ['token' => $tok, 'courseId' => $course])['data'] as $q) {
    $key[$q['QuestionID']] = $q;
}
$papers = [];
foreach ($sv as $m => $s) {
    $m = (string) $m;
    $papers[$m] = ok(call('paper', ['t' => $s['t']]), "SV $m nhận đề");
}
$p1 = $papers['25160001'];
check(!isset($p1['questions'][0]['answer']) && !str_contains(json_encode($p1), 'explanation'), 'Đề gửi xuống máy SV KHÔNG chứa đáp án / giải thích');
check(array_column($papers['25160001']['questions'], 'id') !== array_column($papers['25160002']['questions'], 'id'), 'Thứ tự câu khác nhau giữa 2 SV (đã xáo)');
mt_srand(7);
foreach ($sv as $m => $s) {
    $m = (string) $m;
    $ans = [];
    foreach ($papers[$m]['questions'] as $q) {
        $k = $key[$q['id']];
        $right = $k['Type'] === 'short' ? $k['Answer'][0] : ($k['Type'] === 'multi' ? $k['Answer'] : $k['Answer'][0]);
        $wrongKey = array_values(array_diff(array_column($k['Options'], 'key'), $k['Answer']))[0] ?? 'A';
        $wrongOpt = $k['Type'] === 'short' ? 'không biết' : ($k['Type'] === 'multi' ? [$wrongKey] : $wrongKey);
        if ($m === '25160001') {
            $ans[$q['id']] = $right;
        } elseif ($m === '25160002') {
            $ans[$q['id']] = $wrongOpt;
        } else {
            $ans[$q['id']] = mt_rand(0, 100) < 30 + 10 * (int) substr($m, -1) ? $right : $wrongOpt;
        }
    }
    ok(call('save', ['t' => $s['t'], 'answers' => $ans]), "SV $m lưu bài");
}
// tên viết không dấu vẫn được chấm đúng
$shortQ = array_values(array_filter($papers['25160001']['questions'], fn($q) => $q['type'] === 'short' && str_contains($q['stem'], 'SiO2')));
if ($shortQ) {
    ok(call('save', ['t' => $sv['25160001']['t'], 'qid' => $shortQ[0]['id'], 'answer' => '  Thach Anh ']), 'SV1 trả lời ngắn không dấu "Thach Anh"');
}

// vi phạm: SV3 chuyển tab 2 lần → bị khoá (ngưỡng 2)
$st = ok(call('state', ['t' => $sv['25160003']['t'], 'events' => [['type' => 'hidden', 'detail' => 'Chuyển tab']]]), 'SV3 chuyển tab lần 1');
check($st['me']['violations'] === 1 && $st['me']['status'] === 'DOING', 'SV3: 1 vi phạm, vẫn làm bài');
$st = ok(call('state', ['t' => $sv['25160003']['t'], 'events' => [['type' => 'blur'], ['type' => 'hidden']]]), 'SV3: blur + hidden cùng lúc');
check($st['me']['violations'] === 1, 'Hai tín hiệu liền nhau gộp thành 1 vi phạm');
sleep(2);
$st = ok(call('state', ['t' => $sv['25160003']['t'], 'events' => [['type' => 'copy', 'detail' => 'Ctrl+C']]]), 'SV3 sao chép');
check($st['me']['status'] === 'LOCKED', 'SV3 vượt ngưỡng → BỊ KHOÁ');
expect_fail(call('save', ['t' => $sv['25160003']['t'], 'qid' => $papers['25160003']['questions'][0]['id'], 'answer' => 'A']), 'Bài bị khoá không lưu được');
expect_fail(call('pinUnlock', ['t' => $sv['25160003']['t'], 'pin' => '000000' === $pin ? '111111' : '000000']), 'PIN sai bị từ chối');
$st = ok(call('pinUnlock', ['t' => $sv['25160003']['t'], 'pin' => $pin]), 'Giám thị nhập PIN mở khoá');
check($st['me']['status'] === 'DOING' && $st['me']['limit'] === 3, 'Mở khoá xong: làm tiếp, ngưỡng nâng lên 3');

// thiết bị thứ hai
expect_fail(call('join', ['code' => $code, 'mssv' => '25160004', 'deviceId' => 'mayKhac1234567890xyz']), 'SV4 vào từ máy thứ hai bị chặn');
$st = ok(call('state', ['t' => $sv['25160004']['t']]), 'SV4 trạng thái');
check($st['me']['violations'] === 1, 'Máy thứ hai được tính 1 vi phạm cho SV4');
// GV cho đổi máy
$mon = ok(call('monitor', ['token' => $tok, 'examId' => $exam]), 'Giám sát sau vi phạm');
$att4 = array_values(array_filter($mon['attempts'], fn($a) => $a['MSSV'] === '25160004'))[0]['AttemptID'];
ok(call('allowRejoin', ['token' => $tok, 'examId' => $exam, 'attemptId' => $att4]), 'GV cho SV4 đổi máy');
$new4 = ok(call('join', ['code' => $code, 'mssv' => '25160004', 'deviceId' => 'mayKhac1234567890xyz']), 'SV4 vào lại bằng máy mới')['token'] ?? '';
expect_fail(call('state', ['t' => $sv['25160004']['t']]), 'Phiên trên máy cũ của SV4 hết hiệu lực');
$sv['25160004']['t'] = $new4;
$p4 = ok(call('paper', ['t' => $new4, 'reloaded' => true]), 'SV4 nhận lại đề, bài cũ còn nguyên');
check(count((array) $p4['answers']) > 0, 'Đáp án đã lưu của SV4 vẫn còn');
ok(call('addTime', ['token' => $tok, 'examId' => $exam, 'attemptId' => $att4, 'minutes' => 3]), 'Cộng 3 phút cho SV4');
check(count($mon['events']) > 0, 'Bảng giám sát có nhật ký sự kiện');

// nộp
foreach (['25160001', '25160002', '25160003'] as $m) {
    $r = ok(call('submit', ['t' => $sv[$m]['t']]), "SV $m nộp bài");
    if ($m === '25160001') {
        check(($r['result']['score10'] ?? null) == 10.0, 'SV1 làm đúng hết → 10 điểm');
    }
    if ($m === '25160002') {
        check(($r['result']['score10'] ?? null) == 0.0, 'SV2 làm sai hết → 0 điểm');
    }
}
expect_fail(call('review', ['t' => $sv['25160001']['t']]), 'Chưa công bố → SV chưa xem được đáp án');
ok(call('closeExam', ['token' => $tok, 'examId' => $exam]), 'GV thu bài, đóng phòng (chấm cả SV chưa nộp)');
$res = ok(call('results', ['token' => $tok, 'examId' => $exam]), 'Kết quả + phân tích');
check($res['stats']['n'] === 6 && $res['stats']['notTaken'] === 34, 'Thống kê: 6 bài, 34 SV không dự thi');
check(count($res['items']) === 17 && $res['items'][0]['p'] !== null && $res['items'][0]['D'] !== null, 'Có độ khó p, độ phân biệt D từng câu');
check(count(array_filter($res['clos'], fn($c) => $c['cloId'])) === 4, 'Báo cáo đủ 4 CLO');
printf("   Điểm TB %.2f, SD %.2f, α = %s; CLO: %s\n", $res['stats']['mean'], $res['stats']['sd'], $res['stats']['alpha'] === null ? '—' : round($res['stats']['alpha'], 3),
    implode(' | ', array_map(fn($c) => $c['code'] . ' ' . round((float) $c['achievedRate']) . '% SV đạt (' . ($c['met'] ? 'ĐẠT' : 'chưa đạt') . ')', $res['clos'])));
$push = ok(call('pushGrades', ['token' => $tok, 'examId' => $exam, 'columnName' => 'Giữa kỳ', 'weight' => 30]), 'Chuyển điểm sang cột "Giữa kỳ"');
check(($push['written'] ?? 0) === 6, 'Đã ghi 6 điểm vào bảng điểm');
ok(call('publishResults', ['token' => $tok, 'examId' => $exam, 'published' => true]), 'Công bố đáp án + giải thích');
$rv = ok(call('review', ['t' => $sv['25160001']['t']]), 'SV1 xem lại bài');
check(isset($rv['items'][0]['explanation']) && $rv['score10'] == 10.0, 'SV xem được đáp án, giải thích và điểm');
$log = ok(call('attemptLog', ['token' => $tok, 'examId' => $exam, 'attemptId' => array_values(array_filter($res['students'], fn($s) => $s['mssv'] === '25160003'))[0]['attemptId']]), 'Nhật ký SV3');
check(count(array_filter($log['events'], fn($e) => (int) $e['Counted'] === 1)) === 2, 'Nhật ký SV3 có đúng 2 vi phạm được tính');
expect_fail(call('deleteExam', ['token' => $tok, 'examId' => $exam]), 'Không xoá được đề đã có bài làm');
$dup = ok(call('duplicateExam', ['token' => $tok, 'examId' => $exam]), 'Nhân bản đề cho lớp khác / thi lại');

echo $fails ? "\n$fails KIỂM TRA THẤT BẠI\n" : "\nTẤT CẢ ĐỀU ĐẠT\n";
exit($fails ? 1 : 0);
