-- 0016 — Dossiers « épinglés » par un utilisateur pour être disponibles hors ligne sur ses appareils,
-- et index utilisé pour calculer le périmètre de synchronisation (dossiers créés récemment par l'utilisateur).

CREATE TABLE sync_patient_subscriptions (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    BIGINT UNSIGNED NOT NULL,
    patient_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sync_patient_subscriptions (user_id, patient_id),
    KEY ix_sync_patient_subscriptions_patient (patient_id),
    CONSTRAINT fk_sync_patient_subscriptions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_sync_patient_subscriptions_patient FOREIGN KEY (patient_id) REFERENCES patients (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE patients ADD KEY ix_patients_created_by (created_by, created_at);
