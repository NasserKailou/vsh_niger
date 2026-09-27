-- Seed 0001 — Rôles, permissions et associations par défaut.
-- Rejouable sans effet de bord. Les associations sont un POINT DE DÉPART : l'administrateur les modifie ensuite
-- depuis l'interface. Le code ne vérifie que des codes de permission, jamais des noms de rôles.

INSERT INTO roles (code, label, description, is_system) VALUES
    ('ADMIN',      'Administrateur',       'Gestion de la plateforme (sans accès médical par défaut)', 1),
    ('MEDECIN',    'Médecin',              'Consultations, diagnostics, prescriptions, validation d''examens', 1),
    ('INFIRMIER',  'Infirmier',            'Soins, constantes, interventions à domicile', 1),
    ('TECHNICIEN', 'Technicien de santé',  'Réalisation des examens et saisie des résultats', 1),
    ('ACCUEIL',    'Accueil',              'Accueil, rendez-vous, validation des inscriptions, dispatch, facturation', 1),
    ('PATIENT',    'Patient',              'Accès à son propre dossier', 1)
ON DUPLICATE KEY UPDATE label = VALUES(label), description = VALUES(description), is_system = VALUES(is_system);

INSERT INTO permissions (code, module, label) VALUES
    -- Administration
    ('users.read',                     'users',         'Consulter les utilisateurs'),
    ('users.manage',                   'users',         'Créer, modifier, activer, suspendre des utilisateurs'),
    ('roles.manage',                   'users',         'Gérer les rôles et leurs permissions'),
    ('settings.manage',                'settings',      'Modifier les paramètres de l''application'),
    ('audit.read',                     'audit',         'Consulter le journal d''audit'),
    ('sync.supervise',                 'sync',          'Superviser la synchronisation et révoquer des appareils'),
    -- Patients
    ('patients.read',                  'patients',      'Consulter l''identité des patients'),
    ('patients.create',                'patients',      'Créer un patient'),
    ('patients.update',                'patients',      'Modifier l''identité et les coordonnées d''un patient'),
    ('patients.validate_registration', 'patients',      'Valider ou rejeter les inscriptions patients'),
    ('patients.merge',                 'patients',      'Fusionner des dossiers en doublon'),
    ('patients.assign_attending',      'patients',      'Désigner le médecin traitant'),
    ('patients.medical.read',          'patients',      'Consulter les données médicales (périmètre de prise en charge)'),
    ('patients.medical.write',         'patients',      'Saisir antécédents, allergies, traitements en cours'),
    ('patients.medical.read_all',      'patients',      'Consulter les données médicales de tous les patients'),
    -- Référentiels
    ('reference.read',                 'reference',     'Consulter les référentiels'),
    ('reference.manage',               'reference',     'Gérer services, actes, soins, examens, médicaments'),
    ('tariffs.manage',                 'reference',     'Gérer les tarifs'),
    -- Rendez-vous
    ('appointments.read',              'appointments',  'Consulter les rendez-vous'),
    ('appointments.manage',            'appointments',  'Confirmer, déplacer, annuler, affecter des rendez-vous'),
    ('appointments.request_self',      'appointments',  'Demander et annuler ses propres rendez-vous'),
    -- Consultations
    ('consultations.read',             'consultations', 'Consulter les consultations'),
    ('consultations.create',           'consultations', 'Ouvrir une consultation'),
    ('consultations.update',           'consultations', 'Renseigner une consultation (symptômes, examen clinique)'),
    ('consultations.close',            'consultations', 'Clôturer une consultation'),
    ('vitals.record',                  'consultations', 'Enregistrer des constantes'),
    ('diagnoses.write',                'consultations', 'Établir un diagnostic'),
    -- Soins
    ('treatments.read',                'treatments',    'Consulter les soins'),
    ('treatments.perform',             'treatments',    'Programmer et enregistrer des soins'),
    -- Examens
    ('examinations.read',              'examinations',  'Consulter les examens'),
    ('examinations.prescribe',         'examinations',  'Prescrire des examens'),
    ('examinations.perform',           'examinations',  'Réaliser des examens et saisir les résultats'),
    ('examinations.validate',          'examinations',  'Valider des résultats (médecin traitant, prescripteur ou équipe en charge — D-005)'),
    -- Ordonnances
    ('prescriptions.read',             'prescriptions', 'Consulter les ordonnances'),
    ('prescriptions.write',            'prescriptions', 'Rédiger des ordonnances'),
    ('prescriptions.sign',             'prescriptions', 'Signer des ordonnances'),
    ('prescription_templates.read',    'prescriptions', 'Consulter et utiliser les modèles d''ordonnance'),
    ('prescription_templates.manage',  'prescriptions', 'Créer et modifier les modèles d''ordonnance'),
    ('prescription_templates.approve', 'prescriptions', 'Approuver (activer) un modèle d''ordonnance'),
    -- Homecare & équipes
    ('homecare.read',                  'homecare',      'Consulter les demandes et interventions à domicile'),
    ('homecare.request',               'homecare',      'Créer une demande à domicile pour un patient'),
    ('homecare.request_self',          'homecare',      'Demander une visite à domicile pour soi'),
    ('homecare.intervene',             'homecare',      'Accepter une demande et faire évoluer l''intervention (membre d''équipe)'),
    ('homecare.dispatch',              'homecare',      'Affecter ou réaffecter une équipe (régulation)'),
    ('teams.read',                     'teams',         'Consulter les équipes'),
    ('teams.manage',                   'teams',         'Gérer les équipes et leurs membres'),
    -- Facturation (D-004)
    ('invoices.read',                  'billing',       'Consulter les factures'),
    ('invoices.manage',                'billing',       'Créer et modifier des factures brouillon'),
    ('invoices.issue',                 'billing',       'Émettre ou annuler une facture'),
    ('invoices.settlement_declare',    'billing',       'Déclarer l''état de règlement d''une facture'),
    -- Pilotage
    ('dashboard.global',               'dashboard',     'Tableau de bord global'),
    ('dashboard.personal',             'dashboard',     'Tableau de bord personnel'),
    ('reports.read',                   'dashboard',     'Rapports et exports'),
    ('map.read',                       'dashboard',     'Carte des interventions'),
    -- Espace patient
    ('self.record.read',               'self',          'Consulter son propre dossier'),
    ('self.invoices.read',             'self',          'Consulter ses propres factures')
