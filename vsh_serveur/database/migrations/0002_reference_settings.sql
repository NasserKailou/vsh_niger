-- 0002 — Paramètres, séquences de numérotation, référentiels, tarifs, profils professionnels

CREATE TABLE settings (
    setting_key VARCHAR(100) NOT NULL,
    value       TEXT NULL,
    value_type  VARCHAR(10)  NOT NULL DEFAULT 'STRING',
    description VARCHAR(255) NULL,
    is_public   TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = transmis aux applications (paquet de référence)',
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by  BIGINT UNSIGNED NULL,
    PRIMARY KEY (setting_key),
    CONSTRAINT ck_settings_type CHECK (value_type IN ('STRING','INT','BOOL','JSON'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Numérotation sans trou ni collision : SELECT ... FOR UPDATE dans la transaction qui attribue le numéro.
CREATE TABLE number_sequences (
    name       VARCHAR(50) NOT NULL,
    period     VARCHAR(10) NOT NULL COMMENT 'Ex. 2026 ; ALL si pas de remise à zéro',
    last_value INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (name, period)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE services (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid        CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    code        VARCHAR(30)  NOT NULL,
    label       VARCHAR(150) NOT NULL,
    description VARCHAR(500) NULL,
    accepts_appointments TINYINT(1) NOT NULL DEFAULT 1,
    active      TINYINT(1) NOT NULL DEFAULT 1,
    version     INT UNSIGNED NOT NULL DEFAULT 1,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by  BIGINT UNSIGNED NULL,
    updated_by  BIGINT UNSIGNED NULL,
    deleted_at  DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_services_uuid (uuid),
    UNIQUE KEY uq_services_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE service_schedules (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid              CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    service_id        BIGINT UNSIGNED NOT NULL,
    weekday           TINYINT UNSIGNED NOT NULL COMMENT '1 = lundi … 7 = dimanche (ISO-8601)',
    start_time        TIME NOT NULL COMMENT 'Heure locale de la clinique',
    end_time          TIME NOT NULL,
    slot_minutes      SMALLINT UNSIGNED NOT NULL,
    capacity_per_slot SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    active            TINYINT(1) NOT NULL DEFAULT 1,
    version           INT UNSIGNED NOT NULL DEFAULT 1,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_service_schedules_uuid (uuid),
    KEY ix_service_schedules_service (service_id, weekday),
    CONSTRAINT fk_service_schedules_service FOREIGN KEY (service_id) REFERENCES services (id) ON DELETE RESTRICT,
    CONSTRAINT ck_service_schedules_weekday CHECK (weekday BETWEEN 1 AND 7),
    CONSTRAINT ck_service_schedules_times CHECK (end_time > start_time),
    CONSTRAINT ck_service_schedules_slot CHECK (slot_minutes > 0 AND capacity_per_slot > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE staff_profiles (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id        BIGINT UNSIGNED NOT NULL,
    profession     VARCHAR(30)  NOT NULL,
    speciality     VARCHAR(150) NULL,
    license_number VARCHAR(50)  NULL COMMENT 'N° d''inscription à l''ordre professionnel',
    service_id     BIGINT UNSIGNED NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_staff_profiles_user (user_id),
    KEY ix_staff_profiles_profession (profession),
    CONSTRAINT fk_staff_profiles_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_staff_profiles_service FOREIGN KEY (service_id) REFERENCES services (id) ON DELETE SET NULL,
    CONSTRAINT ck_staff_profiles_profession CHECK (profession IN ('MEDECIN','INFIRMIER','SAGE_FEMME','TECHNICIEN','ADMINISTRATIF','AUTRE'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE medical_acts (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid        CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    code        VARCHAR(30)  NOT NULL,
    label       VARCHAR(190) NOT NULL,
    category    VARCHAR(50)  NULL COMMENT 'Ex. CONSULTATION, DEPLACEMENT, AUTRE',
    active      TINYINT(1) NOT NULL DEFAULT 1,
    version     INT UNSIGNED NOT NULL DEFAULT 1,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by  BIGINT UNSIGNED NULL,
    updated_by  BIGINT UNSIGNED NULL,
    deleted_at  DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_medical_acts_uuid (uuid),
    UNIQUE KEY uq_medical_acts_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE treatment_types (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid        CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    code        VARCHAR(30)  NOT NULL,
    label       VARCHAR(190) NOT NULL,
    description VARCHAR(500) NULL,
    active      TINYINT(1) NOT NULL DEFAULT 1,
    version     INT UNSIGNED NOT NULL DEFAULT 1,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by  BIGINT UNSIGNED NULL,
    updated_by  BIGINT UNSIGNED NULL,
    deleted_at  DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_treatment_types_uuid (uuid),
    UNIQUE KEY uq_treatment_types_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE examination_types (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid         CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    code         VARCHAR(30)  NOT NULL,
    label        VARCHAR(190) NOT NULL,
    category     VARCHAR(50)  NULL COMMENT 'Ex. BIOLOGIE, IMAGERIE, TEST_RAPIDE',
    sample_type  VARCHAR(100) NULL,
    instructions VARCHAR(500) NULL,
    active       TINYINT(1) NOT NULL DEFAULT 1,
    version      INT UNSIGNED NOT NULL DEFAULT 1,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by   BIGINT UNSIGNED NULL,
    updated_by   BIGINT UNSIGNED NULL,
    deleted_at   DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_examination_types_uuid (uuid),
    UNIQUE KEY uq_examination_types_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Paramètres de résultat et valeurs de référence : entièrement configurables (aucune norme codée en dur).
CREATE TABLE examination_type_parameters (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid                CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    examination_type_id BIGINT UNSIGNED NOT NULL,
    code                VARCHAR(30)  NOT NULL,
    label               VARCHAR(190) NOT NULL,
    value_type          VARCHAR(10)  NOT NULL DEFAULT 'NUMERIC',
    choices             JSON NULL COMMENT 'Valeurs possibles si value_type = CHOICE',
    unit                VARCHAR(30)  NULL,
    ref_min             DECIMAL(14,4) NULL,
    ref_max             DECIMAL(14,4) NULL,
    ref_text            VARCHAR(190) NULL,
    sex                 CHAR(1) NULL COMMENT 'NULL = tous',
    age_min_months      SMALLINT UNSIGNED NULL,
    age_max_months      SMALLINT UNSIGNED NULL,
    sort_order          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    active              TINYINT(1) NOT NULL DEFAULT 1,
    version             INT UNSIGNED NOT NULL DEFAULT 1,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_examination_type_parameters_uuid (uuid),
    KEY ix_examination_type_parameters_type (examination_type_id, sort_order),
    CONSTRAINT fk_examination_type_parameters_type FOREIGN KEY (examination_type_id) REFERENCES examination_types (id) ON DELETE RESTRICT,
    CONSTRAINT ck_examination_type_parameters_value_type CHECK (value_type IN ('NUMERIC','TEXT','CHOICE')),
    CONSTRAINT ck_examination_type_parameters_sex CHECK (sex IS NULL OR sex IN ('M','F')),
    CONSTRAINT ck_examination_type_parameters_range CHECK (ref_min IS NULL OR ref_max IS NULL OR ref_max >= ref_min),
    CONSTRAINT ck_examination_type_parameters_age CHECK (age_min_months IS NULL OR age_max_months IS NULL OR age_max_months >= age_min_months)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE medications (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid            CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    dci             VARCHAR(190) NOT NULL COMMENT 'Dénomination commune internationale',
    commercial_name VARCHAR(190) NULL,
    form            VARCHAR(60)  NULL COMMENT 'Comprimé, sirop, injectable…',
    strength        VARCHAR(60)  NULL COMMENT 'Dosage, ex. 500 mg',
    route           VARCHAR(60)  NULL COMMENT 'Voie d''administration',
    active          TINYINT(1) NOT NULL DEFAULT 1,
    version         INT UNSIGNED NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by      BIGINT UNSIGNED NULL,
    updated_by      BIGINT UNSIGNED NULL,
    deleted_at      DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_medications_uuid (uuid),
    KEY ix_medications_dci (dci),
    KEY ix_medications_commercial_name (commercial_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tarifs datés : un prix n'est jamais codé en dur ; la facture copie le montant en vigueur à la date de l'acte.
-- billable_id référence la table indiquée par billable_type (pas de FK possible : contrôle applicatif).
CREATE TABLE tariffs (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid          CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    billable_type VARCHAR(20) NOT NULL,
    billable_id   BIGINT UNSIGNED NOT NULL,
    amount        BIGINT UNSIGNED NOT NULL COMMENT 'Montant entier dans la devise (FCFA)',
    currency      CHAR(3) NOT NULL DEFAULT 'XOF',
    valid_from    DATE NOT NULL,
    valid_to      DATE NULL,
    version       INT UNSIGNED NOT NULL DEFAULT 1,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by    BIGINT UNSIGNED NULL,
    updated_by    BIGINT UNSIGNED NULL,
    deleted_at    DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tariffs_uuid (uuid),
    KEY ix_tariffs_lookup (billable_type, billable_id, valid_from),
    CONSTRAINT ck_tariffs_type CHECK (billable_type IN ('MEDICAL_ACT','TREATMENT_TYPE','EXAMINATION_TYPE','MEDICATION')),
    CONSTRAINT ck_tariffs_period CHECK (valid_to IS NULL OR valid_to >= valid_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
