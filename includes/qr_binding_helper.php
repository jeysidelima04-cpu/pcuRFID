<?php

declare(strict_types=1);

function qr_binding_enabled(): bool {
    return filter_var(env('QR_FACE_BINDING_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN);
}

function qr_binding_strict(): bool {
    return filter_var(env('QR_FACE_BINDING_STRICT', 'true'), FILTER_VALIDATE_BOOLEAN);
}

function qr_guard_session_hash(): string {
    return hash('sha256', session_id());
}

function qr_datetime_is_expired(?string $dt): bool {
    if (!$dt) {
        return true;
    }
    $ts = strtotime($dt);
    if ($ts === false) {
        return true;
    }
    return $ts <= time();
}

function ensure_qr_binding_tables(PDO $pdo): void {
    // Tables are now created via migrations (002_add_runtime_tables.sql).
    // This function is kept for backward compatibility but no longer creates tables.
    // If tables are missing, run: php scripts/validate_schema.php
    $check = $pdo->query("SHOW TABLES LIKE 'qr_scan_challenges'")->fetch();
    if (!$check) {
        error_log('[PCU RFID] QR binding tables not found. Run migrations first.');
    }
}

function qr_binding_expire_stale_rows(PDO $pdo): void {
    $chRows = $pdo->query("SELECT id, expires_at FROM qr_scan_challenges WHERE status = 'active'")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($chRows as $row) {
        if (qr_datetime_is_expired((string)($row['expires_at'] ?? ''))) {
            $pdo->prepare("UPDATE qr_scan_challenges SET status = 'expired' WHERE id = ? AND status = 'active'")
                ->execute([(int)$row['id']]);
        }
    }

    $pendingRows = $pdo->query("SELECT id, expires_at FROM qr_face_pending WHERE status = 'pending'")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($pendingRows as $row) {
        if (qr_datetime_is_expired((string)($row['expires_at'] ?? ''))) {
            $pdo->prepare("UPDATE qr_face_pending SET status = 'expired', resolved_at = NOW() WHERE id = ? AND status = 'pending'")
                ->execute([(int)$row['id']]);
        }
    }
}

function qr_binding_get_pending(PDO $pdo, string $guardSessionHash): ?array {
    $stmt = $pdo->prepare("SELECT * FROM qr_face_pending WHERE guard_session_hash = ? AND status = 'pending' ORDER BY id DESC LIMIT 5");
    $stmt->execute([$guardSessionHash]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        if (!qr_datetime_is_expired((string)($row['expires_at'] ?? ''))) {
            return $row;
        }

        $pdo->prepare("UPDATE qr_face_pending SET status = 'expired', resolved_at = NOW() WHERE id = ? AND status = 'pending'")
            ->execute([(int)$row['id']]);
    }

    return null;
}

function qr_binding_clear_pending(PDO $pdo, string $guardSessionHash, string $reason = 'manual_clear'): int {
    $stmt = $pdo->prepare("UPDATE qr_face_pending
        SET status = 'rejected', reject_reason = ?, resolved_at = NOW()
        WHERE guard_session_hash = ? AND status = 'pending'");
    $stmt->execute([$reason, $guardSessionHash]);
    return $stmt->rowCount();
}

function qr_binding_log_event(PDO $pdo, string $eventType, ?int $userId = null, ?string $studentId = null, ?string $challengeId = null, ?string $tokenHash = null, array $details = []): void {
    try {
        $stmt = $pdo->prepare("INSERT INTO qr_security_events
            (event_type, guard_session_hash, guard_username, user_id, student_id, challenge_id, token_hash, details_json)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $eventType,
            qr_guard_session_hash(),
            (string)($_SESSION['security_username'] ?? 'Unknown'),
            $userId,
            $studentId,
            $challengeId,
            $tokenHash,
            empty($details) ? null : json_encode($details)
        ]);
    } catch (Throwable $e) {
        error_log('qr_binding_log_event error: ' . $e->getMessage());
    }
}
