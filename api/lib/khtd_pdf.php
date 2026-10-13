<?php
/**
 * api/lib/khtd_pdf.php — "Phiếu đánh giá điểm" PDF cho phiếu học tập online (docs/04 mục 31).
 *
 * Bố cục theo mẫu phiếu đánh giá điểm học phần của Trường (A4 dọc):
 *   đầu trang logo + TRƯỜNG ĐẠI HỌC KHOA HỌC TỰ NHIÊN, ĐHQG-HCM + PHIẾU ĐÁNH GIÁ ĐIỂM …
 *   1. PHẦN DÀNH CHO SINH VIÊN  (a–g)
 *   2. BẢNG ĐIỂM CHI TIẾT       (bảng tiêu chí có ô màu, các lần làm bài, nhận xét từng tiêu chí, bài làm)
 *   3. PHẦN NHẬN XÉT / ĐÁNH GIÁ TỪ GIẢNG VIÊN
 * Thư viện: tFPDF 1.33 (LGPL, api/lib/pdf/) + font Liberation Sans (OFL) — có đủ dấu tiếng Việt.
 * Không ghi gì ra thư mục web; ảnh tạm nằm ở sys_get_temp_dir() và bị xoá ngay.
 */

if (!defined('FPDF_FONTPATH')) define('FPDF_FONTPATH', __DIR__ . '/pdf/font/');
require_once __DIR__ . '/pdf/font/unifont/ttfonts.php';   // tFPDF 1.33 trông vào autoload của composer — nạp tay
require_once __DIR__ . '/pdf/tfpdf.php';

const KHTD_PDF_LOGO_URL = 'https://firebasestorage.googleapis.com/v0/b/link-anh-web.firebasestorage.app/o/Logo-chinh-hcmus.png?alt=media&token=9cf3a1de-75f1-493b-8711-fd22c7b98738';

class KhtdPdf extends tFPDF
{
    public string $footerText = '';
    /** @var string[] tệp ảnh tạm cần xoá khi xong */
    public array $tmpFiles = [];

    public function __construct()
    {
        parent::__construct('P', 'mm', 'A4');
        $this->SetMargins(15, 12, 15);
        $this->SetAutoPageBreak(true, 16);
        $this->AddFont('LS', '', 'LiberationSans-Regular.ttf', true);
        $this->AddFont('LS', 'B', 'LiberationSans-Bold.ttf', true);
        $this->AddFont('LS', 'I', 'LiberationSans-Italic.ttf', true);
        $this->AddFont('LS', 'BI', 'LiberationSans-BoldItalic.ttf', true);
        $this->AliasNbPages();
        $this->SetTitle('Phiếu đánh giá điểm', true);
        $this->SetCreator('diemdanhsv.com', true);
    }

    public function Footer(): void
    {
        $this->SetY(-11);
        $this->font(7.5, 'I', [120, 120, 120]);
        $this->Cell(0, 5, $this->footerText . '   ·   Trang ' . $this->PageNo() . '/{nb}', 0, 0, 'R');
    }

    public function __destruct()
    {
        foreach ($this->tmpFiles as $f) @unlink($f);
    }

    /** Sang trang mới nếu không còn đủ $h mm. */
    public function need(float $h): void
    {
        if ($this->GetY() + $h > $this->PageBreakTrigger) $this->AddPage();
    }

    public function font(float $size, string $style = '', array $rgb = [0, 0, 0]): void
    {
        $this->SetFont('LS', $style, $size);
        $this->SetTextColor($rgb[0], $rgb[1], $rgb[2]);
    }

    /** Tách chữ thành các dòng vừa bề rộng $w (mm) theo cỡ chữ hiện tại. */
    public function wrap(string $text, float $w): array
    {
        $lines = [];
        foreach (preg_split("/\r\n|\n|\r/", $text) as $para) {
            $cur = '';
            foreach (preg_split('/ +/u', $para) as $word) {
                $try = $cur === '' ? $word : $cur . ' ' . $word;
                if ($this->GetStringWidth($try) <= $w - 2 || $cur === '') {
                    // từ quá dài: cắt theo ký tự
                    while ($this->GetStringWidth($try) > $w - 2 && mb_strlen($try) > 1) {
                        $cut = mb_strlen($try) - 1;
                        while ($cut > 1 && $this->GetStringWidth(mb_substr($try, 0, $cut)) > $w - 2) $cut--;
                        $lines[] = mb_substr($try, 0, $cut);
                        $try = mb_substr($try, $cut);
                    }
                    $cur = $try;
                } else {
                    $lines[] = $cur; $cur = $word;
                }
            }
            $lines[] = $cur;
        }
        return $lines;
    }

