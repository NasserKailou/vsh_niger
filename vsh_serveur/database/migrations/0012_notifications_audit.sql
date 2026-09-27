-- 0012 — Notifications et journal d'audit

-- Source de vérité des notifications : fonctionne sans push (récupérées à la synchronisation).
-- title/body ne contiennent jamais de donnée médicale.
CREATE TABLE notifications (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid        CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    user_id     BIGINT UNSIGNED NOT NULL,
    notif_type  VARCHAR(50)  NOT NULL COMMENT 'Ex. APPOINTMENT_CONFIRMED, HOMECARE_STATUS, EXAM_RESULT, INVOICE_ISSUED',
    title       VARCHAR(190) NOT NULL,
    body        VARCHAR(500) NULL,
    entity_type VARCHAR(50)  NULL,
    entity_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    pushed_at   DATETIME NULL,
    read_at     DATETIME NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_notifications_uuid (uuid),
    KEY ix_notifications_user (user_id, read_at, created_at),
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE push_tokens (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    BIGINT UNSIGNED NOT NULL,
    device_id  BIGINT UNSIGNED NOT NULL,
    provider   VARCHAR(10)  NOT NULL,
    token      VARCHAR(512) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_push_tokens_device (device_id, provider),
    KEY ix_push_tokens_user (user_id),
    CONSTRAINT fk_push_tokens_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_push_tokens_device FOREIGN KEY (device_id) REFERENCES devices (id) ON DELETE CASCADE,
    CONSTRAINT ck_push_tokens_provider CHECK (provider IN ('FCM','APNS'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Journal d'audit : sans clé étrangère (doit survivre à toute modification), jamais de mot de passe, jeton ni code OTP.
CREATE TABLE audit_logs (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     BIGINT UNSIGNED NULL,
    action      VARCHAR(50) NOT NULL COMMENT 'Ex. CREATE, UPDATE, DELETE, VIEW, LOGIN, LOGIN_FAILED, VALIDATE, EXPORT',
    entity_type VARCHAR(50) NULL,
    entity_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    old_values  JSON NULL,
    new_values  JSON NULL,
    ip_address  VARCHAR(45)  NULL,
    user_agent  VARCHAR(255) NULL,
    device_id   BIGINT UNSIGNED NULL,
    request_id  CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL COMMENT 'Corrélation avec les logs techniques',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_audit_logs_entity (entity_type, entity_uuid, created_at),
    KEY ix_audit_logs_user (user_id, created_at),
    KEY ix_audit_logs_action (action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
