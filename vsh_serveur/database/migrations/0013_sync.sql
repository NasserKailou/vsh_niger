-- 0013 — Synchronisation Offline/Online (voir rapport §E)

-- Idempotence : une opération (device, op_id) n'est exécutée qu'une fois ; un renvoi retourne le résultat enregistré.
CREATE TABLE sync_operations (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    device_id    BIGINT UNSIGNED NOT NULL,
    op_id        CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    user_id      BIGINT UNSIGNED NOT NULL,
    entity       VARCHAR(50) NOT NULL,
    entity_uuid  CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    operation    VARCHAR(10) NOT NULL,
    action_name  VARCHAR(50) NULL COMMENT 'Pour operation = ACTION, ex. homecare.accept',
    base_version INT UNSIGNED NULL,
    status       VARCHAR(10) NOT NULL,
    result       JSON NULL COMMENT 'Réponse renvoyée à l''appareil (server_id, version, erreurs)',
    client_created_at DATETIME NULL,
    received_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sync_operations_op (device_id, op_id),
    KEY ix_sync_operations_status (status, received_at),
    KEY ix_sync_operations_entity (entity, entity_uuid),
    KEY ix_sync_operations_user (user_id, received_at),
    CONSTRAINT fk_sync_operations_device FOREIGN KEY (device_id) REFERENCES devices (id) ON DELETE RESTRICT,
    CONSTRAINT fk_sync_operations_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT ck_sync_operations_operation CHECK (operation IN ('CREATE','UPDATE','DELETE','ACTION')),
    CONSTRAINT ck_sync_operations_status CHECK (status IN ('APPLIED','CONFLICT','REJECTED'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Journal des changements pour le PULL incrémental (curseur = seq).
-- Colonnes de portée : patient_id / team_id / user_id NULL partout = donnée de référence visible de tous.
CREATE TABLE sync_changes (
    seq         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    entity      VARCHAR(50) NOT NULL,
    entity_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    operation   VARCHAR(10) NOT NULL,
    patient_id  BIGINT UNSIGNED NULL,
    team_id     BIGINT UNSIGNED NULL,
    user_id     BIGINT UNSIGNED NULL,
    changed_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (seq),
    KEY ix_sync_changes_patient (patient_id, seq),
    KEY ix_sync_changes_team (team_id, seq),
    KEY ix_sync_changes_user (user_id, seq),
    KEY ix_sync_changes_entity (entity, seq),
    CONSTRAINT ck_sync_changes_operation CHECK (operation IN ('UPSERT','DELETE'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sync_conflicts (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid           CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    sync_operation_id BIGINT UNSIGNED NOT NULL,
    user_id        BIGINT UNSIGNED NOT NULL,
    entity         VARCHAR(50) NOT NULL,
    entity_uuid    CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    conflicting_fields JSON NULL,
    client_payload JSON NOT NULL,
    server_state   JSON NOT NULL,
    status         VARCHAR(20) NOT NULL DEFAULT 'OUVERT',
    resolved_by    BIGINT UNSIGNED NULL,
    resolved_at    DATETIME NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sync_conflicts_uuid (uuid),
    KEY ix_sync_conflicts_status (status, created_at),
    KEY ix_sync_conflicts_user (user_id, status),
    CONSTRAINT fk_sync_conflicts_operation FOREIGN KEY (sync_operation_id) REFERENCES sync_operations (id) ON DELETE RESTRICT,
    CONSTRAINT fk_sync_conflicts_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_sync_conflicts_resolved_by FOREIGN KEY (resolved_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT ck_sync_conflicts_status CHECK (status IN ('OUVERT','RESOLU_SERVEUR','RESOLU_CLIENT','RESOLU_FUSION')),
    CONSTRAINT ck_sync_conflicts_resolved CHECK (status = 'OUVERT' OR (resolved_by IS NOT NULL AND resolved_at IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
