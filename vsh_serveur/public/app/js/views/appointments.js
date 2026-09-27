/**
 * Rendez-vous : agenda du jour (accueil : tout le planning ; praticien : le sien), demandes à confirmer,
 * prise de rendez-vous sur les créneaux libres calculés par le serveur, confirmation (praticien),
 * déplacement, annulation motivée, arrivée du patient et absence.
 */
import { h, mount } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { api, ApiError } from '../core/api.js';
import { session } from '../core/session.js';
import { setQuery } from '../core/router.js';
import { badge, formatTime, formatDateLong, formatDateTime, TIMEZONE } from '../core/format.js';
import { pageHead, field, button, table, tabs, emptyState, errorState, skeleton, loadingRegion, askReason, iconButton, toast, setBusy } from '../ui.js';
import { confirmAction, formDialog } from '../dialogs.js';
import { patientPicker } from '../patientPicker.js';

// ---------------------------------------------------------------- Dates locales (fuseau de la clinique)

const dayFormat = new Intl.DateTimeFormat('en-CA', { timeZone: TIMEZONE, year: 'numeric', month: '2-digit', day: '2-digit' });
const localDay = (date = new Date()) => dayFormat.format(date);
function addDays(day, count) {
  const [y, m, d] = day.split('-').map(Number);
  return new Date(Date.UTC(y, m - 1, d + count, 12)).toISOString().slice(0, 10);
}
const dayToDate = (day) => new Date(`${day}T12:00:00Z`);

// ---------------------------------------------------------------- Listes de référence (mises en cache pour la session)

let servicesCache = null;
async function services() {
  if (!servicesCache) {
    const { data } = await api.get('services', { active: 1, per_page: 200 });
    servicesCache = data.filter((s) => s.accepts_appointments !== false);
  }
  return servicesCache;
}

let practitionersCache = null;
async function practitioners() {
  if (!practitionersCache) {
    practitionersCache = (await api.get('staff/directory')).data.filter((p) => ['MEDECIN', 'INFIRMIER', 'SAGE_FEMME'].includes(p.profession));
  }
  return practitionersCache;
}

function practitionerOptions(select, current) {
  practitioners().then((list) => {
    select.replaceChildren(h('option', { value: '' }, 'À attribuer plus tard'),
      ...list.map((p) => h('option', { value: p.id, selected: p.id === current }, `${p.name}${p.speciality ? ` — ${p.speciality}` : ''}`)));
  }).catch(() => select.replaceChildren(h('option', { value: '' }, 'Liste indisponible')));
}

// ---------------------------------------------------------------- Sélecteur de créneau

/** Grille des créneaux d'un service pour une date. @returns {{el, load, value}} */
function slotPicker() {
  const region = h('div', { class: 'stack stack--sm', 'aria-live': 'polite' });
  const wrap = h('fieldset', { class: 'fieldset' }, h('legend', {}, 'Créneau', h('span', { 'aria-hidden': 'true' }, ' *')), region);
  let chosen = null;

  async function load(serviceId, day) {
    chosen = null;
    if (!serviceId || !day) {
      mount(region, h('p', { class: 'vsh-muted' }, 'Choisissez un service et une date pour voir les créneaux libres.'));
      return;
    }
    mount(region, skeleton('line', 2));
    try {
      const { data } = await api.get('appointments/slots', { service_id: serviceId, date: day });
      if (!data.slots.length) {
        mount(region, h('p', { class: 'vsh-muted' }, 'Aucun créneau à venir ce jour-là (service fermé ou journée passée). Essayez une autre date.'));
        return;
      }
      const buttons = data.slots.map((slot) => {
        const full = slot.available <= 0;
        const btn = h('button', {
          type: 'button', class: 'slot', 'aria-pressed': 'false', disabled: full,
          'aria-label': `${slot.local_time}, ${full ? 'complet' : `${slot.available} place(s) libre(s)`}`,
          onclick: () => {
            buttons.forEach((b) => b.setAttribute('aria-pressed', 'false'));
            btn.setAttribute('aria-pressed', 'true');
            chosen = slot;
          },
        }, slot.local_time, h('small', {}, full ? 'Complet' : `${slot.available} libre${slot.available > 1 ? 's' : ''}`));
        return btn;
      });
      mount(region, h('div', { class: 'slots' }, buttons));
    } catch (error) {
      mount(region, h('p', { class: 'vsh-field__error' }, icon('alert', { size: 16 }), error.message));
    }
  }
  return { el: wrap, load, value: () => chosen };
}

