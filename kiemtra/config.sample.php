<?php
// KIEMTRA KHÔNG cần file cấu hình riêng: nó đọc CHUNG file bí mật của diemdanhsv.com
//   domains/diemdanhsv.com/private/config.php   (đã có mục 'db')
// Chỉ khi muốn đổi mặc định, thêm mục 'kiemtra' vào CHÍNH file đó, ví dụ:
return [
    // 'db' => [...],            // đã có sẵn của site chính — giữ nguyên
    'kiemtra' => [
        // Thư mục lưu ảnh câu hỏi, NGOÀI public_html. Mặc định: private/kiemtra-uploads
        // 'upload_dir' => '/home/u000000000/domains/diemdanhsv.com/private/kiemtra-uploads',
    ],
];
