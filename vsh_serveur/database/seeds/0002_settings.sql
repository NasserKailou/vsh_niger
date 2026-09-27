-- Seed 0002 — Paramètres métier par défaut (modifiables par l'administrateur).
-- Rejouable : n'écrase JAMAIS une valeur déjà modifiée (seuls type, description et visibilité sont mis à jour).
-- Les secrets et paramètres techniques (durées de jetons, SMS, base de données) sont dans .env, pas ici.

INSERT INTO settings (setting_key, value, value_type, description, is_public) VALUES
    ('app.clinic_name',                       'Vision Homecare Services', 'STRING', 'Nom affiché sur les documents et les applications', 1),
    ('app.timezone',                          'Africa/Niamey',  'STRING', 'Fuseau horaire d''affichage (stockage en UTC)', 1),
    ('app.currency',                          'XOF',            'STRING', 'Devise des factures', 1),
    ('app.default_locale',                    'fr',             'STRING', 'Langue par défaut', 1),

    ('auth.registration_otp_required',        'true',           'BOOL',   'Vérification du téléphone par OTP à l''inscription patient', 1),
    ('auth.patient_portal_otp_required',      'true',           'BOOL',   'OTP obligatoire pour le portail web patient (D-001)', 0),

    ('patients.file_number_prefix',           'VSH',            'STRING', 'Préfixe du n° de dossier (ex. VSH-2026-000123)', 0),
    ('patients.pending_can_request',          'false',          'BOOL',   'Un patient non validé peut demander RDV / visite à domicile (D-007)', 1),

    ('appointments.patient_cancel_min_hours', '24',             'INT',    'Délai minimal (heures) pour qu''un patient annule son rendez-vous', 1),
    ('appointments.max_days_ahead',           '60',             'INT',    'Horizon maximal de prise de rendez-vous (jours)', 1),

    ('consultations.urgent_enabled',          'true',           'BOOL',   'Type de consultation URGENCE activé', 1),

    ('homecare.dispatch_mode',                'BOTH',           'STRING', 'BOTH | SELF_ASSIGN | DISPATCH_ONLY (D-003)', 1),
    ('homecare.auto_accept_new_requests',     'true',           'BOOL',   'Passage automatique NOUVELLE → EN_ATTENTE (sinon validation par l''accueil)', 0),

    ('geo.arrival_radius_m',                  '300',            'INT',    'Distance (m) au-delà de laquelle une arrivée est signalée « loin du domicile » (information, sans blocage)', 1),
    ('geo.low_accuracy_m',                    '100',            'INT',    'Précision GPS (m) au-delà de laquelle une position est signalée imprécise', 1),
    ('geo.position_stale_minutes',            '10',             'INT',    'Âge (minutes) au-delà duquel la dernière position d''une équipe est signalée ancienne', 1),
    ('geo.track_interval_seconds',            '30',             'INT',    'Intervalle d''envoi de la position de l''équipe pendant une visite', 1),
    ('geo.trace_retention_days',              '90',             'INT',    'Conservation des points de trajet des équipes (hors départ et arrivée), purgés ensuite', 0),

    ('invoices.number_prefix',                'FAC',            'STRING', 'Préfixe du n° de facture (ex. FAC-2026-000045)', 0),
    ('documents.letterhead',                  '{"address":"","phone":"","email":"","invoice_footer":"","prescription_footer":""}', 'JSON', 'En-tête et pieds de page des documents imprimés (adresse, téléphone, e-mail, mentions) ; champs vides omis', 0),

    ('sync.offline_scope_months',             '6',              'INT',    'Ancienneté des dossiers conservés hors ligne sur les appareils des professionnels', 1),
    ('sync.outbox_retention_days',            '30',             'INT',    'Conservation locale des opérations déjà synchronisées', 1),

    ('uploads.max_size_mb',                   '10',             'INT',    'Taille maximale d''une pièce jointe', 1),
    ('uploads.allowed_mime_types',            '["application/pdf","image/jpeg","image/png"]', 'JSON', 'Types de fichiers autorisés', 1),

    ('notifications.push_enabled',            'false',          'BOOL',   'Notifications push (FCM, pilote PUSH_DRIVER dans .env) ; l''application fonctionne sans (D-011)', 1),
    ('notifications.sms_mode',                'FALLBACK',       'STRING', 'SMS aux patients : OFF | FALLBACK (seulement si le push n''a pas abouti) | ALWAYS (D-011)', 0),
    ('notifications.sms_types',               '["PATIENT_APPROVED","PATIENT_REJECTED","APPOINTMENT_STATUS","HOMECARE_STATUS","EXAM_RESULT"]', 'JSON', 'Notifications patient également envoyées par SMS (chaque SMS est facturé)', 0)
ON DUPLICATE KEY UPDATE value_type = VALUES(value_type), description = VALUES(description), is_public = VALUES(is_public);
