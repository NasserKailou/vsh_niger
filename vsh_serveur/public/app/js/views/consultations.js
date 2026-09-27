/**
 * Consultations : file de travail (en cours, du jour, clôturées) et ouverture d'une consultation.
 * La liste ne montre que des métadonnées ; le contenu clinique s'ouvre dans l'espace de consultation.
 */
import { h, mount } from '../core/dom.js';
import { api, ApiError } from '../core/api.js';
import { session } from '../core/session.js';
import { navigate, setQuery } from '../core/router.js';
import { badge, formatDateTime, formatTime, CONSULTATION_TYPES, TIMEZONE } from '../core/format.js';
import { pageHead, field, button, table, tabs, emptyState, errorState, skeleton, loadingRegion, checkbox, toast } from '../ui.js';
import { formDialog } from '../dialogs.js';
import { patientPicker } from '../patientPicker.js';

const dayFormat = new Intl.DateTimeFormat('en-CA', { timeZone: TIMEZONE, year: 'numeric', month: '2-digit', day: '2-digit' });
const today = () => dayFormat.format(new Date());

/** Ouverture d'une consultation (clinique, suivi, urgence). La consultation à domicile s'ouvre depuis la visite. */
export function newConsultationDialog({ initialPatient = null, appointmentId = null } = {}) {
  const parent = field({ label: 'Consultation d’origine', name: 'parent_consultation_id', options: [['', 'Choisissez d’abord le patient']], hint: 'Obligatoire pour un suivi.' });
  const picker = patientPicker({ initial: null, onChange: (patient) => loadParents(patient) });
  const type = field({ label: 'Type', name: 'consultation_type', required: true, options: [['CLINIQUE', 'Consultation à la clinique'], ['SUIVI', 'Consultation de suivi'], ['URGENCE', 'Urgence']] });
  const complaint = field({ label: 'Motif de consultation', name: 'chief_complaint', attrs: { maxlength: '5000' }, hint: 'Facultatif à l’ouverture. Visible seulement par les soignants.' });
  parent.el.hidden = true;
  type.input.addEventListener('change', () => { parent.el.hidden = type.input.value !== 'SUIVI'; });

  async function loadParents(patient) {
    if (!patient) {
      parent.input.replaceChildren(h('option', { value: '' }, 'Choisissez d’abord le patient'));
      return;
    }
    try {
      const { data } = await api.get(`patients/${patient.id}/consultations`, { per_page: 20 });
      const previous = data.filter((c) => c.status !== 'ANNULEE');
      parent.input.replaceChildren(h('option', { value: '' }, previous.length ? 'Choisir la consultation suivie' : 'Aucune consultation antérieure'),
        ...previous.map((c) => h('option', { value: c.id }, `${formatDateTime(c.started_at)} — ${CONSULTATION_TYPES[c.consultation_type] || c.consultation_type}`)));
    } catch (e) {
      parent.input.replaceChildren(h('option', { value: '' }, 'Liste indisponible'));
    }
  }

  formDialog({
    title: 'Nouvelle consultation',
    description: appointmentId ? 'Ouverte depuis le rendez-vous : celui-ci sera marqué « honoré ».' : 'Le praticien connecté est enregistré comme praticien de la consultation.',
    fields: { patient_id: picker, consultation_type: type, parent_consultation_id: parent, chief_complaint: complaint },
    submitText: 'Ouvrir la consultation',
    submit: async () => {
      const patient = picker.value();
      if (!patient) throw new ApiError(422, 'VALIDATION_ERROR', 'Choisissez un patient.', { patient_id: ['Choisissez un patient.'] });
      const { data } = await api.post('consultations', {
        patient_id: patient.id,
        consultation_type: type.input.value,
        parent_consultation_id: type.input.value === 'SUIVI' ? (parent.input.value || null) : null,
        chief_complaint: complaint.input.value.trim() || null,
        appointment_id: appointmentId,
      });
      toast('Consultation ouverte.');
      navigate(`/consultations/${data.id}`);
    },
  });
  if (initialPatient) picker.select(initialPatient);
}

