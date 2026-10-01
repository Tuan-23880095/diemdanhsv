<?php
// db/config.sample.php — MẪU cho ../private/config.php, KHÔNG chứa bí mật thật.
// Copy file này sang domains/<domain>/private/config.php trên Hostinger
// (NGOÀI public_html — rule 4 của dự án) rồi điền giá trị thật ở đó.
// KHÔNG BAO GIỜ commit file có bí mật thật vào repo.
//
// Xem docs/04-API-PHP.md mục 8 để biết mỗi mục được dùng ở đâu.
return [
    'db' => [
        'host'    => 'localhost',
        'name'    => 'u000000000_dbname',   // đổi theo CSDL thật trên hPanel
        'user'    => 'u000000000_dbuser',
        'pass'    => 'CHANGE_ME',
        'charset' => 'utf8mb4',
    ],
    'smtp' => [
        'host' => 'smtp.hostinger.com',
        'port' => 465,
        'user' => 'noreply@diemdanhsv.com', // GĐ3: dùng stub cho đến khi thầy tạo hộp thư thật
        'pass' => 'CHANGE_ME',
    ],
    'app' => [
        'timezone'             => 'Asia/Ho_Chi_Minh',
        'code_length'          => 4,
        'code_alphabet'        => 'ACDEFGHJKMNPQRTUVWXY34679', // bỏ 0/O,1/I/L,2/Z,5/S,8/B — giữ nguyên bộ ký tự cũ
        'present_minutes'      => 5,
        'window_minutes'       => 15,
        'gps_accuracy_limit_m' => 150,
        'default_radius_m'     => 100,
        // Stub mail (GĐ3): true = ghi cả thân email (có mã xem điểm) vào error_log.
        // CHỈ bật trên CSDL thử — KHÔNG bật ở host thật (docs/05-GD5-smoke-review.md, H2).
        'mail_stub_log_body'   => false,
    ],
];
