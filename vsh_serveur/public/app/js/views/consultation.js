/**
 * Espace de consultation : observation clinique, constantes, diagnostics, notes (addendums après
 * clôture), soins et examens prescrits. Allergies connues rappelées en tête.
 * Sans droits médicaux (accueil, administration), seules les métadonnées sont affichées (D-010).
 * Aucune règle clinique n'est appliquée par l'interface : le professionnel décide.
 */
import { h, mount } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { api, ApiError } from '../core/api.js';
import { session } from '../core/session.js';
import { badge, formatDateTime, formatTime, formatDate, CONSULTATION_TYPES } from '../core/format.js';
import { card, table, emptyState, errorState, skeleton, loadingRegion, button, field, askReason, iconButton, toast, setBusy, errorSummary, applyErrors } from '../ui.js';
import { confirmAction, formDialog } from '../dialogs.js';
import { prescriptionsCard } from './prescriptions.js';
import { createInvoiceFor } from './billing.js';

const VITALS = [
  ['temperature_c', 'Température', '°C', 'decimal'],
  ['systolic_mmhg', 'Tension systolique', 'mmHg', 'numeric'],
  ['diastolic_mmhg', 'Tension diastolique', 'mmHg', 'numeric'],
  ['pulse_bpm', 'Pouls', 'bpm', 'numeric'],
  ['respiratory_rate', 'Fréquence respiratoire', '/min', 'numeric'],
  ['spo2_percent', 'SpO₂', '%', 'numeric'],
  ['weight_kg', 'Poids', 'kg', 'decimal'],
  ['height_cm', 'Taille', 'cm', 'decimal'],
  ['glycemia_g_l', 'Glycémie', 'g/L', 'decimal'],
];
const KINDS = { PRINCIPAL: 'Principal', SECONDAIRE: 'Secondaire' };
const CERTAINTY = { CONFIRME: 'Confirmé', PROBABLE: 'Probable', SUSPECTE: 'Suspecté' };
export const EXAM_STATUS = {
  PRESCRIT: ['warning', 'Prescrit'], EN_COURS: ['info', 'En cours'], TERMINE: ['accent', 'Résultats saisis'], VALIDE: ['success', 'Validé'], ANNULE: ['neutral', 'Annulé'],
};

export function textArea(label, name, value, disabled, hint) {
  const f = field({ label, name, hint });
  const area = h('textarea', { id: f.input.id, name, class: 'vsh-textarea', maxlength: '5000', disabled, rows: '4' });
  const describedBy = f.input.getAttribute('aria-describedby');
  if (describedBy) area.setAttribute('aria-describedby', describedBy);
  area.value = value || '';
  f.input.replaceWith(area);
  f.input = area;
  return f;
}

const numberText = (value) => (value === null || value === undefined ? '—' : String(value).replace('.', ','));