    /**
     * Một hàng bảng: $cells = [[text, width, align, fill rgb|null, style, size, color rgb, image path|null], …].
     * Tự xuống dòng trong ô, cao bằng ô cao nhất, sang trang khi hết chỗ.
     */
    public function row(array $cells, float $lineH = 4.6, float $minH = 0): void
    {
        $h = $minH;
        foreach ($cells as $c) {
            $this->font($c[5] ?? 8.5, $c[4] ?? '');
            $n = count($this->wrap((string) $c[0], (float) $c[1]));
            $imgH = !empty($c[7]) ? 20 : 0;
            $h = max($h, $n * $lineH + 1.6 + $imgH);
        }
        if ($this->GetY() + $h > $this->PageBreakTrigger) $this->AddPage();
        $x0 = $this->GetX(); $y0 = $this->GetY(); $x = $x0;
        foreach ($cells as $c) {
            $w = (float) $c[1];
            if (!empty($c[3])) { $this->SetFillColor($c[3][0], $c[3][1], $c[3][2]); $this->Rect($x, $y0, $w, $h, 'F'); }
            $this->SetDrawColor(150, 150, 150);
            $this->Rect($x, $y0, $w, $h);
            $yy = $y0 + 0.8;
            if (!empty($c[7])) {
                $imgs = (array) $c[7]; $ix = $x + 0.8;
                foreach ($imgs as $img) {
                    if ($ix + 19 > $x + $w) break;
                    try { $this->Image($img, $ix, $yy, 19, 19); } catch (Throwable $e) { /* ảnh hỏng — bỏ qua */ }
                    $ix += 19.8;
                }
                $yy += 20;
            }
            $this->font($c[5] ?? 8.5, $c[4] ?? '', $c[6] ?? [0, 0, 0]);
            foreach ($this->wrap((string) $c[0], $w) as $ln) {
                $this->SetXY($x, $yy);
                $this->Cell($w, $lineH, $ln, 0, 0, $c[2] ?? 'L');
                $yy += $lineH;
            }
            $x += $w;
        }
        $this->SetXY($x0, $y0 + $h);
    }

    /** Đoạn văn tự xuống dòng, thụt lề $indent mm. */
    public function para(string $text, float $size = 9, string $style = '', array $rgb = [0, 0, 0], float $indent = 0, float $lineH = 4.6): void
    {
        $this->font($size, $style, $rgb);
        $w = $this->GetPageWidth() - $this->lMargin - $this->rMargin - $indent;
        foreach ($this->wrap($text, $w) as $ln) {
            if ($this->GetY() + $lineH > $this->PageBreakTrigger) $this->AddPage();
            $this->SetX($this->lMargin + $indent);
            $this->Cell($w, $lineH, $ln, 0, 1);
        }
    }

    /** "a.  Nhãn: giá trị" — nhãn thường, giá trị có thể in đậm/màu như mẫu. */
    public function labelLine(string $mark, string $label, string $value, string $vStyle = 'B', array $vRgb = [0, 0, 0], float $vSize = 9.5): void
    {
        $this->SetX($this->lMargin + 10);
        $this->font(9.5, '', [0, 0, 0]);
        $this->Cell(6, 5.2, $mark);
        $lw = $this->GetStringWidth($label . ' ') + 0.5;
        $this->Cell($lw, 5.2, $label . ' ');
        $this->font($vSize, $vStyle, $vRgb);
        $rest = $this->GetPageWidth() - $this->rMargin - $this->GetX();
        $lines = $this->wrap($value, $rest);
        foreach ($lines as $i => $ln) {
            if ($i) $this->SetX($this->lMargin + 16 + $lw);
            $this->Cell($rest, 5.2, $ln, 0, 1);
        }
    }

    public function section(string $title): void
    {
        if ($this->GetY() + 14 > $this->PageBreakTrigger) $this->AddPage();
        $this->Ln(1.5);
        $this->SetX($this->lMargin + 3);
        $this->font(10.5, 'B', [0, 0, 0]);
        $this->Cell(0, 6, $title, 0, 1);
    }

    public function caption(string $bold, string $rest = ''): void
    {
        if ($this->GetY() + 12 > $this->PageBreakTrigger) $this->AddPage();
        $this->Ln(1.2);
        $this->font(8.5, 'B');
        $this->Cell($this->GetStringWidth($bold) + 1, 5, $bold);
        $this->font(8.5, '', [60, 60, 60]);
        $this->Cell(0, 5, $rest, 0, 1);
    }
}

/* ------------------------------------------------------------------ */
/* Dữ liệu                                                              */
/* ------------------------------------------------------------------ */

