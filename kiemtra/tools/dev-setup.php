<?php
/**
 * tools/dev-setup.php — CHỈ để chạy thử trên máy (CLI). Tạo CSDL SQLite gồm
 * các bảng tối thiểu của diemdanhsv (users, courses, classes, students,
 * enrollments, auth_tokens, grade_columns, grades, rate_limits, audit_log) +
 * bảng kt_*, kèm dữ liệu mẫu: GV "gv" / mật khẩu "123456", học phần
 * "Đá và khoáng vật", lớp 25DC1 với 40 SV, 4 CLO và ngân hàng câu hỏi mẫu.
 *
 *   php tools/dev-setup.php /đường/dẫn/thư-mục-dev
 *   KIEMTRA_CONFIG=/đường/dẫn/thư-mục-dev/config.php php -S 127.0.0.1:8090 -t .
 *
 * .htaccess chặn thư mục tools/ trên host thật.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$dir = $argv[1] ?? (__DIR__ . '/../.dev');
@mkdir($dir, 0777, true);
$dir = realpath($dir);
$dbPath = $dir . '/kiemtra.sqlite';
@unlink($dbPath);
file_put_contents($dir . '/config.php', "<?php\nreturn ['db' => ['driver' => 'sqlite', 'path' => " . var_export($dbPath, true) . "], 'kiemtra' => ['upload_dir' => " . var_export($dir . '/uploads', true) . "]];\n");

$pdo = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$core = <<<SQL
CREATE TABLE users (UserID TEXT PRIMARY KEY, Username TEXT UNIQUE, FullName TEXT, Email TEXT, PasswordHash TEXT, Salt TEXT, Role TEXT DEFAULT 'LECTURER', Status TEXT DEFAULT 'ACTIVE', CreatedAt DATETIME DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE courses (CourseID TEXT PRIMARY KEY, CourseCode TEXT, CourseName TEXT, Credits REAL, TheoryHours INT, PracticeHours INT, Status TEXT DEFAULT 'ACTIVE', CreatedAt DATETIME DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE classes (ClassID TEXT PRIMARY KEY, CourseID TEXT, LecturerID TEXT, ClassCode TEXT, Semester TEXT, AcademicYear TEXT, RoomLat REAL, RoomLng REAL, AllowedRadiusM INT DEFAULT 100, Status TEXT DEFAULT 'ACTIVE', CreatedAt DATETIME DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE students (StudentID TEXT PRIMARY KEY, MSSV TEXT UNIQUE, FullName TEXT, Email TEXT, Status TEXT DEFAULT 'ACTIVE', CreatedAt DATETIME DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE enrollments (EnrollmentID TEXT PRIMARY KEY, StudentID TEXT, ClassID TEXT, Status TEXT DEFAULT 'ACTIVE', CreatedAt DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE (StudentID, ClassID));
CREATE TABLE auth_tokens (Token TEXT PRIMARY KEY, Kind TEXT, SubjectID TEXT, ExpiresAt DATETIME, CreatedAt DATETIME DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE grade_columns (GradeColumnID TEXT PRIMARY KEY, ClassID TEXT, Name TEXT, Weight REAL DEFAULT 0, SortOrder INT DEFAULT 0, Status TEXT DEFAULT 'ACTIVE', CreatedAt DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE (ClassID, Name));
CREATE TABLE grades (GradeID TEXT PRIMARY KEY, StudentID TEXT, ClassID TEXT, GradeColumnID TEXT, Score REAL, UpdatedBy TEXT, UpdatedAt DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE (StudentID, GradeColumnID));
CREATE TABLE rate_limits (Bucket TEXT, ClientKey TEXT, WindowStart DATETIME, Hits INT DEFAULT 0, PRIMARY KEY (Bucket, ClientKey));
CREATE TABLE audit_log (LogID TEXT PRIMARY KEY, Time DATETIME DEFAULT CURRENT_TIMESTAMP, Actor TEXT, ActorRole TEXT, Action TEXT, TargetType TEXT, TargetID TEXT, Data TEXT, IP TEXT);
SQL;
$pdo->exec($core);

// chuyển schema MySQL của kt_* sang SQLite
$sql = file_get_contents(__DIR__ . '/../db/schema.mysql.sql');
$sql = preg_replace('/^--.*$/m', '', $sql);
$sql = preg_replace('/SET NAMES \w+;/', '', $sql);
$sql = preg_replace('/\)\s*ENGINE=[^;]*;/', ');', $sql);
$sql = preg_replace("/ENUM\([^)]*\)/", 'TEXT', $sql);
$sql = str_replace('ON UPDATE CURRENT_TIMESTAMP', '', $sql);
$sql = str_replace('EventID   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT', 'EventID INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
$sql = preg_replace('/^\s*PRIMARY KEY \(EventID\),\s*$/m', '', $sql);
$sql = preg_replace('/UNIQUE KEY \w+ \(/', 'UNIQUE (', $sql);
$sql = preg_replace('/^\s*KEY \w+ \([^)]*\),?\s*$/m', '', $sql);
$sql = preg_replace('/,\s*\)\s*;/', "\n);", $sql);
$pdo->exec($sql);

// dữ liệu mẫu
$pdo->prepare('INSERT INTO users (UserID, Username, FullName, PasswordHash, Role) VALUES (?,?,?,?,?)')
    ->execute(['1607', 'gv', 'ThS. Đinh Quốc Tuấn', password_hash('123456', PASSWORD_DEFAULT), 'LECTURER']);
$pdo->exec("INSERT INTO courses (CourseID, CourseCode, CourseName, Credits) VALUES ('CRS_dakv', 'GEO10203', 'Đá và khoáng vật', 3)");
$pdo->exec("INSERT INTO classes (ClassID, CourseID, LecturerID, ClassCode, Semester, AcademicYear) VALUES ('CLS_25dc1', 'CRS_dakv', '1607', '25DC1', 'HK1', '2026-2027')");
$ho = ['Nguyễn', 'Trần', 'Lê', 'Phạm', 'Hoàng', 'Võ', 'Đặng', 'Bùi'];
$ten = ['An', 'Bình', 'Chi', 'Dũng', 'Giang', 'Hà', 'Khoa', 'Linh', 'Minh', 'Nam'];
$ins = $pdo->prepare('INSERT INTO students (StudentID, MSSV, FullName) VALUES (?,?,?)');
$en = $pdo->prepare('INSERT INTO enrollments (EnrollmentID, StudentID, ClassID) VALUES (?,?,?)');
for ($i = 1; $i <= 40; $i++) {
    $mssv = sprintf('2516%04d', $i);
    $ins->execute(['STD_' . $mssv, $mssv, $ho[$i % 8] . ' Văn ' . $ten[$i % 10]]);
    $en->execute(['ENR_' . $mssv, 'STD_' . $mssv, 'CLS_25dc1']);
}
echo "OK: $dbPath\nconfig: $dir/config.php\nĐăng nhập GV: gv / 123456 · MSSV mẫu: 25160001…25160040\n";
