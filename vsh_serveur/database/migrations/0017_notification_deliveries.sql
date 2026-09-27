-- 0017 — Envoi des notifications par push (FCM) et par SMS (D-011).
-- Boîte d'envoi : chaque envoi est enregistré dans la même transaction que la notification, puis
-- traité hors requête par « php bin/console.php notifications:dispatch ». La notification in-app
-- reste la source de vérité : un envoi en échec ne fait rien perdre.

CREATE TABLE notification_deliveries (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    notification_id BIGINT UNSIGNED NOT NULL,
    channel         VARCHAR(10)  NOT NULL,
    status          VARCHAR(10)  NOT NULL DEFAULT 'PENDING',
    attempts        TINYINT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at DATETIME NOT NULL,
    last_error      VARCHAR(255) NULL COMMENT 'Motif technique, jamais le contenu du message',
    sent_at         DATETIME NULL,
    created_at      DATETIME NOT NULL,
    updated_at      DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_notification_deliveries (notification_id, channel),
    KEY ix_notification_deliveries_queue (status, next_attempt_at),
    CONSTRAINT fk_notification_deliveries_notification FOREIGN KEY (notification_id) REFERENCES notifications (id) ON DELETE CASCADE,
    CONSTRAINT ck_notification_deliveries_channel CHECK (channel IN ('PUSH','SMS')),
    CONSTRAINT ck_notification_deliveries_status CHECK (status IN ('PENDING','SENT','FAILED','SKIPPED'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