/** Bỏ dấu tiếng Việt, ghép chữ hoa đầu từ: "Vũ Thị Thúy Vy" → "VuThiThuyVy". */
function khtd_ascii(string $s, bool $camel = true): string
{
    $s = str_replace(['đ', 'Đ'], ['d', 'D'], $s);
    if (class_exists('Normalizer')) $s = (string) Normalizer::normalize($s, Normalizer::FORM_D);
    $s = preg_replace('/\p{Mn}+/u', '', $s);
    $s = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
    $words = preg_split('/[^A-Za-z0-9]+/', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return $camel ? implode('', array_map(static fn ($w) => ucfirst(strtolower($w)), $words)) : implode('-', $words);
}

/** Tên buổi ngắn: phần trước dấu "–" của tiêu đề phiếu ("Đá magma – kiến trúc…" → "Đá magma"). */
function khtd_short_title(string $title): string
{
    $t = trim((string) preg_split('/\s+[–—-]\s+/u', $title)[0]);
    return mb_substr($t, 0, 40);
}

/** <mssv>-<Ten>-Diem-Phieu<n>-<TenBuoi>-Lan<k>.pdf */
function khtd_pdf_filename(array $d): string
{
    $name = $d['mssv'] . '-' . khtd_ascii($d['name']) . '-Diem-Phieu' . (int) $d['ws']['No'] . '-' . khtd_ascii(khtd_short_title((string) $d['ws']['Title'])) . '-Lan' . (int) $d['sub']['AttemptNo'] . '.pdf';
    return preg_replace('/[^A-Za-z0-9.\-]/', '', $name);
}

/** "Học kỳ I – Năm học 2026-2027" từ classes.Semester/AcademicYear, không có thì suy từ ngày. */
function khtd_term_label(array $cls, ?string $date): string
{
    $ts = $date ? (strtotime($date) ?: time()) : time();
    $y = (int) date('Y', $ts); $m = (int) date('n', $ts);
    $year = trim((string) ($cls['AcademicYear'] ?? '')) ?: ($m >= 8 ? $y . '-' . ($y + 1) : ($y - 1) . '-' . $y);
    $sem = trim((string) ($cls['Semester'] ?? ''));
    if ($sem === '') $sem = ($m >= 8 || $m == 1) ? 'I' : ($m <= 6 ? 'II' : 'hè');
    $sem = ['1' => 'I', '2' => 'II', '3' => 'hè', 'HK1' => 'I', 'HK2' => 'II'][$sem] ?? $sem;
    return 'Học kỳ ' . $sem . ' – Năm học ' . $year;
}

/**
 * Nạp mọi thứ cần để in một bài nộp. $withRisk = bản giảng viên (kèm ước lượng nguy cơ dùng AI).
 */
function khtd_pdf_data(string $submissionId, bool $withRisk = false): array
{
    $stmt = db()->prepare(
        'SELECT sub.*, s.MSSV, s.FullName, s.Email, w.ClassID, w.No, w.Title, w.SchemaJSON, w.MaxAttempts, w.ScorePolicy, w.Status AS WsStatus, w.OpenAt, w.CloseAt, ' .
        'c.ClassCode, c.Semester, c.AcademicYear, c.LecturerID, co.CourseCode, co.CourseName, u.FullName AS GraderName ' .
        'FROM khtd_submissions sub JOIN students s ON s.StudentID = sub.StudentID JOIN khtd_worksheets w ON w.WorksheetID = sub.WorksheetID ' .
        'JOIN classes c ON c.ClassID = w.ClassID LEFT JOIN courses co ON co.CourseID = c.CourseID LEFT JOIN users u ON u.UserID = sub.GradedBy ' .
        'WHERE sub.SubmissionID = :id LIMIT 1'
    );
    $stmt->execute(['id' => $submissionId]);
    $r = $stmt->fetch();
    if (!$r) throw new RuntimeException('Không tìm thấy bài nộp.');
    $schema = khtd_schema_normalize(json_decode((string) $r['SchemaJSON'], true) ?: []);
    $answers = json_decode((string) $r['AnswersJSON'], true) ?: [];
    $ai = $r['AiJSON'] ? json_decode((string) $r['AiJSON'], true) : null;
    if (is_array($ai) && !isset($ai['criteria'])) $ai = null;
    $wsRow = ['MaxAttempts' => $r['MaxAttempts'], 'ScorePolicy' => $r['ScorePolicy'], 'Status' => $r['WsStatus'], 'OpenAt' => $r['OpenAt'], 'CloseAt' => $r['CloseAt']];
    $sum = khtd_attempt_summary($wsRow, khtd_student_attempts((string) $r['WorksheetID'], (string) $r['StudentID']), khtd_extra_attempts((string) $r['WorksheetID'], (string) $r['StudentID']));

    $lecturer = (string) ($r['GraderName'] ?? '');
    if ($lecturer === '') {
        $ids = array_filter(array_map('trim', explode(',', (string) $r['LecturerID'])));
        if ($ids) {
            $u = db()->prepare('SELECT FullName FROM users WHERE UserID = :id LIMIT 1');
            $u->execute(['id' => reset($ids)]);
            $lecturer = (string) ($u->fetchColumn() ?: '');
        }
    }
    $photos = [];
    $refs = (array) ($answers['_photos'] ?? []);
    if ($refs) {
        $p = db()->prepare('SELECT PhotoID, WorksheetID, FileName FROM khtd_photos WHERE WorksheetID = :w AND StudentID = :s');
        $p->execute(['w' => $r['WorksheetID'], 's' => $r['StudentID']]);
        foreach ($p->fetchAll() as $ph) $photos[$ph['PhotoID']] = khtd_upload_dir() . '/' . basename($ph['WorksheetID']) . '/' . basename($ph['FileName']);
    }
    return [
        'mssv' => (string) $r['MSSV'], 'name' => (string) $r['FullName'], 'email' => (string) ($r['Email'] ?? ''),
        'cls' => ['ClassCode' => $r['ClassCode'], 'Semester' => $r['Semester'], 'AcademicYear' => $r['AcademicYear'],
                  'CourseCode' => (string) ($r['CourseCode'] ?? ''), 'CourseName' => (string) ($r['CourseName'] ?? '')],
        'lecturer' => $lecturer,
        'ws' => ['No' => (int) $r['No'], 'Title' => (string) $r['Title'], 'schema' => $schema],
        'sub' => ['SubmissionID' => $r['SubmissionID'], 'AttemptNo' => (int) $r['AttemptNo'], 'Status' => $r['Status'], 'SubmittedAt' => $r['SubmittedAt'],
                  'AiScore' => $r['AiScore'], 'FinalScore' => $r['FinalScore'], 'FinalNote' => (string) ($r['FinalNote'] ?? ''), 'GradedAt' => $r['GradedAt']],
        'ai' => $ai, 'answers' => $answers, 'attempt' => $sum, 'photos' => $photos,
        'risk' => $withRisk && $r['Status'] !== 'DRAFT' ? khtd_ai_risk($r, $answers, is_array($ai) ? $ai : null, $schema) : null,
    ];
}

/** Logo Trường: bản đệm trong ../private, lần đầu tải từ link ảnh web đang dùng; lỗi → logo hoa sen của hệ thống. */
function khtd_pdf_logo(): ?string
{
    $cache = dirname(khtd_upload_dir()) . '/khtd_cache/logo-hcmus.png';
    if (is_file($cache) && filesize($cache) > 1000) return $cache;
    if (function_exists('curl_init') && !is_file($cache . '.fail') ) {
        $ch = curl_init(KHTD_PDF_LOGO_URL);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_FOLLOWLOCATION => true]);
        $bin = curl_exec($ch); $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        $info = is_string($bin) ? @getimagesizefromstring($bin) : false;
        @mkdir(dirname($cache), 0750, true);
        if ($http === 200 && $info && $info[2] === IMAGETYPE_PNG && @file_put_contents($cache, $bin) !== false) return $cache;
        @touch($cache . '.fail');                              // không thử lại mỗi lần (xoá tệp .fail để thử lại)
    }
    $fallback = dirname(__DIR__, 2) . '/images/icon-192.png';
    return is_file($fallback) ? $fallback : null;
}

