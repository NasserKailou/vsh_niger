-- 0001 — Identité, rôles, permissions, appareils, jetons, OTP
-- Conventions : voir docs/02_BASE_DE_DONNEES.md

CREATE TABLE users (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid                 CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    account_type         VARCHAR(20)  NOT NULL,
    phone                VARCHAR(20)  NOT NULL COMMENT 'Format E.164, ex. +22790000000',
    email                VARCHAR(190) NULL,
    password_hash        VARCHAR(255) NULL COMMENT 'NULL si accès par OTP uniquement',
    first_name           VARCHAR(100) NOT NULL,
    last_name            VARCHAR(100) NOT NULL,
    status               VARCHAR(20)  NOT NULL DEFAULT 'PENDING',
    phone_verified_at    DATETIME NULL,
    last_login_at        DATETIME NULL,
    failed_login_count   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until         DATETIME NULL,
    must_change_password TINYINT(1) NOT NULL DEFAULT 0,
    version              INT UNSIGNED NOT NULL DEFAULT 1,
    created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by           BIGINT UNSIGNED NULL,
    updated_by           BIGINT UNSIGNED NULL,
    deleted_at           DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_uuid (uuid),
    UNIQUE KEY uq_users_phone (phone),
    UNIQUE KEY uq_users_email (email),
    KEY ix_users_status (status),
    CONSTRAINT ck_users_account_type CHECK (account_type IN ('STAFF','PATIENT')),
    CONSTRAINT ck_users_status CHECK (status IN ('PENDING','ACTIVE','SUSPENDED','REJECTED'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE roles (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code        VARCHAR(50)  NOT NULL,
    label       VARCHAR(100) NOT NULL,
    description VARCHAR(255) NULL,
    is_system   TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Rôle fourni à l''installation : non supprimable',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_roles_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE permissions (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code       VARCHAR(100) NOT NULL,
    module     VARCHAR(50)  NOT NULL,
    label      VARCHAR(190) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_permissions_code (code),
    KEY ix_permissions_module (module)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE role_permissions (
    role_id       INT UNSIGNED NOT NULL,
    permission_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    KEY ix_role_permissions_permission (permission_id),
    CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE,
    CONSTRAINT fk_role_permissions_permission FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_roles (
    user_id     BIGINT UNSIGNED NOT NULL,
    role_id     INT UNSIGNED NOT NULL,
    assigned_by BIGINT UNSIGNED NULL,
    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, role_id),
    KEY ix_user_roles_role (role_id),
    CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_user_roles_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE devices (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid         CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'Généré par l''application à l''installation',
    user_id      BIGINT UNSIGNED NOT NULL,
    platform     VARCHAR(10)  NOT NULL,
    device_name  VARCHAR(100) NULL,
    app_version  VARCHAR(20)  NULL,
    last_seen_at DATETIME NULL,
    last_sync_at DATETIME NULL,
    revoked_at   DATETIME NULL COMMENT 'Révocation : jetons invalidés et purge locale au prochain contact',
    revoked_by   BIGINT UNSIGNED NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_devices_uuid (uuid),
    KEY ix_devices_user (user_id),
    CONSTRAINT fk_devices_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT ck_devices_platform CHECK (platform IN ('ANDROID','IOS','WEB'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Jetons opaques : seul le SHA-256 est stocké, jamais le jeton en clair.
CREATE TABLE access_tokens (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    token_hash   CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    user_id      BIGINT UNSIGNED NOT NULL,
    device_id    BIGINT UNSIGNED NULL,
    channel      VARCHAR(10) NOT NULL,
    expires_at   DATETIME NOT NULL,
    last_used_at DATETIME NULL,
    revoked_at   DATETIME NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_access_tokens_hash (token_hash),
    KEY ix_access_tokens_user (user_id),
    KEY ix_access_tokens_device (device_id),
    KEY ix_access_tokens_expires (expires_at),
    CONSTRAINT fk_access_tokens_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_access_tokens_device FOREIGN KEY (device_id) REFERENCES devices (id) ON DELETE CASCADE,
    CONSTRAINT ck_access_tokens_channel CHECK (channel IN ('MOBILE','WEB'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Refresh tokens rotatifs : une "famille" par connexion ; la réutilisation d'un jeton déjà remplacé révoque toute la famille.
CREATE TABLE refresh_tokens (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    token_hash     CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    family_id      CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    user_id        BIGINT UNSIGNED NOT NULL,
    device_id      BIGINT UNSIGNED NULL,
    expires_at     DATETIME NOT NULL,
    revoked_at     DATETIME NULL,
    replaced_by_id BIGINT UNSIGNED NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_refresh_tokens_hash (token_hash),
    KEY ix_refresh_tokens_family (family_id),
    KEY ix_refresh_tokens_user (user_id),
    KEY ix_refresh_tokens_expires (expires_at),
    CONSTRAINT fk_refresh_tokens_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_refresh_tokens_device FOREIGN KEY (device_id) REFERENCES devices (id) ON DELETE CASCADE,
    CONSTRAINT fk_refresh_tokens_replaced FOREIGN KEY (replaced_by_id) REFERENCES refresh_tokens (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE otp_codes (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    phone        VARCHAR(20) NOT NULL,
    purpose      VARCHAR(30) NOT NULL,
    user_id      BIGINT UNSIGNED NULL,
    code_hash    CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'HMAC-SHA256 du code',
    attempts     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 5,
    expires_at   DATETIME NOT NULL,
    consumed_at  DATETIME NULL,
    ip_address   VARCHAR(45) NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_otp_codes_lookup (phone, purpose, created_at),
    KEY ix_otp_codes_expires (expires_at),
    CONSTRAINT fk_otp_codes_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT ck_otp_codes_purpose CHECK (purpose IN ('REGISTRATION','LOGIN','PATIENT_PORTAL','PASSWORD_RESET'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Limitation des tentatives. L'identifiant (téléphone, n° dossier) est haché : pas de donnée personnelle en clair.
CREATE TABLE login_attempts (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    identifier_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    ip_address      VARCHAR(45) NULL,
    channel         VARCHAR(20) NOT NULL,
    success         TINYINT(1) NOT NULL,
    attempted_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_login_attempts_identifier (identifier_hash, attempted_at),
    KEY ix_login_attempts_ip (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
