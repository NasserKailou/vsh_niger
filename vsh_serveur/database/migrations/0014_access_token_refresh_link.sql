-- 0014 — Rattache chaque jeton d'accès à son refresh token : la révocation d'une session
-- (déconnexion, vol détecté, appareil révoqué) invalide précisément ses jetons d'accès.

ALTER TABLE access_tokens
    ADD COLUMN refresh_token_id BIGINT UNSIGNED NULL AFTER device_id,
    ADD KEY ix_access_tokens_refresh (refresh_token_id),
    ADD CONSTRAINT fk_access_tokens_refresh FOREIGN KEY (refresh_token_id) REFERENCES refresh_tokens (id) ON DELETE CASCADE;