/** Ảnh mẫu thu nhỏ (JPEG ~500 px) để PDF gọn; trả đường dẫn tệp tạm hoặc null. */
function khtd_pdf_thumb(KhtdPdf $pdf, string $path): ?string
{
    if (!is_file($path)) return null;
    $info = @getimagesize($path);
    if (!$info) return null;
    if (!function_exists('imagecreatefromstring')) return $info[2] === IMAGETYPE_JPEG || $info[2] === IMAGETYPE_PNG ? $path : null;
    $src = @imagecreatefromstring((string) file_get_contents($path));
    if ($src === false) return null;
    $w = imagesx($src); $h = imagesy($src); $side = min($w, $h);
    $dst = imagecreatetruecolor(500, 500);                   // cắt vuông ở giữa như ảnh thu nhỏ trên web
    imagecopyresampled($dst, $src, 0, 0, (int) (($w - $side) / 2), (int) (($h - $side) / 2), 500, 500, $side, $side);
    $tmp = tempnam(sys_get_temp_dir(), 'khtdpdf') . '.jpg';
    imagejpeg($dst, $tmp, 72);
    imagedestroy($src); imagedestroy($dst);
    $pdf->tmpFiles[] = $tmp;
    return $tmp;
}

function khtd_num(?float $v): string
{
    if ($v === null) return '';
    $s = number_format($v, 2, ',', '');
    return rtrim(rtrim($s, '0'), ',');
}

/* ------------------------------------------------------------------ */
/* Vẽ                                                                   */
/* ------------------------------------------------------------------ */

const KHTD_PDF_BLUE   = [17, 85, 204];
const KHTD_PDF_NAVY   = [12, 52, 120];
const KHTD_PDF_PURPLE = [112, 48, 160];
const KHTD_PDF_GREEN  = [56, 118, 29];
const KHTD_PDF_FILLS  = [[217, 234, 211], [207, 226, 243], [234, 209, 220], [255, 242, 204], [252, 229, 205], [217, 210, 233]];