ON DUPLICATE KEY UPDATE module = VALUES(module), label = VALUES(label);

-- Associations par défaut (rejouables : INSERT IGNORE sur la clé primaire composite).
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.code IN (
    'users.read','users.manage','roles.manage','settings.manage','audit.read','sync.supervise',
    'patients.read','patients.create','patients.update','patients.validate_registration','patients.merge','patients.assign_attending',
    'reference.read','reference.manage','tariffs.manage',
    'appointments.read','appointments.manage',
    'consultations.read','treatments.read','examinations.read',
    'prescription_templates.read','prescription_templates.manage',
    'homecare.read','homecare.request','homecare.dispatch','teams.read','teams.manage',
    'invoices.read','invoices.manage','invoices.issue','invoices.settlement_declare',
    'dashboard.global','reports.read','map.read')
WHERE r.code = 'ADMIN';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.code IN (
    'patients.read','patients.create','patients.update','patients.medical.read','patients.medical.write',
    'reference.read','appointments.read',
    'consultations.read','consultations.create','consultations.update','consultations.close','vitals.record','diagnoses.write',
    'treatments.read','treatments.perform',
    'examinations.read','examinations.prescribe','examinations.validate',
    'prescriptions.read','prescriptions.write','prescriptions.sign',
    'prescription_templates.read','prescription_templates.approve',
    'homecare.read','homecare.request','homecare.intervene','teams.read','dashboard.personal')
WHERE r.code = 'MEDECIN';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.code IN (
    'patients.read','patients.create','patients.update','patients.medical.read','patients.medical.write',
    'reference.read','appointments.read',
    'consultations.read','vitals.record',
    'treatments.read','treatments.perform',
    'examinations.read','examinations.validate',
    'prescriptions.read',
    'homecare.read','homecare.request','homecare.intervene','teams.read','dashboard.personal')
WHERE r.code = 'INFIRMIER';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.code IN (
    'patients.read','reference.read','examinations.read','examinations.perform','dashboard.personal')
WHERE r.code = 'TECHNICIEN';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.code IN (
    'patients.read','patients.create','patients.update','patients.validate_registration',
    'reference.read','appointments.read','appointments.manage',
    'consultations.read','consultations.create',
    'homecare.read','homecare.request','homecare.dispatch','teams.read',
    'invoices.read','invoices.manage','invoices.issue','invoices.settlement_declare',
    'dashboard.personal')
WHERE r.code = 'ACCUEIL';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.code IN (
    'self.record.read','self.invoices.read','appointments.request_self','homecare.request_self')
WHERE r.code = 'PATIENT';