export async function consultationView(ctx) {
  const { main, setTitle, params, isCurrent } = ctx;
  setTitle('Consultation');
  mount(main, loadingRegion([skeleton('line'), skeleton('block')]));
  let consultation;
  try {
    ({ data: consultation } = await api.get(`consultations/${encodeURIComponent(params.id)}`));
  } catch (error) {
    if (!isCurrent()) return;
    mount(main, h('a', { class: 'back-link', href: '#/consultations' }, icon('chevronLeft'), 'Consultations'),
      error.status === 404 ? emptyState({ iconName: 'search', title: 'Consultation introuvable' }) : errorState(error, () => consultationView(ctx)));
    return;
  }
  if (!isCurrent()) return;

  const reload = () => consultationView(ctx);
  const clinical = Object.prototype.hasOwnProperty.call(consultation, 'chief_complaint');
  const open = ['OUVERTE', 'EN_COURS'].includes(consultation.status);
  const canWrite = clinical && open && session.can('consultations.update');
  const patientName = consultation.patient ? consultation.patient.name : 'Patient';
  setTitle(`Consultation · ${patientName}`);

  // ---------------------------------------------------------------- En-tête
  const actions = [];
  if (open && clinical && session.can('consultations.close')) {
    actions.push(button({
      text: 'Clôturer', iconName: 'checkCircle',
      onClick: () => confirmAction({
        title: 'Clôturer la consultation ?', danger: false, confirmText: 'Clôturer',
        text: 'Après clôture, l’observation n’est plus modifiable : toute correction se fera par un addendum daté et signé.',
        run: async () => {
          await api.post(`consultations/${consultation.id}/close`);
          toast('Consultation clôturée.');
          reload();
        },
      }),
    }));
  }
  if (open && session.can('consultations.update')) {
    actions.push(button({
      text: 'Annuler', variant: 'secondary',
      onClick: async () => {
        const reason = await askReason({
          title: 'Annuler la consultation', label: 'Motif de l’annulation', confirmText: 'Annuler la consultation',
          description: 'Par exemple : patient reparti avant d’être vu, ouverture par erreur.',
          submit: (value) => api.post(`consultations/${consultation.id}/cancel`, { reason: value }),
        });
        if (reason !== null) {
          toast('Consultation annulée.');
          reload();
        }
      },
    }));
  }

  if (consultation.status === 'CLOTUREE' && session.can('invoices.manage') && consultation.patient) {
    // Brouillon : soins et examens réalisés non encore facturés repris au tarif en vigueur (serveur).
    const bill = button({
      text: 'Facturer', iconName: 'receipt', variant: 'secondary',
      onClick: async () => {
        setBusy(bill, true);
        try {
          await createInvoiceFor({ patientId: consultation.patient.id, consultationId: consultation.id });
        } catch (error) {
          toast(error.message, { type: 'error' });
        } finally {
          setBusy(bill, false);
        }
      },
    });
    actions.push(bill);
  }

  const header = h('section', { class: 'vsh-card' },
    h('div', { class: 'page-head' },
      h('div', { class: 'page-head__text' },
        h('div', { class: 'patient-head__name' },
          h('h1', { tabindex: '-1', 'data-page-title': 'true' }, patientName),
          badge('consultation', consultation.status),
          consultation.consultation_type === 'URGENCE' ? h('span', { class: 'vsh-badge vsh-badge--danger' }, 'Urgence') : null),
        h('div', { class: 'patient-head__meta' },
          consultation.patient ? h('a', { class: 'mono', href: `#/patients/${consultation.patient.id}` }, icon('fileText'), consultation.patient.file_number || 'Dossier') : null,
          h('span', {}, icon('stethoscope'), CONSULTATION_TYPES[consultation.consultation_type] || consultation.consultation_type),
          h('span', {}, icon('clock'), `Ouverte le ${formatDateTime(consultation.started_at)}`),
          h('span', {}, icon('user'), consultation.practitioner ? consultation.practitioner.name : 'Praticien non attribué'),
          consultation.closed_at ? h('span', {}, icon('lock'), `Clôturée le ${formatDateTime(consultation.closed_at)}`) : null)),
      actions.length ? h('div', { class: 'row' }, actions) : null));

  const top = [h('a', { class: 'back-link', href: '#/consultations' }, icon('chevronLeft'), 'Consultations'), header];

  if (!clinical) {
    mount(main, top, h('div', { class: 'vsh-alert vsh-alert--info', role: 'status' }, icon('lock', { size: 20 }),
      h('span', {}, 'Le contenu clinique de cette consultation est réservé aux soignants autorisés. Vous voyez ses informations administratives.')));
    return;
  }

  // Allergies connues : rappel en tête (sécurité de la prescription), sans interprétation.
  const allergyBox = h('div', { hidden: true });
  if (consultation.patient) {
    api.get(`patients/${consultation.patient.id}`).then(({ data }) => {
      const allergies = data.medical ? data.medical.allergies : [];
      if (allergies && allergies.length && isCurrent()) {
        allergyBox.hidden = false;
        mount(allergyBox, h('div', { class: 'vsh-alert vsh-alert--danger', role: 'note' }, icon('alert', { size: 20 }),
          h('div', {}, h('strong', {}, 'Allergies connues : '),
            allergies.map((a, i) => [i ? ' · ' : '', `${a.allergen}${a.reaction ? ` (${a.reaction})` : ''}`]))));
      }
    }).catch(() => {});
  }
  if (consultation.status === 'CLOTUREE') {
    top.push(h('div', { class: 'vsh-alert vsh-alert--info', role: 'status' }, icon('lock', { size: 20 }),
      h('span', {}, 'Consultation clôturée : l’observation est verrouillée. Ajoutez un addendum pour toute correction.')));
  }

  // ---------------------------------------------------------------- Observation
  const obs = {
    chief_complaint: textArea('Motif de consultation', 'chief_complaint', consultation.chief_complaint, !canWrite),
    symptoms: textArea('Symptômes et anamnèse', 'symptoms', consultation.symptoms, !canWrite),
    clinical_exam: textArea('Examen clinique', 'clinical_exam', consultation.clinical_exam, !canWrite),
    conclusion: textArea('Conclusion et conduite à tenir', 'conclusion', consultation.conclusion, !canWrite),
  };
  const obsSummary = h('div', { hidden: true });
  const dirtyNote = h('span', { class: 'vsh-muted', role: 'status' });
  const saveButton = button({ text: 'Enregistrer l’observation', iconName: 'checkCircle', type: 'submit' });
  let version = consultation.version;
  const obsForm = h('form', { class: 'stack', novalidate: true }, obsSummary, Object.values(obs).map((f) => f.el),
    canWrite ? h('div', { class: 'row row--between' }, dirtyNote, saveButton) : null);
  Object.values(obs).forEach((f) => f.input.addEventListener('input', () => { dirtyNote.textContent = 'Modifications non enregistrées'; }));
  obsForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    setBusy(saveButton, true);
    try {
      const body = { version };
      Object.entries(obs).forEach(([key, f]) => { body[key] = f.input.value.trim() || null; });
      const { data } = await api.put(`consultations/${consultation.id}`, body);
      version = data.version;
      dirtyNote.textContent = `Enregistré à ${formatTime(new Date())}`;
      errorSummary(obsSummary, null);
      toast('Observation enregistrée.');
    } catch (error) {
      if (error instanceof ApiError && error.code === 'VALIDATION_ERROR') {
        errorSummary(obsSummary, 'Veuillez corriger les champs indiqués.', applyErrors(obs, error.errors));
      } else if (error instanceof ApiError && error.code === 'VERSION_CONFLICT') {
        errorSummary(obsSummary, 'Un collègue a modifié cette consultation entre-temps. Copiez votre texte, rechargez la page puis réintégrez vos modifications.', []);
      } else {
        errorSummary(obsSummary, error.message || 'Enregistrement impossible.', []);
      }
    } finally {
      setBusy(saveButton, false);
    }
  });

  // ---------------------------------------------------------------- Constantes
  const vitals = consultation.vitals || [];
  const vitalsContent = vitals.length
    ? table({
      caption: 'Constantes mesurées',
      columns: [
        { label: 'Heure', render: (v) => formatTime(v.recorded_at) },
        { label: 'T°', cls: 'num', render: (v) => numberText(v.temperature_c) },
        { label: 'TA', cls: 'num', render: (v) => (v.systolic_mmhg ? `${v.systolic_mmhg}/${v.diastolic_mmhg || '—'}` : '—') },
        { label: 'Pouls', cls: 'num', render: (v) => numberText(v.pulse_bpm) },
        { label: 'SpO₂', cls: 'num', render: (v) => (v.spo2_percent ? `${v.spo2_percent} %` : '—') },
        { label: 'Poids', cls: 'num', render: (v) => (v.weight_kg ? `${numberText(v.weight_kg)} kg` : '—') },
        { label: 'IMC', cls: 'num', render: (v) => numberText(v.bmi) },
        { label: 'Gly.', cls: 'num', render: (v) => numberText(v.glycemia_g_l) },
      ],
      rows: vitals,
    })
    : h('p', { class: 'vsh-muted' }, 'Aucune constante mesurée pour cette consultation.');
  const canVitals = open && session.can('vitals.record');
  function addVitals() {
    const fields = Object.fromEntries(VITALS.map(([name, label, unit, mode]) => [name, field({ label: `${label} (${unit})`, name, inputmode: mode })]));
    const notes = field({ label: 'Remarque (facultatif)', name: 'notes', attrs: { maxlength: '500' } });
    fields.notes = notes;
    const grid = h('div', { class: 'form-grid' }, Object.values(fields).map((f) => f.el));
    formDialog({
      title: 'Saisir des constantes', description: 'Renseignez au moins une mesure. Les valeurs physiquement impossibles (faute de frappe) sont refusées.',
      fields: {}, extra: [grid], submitText: 'Enregistrer',
      submit: async () => {
        const body = {};
        VITALS.forEach(([name]) => {
          const raw = fields[name].input.value.trim().replace(',', '.');
          if (raw !== '') body[name] = Number(raw);
        });
        if (notes.input.value.trim()) body.notes = notes.input.value.trim();
        try {
          await api.post(`consultations/${consultation.id}/vitals`, body);
        } catch (error) {
          if (error instanceof ApiError && error.code === 'VALIDATION_ERROR') applyErrors(fields, error.errors);
          throw error;
        }
        toast('Constantes enregistrées.');
        reload();
      },
    });
  }

  // ---------------------------------------------------------------- Diagnostics
  const diagnoses = consultation.diagnoses || [];
  const diagnosisList = diagnoses.length
    ? h('ul', { class: 'item-list' }, diagnoses.map((d) => h('li', {},
      h('div', { class: 'grow' }, h('strong', {}, d.label),
        h('small', {}, [d.icd10_code ? `CIM-10 ${d.icd10_code}` : null, KINDS[d.diagnosis_kind], CERTAINTY[d.certainty]].filter(Boolean).join(' · '))),
      canWrite && session.can('diagnoses.write')
        ? iconButton('x', `Retirer le diagnostic ${d.label}`, () => confirmAction({
          title: 'Retirer ce diagnostic ?', confirmText: 'Retirer', text: d.label,
          run: async () => {
            await api.del(`consultations/${consultation.id}/diagnoses/${d.id}`);
            reload();
          },
        })) : null)))
    : h('p', { class: 'vsh-muted' }, 'Aucun diagnostic.');
  function addDiagnosis() {
    const f = {
      label: field({ label: 'Diagnostic', name: 'label', required: true, attrs: { maxlength: '255' } }),
      icd10_code: field({ label: 'Code CIM-10 (facultatif)', name: 'icd10_code', hint: 'Ex. B54 ou J06.9', attrs: { maxlength: '8', autocapitalize: 'characters' } }),
      diagnosis_kind: field({ label: 'Nature', name: 'diagnosis_kind', options: [['PRINCIPAL', 'Principal'], ['SECONDAIRE', 'Secondaire']], value: diagnoses.length ? 'SECONDAIRE' : 'PRINCIPAL' }),
      certainty: field({ label: 'Certitude', name: 'certainty', options: [['CONFIRME', 'Confirmé'], ['PROBABLE', 'Probable'], ['SUSPECTE', 'Suspecté']] }),
    };
    formDialog({
      title: 'Ajouter un diagnostic', fields: f, submitText: 'Ajouter',
      submit: async () => {
        await api.post(`consultations/${consultation.id}/diagnoses`, {
          label: f.label.input.value.trim(), icd10_code: f.icd10_code.input.value.trim().toUpperCase() || null,
          diagnosis_kind: f.diagnosis_kind.input.value, certainty: f.certainty.input.value,
        });
        toast('Diagnostic ajouté.');
        reload();
      },
    });
  }

  // ---------------------------------------------------------------- Notes et addendums
  const notes = consultation.notes || [];
  const closed = consultation.status === 'CLOTUREE';
  const noteArea = textArea(closed ? 'Nouvel addendum' : 'Nouvelle note', 'content', '', false,
    closed ? 'L’addendum est daté et signé ; il ne modifie pas l’observation.' : null);
  const noteButton = button({ text: closed ? 'Ajouter l’addendum' : 'Ajouter la note', type: 'submit', variant: 'secondary' });
  const noteForm = h('form', { class: 'stack', novalidate: true }, noteArea.el, h('div', {}, noteButton));
  noteForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const content = noteArea.input.value.trim();
    if (!content) {
      noteArea.setError('Écrivez le texte de la note.');
      return;
    }
    setBusy(noteButton, true);
    try {
      await api.post(`consultations/${consultation.id}/notes`, { content });
      toast(closed ? 'Addendum ajouté.' : 'Note ajoutée.');
      reload();
    } catch (error) {
      noteArea.setError(error.message);
      setBusy(noteButton, false);
    }
  });
  const canNote = session.can('consultations.update') && consultation.status !== 'ANNULEE';
  const notesContent = h('div', { class: 'stack' },
    notes.length
      ? h('ul', { class: 'item-list' }, notes.map((n) => h('li', {},
        h('div', { class: 'grow' },
          h('small', {}, [n.note_kind === 'ADDENDUM' ? 'Addendum' : 'Note', n.author ? n.author.name : null, formatDateTime(n.created_at)].filter(Boolean).join(' · ')),
          h('p', { class: 'note-text' }, n.content)))))
      : h('p', { class: 'vsh-muted' }, 'Aucune note.'),
    canNote ? noteForm : null);

  // ---------------------------------------------------------------- Soins
  const treatments = consultation.treatments || [];
  const treatmentLabel = { REALISE: ['success', 'Réalisé'], PLANIFIE: ['info', 'Programmé'], ANNULE: ['neutral', 'Annulé'] };
  const treatmentsContent = treatments.length
    ? h('ul', { class: 'item-list' }, treatments.map((t) => {
      const [tone, text] = treatmentLabel[t.status] || ['neutral', t.status];
      return h('li', {},
        h('div', { class: 'grow' }, h('strong', {}, t.type.label),
          h('small', {}, t.status === 'REALISE' ? `Réalisé le ${formatDateTime(t.performed_at)}` : t.status === 'PLANIFIE' ? `Programmé le ${formatDateTime(t.scheduled_for)}` : 'Annulé'),
          t.observations ? h('small', {}, t.observations) : null),
        h('span', { class: `vsh-badge vsh-badge--${tone}` }, text));
    }))
    : h('p', { class: 'vsh-muted' }, 'Aucun soin.');
  function addTreatment() {
    const type = field({ label: 'Soin', name: 'treatment_type_id', required: true, options: [['', 'Chargement…']] });
    const status = field({ label: 'Statut', name: 'status', options: [['REALISE', 'Réalisé maintenant'], ['PLANIFIE', 'À programmer']] });
    const when = field({ label: 'Date et heure prévues', name: 'scheduled_for', type: 'datetime-local' });
    const observations = textArea('Observations (facultatif)', 'observations', '', false);
    when.el.hidden = true;
    status.input.addEventListener('change', () => { when.el.hidden = status.input.value !== 'PLANIFIE'; });
    api.get('treatment-types', { active: 1, per_page: 200 }).then(({ data }) => {
      type.input.replaceChildren(h('option', { value: '' }, 'Choisir un soin'), ...data.map((t) => h('option', { value: t.id }, t.label)));
    }).catch(() => type.input.replaceChildren(h('option', { value: '' }, 'Liste indisponible')));
    formDialog({
      title: 'Ajouter un soin', fields: { treatment_type_id: type, status, scheduled_for: when, observations }, submitText: 'Enregistrer',
      submit: async () => {
        await api.post('treatments', {
          patient_id: consultation.patient_id, consultation_id: consultation.id, treatment_type_id: type.input.value,
          status: status.input.value,
          scheduled_for: status.input.value === 'PLANIFIE' && when.input.value ? new Date(when.input.value).toISOString() : null,
          observations: observations.input.value.trim() || null,
        });
        toast('Soin enregistré.');
        reload();
      },
    });
  }

  // ---------------------------------------------------------------- Examens de la consultation
  const examsBox = h('div', {}, skeleton('line', 2));
  if (session.can('examinations.read')) {
    api.get('examinations', { patient_id: consultation.patient_id, per_page: 100 }).then(({ data }) => {
      const exams = data.filter((e) => e.consultation_id === consultation.id);
      mount(examsBox, exams.length
        ? h('ul', { class: 'item-list' }, exams.map((e) => {
          const [tone, text] = EXAM_STATUS[e.status] || ['neutral', e.status];
          return h('li', {},
            h('div', { class: 'grow' }, h('a', { class: 'cell-link', href: `#/examinations/${e.id}` }, e.examination_type.label),
              h('small', {}, `Prescrit le ${formatDate(e.prescribed_at)}${e.priority === 'URGENTE' ? ' · urgent' : ''}`)),
            h('span', { class: `vsh-badge vsh-badge--${tone}` }, text));
        }))
        : h('p', { class: 'vsh-muted' }, 'Aucun examen prescrit dans cette consultation.'));
    }).catch((error) => mount(examsBox, h('p', { class: 'vsh-field__error' }, error.message)));
  }
  function prescribeExam() {
    const type = field({ label: 'Examen', name: 'examination_type_id', required: true, options: [['', 'Chargement…']] });
    const priority = field({ label: 'Priorité', name: 'priority', options: [['NORMALE', 'Normale'], ['URGENTE', 'Urgente']] });
    const info = textArea('Renseignements cliniques (pour le technicien)', 'clinical_info', '', false);
    api.get('examination-types', { active: 1, per_page: 200 }).then(({ data }) => {
      type.input.replaceChildren(h('option', { value: '' }, 'Choisir un examen'), ...data.map((t) => h('option', { value: t.id }, t.category ? `${t.label} — ${t.category}` : t.label)));
    }).catch(() => type.input.replaceChildren(h('option', { value: '' }, 'Liste indisponible')));
    formDialog({
      title: 'Prescrire un examen', fields: { examination_type_id: type, priority, clinical_info: info }, submitText: 'Prescrire',
      submit: async () => {
        await api.post('examinations', {
          patient_id: consultation.patient_id, consultation_id: consultation.id, examination_type_id: type.input.value,
          priority: priority.input.value, clinical_info: info.input.value.trim() || null,
        });
        toast('Examen prescrit : il apparaît dans la file du technicien.');
        reload();
      },
    });
  }

  // ---------------------------------------------------------------- Mise en page
  const ghost = (text, iconName, onClick) => button({ text, iconName, variant: 'ghost', onClick });
  mount(main, top, allergyBox,
    h('div', { class: 'workspace' },
      h('div', { class: 'stack' },
        card('Observation', obsForm),
        card(closed ? 'Notes et addendums' : 'Notes', notesContent)),
      h('div', { class: 'stack' },
        card('Constantes', vitalsContent, { actions: canVitals ? ghost('Saisir', 'activity', addVitals) : null }),
        card('Diagnostics', diagnosisList, { actions: canWrite && session.can('diagnoses.write') ? ghost('Ajouter', 'clipboard', addDiagnosis) : null }),
        card('Soins', treatmentsContent, { actions: open && session.can('treatments.perform') ? ghost('Ajouter', 'activity', addTreatment) : null }),
        session.can('examinations.read')
          ? card('Examens', examsBox, { actions: open && session.can('examinations.prescribe') ? ghost('Prescrire', 'flask', prescribeExam) : null })
          : null,
        session.can('prescriptions.read') ? prescriptionsCard(consultation, { open, isCurrent }) : null)));
}
