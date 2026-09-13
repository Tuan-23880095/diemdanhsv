<#
    push-len-github.ps1 — Đẩy dự án Web điểm danh lên GitHub

    CÁCH DÙNG: mở PowerShell, chạy:
        cd D:\2026-Web-DayKemToan\Web-diem-danh
        powershell -ExecutionPolicy Bypass -File .\push-len-github.ps1

    Script tự kiểm tra an toàn TRƯỚC khi commit, và dừng lại cho thầy
    xem danh sách file rồi mới đẩy. Chạy lại nhiều lần được: lần sau
    nó chỉ commit phần thay đổi.
#>

$ErrorActionPreference = 'Stop'
Set-Location -Path $PSScriptRoot

$REPO = 'https://github.com/Tuan-23880095/diemdanhsv.git'

function Fail($msg) {
    Write-Host ""
    Write-Host "DUNG LAI: $msg" -ForegroundColor Red
    Write-Host "Khong co gi duoc commit hay day len GitHub." -ForegroundColor Red
    exit 1
}

Write-Host "=== DAY DU AN LEN GITHUB ===" -ForegroundColor Cyan
Write-Host "Thu muc: $PSScriptRoot"
Write-Host ""

# --- 0. Co git chua? ---
try { $v = git --version } catch { Fail "Chua cai git. Tai tai https://git-scm.com roi mo lai PowerShell." }
Write-Host "[OK] $v"

# --- 1. Khong duoc co file du lieu sinh vien trong thu muc ---
$dataFiles = Get-ChildItem -Recurse -File -Include *.csv, *.xlsx, *.xls -ErrorAction SilentlyContinue |
             Where-Object { $_.FullName -notmatch '\\\.git\\' }
if ($dataFiles) {
    Write-Host ""
    Write-Host "Tim thay file du lieu trong thu muc du an:" -ForegroundColor Yellow
    $dataFiles | ForEach-Object { Write-Host "   $($_.FullName)" -ForegroundColor Yellow }
    Fail "Chuyen cac file nay ra ngoai thu muc du an truoc. Danh sach sinh vien KHONG duoc len GitHub (repo nay la public)."
}
Write-Host "[OK] Khong co file .csv/.xlsx/.xls trong thu muc"

# --- 2. Khong duoc con mat khau tho trong ma nguon ---
$pwPatterns = @(
    "password\s*:\s*'(?!MAT_KHAU_THAT)[^']{3,}'",   # ACCOUNTS_TO_CREATE trong 03-Auth.gs
    "PASSWORD\s*=\s*'(?!DOI_MAT_KHAU_NAY)[^']{3,}'" # runSmokeTest trong 06-Seed.gs
)
$pwHits = Get-ChildItem -Recurse -File -Include *.gs, *.js, *.html, *.md -ErrorAction SilentlyContinue |
          Where-Object { $_.FullName -notmatch '\\\.git\\' } |
          Select-String -Pattern $pwPatterns -ErrorAction SilentlyContinue
if ($pwHits) {
    Write-Host ""
    Write-Host "Tim thay mat khau dang ro trong ma nguon:" -ForegroundColor Yellow
    $pwHits | ForEach-Object { Write-Host "   $($_.Path) : dong $($_.LineNumber)" -ForegroundColor Yellow }
    Fail "Xoa mat khau (dua ACCOUNTS_TO_CREATE ve mang rong) roi chay lai. Da commit la nam trong lich su git vinh vien."
}
Write-Host "[OK] Khong con mat khau tho trong ma nguon"

# --- 3. Khoi tao git neu chua co ---
if (-not (Test-Path '.git')) {
    git init -b main | Out-Null
    Write-Host "[OK] Da khoi tao git (nhanh main)"
} else {
    Write-Host "[OK] Thu muc da la git repo"
}

# --- 4. Gan remote (bo qua neu da co) ---
$hasRemote = git remote 2>$null | Select-String -Pattern '^origin$' -Quiet
if (-not $hasRemote) {
    git remote add origin $REPO
    Write-Host "[OK] Da gan remote origin"
} else {
    Write-Host "[OK] Remote origin da co"
}

# --- 5. Dua file vao va CHO THAY DUYET ---
git add -A
$staged = git diff --cached --name-only
if (-not $staged) {
    Write-Host ""
    Write-Host "Khong co thay doi nao de commit. Xong." -ForegroundColor Green
    exit 0
}

Write-Host ""
Write-Host "=== $($staged.Count) FILE SE DUOC DAY LEN (public) ===" -ForegroundColor Cyan
$staged | ForEach-Object { Write-Host "   $_" }
Write-Host ""
Write-Host "Xem ky danh sach tren. Khong duoc co file danh sach sinh vien." -ForegroundColor Yellow
$answer = Read-Host "Dung thi go OK roi Enter (bat cu gi khac la huy)"
if ($answer -ne 'OK') { Fail "Thay da huy." }

# --- 6. Commit va day len ---
$msg = Read-Host "Mo ta lan thay doi nay (Enter de dung mac dinh)"
if ([string]::IsNullOrWhiteSpace($msg)) { $msg = "Cap nhat he thong diem danh" }

git commit -m $msg | Out-Null
Write-Host "[OK] Da commit: $msg"

Write-Host ""
Write-Host "Dang day len GitHub — cua so dang nhap GitHub co the hien ra..." -ForegroundColor Cyan
git push -u origin main

Write-Host ""
Write-Host "=== XONG ===" -ForegroundColor Green
Write-Host "Repo:      https://github.com/Tuan-23880095/diemdanhsv"
Write-Host ""
Write-Host "Buoc cuoi (chi lam 1 lan): bat GitHub Pages" -ForegroundColor Cyan
Write-Host "  Settings > Pages > Source: Deploy from a branch > main > / (root) > Save"
Write-Host ""
Write-Host "Cho 1-2 phut roi mo:"
Write-Host "  Sinh vien:  https://tuan-23880095.github.io/diemdanhsv/pages/student.html"
Write-Host "  Giang vien: https://tuan-23880095.github.io/diemdanhsv/pages/lecturer.html"
