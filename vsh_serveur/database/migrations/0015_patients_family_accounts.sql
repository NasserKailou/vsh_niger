-- 0015 — D-009 : un compte patient (un numéro de téléphone) peut gérer plusieurs dossiers (famille).
-- L'index simple est créé avant la suppression de l'index unique, requis par la clé étrangère.

ALTER TABLE patients ADD KEY ix_patients_user (user_id);

ALTER TABLE patients DROP INDEX uq_patients_user;