/** Thêm "phiếu đánh giá điểm" của một bài nộp vào $pdf (bắt đầu trang mới). */
function khtd_pdf_render(KhtdPdf $pdf, array $d, bool $lecturerCopy = false): void
{
    $pdf->footerText = 'diemdanhsv.com · Phiếu ' . $d['ws']['No'] . ' · ' . $d['mssv'] . ' · lần ' . $d['sub']['AttemptNo'];
    $pdf->AddPage();
    $W = $pdf->GetPageWidth() - 30;
    $ai = $d['ai']; $sub = $d['sub']; $sum = $d['attempt'];
    $final = $sub['FinalScore'] !== null ? (float) $sub['FinalScore'] : null;
    $aiScore = $sub['AiScore'] !== null ? (float) $sub['AiScore'] : null;

    // ---------- Đầu trang ----------
    $logo = khtd_pdf_logo();
    if ($logo) { try { $pdf->Image($logo, 22, 10, 22); } catch (Throwable $e) { /* bỏ logo */ } }
    $pdf->SetXY(48, 11);
    $pdf->font(11.5, 'B', KHTD_PDF_NAVY);
    $pdf->Cell($W - 33, 6, 'TRƯỜNG ĐẠI HỌC KHOA HỌC TỰ NHIÊN, ĐHQG-HCM', 0, 2, 'C');
    $pdf->font(15, 'B', KHTD_PDF_NAVY);
    $pdf->Cell($W - 33, 7.5, 'PHIẾU ĐÁNH GIÁ ĐIỂM PHIẾU HỌC TẬP', 0, 2, 'C');
    $pdf->font(12.5, 'B', KHTD_PDF_NAVY);
    $pdf->Cell($W - 33, 6.5, khtd_term_label($d['cls'], $sub['SubmittedAt']), 0, 2, 'C');
    if ($lecturerCopy) {
        $pdf->SetXY(150, 4); $pdf->font(7, 'B', [180, 0, 0]); $pdf->Cell(45, 4, 'BẢN LƯU GIẢNG VIÊN', 0, 0, 'R');
    }
    $pdf->SetY(36);

    // ---------- 1. Sinh viên ----------
    $pdf->section('1.   PHẦN DÀNH CHO SINH VIÊN');
    $pdf->labelLine('a.', 'Họ và tên sinh viên:', mb_strtoupper($d['name']), 'B', KHTD_PDF_BLUE, 11);
    $pdf->labelLine('b.', 'Mã số sinh viên:', $d['mssv'], '', [0, 0, 0]);
    $pdf->labelLine('c.', 'Tên học phần:', $d['cls']['CourseName'] ?: 'Thực tập Khoa học Trái đất', 'B', KHTD_PDF_NAVY);
    $pdf->labelLine('d.', 'Mã học phần:', $d['cls']['CourseCode'] ?: '—', 'B', [0, 0, 0]);
    $pdf->labelLine('e.', 'Lớp:', (string) $d['cls']['ClassCode'], '', KHTD_PDF_GREEN);
    $pdf->labelLine('f.', 'Buổi học / phiếu:', 'Phiếu học tập số ' . $d['ws']['No'] . ' – ' . $d['ws']['Title'], 'B', [0, 0, 0], 9.5);
    $of = $sum['allowed'] === null ? ' (không giới hạn số lần)' : '/' . $sum['allowed'];
    $pdf->labelLine('g.', 'Lần làm bài:', 'Lần ' . $sub['AttemptNo'] . $of . ' — nộp lúc ' . (string) $sub['SubmittedAt'], '', [0, 0, 0]);

    // ---------- 2. Bảng điểm ----------
    $pdf->section('2.   BẢNG ĐIỂM CHI TIẾT');
    $pdf->font(9.5, 'B');
    $pdf->Cell(0, 6, 'Bảng điểm chi tiết Phiếu học tập số ' . $d['ws']['No'] . ' – ' . khtd_short_title($d['ws']['Title']), 0, 1, 'C');

    $crit = is_array($ai['criteria'] ?? null) ? $ai['criteria'] : array_map(static fn ($c) => $c + ['score' => null, 'comment' => ''], (array) ($d['ws']['schema']['rubric']['criteria'] ?? []));
    $nC = max(1, count($crit));
    $wMssv = 20; $wName = 34; $wTot = 19; $wC = ($W - $wMssv - $wName - $wTot) / $nC;
    $hdr = [['MSSV', $wMssv, 'C', [242, 242, 242], 'B', 8.5], ['Họ và tên', $wName, 'C', [242, 242, 242], 'B', 8.5]];
    $sub2 = [['', $wMssv, 'C', [242, 242, 242]], ['', $wName, 'C', [242, 242, 242]]];
    $vals = [[$d['mssv'], $wMssv, 'C', null, 'B', 8.5, [153, 0, 0]], [mb_strtoupper($d['name']), $wName, 'C', null, 'B', 8, [153, 0, 0]]];
    foreach (array_values($crit) as $i => $c) {
        $fill = KHTD_PDF_FILLS[$i % count(KHTD_PDF_FILLS)];
        $hdr[] = [(string) $c['name'], $wC, 'C', $fill, 'B', 7.5];
        $sub2[] = ['tối đa ' . khtd_num((float) $c['max']), $wC, 'C', $fill, '', 7];
        $vals[] = [$c['score'] === null ? '' : khtd_num((float) $c['score']), $wC, 'C', null, 'B', 9.5, KHTD_PDF_PURPLE];
    }
    $hdr[] = ['Tổng kết', $wTot, 'C', [207, 226, 243], 'B', 8.5];
    $sub2[] = ['/10', $wTot, 'C', [207, 226, 243], '', 7];
    $tot = $final ?? $aiScore;
    $vals[] = [$tot === null ? 'chưa chấm' : khtd_num($tot), $wTot, 'C', null, 'B', 10.5, $final !== null ? KHTD_PDF_GREEN : [180, 95, 6]];
    $pdf->row($hdr, 3.8);
    $pdf->row($sub2, 3.6);
    $pdf->row($vals, 5.2);
    $pdf->font(7.5, 'I', [90, 90, 90]);
    $note = $final !== null
        ? 'Tổng kết là điểm giảng viên đã duyệt' . ($aiScore !== null ? ' (trợ lý AI đề xuất ' . khtd_num($aiScore) . ')' : '') . '. Điểm từng tiêu chí do trợ lý AI chấm theo rubric, giảng viên xem lại.'
        : ($aiScore !== null ? 'Điểm TẠM TÍNH do trợ lý AI chấm theo rubric — giảng viên sẽ duyệt lại.' : 'Bài chưa có điểm.');
    $pdf->MultiCell(0, 4, $note, 0, 'L');

    // Các lần làm bài
    $rows = array_values(array_filter($sum['rows'], static fn ($r) => $r['Status'] !== 'DRAFT'));
    if (count($rows) > 1 || ($sum['allowed'] ?? 1) !== 1) {
        $pdf->caption('Các lần làm bài –', ' điểm phiếu tính theo ' . ($sum['policy'] === 'LAST' ? 'lần nộp cuối' : 'điểm cao nhất các lần') . ' (ô nền xanh: lần được tính; chữ tím: lần của phiếu này)');
        $n = min(count($rows), 10); $wl = ($W - 22) / max(1, $n);
        $h1 = []; $h2 = [];
        foreach (array_slice($rows, 0, 10) as $r) {
            $h1[] = ['Lần ' . $r['AttemptNo'], $wl, 'C', [242, 242, 242], 'B', 8.5];
            $sc = khtd_attempt_score($r);
            $h2[] = [$sc === null ? '—' : khtd_num($sc), $wl, 'C', (int) $r['AttemptNo'] === $sum['countedNo'] ? [217, 234, 211] : null, 'B', 9, (int) $r['AttemptNo'] === (int) $sub['AttemptNo'] ? KHTD_PDF_PURPLE : [0, 0, 0]];
        }
        $h1[] = ['Điểm phiếu', 22, 'C', [207, 226, 243], 'B', 8.5];
        $h2[] = [$sum['score'] === null ? '' : khtd_num($sum['score']), 22, 'C', null, 'B', 9.5, KHTD_PDF_GREEN];
        $pdf->row($h1); $pdf->row($h2, 5);
    }

    // Nhận xét từng tiêu chí
    if (is_array($ai['criteria'] ?? null) && $ai['criteria']) {
        $pdf->caption('Nhận xét theo tiêu chí', ' (trợ lý AI, giảng viên đã xem)');
        $pdf->row([['Tiêu chí', 52, 'C', [242, 242, 242], 'B', 8.5], ['Điểm', 18, 'C', [242, 242, 242], 'B', 8.5], ['Nhận xét', $W - 70, 'C', [242, 242, 242], 'B', 8.5]]);
        foreach ($ai['criteria'] as $c) {
            $pdf->row([[(string) $c['name'], 52, 'L', null, 'B', 8.5], [khtd_num((float) $c['score']) . '/' . khtd_num((float) $c['max']), 18, 'C', null, 'B', 8.5, KHTD_PDF_PURPLE],
                       [(string) ($c['comment'] ?? ''), $W - 70, 'L', null, '', 8.5]]);
        }
    }

    // Bài làm
    $pdf->caption('Bài làm của sinh viên', '');
    $a = $d['answers'];
    foreach ($d['ws']['schema']['sections'] ?? [] as $s) {
        $t = $s['type'] ?? '';
        if ($t === 'table') {
            $pdf->need(20);
            $pdf->font(8.5, 'B'); $pdf->Cell(0, 5, (string) $s['title'], 0, 1);
            $cols = (array) $s['columns']; $hasPh = !empty($s['photos']);
            $wr = 16; $wp = $hasPh ? 40.6 : 0; $wcol = ($W - $wr - $wp) / max(1, count($cols));
            $h = [['', $wr, 'C', [242, 242, 242], 'B', 7.5]];
            if ($hasPh) $h[] = ['Ảnh mẫu', $wp, 'C', [217, 234, 211], 'B', 7.5];
            foreach ($cols as $c) $h[] = [(string) $c, $wcol, 'C', [242, 242, 242], 'B', 7.5];
            $pdf->row($h, 3.6);
            foreach ((array) $s['rows'] as $ri => $label) {
                $cells = [[(string) $label, $wr, 'L', [248, 248, 248], 'B', 7.5]];
                if ($hasPh) {
                    $imgs = [];
                    foreach ((array) ($a['_photos'][$s['id']][$ri] ?? ($a['_photos'][$s['id']][(string) $ri] ?? [])) as $pid) {
                        if (isset($d['photos'][$pid]) && ($th = khtd_pdf_thumb($pdf, $d['photos'][$pid]))) $imgs[] = $th;
                    }
                    $cells[] = ['', $wp, 'L', null, '', 7.5, [0, 0, 0], $imgs ?: null];
                }
                foreach ($cols as $ci => $_) $cells[] = [(string) ($a[$s['id']][$ri][$ci] ?? ''), $wcol, 'L', null, '', 7.5];
                $pdf->row($cells, 3.6);
            }
            $pdf->Ln(1.5);
        } elseif ($t === 'questions') {
            $pdf->need(15);
            $pdf->font(8.5, 'B'); $pdf->Cell(0, 5, (string) $s['title'], 0, 1);
            foreach ((array) $s['items'] as $i => $q) {
                $pdf->para('Câu ' . ($i + 1) . '. ' . $q['text'], 8.5, 'B', [0, 0, 0], 2, 4.2);
                $pdf->para(trim((string) ($a[$q['id']] ?? '')) ?: '(để trống)', 8.5, '', KHTD_PDF_NAVY, 6, 4.2);
            }
            $pdf->Ln(1);
        } elseif ($t === 'text') {
            $pdf->para((string) $s['title'] . ': ' . (string) ($s['prompt'] ?? ''), 8.5, 'B', [0, 0, 0], 0, 4.2);
            $pdf->para(trim((string) ($a[$s['id']] ?? '')) ?: '(để trống)', 8.5, '', KHTD_PDF_NAVY, 6, 4.2);
            $pdf->Ln(1);
        } elseif ($t === 'self') {
            $v = (array) ($a[$s['id']] ?? []);
            $parts = [];
            foreach ((array) $s['items'] as $i => $it) $parts[] = $it . ': ' . (['Chưa', 'Tạm được', 'Tự tin'][$v[$i] ?? -1] ?? '—');
            $pdf->para((string) $s['title'], 8.5, 'B', [0, 0, 0], 0, 4.2);
            $pdf->para(implode(' · ', $parts), 8, '', [60, 60, 60], 6, 4);
            $pdf->Ln(1);
        }
    }

    // ---------- 3. Nhận xét giảng viên ----------
    $pdf->section('3.   PHẦN NHẬN XÉT / ĐÁNH GIÁ TỪ GIẢNG VIÊN');
    if ($d['lecturer'] !== '') $pdf->para('Giảng viên: ' . $d['lecturer'], 10, 'B', KHTD_PDF_BLUE, 8, 5.2);
    $pdf->para('Nhận xét của giảng viên:', 11, 'BI', KHTD_PDF_NAVY, 8, 6);
    $pdf->para($sub['FinalNote'] !== '' ? $sub['FinalNote'] : ($final !== null ? 'Giảng viên đã duyệt điểm, không có nhận xét thêm.' : 'Giảng viên chưa duyệt bài này.'), 9, '', [153, 0, 0], 8, 4.8);
    if (!empty($ai['feedback'])) {
        $pdf->para('Nhận xét của trợ lý AI (chấm theo rubric):', 11, 'BI', KHTD_PDF_NAVY, 8, 6);
        $pdf->para((string) $ai['feedback'], 9, '', [153, 0, 0], 8, 4.8);
    }
    if ($lecturerCopy && $d['risk']) {
        $rk = $d['risk'];
        $pdf->para('Nguy cơ dùng AI (ước lượng, chỉ để giảng viên xem lại): ' . $rk['level'] . ' – ' . $rk['score'] . '/100', 9.5, 'B', $rk['score'] >= KHTD_RISK_HIGH ? [180, 0, 0] : [120, 80, 0], 8, 5);
        if ($rk['reasons']) $pdf->para('Dấu hiệu: ' . implode('; ', $rk['reasons']), 8.5, '', [80, 80, 80], 8, 4.4);
    }
    $pdf->Ln(2);
    $pdf->para('Thắc mắc về điểm: phản hồi giảng viên trong 7 ngày kể từ khi nhận phiếu. Phiếu được tạo tự động từ diemdanhsv.com lúc ' . date('d/m/Y H:i') . '.', 7.5, 'I', [110, 110, 110], 8, 3.8);
}

