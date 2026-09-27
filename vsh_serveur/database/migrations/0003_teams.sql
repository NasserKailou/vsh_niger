-- 0003 — Équipes médicales (mobiles ou non)

CREATE TABLE teams (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid        CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    code        VARCHAR(30)  NOT NULL,
    label       VARCHAR(150) NOT NULL,
    description VARCHAR(500) NULL,
    is_mobile   TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = peut intervenir à domicile',
    active      TINYINT(1) NOT NULL DEFAULT 1,
    version     INT UNSIGNED NOT NULL DEFAULT 1,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by  BIGINT UNSIGNED NULL,
    updated_by  BIGINT UNSIGNED NULL,
    deleted_at  DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_teams_uuid (uuid),
    UNIQUE KEY uq_teams_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Appartenance datée : permet de savoir qui faisait partie de l'équipe à une date donnée (validation d'examens, D-005).
CREATE TABLE team_members (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid       CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    team_id    BIGINT UNSIGNED NOT NULL,
    user_id    BIGINT UNSIGNED NOT NULL,
    team_role  VARCHAR(20) NOT NULL,
    from_date  DATE NOT NULL,
    to_date    DATE NULL,
    version    INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_team_members_uuid (uuid),
    KEY ix_team_members_team (team_id, to_date),
    KEY ix_team_members_user (user_id, to_date),
    CONSTRAINT fk_team_members_team FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE RESTRICT,
    CONSTRAINT fk_team_members_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT ck_team_members_role CHECK (team_role IN ('CHEF','MEDECIN','INFIRMIER','SAGE_FEMME','TECHNICIEN','CHAUFFEUR','AUTRE')),
    CONSTRAINT ck_team_members_period CHECK (to_date IS NULL OR to_date >= from_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
