<?php
declare(strict_types=1);

/**
 * api/lib/audit.php — Ghi audit_log, D.8 lớp 6 BẮT BUỘC cho mọi action ghi
 * (docs/04-API-PHP.md mục 7). Lỗi ghi log KHÔNG được làm hỏng nghiệp vụ
 * chính — giống logAudit() cũ có try/catch riêng, không ném lỗi ra ngoài
 * (gas/02-Repo.gs).
 *
 * Scaffolding cho GĐ3+ — action `ping` (GĐ2) không ghi audit vì không phải
 * thao tác ghi (docs/04-API-PHP.md mục 5, bảng GET, cột "Bảng chính": —).
 */
function log_audit(
    ?string $actor,
    ?string $actorRole,
    string $action,
    ?string $targetType = null,
    ?string $targetId = null,
    $data = null
): void {
    try {
        $stmt = db()->prepare(
            'INSERT INTO audit_log (LogID, Actor, ActorRole, Action, TargetType, TargetID, `Data`, IP) ' .
            'VALUES (:id, :actor, :role, :action, :type, :target, :data, :ip)'
        );
        $stmt->execute([
            'id'     => new_id('LOG'),
            'actor'  => $actor,
            'role'   => $actorRole,
            'action' => $action,
            'type'   => $targetType,
            'target' => $targetId,
            'data'   => $data !== null ? json_encode($data, JSON_UNESCAPED_UNICODE) : null,
            'ip'     => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $e) {
        // Nuốt lỗi có chủ đích — không để việc ghi log làm hỏng request chính.
        error_log('log_audit() thất bại: ' . $e->getMessage());
    }
}

/** Sinh ID duy nhất có tiền tố — giữ đúng quy ước newId() cũ (gas/00-Config.gs),
 *  không dùng số dòng làm ID (thiết kế C.1). */
function new_id(string $prefix): string
{
    return $prefix . '_' . strtoupper(substr(bin2hex(random_bytes(8)), 0, 12));
}