/** Một bài nộp → chuỗi PDF. */
function khtd_pdf_one(string $submissionId, bool $lecturerCopy = false): array
{
    $d = khtd_pdf_data($submissionId, $lecturerCopy);
    $pdf = new KhtdPdf();
    $pdf->SetSubject('Phiếu ' . $d['ws']['No'] . ' – ' . $d['mssv'], true);
    khtd_pdf_render($pdf, $d, $lecturerCopy);
    return ['name' => khtd_pdf_filename($d), 'data' => $pdf->Output('S'), 'meta' => $d];
}

/** Trang đầu của bản "xuất tất cả bài": thống kê + danh sách cả lớp cho một phiếu (buổi). */
function khtd_pdf_cover(KhtdPdf $pdf, array $cls, array $st, string $lecturer): void
{
    $pdf->footerText = 'diemdanhsv.com · ' . $cls['ClassCode'] . ' · Phiếu ' . $st['no'] . ' · tổng hợp';
    $pdf->AddPage();
    $W = $pdf->GetPageWidth() - 30;
    $logo = khtd_pdf_logo();
    if ($logo) { try { $pdf->Image($logo, 22, 10, 22); } catch (Throwable $e) { /* bỏ logo */ } }
    $pdf->SetXY(48, 11);
    $pdf->font(11.5, 'B', KHTD_PDF_NAVY); $pdf->Cell($W - 33, 6, 'TRƯỜNG ĐẠI HỌC KHOA HỌC TỰ NHIÊN, ĐHQG-HCM', 0, 2, 'C');
    $pdf->font(14, 'B', KHTD_PDF_NAVY); $pdf->Cell($W - 33, 7.5, 'TỔNG HỢP ĐIỂM PHIẾU HỌC TẬP SỐ ' . $st['no'], 0, 2, 'C');
    $pdf->font(11, 'B', KHTD_PDF_NAVY); $pdf->Cell($W - 33, 6, khtd_short_title($st['title']) . ' · ' . khtd_term_label($cls, null), 0, 2, 'C');
    $pdf->SetXY(150, 4); $pdf->font(7, 'B', [180, 0, 0]); $pdf->Cell(45, 4, 'BẢN LƯU GIẢNG VIÊN', 0, 0, 'R');
    $pdf->SetY(36);
    $pdf->labelLine('', 'Học phần:', trim(($cls['CourseCode'] ?? '') . ' – ' . ($cls['CourseName'] ?? ''), ' –'), 'B', KHTD_PDF_NAVY);
    $pdf->labelLine('', 'Lớp:', (string) $cls['ClassCode'] . ($lecturer !== '' ? '   ·   Giảng viên: ' . $lecturer : ''), '', [0, 0, 0]);
    $pdf->labelLine('', 'Phiếu:', $st['title'] . ' (' . ($st['maxAttempts'] === 0 ? 'không giới hạn số lần' : $st['maxAttempts'] . ' lần nộp') . ', điểm ' . ($st['scorePolicy'] === 'LAST' ? 'lần cuối' : 'cao nhất') . ')', '', [0, 0, 0]);

    $pdf->section('Thống kê nhanh');
    $sb = $st['submissions']; $sc = $st['scores'];
    $cells = [
        ['Sĩ số' . "\n" . $st['enrolled'], 0, 'C', [242, 242, 242], 'B', 9],
        ['Đã nộp bài' . "\n" . $st['studentsSubmitted'] . '/' . $st['enrolled'], 0, 'C', [217, 234, 211], 'B', 9],
        ['Tổng bài nộp' . "\n" . $sb['total'], 0, 'C', [207, 226, 243], 'B', 9],
        ['GV đã chấm' . "\n" . $sb['final'], 0, 'C', [217, 234, 211], 'B', 9],
        ['Chỉ AI chấm' . "\n" . $sb['aiOnly'], 0, 'C', [255, 242, 204], 'B', 9],
        ['Chưa có điểm' . "\n" . $sb['ungraded'], 0, 'C', [244, 204, 204], 'B', 9],
        ['Nguy cơ AI ≥ TB' . "\n" . count($st['aiRisk']), 0, 'C', [234, 209, 220], 'B', 9],
    ];
    foreach ($cells as &$c) $c[1] = $W / count($cells);
    unset($c);
    $pdf->row($cells, 4.6);
    $line = 'Điểm (theo lần được tính): ';
    if ($sc['count']) {
        $nm = static fn ($arr) => implode(', ', array_map(static fn ($x) => $x['name'] . ' (' . $x['mssv'] . ')', array_slice($arr, 0, 3))) . (count($arr) > 3 ? ' …' : '');
        $line .= 'trung bình ' . khtd_num($sc['avg']) . ' · trung vị ' . khtd_num((float) $sc['median'])
               . ' · cao nhất ' . khtd_num($sc['max']['score']) . ' – ' . $nm($sc['max']['students'])
               . ' · thấp nhất ' . khtd_num($sc['min']['score']) . ' – ' . $nm($sc['min']['students']) . '.';
        $line .= ' Phân bố: ' . implode(', ', array_map(static fn ($k, $v) => $k . ': ' . $v, array_keys($sc['buckets']), $sc['buckets'])) . '.';
    } else {
        $line .= 'chưa có bài nào có điểm.';
    }
    $pdf->Ln(1);
    $pdf->para($line, 8.5, '', [0, 0, 0], 0, 4.4);

    $pdf->section('Danh sách sinh viên');
    $w = [9, 19, 44, 11, 13, 24, 22, $W - 142];
    $pdf->row([['STT', $w[0], 'C', [242, 242, 242], 'B', 8], ['MSSV', $w[1], 'C', [242, 242, 242], 'B', 8], ['Họ và tên', $w[2], 'C', [242, 242, 242], 'B', 8],
               ['Số lần', $w[3], 'C', [242, 242, 242], 'B', 8], ['Điểm', $w[4], 'C', [207, 226, 243], 'B', 8], ['Trạng thái', $w[5], 'C', [242, 242, 242], 'B', 8],
               ['Nguy cơ AI', $w[6], 'C', [234, 209, 220], 'B', 8], ['Ghi chú (bài đặc biệt)', $w[7], 'C', [242, 242, 242], 'B', 8]]);
    foreach ($st['roster'] as $i => $r) {
        $rk = $r['risk'];
        $pdf->row([[(string) ($i + 1), $w[0], 'C', null, '', 8], [$r['mssv'], $w[1], 'C', null, '', 8], [$r['name'], $w[2], 'L', null, '', 8],
                   [$r['used'] ? (string) $r['used'] : '', $w[3], 'C', null, '', 8],
                   [$r['score'] === null ? '' : khtd_num((float) $r['score']), $w[4], 'C', null, 'B', 8.5, $r['final'] ? KHTD_PDF_GREEN : [180, 95, 6]],
                   [$r['state'], $w[5], 'L', null, '', 7.5],
                   [$rk ? $rk['level'] . ' (' . $rk['score'] . ')' : '', $w[6], 'C', $rk && $rk['score'] >= KHTD_RISK_HIGH ? [244, 204, 204] : ($rk && $rk['score'] >= KHTD_RISK_MID ? [255, 242, 204] : null), '', 7.5],
                   [implode('; ', $r['tags']), $w[7], 'L', null, '', 7]], 3.8);
    }
    $pdf->Ln(1);
    $pdf->para('Điểm xanh: giảng viên đã duyệt; điểm cam: trợ lý AI tạm tính. "Nguy cơ AI" là ước lượng tự động (dán nội dung, tốc độ viết, văn phong, ước lượng của Gemini) — chỉ để giảng viên xem lại, không phải kết luận. Các trang sau: phiếu đánh giá điểm của từng sinh viên (lần được tính điểm).', 7.5, 'I', [90, 90, 90], 0, 3.8);
}