function consultationsTable(rows, { showDate }) {
  return table({
    caption: 'Consultations',
    columns: [
      { label: showDate ? 'Date' : 'Heure', render: (c) => h('span', { class: showDate ? 'nowrap' : 'time-cell' }, showDate ? formatDateTime(c.started_at) : formatTime(c.started_at)) },
      {
        label: 'Patient',
        render: (c) => (c.patient
          ? h('div', {}, h('a', { class: 'cell-link', href: `#/consultations/${c.id}` }, c.patient.name), h('div', { class: 'vsh-muted mono' }, c.patient.file_number || ''))
          : h('a', { class: 'cell-link', href: `#/consultations/${c.id}` }, 'Ouvrir')),
      },
      { label: 'Type', render: (c) => (c.consultation_type === 'URGENCE' ? h('span', { class: 'vsh-badge vsh-badge--danger' }, 'Urgence') : CONSULTATION_TYPES[c.consultation_type] || c.consultation_type) },
      { label: 'Praticien', render: (c) => (c.practitioner ? c.practitioner.name : h('span', { class: 'vsh-muted' }, 'Non attribué')) },
      { label: 'Statut', render: (c) => badge('consultation', c.status) },
      {
        label: 'Action',
        render: (c) => h('a', { class: 'vsh-btn vsh-btn--secondary vsh-btn--sm', href: `#/consultations/${c.id}` }, ['CLOTUREE', 'ANNULEE'].includes(c.status) ? 'Voir' : 'Reprendre'),
      },
    ],
    rows,
  });
}

export async function consultationsView({ main, setTitle, query, isCurrent }) {
  setTitle('Consultations');
  const canCreate = session.can('consultations.create');
  const practitioner = session.can('consultations.update');
  const head = pageHead({
    title: 'Consultations',
    subtitle: 'File de travail : consultations en cours, du jour et clôturées.',
    actions: canCreate ? button({ text: 'Nouvelle consultation', iconName: 'stethoscope', onClick: () => newConsultationDialog() }) : null,
  });
  const mine = practitioner ? checkbox({ label: 'Seulement les miennes', name: 'mine', checked: query.mine !== '0' }) : null;
  const tabDefs = [{ id: 'open', label: 'En cours' }, { id: 'today', label: 'Aujourd’hui' }, { id: 'closed', label: 'Clôturées' }];
  const initial = tabDefs.some((t) => t.id === query.tab) ? query.tab : 'open';
  let currentPanel = null;
  let currentTab = initial;

  const tabset = tabs(tabDefs, initial, (id, panel) => {
    currentTab = id;
    currentPanel = panel;
    setQuery({ tab: id === 'open' ? null : id, mine: mine && !mine.input.checked ? '0' : null });
    load();
  });
  if (mine) mine.input.addEventListener('change', () => tabset.select(currentTab, false));
  mount(main, head, mine ? h('div', { class: 'row' }, mine.el) : null, tabset.el);
  tabset.select(initial, false);

  async function load() {
    const panel = currentPanel;
    const params = { per_page: 100, mine: mine && mine.input.checked ? 1 : null };
    if (currentTab === 'today') {
      params.from = today();
      params.to = today();
    } else if (currentTab === 'closed') {
      params.status = 'CLOTUREE';
    }
    mount(panel, loadingRegion(skeleton('block')));
    try {
      let rows;
      if (currentTab === 'open') {
        const [opened, inProgress] = await Promise.all([
          api.get('consultations', { ...params, status: 'OUVERTE' }),
          api.get('consultations', { ...params, status: 'EN_COURS' }),
        ]);
        rows = [...opened.data, ...inProgress.data].sort((a, b) => a.started_at.localeCompare(b.started_at));
      } else {
        rows = (await api.get('consultations', params)).data;
      }
      if (!isCurrent() || panel !== currentPanel) return;
      mount(panel, rows.length
        ? consultationsTable(rows, { showDate: currentTab !== 'today' })
        : emptyState({
          iconName: 'stethoscope',
          title: currentTab === 'open' ? 'Aucune consultation en cours' : currentTab === 'today' ? 'Aucune consultation aujourd’hui' : 'Aucune consultation clôturée',
          text: canCreate ? 'Ouvrez une consultation avec le bouton ci-dessus, ou depuis l’agenda à l’arrivée du patient.' : null,
        }));
    } catch (error) {
      if (isCurrent() && panel === currentPanel) mount(panel, errorState(error, load));
    }
  }

  if (query.new && canCreate) {
    const initialPatient = query.patient ? await api.get(`patients/${encodeURIComponent(query.patient)}`).then((r) => r.data).catch(() => null) : null;
    setQuery({});
    if (isCurrent()) newConsultationDialog({ initialPatient, appointmentId: query.appointment || null });
  }
}
