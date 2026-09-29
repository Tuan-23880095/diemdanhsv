<?php
declare(strict_types=1);

/**
 * api/lib/config.php — Nạp bí mật từ ../private/config.php, NẰM NGOÀI
 * public_html (rule 4 của dự án). Repo chỉ commit db/config.sample.php với
 * placeholder — KHÔNG BAO GIỜ commit file thật (docs/04-API-PHP.md mục 8).
 *
 * Cấu trúc thư mục mong đợi trên Hostinger:
 *   domains/<domain>/public_html/api/lib/config.php   (file này)
 *   domains/<domain>/private/config.php               (bí mật thật)
 * → private/ là ANH EM của public_html/, không phải con của nó.
 */
function app_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    // __DIR__ = .../public_html/api/lib
    // dirname(__DIR__, 2) = .../public_html  →  + /../private/config.php
    // = .../private/config.php (anh em của public_html, đúng rule 4).
    $path = dirname(__DIR__, 2) . '/../private/config.php';

    if (!is_file($path)) {
        throw new RuntimeException(
            'Chưa có file cấu hình bí mật (' . basename($path) . '). ' .
            'Xem mẫu tại db/config.sample.php — copy sang ../private/config.php ' .
            'trên host thật rồi điền giá trị (KHÔNG commit file thật vào repo).'
        );
    }

    $loaded = require $path;
    if (!is_array($loaded)) {
        throw new RuntimeException('File cấu hình bí mật không hợp lệ (phải return một mảng PHP).');
    }

    $config = $loaded;
    return $config;
}
