<?php
declare(strict_types=1);

/**
 * slido-api.php — Realtime API cho Hệ thống Trình chiếu Tương tác Slido Toán 7 & BDVH
 * ThS. Đinh Quốc Tuấn — Trường ĐH Khoa học Tự nhiên, ĐHQG-HCM
 * 
 * Hỗ trợ:
 * - Đồng bộ trạng thái Slide giáo viên đang chiếu (Câu hỏi, Chế độ Đề/Đáp án/Giải thích, Khóa bình chọn).
 * - Thu nhận bình chọn trực tiếp của học sinh (A, B, C, D).
 * - Trả kết quả thống kê thời gian thực (Counts, Percentages, Recent voters).
 * - Tự động thích ứng CSDL MySQL (Hostinger) hoặc SQLite nội bộ.
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);
date_default_timezone_set('Asia/Ho_Chi_Minh');

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: no-store, no-cache, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function slido_json(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Kết nối DB
$root = dirname(__DIR__);
$isLiveHost = file_exists($root . '/api/lib/config.php') && file_exists($root . '/api/lib/db.php');
$pdo = null;

if ($isLiveHost) {
    try {
        require_once $root . '/api/lib/config.php';
        require_once $root . '/api/lib/db.php';
        $pdo = db();
    } catch (Throwable $e) {
        $pdo = null;
    }
}

if (!$pdo) {
    $sqliteFile = __DIR__ . '/bdvh_local.sqlite';
    $pdo = new PDO('sqlite:' . $sqliteFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
}

// Khởi tạo bảng nếu chưa có
function ensure_slido_tables(PDO $db): void {
    $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $db->exec("
            CREATE TABLE IF NOT EXISTS slido_state (
                exam_code TEXT PRIMARY KEY,
                current_q INTEGER DEFAULT 1,
                slide_type TEXT DEFAULT 'question',
                is_locked INTEGER DEFAULT 0,
                updated_at INTEGER DEFAULT 0
            );
            CREATE TABLE IF NOT EXISTS slido_votes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                exam_code TEXT NOT NULL,
                q_id INTEGER NOT NULL,
                client_id TEXT NOT NULL,
                student_name TEXT DEFAULT '',
                answer TEXT NOT NULL,
                created_at INTEGER NOT NULL,
                UNIQUE(exam_code, q_id, client_id)
            );
        ");
    } else {
        $engine = " ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $db->exec("
            CREATE TABLE IF NOT EXISTS slido_state (
                exam_code VARCHAR(32) PRIMARY KEY,
                current_q INT NOT NULL DEFAULT 1,
                slide_type VARCHAR(20) NOT NULL DEFAULT 'question',
                is_locked TINYINT NOT NULL DEFAULT 0,
                updated_at INT NOT NULL DEFAULT 0
            )" . $engine);

        $db->exec("
            CREATE TABLE IF NOT EXISTS slido_votes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                exam_code VARCHAR(32) NOT NULL,
                q_id INT NOT NULL,
                client_id VARCHAR(64) NOT NULL,
                student_name VARCHAR(120) NOT NULL DEFAULT '',
                answer VARCHAR(10) NOT NULL,
                created_at INT NOT NULL,
                UNIQUE KEY uq_slido_vote (exam_code, q_id, client_id),
                KEY k_slido_exam_q (exam_code, q_id)
            )" . $engine);
    }
}

try {
    ensure_slido_tables($pdo);
} catch (Throwable $e) {
    // Có thể bảng đã tồn tại
}

// Đọc dữ liệu đầu vào
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$inputData = [];
$rawInput = file_get_contents('php://input');
if (!empty($rawInput)) {
    $parsed = json_decode($rawInput, true);
    if (is_array($parsed)) {
        $inputData = $parsed;
        if (empty($action) && isset($inputData['action'])) {
            $action = (string)$inputData['action'];
        }
    }
}

$exam = (string)($_GET['exam'] ?? $_POST['exam'] ?? $inputData['exam'] ?? 'toan7');
$exam = preg_replace('/[^a-zA-Z0-9_\-]/', '', $exam) ?: 'toan7';

switch ($action) {
    // 1. LẤY TRẠNG THÁI HIỆN TẠI CỦA PHÒNG CHIẾU (Dành cho cả GV & Học sinh)
    case 'get_state':
        $stmt = $pdo->prepare("SELECT * FROM slido_state WHERE exam_code = ? LIMIT 1");
        $stmt->execute([$exam]);
        $row = $stmt->fetch();
        if (!$row) {
            $now = time();
            $ins = $pdo->prepare("INSERT INTO slido_state (exam_code, current_q, slide_type, is_locked, updated_at) VALUES (?, 1, 'question', 0, ?)");
            $ins->execute([$exam, $now]);
            $row = [
                'exam_code' => $exam,
                'current_q' => 1,
                'slide_type' => 'question',
                'is_locked' => 0,
                'updated_at' => $now
            ];
        }
        slido_json([
            'status' => 'success',
            'state' => [
                'exam_code' => $row['exam_code'],
                'current_q' => (int)$row['current_q'],
                'slide_type' => $row['slide_type'],
                'is_locked' => (bool)$row['is_locked'],
                'updated_at' => (int)$row['updated_at']
            ]
        ]);
        break;

    // 2. GIÁO VIÊN CẬP NHẬT TRẠNG THÁI SLIDE
    case 'set_state':
        $q = isset($inputData['current_q']) ? (int)$inputData['current_q'] : (int)($_POST['current_q'] ?? 1);
        $type = (string)($inputData['slide_type'] ?? $_POST['slide_type'] ?? 'question');
        $locked = isset($inputData['is_locked']) ? ((bool)$inputData['is_locked'] ? 1 : 0) : null;
        $now = time();

        $stmt = $pdo->prepare("SELECT exam_code, is_locked FROM slido_state WHERE exam_code = ? LIMIT 1");
        $stmt->execute([$exam]);
        $existing = $stmt->fetch();

        if ($locked === null && $existing) {
            $locked = (int)$existing['is_locked'];
        } elseif ($locked === null) {
            $locked = 0;
        }

        if ($existing) {
            $upd = $pdo->prepare("UPDATE slido_state SET current_q = ?, slide_type = ?, is_locked = ?, updated_at = ? WHERE exam_code = ?");
            $upd->execute([$q, $type, $locked, $now, $exam]);
        } else {
            $ins = $pdo->prepare("INSERT INTO slido_state (exam_code, current_q, slide_type, is_locked, updated_at) VALUES (?, ?, ?, ?, ?)");
            $ins->execute([$exam, $q, $type, $locked, $now]);
        }

        slido_json([
            'status' => 'success',
            'message' => 'Đã cập nhật trạng thái trình chiếu',
            'state' => [
                'exam_code' => $exam,
                'current_q' => $q,
                'slide_type' => $type,
                'is_locked' => (bool)$locked,
                'updated_at' => $now
            ]
        ]);
        break;

    // 3. KHÓA HOẶC MỞ NHẬN BÌNH CHỌN
    case 'toggle_lock':
        $locked = isset($inputData['is_locked']) ? ((bool)$inputData['is_locked'] ? 1 : 0) : (int)($_POST['is_locked'] ?? 1);
        $now = time();
        $upd = $pdo->prepare("UPDATE slido_state SET is_locked = ?, updated_at = ? WHERE exam_code = ?");
        $upd->execute([$locked, $now, $exam]);
        slido_json([
            'status' => 'success',
            'is_locked' => (bool)$locked
        ]);
        break;

    // 4. HỌC SINH GỬI PHIẾU BÌNH CHỌN
    case 'vote':
        $qId = isset($inputData['q_id']) ? (int)$inputData['q_id'] : (int)($_POST['q_id'] ?? 0);
        $clientId = (string)($inputData['client_id'] ?? $_POST['client_id'] ?? '');
        $name = trim((string)($inputData['student_name'] ?? $_POST['student_name'] ?? 'Học sinh ẩn danh'));
        $ans = strtoupper(trim((string)($inputData['answer'] ?? $_POST['answer'] ?? '')));

        if ($qId <= 0 || empty($clientId) || !in_array($ans, ['A', 'B', 'C', 'D'], true)) {
            slido_json(['status' => 'error', 'message' => 'Dữ liệu bình chọn không hợp lệ'], 400);
        }

        // Kiểm tra xem câu này có đang bị khóa không
        $check = $pdo->prepare("SELECT is_locked, current_q FROM slido_state WHERE exam_code = ? LIMIT 1");
        $check->execute([$exam]);
        $stateRow = $check->fetch();
        if ($stateRow && (int)$stateRow['is_locked'] === 1 && (int)$stateRow['current_q'] === $qId) {
            slido_json(['status' => 'error', 'message' => 'Giáo viên đã khóa nhận bài cho câu này!'], 403);
        }

        $now = time();
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $stmt = $pdo->prepare("
                INSERT INTO slido_votes (exam_code, q_id, client_id, student_name, answer, created_at)
                VALUES (?, ?, ?, ?, ?, ?)
                ON CONFLICT(exam_code, q_id, client_id) DO UPDATE SET
                    student_name = excluded.student_name,
                    answer = excluded.answer,
                    created_at = excluded.created_at
            ");
            $stmt->execute([$exam, $qId, $clientId, $name, $ans, $now]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO slido_votes (exam_code, q_id, client_id, student_name, answer, created_at)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    student_name = VALUES(student_name),
                    answer = VALUES(answer),
                    created_at = VALUES(created_at)
            ");
            $stmt->execute([$exam, $qId, $clientId, $name, $ans, $now]);
        }

        slido_json([
            'status' => 'success',
            'message' => 'Đã ghi nhận câu trả lời',
            'vote' => [
                'q_id' => $qId,
                'answer' => $ans,
                'name' => $name
            ]
        ]);
        break;

    // 5. LẤY KẾT QUẢ THỐNG KÊ REALTIME CHO MỘT CÂU
    case 'get_results':
        $qId = isset($_GET['q_id']) ? (int)$_GET['q_id'] : (int)($_POST['q_id'] ?? ($inputData['q_id'] ?? 0));
        if ($qId <= 0) {
            // Lấy theo current_q
            $st = $pdo->prepare("SELECT current_q FROM slido_state WHERE exam_code = ? LIMIT 1");
            $st->execute([$exam]);
            $r = $st->fetch();
            $qId = $r ? (int)$r['current_q'] : 1;
        }

        $stmt = $pdo->prepare("SELECT answer, student_name, created_at FROM slido_votes WHERE exam_code = ? AND q_id = ? ORDER BY created_at DESC");
        $stmt->execute([$exam, $qId]);
        $rows = $stmt->fetchAll();

        $counts = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0];
        $recent = [];
        $total = count($rows);

        foreach ($rows as $row) {
            $a = $row['answer'];
            if (isset($counts[$a])) {
                $counts[$a]++;
            }
            if (count($recent) < 15) {
                $recent[] = [
                    'name' => $row['student_name'] ?: 'Học sinh',
                    'ans' => $a,
                    'time' => date('H:i:s', (int)$row['created_at'])
                ];
            }
        }

        $pcts = [];
        foreach (['A', 'B', 'C', 'D'] as $k) {
            $pcts[$k] = $total > 0 ? round(($counts[$k] / $total) * 100, 1) : 0;
        }

        slido_json([
            'status' => 'success',
            'exam' => $exam,
            'q_id' => $qId,
            'total' => $total,
            'counts' => $counts,
            'percentages' => $pcts,
            'recent_voters' => $recent
        ]);
        break;

    // 6. HỌC SINH LẤY LỊCH SỬ BÀI LÀM CỦA MÌNH
    case 'get_my_votes':
        $clientId = (string)($_GET['client_id'] ?? $inputData['client_id'] ?? '');
        if (empty($clientId)) {
            slido_json(['status' => 'success', 'votes' => []]);
        }
        $stmt = $pdo->prepare("SELECT q_id, answer, created_at FROM slido_votes WHERE exam_code = ? AND client_id = ?");
        $stmt->execute([$exam, $clientId]);
        $rows = $stmt->fetchAll();
        $myMap = [];
        foreach ($rows as $r) {
            $myMap[(int)$r['q_id']] = [
                'answer' => $r['answer'],
                'created_at' => (int)$r['created_at']
            ];
        }
        slido_json([
            'status' => 'success',
            'votes' => $myMap
        ]);
        break;

    // 7. GIÁO VIÊN RESET BÌNH CHỌN CỦA CÂU NÀY
    case 'reset_q':
        $qId = (int)($inputData['q_id'] ?? $_POST['q_id'] ?? 0);
        if ($qId > 0) {
            $del = $pdo->prepare("DELETE FROM slido_votes WHERE exam_code = ? AND q_id = ?");
            $del->execute([$exam, $qId]);
        } else {
            // Reset toàn bộ bài kiểm tra
            $del = $pdo->prepare("DELETE FROM slido_votes WHERE exam_code = ?");
            $del->execute([$exam]);
        }
        slido_json([
            'status' => 'success',
            'message' => 'Đã làm mới dữ liệu bình chọn'
        ]);
        break;

    default:
        slido_json(['status' => 'error', 'message' => 'Hành động không hợp lệ: ' . htmlspecialchars($action)], 404);
}