// ---------------------------------------------------------------- Dialogues

function newAppointmentDialog({ initialPatient, onDone }) {
  const picker = patientPicker({ initial: initialPatient });
  const service = field({ label: 'Service', name: 'service_id', required: true, options: [['', 'Chargement…']] });
  const date = field({ label: 'Date', name: 'date', type: 'date', required: true, value: localDay(), attrs: { min: localDay() } });
  const practitioner = field({ label: 'Praticien', name: 'practitioner_id', options: [['', 'Chargement…']], hint: 'Facultatif : peut être attribué plus tard.' });
  const reason = field({ label: 'Motif (facultatif)', name: 'reason', attrs: { maxlength: '500' }, hint: 'Visible par l’équipe soignante et par le patient.' });
  const slots = slotPicker();
  const reloadSlots = () => slots.load(service.input.value, date.input.value);
  service.input.addEventListener('change', reloadSlots);
  date.input.addEventListener('change', reloadSlots);
  services().then((list) => {
    service.input.replaceChildren(h('option', { value: '' }, 'Choisir un service'), ...list.map((s) => h('option', { value: s.id }, s.label)));
    if (list.length === 1) {
      service.input.value = list[0].id;
      reloadSlots();
    }
  }).catch(() => service.input.replaceChildren(h('option', { value: '' }, 'Liste indisponible')));
  practitionerOptions(practitioner.input, null);
  slots.load(null, null);

  formDialog({
    title: 'Nouveau rendez-vous',
    description: 'Un rendez-vous pris par l’accueil est confirmé immédiatement ; le patient est prévenu.',
    size: 'lg',
    fields: { patient_id: picker, service_id: service, scheduled_start: date },
    extra: [slots.el, practitioner, reason],
    submitText: 'Enregistrer le rendez-vous',
    submit: async () => {
      const patient = picker.value();
      const slot = slots.value();
      if (!patient) throw new ApiError(422, 'VALIDATION_ERROR', 'Choisissez un patient.', { patient_id: ['Choisissez un patient.'] });
      if (!slot) throw new ApiError(422, 'VALIDATION_ERROR', 'Choisissez un créneau.', { scheduled_start: ['Choisissez un créneau libre.'] });
      try {
        await api.post('appointments', {
          patient_id: patient.id, service_id: service.input.value, scheduled_start: slot.start,
          practitioner_id: practitioner.input.value || null, reason: reason.input.value.trim() || null,
        });
      } catch (error) {
        if (error.code === 'SLOT_FULL') reloadSlots();
        throw error;
      }
      toast(`Rendez-vous enregistré le ${formatDateTime(slot.start)}.`);
      onDone(date.input.value);
    },
  });
}

