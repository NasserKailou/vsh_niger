-- 0018 — Index de montée en charge (docs/22_MONTEE_EN_CHARGE.md, priorité P1).
-- Sur une base volumineuse, chaque ALTER reconstruit l'index de la table : à lancer en heure creuse.

-- Recherche par prénom (seconde branche de la recherche par nom ; l'index nom + prénom existe déjà).
ALTER TABLE patients ADD KEY ix_patients_first_name (first_name, last_name);

-- Tableau de bord : nouveaux dossiers du mois, consultations de la période.
ALTER TABLE patients ADD KEY ix_patients_created_at (created_at);
ALTER TABLE consultations ADD KEY ix_consultations_started (started_at);

-- Périmètre de synchronisation du personnel : « éléments saisis par l'utilisateur depuis N mois ».
-- Sans ces index, chaque synchronisation parcourait entièrement douze tables.
ALTER TABLE patient_contacts ADD KEY ix_patient_contacts_created_by (created_by, created_at);
ALTER TABLE patient_addresses ADD KEY ix_patient_addresses_created_by (created_by, created_at);
ALTER TABLE allergies ADD KEY ix_allergies_created_by (created_by, created_at);
ALTER TABLE medical_history ADD KEY ix_medical_history_created_by (created_by, created_at);
ALTER TABLE patient_current_treatments ADD KEY ix_patient_current_treatments_created_by (created_by, created_at);
ALTER TABLE patient_medical_profiles ADD KEY ix_patient_medical_profiles_created_by (created_by, created_at);
ALTER TABLE consultations ADD KEY ix_consultations_created_by (created_by, created_at);
ALTER TABLE vital_signs ADD KEY ix_vital_signs_created_by (created_by, created_at);
ALTER TABLE treatments ADD KEY ix_treatments_created_by (created_by, created_at);
ALTER TABLE examinations ADD KEY ix_examinations_created_by (created_by, created_at);
ALTER TABLE prescriptions ADD KEY ix_prescriptions_created_by (created_by, created_at);
ALTER TABLE homecare_requests ADD KEY ix_homecare_requests_created_by (created_by, created_at);
