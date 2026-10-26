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
    // SMTP gửi mã xem điểm (api/lib/mailer.php, docs/04 mục 25). pass còn
    // 'CHANGE_ME'/trống = chế độ STUB (không gửi, chỉ ghi error_log).
    // Thử: php tools/mail_test.php --to=<email của thầy>
    'smtp' => [
        'host'      => 'smtp.hostinger.com',
        'port'      => 465,                      // 465 TLS ngầm (khuyên dùng) | 587 STARTTLS
        'user'      => 'noreply@diemdanhsv.com', // = địa chỉ hộp thư tạo trong hPanel → Emails
        'pass'      => 'CHANGE_ME',
        // 'from'      => 'noreply@diemdanhsv.com', // mặc định = user
        // 'from_name' => 'Hệ thống điểm danh & xem điểm',
        // 'tls'       => 'implicit',             // hoặc 'starttls'; mặc định theo cổng
        // 'cafile'    => '/path/ca-bundle.crt',  // chỉ khi host không xác minh được chứng chỉ
    ],
    'gemini' => [
        'api_key' => '',                         // Google AI Studio key — để trống = không chấm AI (GĐ11 khtd)
        // ĐỂ TRỐNG 'model'/'models' là tốt nhất: code tự hỏi Google xem key dùng được model nào
        // (ListModels) rồi ưu tiên bản flash mới nhất — không hỏng khi Google đổi tên model.
        // Chỉ điền khi muốn ép dùng đúng một model: 'model' => 'gemini-2.5-flash',
    ],
    'app' => [
        'timezone'             => 'Asia/Ho_Chi_Minh',
        'code_length'          => 4,
        'code_alphabet'        => 'ACDEFGHJKMNPQRTUVWXY34679', // bỏ 0/O,1/I/L,2/Z,5/S,8/B — giữ nguyên bộ ký tự cũ
        'present_minutes'      => 5,
        'window_minutes'       => 15,
        // M5: trần số phút mở mã điểm danh (presentMinutes/windowMinutes bị kẹp 1–trần).
        'max_window_minutes'   => 60,
        // M4 — giới hạn tần suất theo IP / tên đăng nhập (api/lib/ratelimit.php,
        // docs/04-API-PHP.md mục 19). Bỏ mục này thì dùng đúng các mặc định dưới;
        // limit = 0 là tắt bucket đó. Cửa sổ cố định tính bằng giây.
        'rate_limits' => [
            'login_ip'         => ['limit' => 20, 'window_sec' => 900],  // login SAI / IP
            'login_user'       => ['limit' => 10, 'window_sec' => 900],  // login SAI / tên đăng nhập
            // Các bucket *_ip dưới tính chung cho MỌI SV của trường: cả trường ra Internet
            // bằng cùng một IP Wi-Fi, nên số được đặt cho 2–3 lớp × 50 SV học song song.
            // Đừng hạ về số cũ (60/30/30/600) — lớp thứ hai sẽ bị chặn oan.
            'checkin_ip'       => ['limit' => 120, 'window_sec' => 600],  // mã điểm danh SAI / IP (trước 60)
            'gradecode_req_ip' => ['limit' => 30, 'window_sec' => 900],  // xin mã xem điểm (mọi lượt) / IP
            'gradecode_ver_ip' => ['limit' => 50, 'window_sec' => 900],  // nhập mã xem điểm SAI / IP
            'khtd_login_ip'    => ['limit' => 60, 'window_sec' => 600],  // đăng nhập phiếu online SAI / IP (GĐ11; trước 30)
            'khtd_submit_ip'   => ['limit' => 800, 'window_sec' => 600], // nộp phiếu / IP (GĐ11; trước 30 rồi 400)
            'khtd_submit_student' => ['limit' => 8, 'window_sec' => 600], // nộp phiếu / SINH VIÊN — KHÔNG nới
            'khtd_upload_ip'   => ['limit' => 2000, 'window_sec' => 600], // tải ảnh mẫu / IP (GĐ11e; trước 600)
            'tvts_submit_ip'   => ['limit' => 150, 'window_sec' => 600],  // TVTS02 nộp bài / IP (trước 60)
            'tvts1_save_ip'    => ['limit' => 600, 'window_sec' => 600],  // TVTS01 lưu Bước 1 / IP (trước 300)
            // Phòng tương tác trang trình chiếu (docs/04 mục 36) — bỏ trống dùng mặc định trong api/lib/ratelimit.php:
            // 'live_submit_ip' => ['limit' => 3000, 'window_sec' => 600],  // gửi câu trả lời / IP (3 lớp × 50 SV chung Wi-Fi)
        ],
        // Phòng tương tác trang trình chiếu dkv/ hết hạn sau bao nhiêu giờ (1–12, mặc định 4).
        'live_room_hours'      => 4,
        'gps_accuracy_limit_m' => 150,
        'default_radius_m'     => 100,
        // Stub mail (GĐ3): true = ghi cả thân email (có mã xem điểm) vào error_log.
        // CHỈ bật trên CSDL thử — KHÔNG bật ở host thật (docs/05-GD5-smoke-review.md, H2).
        'mail_stub_log_body'   => false,
        // GĐ8 — công thức chuyên cần (thầy chốt 02/10/2026). Bỏ mục này thì dùng
        // đúng các giá trị mặc định dưới đây (api/lib/grading.php attendance_rules()).
        'attendance_rules' => [
            'absent_penalty'      => 3,     // vắng không phép −3
            'excused_penalty'     => 1.5,   // vắng có phép −1,5
            'late_penalty'        => 1,     // trễ −1
            'late_per_absence'    => 3,     // 3 trễ = 1 vắng (xét cấm thi)
            'excused_per_absence' => 2,     // 2 có phép = 1 vắng (xét cấm thi)
            'ban_threshold'       => 3,     // ≥ 3 vắng tương đương → cấm thi
            'column_name'         => 'Chuyên cần',
            'column_weight'       => 10,    // % tổng điểm
            // L11: SV ghi danh MUỘN không bị tính vắng các buổi trước ngày ghi danh
            // (chỉ khi ngày ghi danh muộn hơn ngày ghi danh sớm nhất của lớp — cả lớp
            // nạp cùng ngày thì không ai muộn). false = tính như bản GAS cũ.
            'count_from_enrollment' => true,
        ],
    ],
];