function rescheduleDialog(appointment, onDone) {
  const date = field({ label: 'Nouvelle date', name: 'date', type: 'date', required: true, value: localDay(new Date(appointment.scheduled_start)), attrs: { min: localDay() } });
  const practitioner = field({ label: 'Praticien', name: 'practitioner_id', options: [['', 'Chargement…']] });
  const slots = slotPicker();
  date.input.addEventListener('change', () => slots.load(appointment.service.id, date.input.value));
  practitionerOptions(practitioner.input, appointment.practitioner ? appointment.practitioner.id : null);
  slots.load(appointment.service.id, date.input.value);
  formDialog({
    title: 'Déplacer le rendez-vous',
    description: `${appointment.patient.name} · ${appointment.service.label} · actuellement le ${formatDateTime(appointment.scheduled_start)}`,
    size: 'lg',
    fields: { scheduled_start: date },
    extra: [slots.el, practitioner],
    submitText: 'Déplacer',
    submit: async () => {
      const slot = slots.value();
      if (!slot) throw new ApiError(422, 'VALIDATION_ERROR', 'Choisissez un créneau.', { scheduled_start: ['Choisissez un créneau libre.'] });
      await api.post(`appointments/${appointment.id}/reschedule`, { scheduled_start: slot.start, practitioner_id: practitioner.input.value || null });
      toast('Rendez-vous déplacé ; le patient est prévenu.');
      onDone(date.input.value);
    },
  });
}

function confirmDialog(appointment, onDone) {
  const practitioner = field({ label: 'Praticien', name: 'practitioner_id', options: [['', 'Chargement…']], hint: 'Le rendez-vous apparaîtra dans son planning.' });
  practitionerOptions(practitioner.input, appointment.practitioner ? appointment.practitioner.id : null);
  formDialog({
    title: 'Confirmer la demande',
    description: `${appointment.patient.name} · ${appointment.service.label} · ${formatDateTime(appointment.scheduled_start)}`,
    fields: { practitioner_id: practitioner },
    submitText: 'Confirmer',
    submit: async () => {
      await api.post(`appointments/${appointment.id}/confirm`, { practitioner_id: practitioner.input.value || null });
      toast('Rendez-vous confirmé ; le patient est prévenu.');
      onDone();
    },
  });
}

// ---------------------------------------------------------------- Actions d'une ligne

function small(btn) {
  btn.classList.add('vsh-btn--sm');
  return btn;
}

function rowActions(a, refresh) {
  if (!session.can('appointments.manage')) return null;
  const actions = [];
  const pending = ['DEMANDE', 'CONFIRME', 'DEPLACE'].includes(a.status);
  const confirmed = ['CONFIRME', 'DEPLACE'].includes(a.status);
  const started = new Date(a.scheduled_start) <= new Date();
  const today = localDay(new Date(a.scheduled_start)) === localDay();
  if (['DEMANDE', 'DEPLACE'].includes(a.status)) {
    actions.push(small(button({ text: 'Confirmer', iconName: 'checkCircle', onClick: () => confirmDialog(a, refresh) })));
  }
  if (confirmed && today && !a.checked_in_at) {
    actions.push(small(button({
      text: 'Arrivé', iconName: 'userCheck', variant: 'secondary',
      onClick: async (event) => {
        const btn = event.currentTarget;
        setBusy(btn, true);
        try {
          await api.post(`appointments/${a.id}/check-in`);
          toast(`Arrivée de ${a.patient.name} enregistrée.`);
          refresh();
        } catch (error) {
          toast(error.message, { type: 'error' });
          setBusy(btn, false);
        }
      },
    })));
  }
  if (confirmed && a.checked_in_at && session.can('consultations.create')) {
    actions.push(small(h('a', {
      class: 'vsh-btn vsh-btn--primary',
      href: `#/consultations?new=1&patient=${a.patient.id}&appointment=${a.id}`,
    }, icon('stethoscope'), 'Consulter')));
  }
  if (confirmed && started && !a.checked_in_at) {
    actions.push(small(button({
      text: 'Absent', variant: 'secondary',
      onClick: () => confirmAction({
        title: 'Déclarer le patient absent ?', confirmText: 'Déclarer absent',
        text: `${a.patient.name} ne s’est pas présenté(e) au rendez-vous de ${formatTime(a.scheduled_start)}.`,
        run: async () => {
          await api.post(`appointments/${a.id}/no-show`);
          refresh();
        },
      }),
    })));
  }
  if (pending) {
    actions.push(
      iconButton('calendar', `Déplacer le rendez-vous de ${a.patient.name}`, () => rescheduleDialog(a, refresh)),
      iconButton('x', `Annuler le rendez-vous de ${a.patient.name}`, async () => {
        const reason = await askReason({
          title: 'Annuler le rendez-vous', label: 'Motif de l’annulation', confirmText: 'Annuler le rendez-vous',
          description: `${a.patient.name} · ${formatDateTime(a.scheduled_start)}. Le patient sera prévenu.`,
          submit: (value) => api.post(`appointments/${a.id}/cancel`, { reason: value }),
        });
        if (reason !== null) {
          toast('Rendez-vous annulé.');
          refresh();
        }
      }));
  }
  return actions.length ? h('div', { class: 'row-actions' }, actions) : null;
}

function appointmentsTable(rows, refresh, { showDate = false } = {}) {
  const columns = [
    {
      label: showDate ? 'Date' : 'Heure',
      render: (a) => h('span', { class: showDate ? 'nowrap' : 'time-cell' }, showDate ? formatDateTime(a.scheduled_start) : formatTime(a.scheduled_start)),
    },
    { label: 'Patient', render: (a) => h('div', {}, h('a', { class: 'cell-link', href: `#/patients/${a.patient.id}` }, a.patient.name), h('div', { class: 'vsh-muted mono' }, a.patient.file_number || '')) },
    { label: 'Service', render: (a) => h('div', {}, a.service.label, a.reason ? h('div', { class: 'vsh-muted' }, a.reason) : null) },
    { label: 'Praticien', render: (a) => (a.practitioner ? a.practitioner.name : h('span', { class: 'vsh-muted' }, 'À attribuer')) },
    {
      label: 'Statut',
      render: (a) => h('div', { class: 'row-actions' }, badge('appointment', a.status),
        a.checked_in_at && ['CONFIRME', 'DEPLACE'].includes(a.status) ? h('span', { class: 'vsh-badge vsh-badge--success' }, `Arrivé ${formatTime(a.checked_in_at)}`) : null,
        a.requested_by_patient ? h('span', { class: 'vsh-badge vsh-badge--neutral' }, 'Via l’application') : null),
    },
  ];
  if (session.can('appointments.manage')) columns.push({ label: 'Actions', render: (a) => rowActions(a, refresh) || '—' });
  return table({ caption: 'Rendez-vous', columns, rows });
}

// ---------------------------------------------------------------- Page

export async function appointmentsView({ main, setTitle, query, isCurrent }) {
  setTitle('Rendez-vous');
  const manage = session.can('appointments.manage');
  let day = /^\d{4}-\d{2}-\d{2}$/.test(query.date || '') ? query.date : localDay();

  const newButton = manage ? button({ text: 'Nouveau rendez-vous', iconName: 'calendar', onClick: () => openNew(null) }) : null;
  const head = pageHead({ title: 'Rendez-vous', subtitle: manage ? 'Agenda de la clinique, demandes à confirmer et prise de rendez-vous.' : 'Votre planning de rendez-vous.', actions: newButton });

  if (!manage) {
    const panel = h('div', { class: 'stack' });
    mount(main, head, panel);
    agendaPanel(panel);
    return;
  }
  const initialTab = query.tab === 'requests' ? 'requests' : 'agenda';
  const tabset = tabs([{ id: 'agenda', label: 'Agenda' }, { id: 'requests', label: 'Demandes à confirmer' }], initialTab, (id, panel) => {
    setQuery(id === 'requests' ? { tab: 'requests' } : { date: day });
    if (id === 'requests') requestsPanel(panel);
    else agendaPanel(panel);
  });
  mount(main, head, tabset.el);
  tabset.select(initialTab, false);

  if (query.new) {
    const initial = query.patient ? await api.get(`patients/${encodeURIComponent(query.patient)}`).then((r) => r.data).catch(() => null) : null;
    setQuery({ date: day });
    if (isCurrent()) openNew(initial);
  }

  function openNew(initialPatient) {
    newAppointmentDialog({
      initialPatient,
      onDone: (newDay) => {
        day = newDay;
        tabset.select('agenda', false);
      },
    });
  }

  // ---------------------------------------------------------------- Agenda du jour
  function agendaPanel(panel) {
    const label = h('span', { class: 'date-nav__label', 'aria-live': 'polite' });
    const dateInput = field({ label: 'Aller au', name: 'day', type: 'date', value: day });
    const serviceFilter = field({ label: 'Service', name: 'service', options: [['', 'Tous les services']] });
    const statusFilter = field({
      label: 'Statut', name: 'status',
      options: [['', 'Tous'], ['DEMANDE', 'Demandé'], ['CONFIRME', 'Confirmé'], ['DEPLACE', 'Déplacé'], ['HONORE', 'Honoré'], ['ABSENT', 'Absent'], ['ANNULE', 'Annulé']],
    });
    const results = h('div', { 'aria-live': 'polite' });
    if (manage) services().then((list) => serviceFilter.input.append(...list.map((s) => h('option', { value: s.id }, s.label)))).catch(() => {});

    const go = (newDay) => {
      day = newDay;
      dateInput.input.value = day;
      setQuery({ date: day });
      load();
    };
    const nav = h('div', { class: 'date-nav', role: 'group', 'aria-label': 'Changer de jour' },
      iconButton('chevronLeft', 'Jour précédent', () => go(addDays(day, -1))),
      label,
      iconButton('chevronRight', 'Jour suivant', () => go(addDays(day, 1))));
    dateInput.input.addEventListener('change', () => { if (dateInput.input.value) go(dateInput.input.value); });
    serviceFilter.input.addEventListener('change', () => load());
    statusFilter.input.addEventListener('change', () => load());

    async function load() {
      label.textContent = formatDateLong(dayToDate(day));
      mount(results, loadingRegion(skeleton('block')));
      try {
        const { data } = await api.get('appointments', { date: day, service_id: serviceFilter.input.value, status: statusFilter.input.value, per_page: 100 });
        if (!isCurrent()) return;
        mount(results, data.length
          ? appointmentsTable(data, load)
          : emptyState({
            iconName: 'calendar', title: 'Aucun rendez-vous ce jour',
            text: manage ? 'Utilisez « Nouveau rendez-vous » pour en programmer un.' : 'Aucun rendez-vous ne vous est attribué ce jour.',
          }));
      } catch (error) {
        if (isCurrent()) mount(results, errorState(error, load));
      }
    }

    mount(panel, h('div', { class: 'stack' },
      h('div', { class: 'toolbar' },
        h('div', { class: 'row' }, nav, button({ text: 'Aujourd’hui', variant: 'secondary', onClick: () => go(localDay()) })),
        dateInput.el, manage ? serviceFilter.el : null, statusFilter.el),
      results));
    load();
  }

  // ---------------------------------------------------------------- Demandes à confirmer
  function requestsPanel(panel) {
    const results = h('div', { 'aria-live': 'polite' });
    async function load() {
      mount(results, loadingRegion(skeleton('block')));
      try {
        const { data, meta } = await api.get('appointments', { status: 'DEMANDE', from: localDay(), per_page: 100 });
        if (!isCurrent()) return;
        mount(results, data.length
          ? h('div', { class: 'stack' },
            h('p', { class: 'vsh-muted' }, `${meta ? meta.total : data.length} demande(s) faite(s) depuis l’application patient. Confirmez en attribuant un praticien, ou proposez un autre créneau.`),
            appointmentsTable(data, load, { showDate: true }))
          : emptyState({ iconName: 'checkCircle', title: 'Aucune demande en attente', text: 'Les demandes faites depuis l’application patient apparaîtront ici.' }));
      } catch (error) {
        if (isCurrent()) mount(results, errorState(error, load));
      }
    }
    mount(panel, results);
    load();
  }
}
