/**
 * Pages du portail patient. Le patient ne voit que ses dossiers (vérifié par le serveur), les résultats
 * VALIDÉS, les ordonnances SIGNÉES et les factures ÉMISES. Aucune interprétation médicale n'est ajoutée :
 * les résultats hors valeurs de référence sont signalés, à discuter avec le médecin.
 */
import { h, mount, uid } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { api, ApiError, download } from '../core/api.js';
import { badge, formatDate, formatDateTime, formatMoney } from '../core/format.js';
import { card, emptyState, errorState, skeleton, loadingRegion, button, field, checkbox, toast, askReason, tabs, setBusy } from '../ui.js';
import { formDialog } from '../dialogs.js';
import { locationPicker } from '../locationPicker.js';

const UPCOMING = ['DEMANDE', 'CONFIRME', 'DEPLACE'];
const OPEN_VISITS = ['NOUVELLE', 'EN_ATTENTE', 'PRISE_EN_CHARGE', 'EN_ROUTE', 'SUR_PLACE', 'EN_COURS'];
const PATIENT_CANCELLABLE = ['NOUVELLE', 'EN_ATTENTE', 'PRISE_EN_CHARGE', 'EN_ROUTE'];
const VISIT_STEPS = [
  ['EN_ATTENTE', 'Demande reçue'], ['PRISE_EN_CHARGE', 'Équipe désignée'], ['EN_ROUTE', 'Équipe en route'],
  ['SUR_PLACE', 'Équipe arrivée'], ['EN_COURS', 'Soins en cours'], ['TERMINEE', 'Visite terminée'],
];

function title(text, sub) {
  return h('div', { class: 'portal-title' }, h('h1', { tabindex: '-1', 'data-page-title': 'true' }, text), sub ? h('p', { class: 'vsh-muted' }, sub) : null);
}

function textarea(label, name, { hint, required, max = 500 } = {}) {
  const f = field({ label, name, hint, required });
  const area = h('textarea', { class: 'vsh-textarea', id: f.input.id, name, rows: '3', maxlength: String(max) });
  if (f.input.getAttribute('aria-describedby')) area.setAttribute('aria-describedby', f.input.getAttribute('aria-describedby'));
  f.input.replaceWith(area);
  f.input = area;
  return f;
}

async function downloadWithToast(path) {
  try {
    const name = await download(path);
    toast(`Document téléchargé : ${name}`);
  } catch (error) {
    toast(error.message, { type: 'error' });
  }
}

function localDay(date = new Date()) {
  return new Intl.DateTimeFormat('fr-CA', { timeZone: 'Africa/Niamey', year: 'numeric', month: '2-digit', day: '2-digit' }).format(date);
}

function pendingNotice(patient) {
  if (patient.status !== 'PENDING') return null;
  return h('div', { class: 'vsh-alert vsh-alert--warning', role: 'status' }, icon('clock', { size: 20 }),
    h('span', {}, 'Votre dossier est en attente de validation par la clinique. Vous pouvez déjà demander un rendez-vous ; les visites à domicile seront possibles après validation.'));
}

/** Progression d'une visite, en langage simple. */
function progress(status) {
  const index = VISIT_STEPS.findIndex(([code]) => code === (status === 'NOUVELLE' ? 'EN_ATTENTE' : status));
  const finished = status === 'TERMINEE' || status === 'FACTUREE';
  return h('ol', { class: 'portal-steps', 'aria-label': 'Avancement de la visite' }, VISIT_STEPS.map(([, label], i) => {
    let state = 'todo';
    if (finished || i < index) state = 'done';
    else if (i === index) state = 'current';
    return h('li', { class: `portal-steps__item is-${state}`, 'aria-current': state === 'current' ? 'step' : null },
      h('span', { class: 'portal-steps__dot', 'aria-hidden': 'true' }),
      h('span', {}, label, state === 'done' ? h('span', { class: 'sr-only' }, ' (fait)') : null));
  }));
}

// ---------------------------------------------------------------- Accueil

export async function homeView(ctx) {
  const { main, setTitle, patient, patients, user, isCurrent } = ctx;
  setTitle('Accueil');
  const firstName = user && user.first_name ? user.first_name : patient.first_name;
  const next = h('div', { class: 'stack' }, loadingRegion(skeleton('block')));
  const visit = h('div', { class: 'stack' });
  const news = h('div', { class: 'stack' });
  const details = [patient.age_years !== null ? `${patient.age_years} ans` : null, patient.sex === 'F' ? 'Femme' : patient.sex === 'M' ? 'Homme' : null].filter(Boolean);
  mount(main,
    title(`Bonjour ${firstName}`, patients.length > 1 ? `Dossier affiché : ${patient.first_name} ${patient.last_name}` : null),
    pendingNotice(patient),
    h('section', { class: 'portal-patient vsh-card' },
      h('div', { class: 'portal-patient__avatar', 'aria-hidden': 'true' }, `${patient.first_name.charAt(0)}${patient.last_name.charAt(0)}`.toUpperCase()),
      h('div', { class: 'stack stack--sm' },
        h('strong', { class: 'portal-patient__name' }, `${patient.first_name} ${patient.last_name.toUpperCase()}`),
        h('span', { class: 'vsh-muted mono' }, patient.file_number || 'Numéro de dossier attribué à la validation'),
        details.length ? h('span', { class: 'vsh-muted' }, details.join(' · ')) : null)),
    h('div', { class: 'portal-actions' },
      h('a', { class: 'portal-action', href: '#/rendez-vous?demande=1' }, icon('calendar'), h('span', {}, 'Demander un rendez-vous')),
      h('a', { class: 'portal-action', href: '#/domicile?demande=1' }, icon('home'), h('span', {}, 'Demander une visite à domicile')),
      h('a', { class: 'portal-action', href: '#/documents' }, icon('fileText'), h('span', {}, 'Mes ordonnances et factures'))),
    h('div', { class: 'portal-grid' },
      card('Prochain rendez-vous', next),
      card('Visite à domicile', visit),
      card('Messages de la clinique', news)));

  const [appointments, visits, notifications] = await Promise.all([
    api.get(`me/patients/${patient.id}/appointments`).then((r) => r.data).catch(() => null),
    api.get(`me/patients/${patient.id}/homecare`).then((r) => r.data).catch(() => null),
    api.get('notifications', { per_page: 5 }).then((r) => r.data).catch(() => null),
  ]);
  if (!isCurrent()) return;

  const upcoming = (appointments || []).filter((a) => UPCOMING.includes(a.status) && new Date(a.scheduled_start) > new Date())
    .sort((a, b) => new Date(a.scheduled_start) - new Date(b.scheduled_start));
  mount(next, upcoming.length
    ? h('div', { class: 'stack stack--sm' },
      h('strong', { class: 'portal-big' }, formatDateTime(upcoming[0].scheduled_start)),
      h('span', {}, upcoming[0].service.label),
      h('div', {}, badge('appointment', upcoming[0].status)),
      h('a', { href: '#/rendez-vous' }, 'Voir mes rendez-vous'))
    : h('div', { class: 'stack stack--sm' }, h('p', { class: 'vsh-muted' }, 'Aucun rendez-vous à venir.'), h('a', { href: '#/rendez-vous?demande=1' }, 'Demander un rendez-vous')));

  const open = (visits || []).find((v) => OPEN_VISITS.includes(v.status));
  mount(visit, open
    ? h('div', { class: 'stack stack--sm' }, h('div', {}, badge('homecare', open.status)), progress(open.status), h('a', { href: '#/domicile' }, 'Suivre ma visite'))
    : h('p', { class: 'vsh-muted' }, 'Aucune visite en cours.'));

  if (!notifications || !notifications.length) {
    mount(news, h('p', { class: 'vsh-muted' }, 'Aucun message.'));
    return;
  }
  const unread = notifications.filter((n) => !n.read_at).length;
  mount(news,
    h('ul', { class: 'plain-list' }, notifications.map((n) => h('li', { class: `portal-note${n.read_at ? '' : ' is-unread'}` },
      h('strong', {}, n.title), n.body ? h('span', {}, n.body) : null, h('small', { class: 'vsh-muted' }, formatDateTime(n.created_at))))),
    unread ? h('div', {}, button({
      text: 'Tout marquer comme lu', variant: 'ghost', size: 'sm',
      onClick: async () => {
        await api.post('notifications/read-all');
        homeView(ctx);
      },
    })) : null);
}

// ---------------------------------------------------------------- Rendez-vous

export async function appointmentsView(ctx) {
  const { main, setTitle, patient, bundle, query, isCurrent } = ctx;
  setTitle('Rendez-vous');
  const services = ((bundle && bundle.services) || []).filter((s) => s.accepts_appointments && s.active !== false);
  const list = h('div', { class: 'stack' }, loadingRegion(skeleton('block')));
  const ask = services.length ? button({ text: 'Demander un rendez-vous', iconName: 'calendar', onClick: () => requestAppointment(ctx, services, load) }) : null;
  mount(main, title('Mes rendez-vous', 'La clinique confirme chaque demande. Vous êtes prévenu par notification.'), pendingNotice(patient),
    ask ? h('div', { class: 'row' }, ask) : h('p', { class: 'vsh-muted' }, 'La prise de rendez-vous en ligne n’est pas ouverte pour le moment. Contactez l’accueil.'),
    list);

  async function load() {
    try {
      const { data } = await api.get(`me/patients/${patient.id}/appointments`);
      if (!isCurrent()) return;
      const now = new Date();
      const future = data.filter((a) => new Date(a.scheduled_start) >= now && UPCOMING.includes(a.status))
        .sort((a, b) => new Date(a.scheduled_start) - new Date(b.scheduled_start));
      const past = data.filter((a) => !future.includes(a)).sort((a, b) => new Date(b.scheduled_start) - new Date(a.scheduled_start));
      const item = (a, cancellable) => h('li', { class: 'portal-item' },
        h('div', { class: 'portal-item__date' }, icon('calendar'), h('strong', {}, formatDateTime(a.scheduled_start))),
        h('div', { class: 'stack stack--sm' },
          h('span', {}, a.service.label, a.practitioner ? ` · ${a.practitioner.name}` : ''),
          h('div', { class: 'row' }, badge('appointment', a.status)),
          a.reason ? h('small', { class: 'vsh-muted' }, a.reason) : null,
          a.cancel_reason ? h('small', { class: 'vsh-muted' }, `Annulé : ${a.cancel_reason}`) : null),
        cancellable ? h('div', {}, button({
          text: 'Annuler', variant: 'secondary', size: 'sm',
          onClick: async () => {
            const reason = await askReason({
              title: 'Annuler ce rendez-vous ?', label: 'Motif (facultatif)', required: false, confirmText: 'Annuler le rendez-vous',
              description: `${formatDateTime(a.scheduled_start)} · ${a.service.label}`,
              submit: (value) => api.post(`appointments/${a.id}/cancel`, { reason: value || null }),
            });
            if (reason !== null) {
              toast('Rendez-vous annulé.');
              load();
            }
          },
        })) : null);
      mount(list,
        h('h2', { class: 'section-title' }, 'À venir'),
        future.length ? h('ul', { class: 'plain-list portal-list' }, future.map((a) => item(a, true))) : h('p', { class: 'vsh-muted' }, 'Aucun rendez-vous à venir.'),
        past.length ? h('details', { class: 'portal-history' }, h('summary', {}, `Historique (${past.length})`), h('ul', { class: 'plain-list portal-list' }, past.map((a) => item(a, false)))) : null);
    } catch (error) {
      if (isCurrent()) mount(list, errorState(error, load));
    }
  }
  load();
  if (query.demande && ask) requestAppointment(ctx, services, load);
}

function requestAppointment(ctx, services, onDone) {
  const { patient } = ctx;
  const service = field({ label: 'Service', name: 'service_id', required: true, options: [['', 'Choisir'], ...services.map((s) => [s.id, s.label])] });
  const day = field({ label: 'Date souhaitée', name: 'date', type: 'date', required: true, value: localDay(), attrs: { min: localDay() } });
  const slotsId = uid('slots');
  const slotsBox = h('div', { class: 'stack', id: slotsId, tabindex: '-1' }, h('p', { class: 'vsh-muted' }, 'Choisissez un service et une date.'));
  const reason = textarea('Motif (facultatif)', 'reason', { hint: 'Quelques mots pour préparer votre venue.' });
  let chosen = null;
  const slotField = { id: slotsId, el: h('div', { class: 'vsh-field' }, h('p', { class: 'vsh-field__label' }, 'Heure'), slotsBox), setError: () => {} };

  async function loadSlots() {
    chosen = null;
    if (!service.input.value || !day.input.value) return;
    mount(slotsBox, loadingRegion(skeleton('line')));
    try {
      const { data } = await api.get('appointments/slots', { service_id: service.input.value, date: day.input.value });
      if (!data.slots.length) {
        mount(slotsBox, h('p', { class: 'vsh-muted' }, 'Aucun créneau ce jour-là. Essayez une autre date.'));
        return;
      }
      const buttons = data.slots.map((slot) => {
        const full = slot.available <= 0;
        const btn = h('button', {
          type: 'button', class: 'slot', 'aria-pressed': 'false', disabled: full,
          'aria-label': `${slot.local_time}, ${full ? 'complet' : 'disponible'}`,
          onclick: () => {
            buttons.forEach((b) => b.setAttribute('aria-pressed', 'false'));
            btn.setAttribute('aria-pressed', 'true');
            chosen = slot;
          },
        }, slot.local_time, h('small', {}, full ? 'Complet' : 'Libre'));
        return btn;
      });
      mount(slotsBox, h('div', { class: 'slots', role: 'group', 'aria-label': 'Créneaux' }, buttons));
    } catch (error) {
      mount(slotsBox, h('p', { class: 'vsh-field__error' }, error.message));
    }
  }
  service.input.addEventListener('change', loadSlots);
  day.input.addEventListener('change', loadSlots);

  formDialog({
    title: 'Demander un rendez-vous',
    description: patient.status === 'PENDING' ? 'Votre dossier sera validé à l’accueil lors de votre venue.' : 'La clinique confirmera votre rendez-vous.',
    fields: { service_id: service, date: day, scheduled_start: slotField, reason },
    submitText: 'Envoyer la demande',
    submit: async () => {
      if (!service.input.value) throw new ApiError(422, 'VALIDATION_ERROR', 'Choisissez un service.', { service_id: ['Choisissez un service.'] });
      if (!chosen) throw new ApiError(422, 'VALIDATION_ERROR', 'Choisissez un créneau.', { scheduled_start: ['Choisissez une heure libre.'] });
      await api.post('appointments', { patient_id: patient.id, service_id: service.input.value, scheduled_start: chosen.start, reason: reason.input.value.trim() || null });
      toast(`Demande envoyée pour le ${formatDateTime(chosen.start)}. La clinique vous confirmera le rendez-vous.`);
      onDone();
    },
  });
}

// ---------------------------------------------------------------- Visites à domicile

export async function homecareView(ctx) {
  const { main, setTitle, patient, query, isCurrent } = ctx;
  setTitle('Visites à domicile');
  const list = h('div', { class: 'stack' }, loadingRegion(skeleton('block')));
  const canAsk = patient.status === 'ACTIVE';
  mount(main, title('Visites à domicile', 'Une équipe de soins se déplace chez vous. Suivez ici l’avancement de la visite.'), pendingNotice(patient),
    h('div', { class: 'vsh-alert vsh-alert--danger', role: 'note' }, icon('alert', { size: 20 }),
      h('span', {}, 'En cas d’urgence vitale (malaise grave, difficulté à respirer, saignement abondant), n’attendez pas : rendez-vous immédiatement aux urgences les plus proches.')),
    canAsk ? h('div', { class: 'row' }, button({ text: 'Demander une visite', iconName: 'home', onClick: () => requestVisit(ctx, load) })) : null,
    list);

  async function load() {
    try {
      const { data } = await api.get(`me/patients/${patient.id}/homecare`);
      if (!isCurrent()) return;
      if (!data.length) {
        mount(list, emptyState({ iconName: 'home', title: 'Aucune visite', text: canAsk ? 'Demandez une visite : la clinique organise le passage d’une équipe.' : null }));
        return;
      }
      mount(list, h('ul', { class: 'plain-list portal-list' }, data.map((v) => h('li', { class: 'portal-item portal-item--visit' },
        h('div', { class: 'row' }, badge('homecare', v.status), v.urgency === 'URGENTE' ? h('span', { class: 'vsh-badge vsh-badge--danger' }, 'Urgent') : null,
          h('small', { class: 'vsh-muted' }, `Demandée le ${formatDateTime(v.created_at)}`)),
        h('span', {}, v.reason),
        OPEN_VISITS.includes(v.status) ? progress(v.status) : null,
        v.team ? h('small', {}, `Équipe : ${v.team.label}`) : null,
        v.departed_at ? h('small', { class: 'vsh-muted' }, `Départ de l’équipe : ${formatDateTime(v.departed_at)}`) : null,
        v.arrived_at ? h('small', { class: 'vsh-muted' }, `Arrivée : ${formatDateTime(v.arrived_at)}`) : null,
        v.cancel_reason ? h('small', { class: 'vsh-muted' }, `Annulée : ${v.cancel_reason}`) : null,
        PATIENT_CANCELLABLE.includes(v.status) ? h('div', {}, button({
          text: 'Annuler la visite', variant: 'secondary', size: 'sm',
          onClick: async () => {
            const reason = await askReason({
              title: 'Annuler la visite ?', label: 'Motif (facultatif)', required: false, confirmText: 'Annuler la visite',
              description: 'L’équipe sera prévenue.',
              submit: (value) => api.post(`homecare/${v.id}/cancel`, { reason: value || null }),
            });
            if (reason !== null) {
              toast('Visite annulée.');
              load();
            }
          },
        })) : null))));
    } catch (error) {
      if (isCurrent()) mount(list, errorState(error, load));
    }
  }
  load();
  if (query.demande && canAsk) requestVisit(ctx, load);
}

async function requestVisit(ctx, onDone) {
  const { patient } = ctx;
  let home = null;
  try {
    const { data } = await api.get(`me/patients/${patient.id}`);
    home = (data.addresses || []).find((a) => a.is_primary) || (data.addresses || [])[0] || null;
  } catch (e) {
    home = null;
  }
  const reason = textarea('Motif de la demande', 'reason', { required: true, max: 2000, hint: 'Ex. pansement, injection, surveillance après une hospitalisation.' });
  const urgent = checkbox({ label: 'C’est urgent', name: 'urgent', hint: 'La régulation traitera la demande en priorité.' });
  const phone = field({ label: 'Téléphone à joindre', name: 'contact_phone', type: 'tel', inputmode: 'tel', required: true, value: patient.phone || '' });
  const address = field({ label: 'Adresse', name: 'address_text', value: home ? [home.address_line, home.district, home.city].filter(Boolean).join(', ') : '', attrs: { maxlength: '255' } });
  const landmark = field({ label: 'Repère', name: 'landmark', value: home && home.landmark ? home.landmark : '', hint: 'Ex. derrière la mosquée, portail bleu.', attrs: { maxlength: '255' } });
  const gps = locationPicker({
    label: 'Position de votre domicile (recommandée)',
    hint: 'Chez vous, appuyez sur « Ma position actuelle » : l’équipe vous trouvera plus facilement.',
    value: home && home.latitude !== null && home.latitude !== undefined
      ? { latitude: Number(home.latitude), longitude: Number(home.longitude), accuracy_m: home.gps_accuracy_m, captured_at: home.gps_captured_at }
      : null,
  });
  formDialog({
    title: 'Demander une visite à domicile',
    description: 'La clinique organise la visite et vous tient informé à chaque étape.',
    size: 'lg',
    fields: { reason, contact_phone: phone, address_text: address, landmark, latitude: gps },
    extra: [urgent],
    submitText: 'Envoyer la demande',
    submit: async () => {
      if (!reason.input.value.trim()) throw new ApiError(422, 'VALIDATION_ERROR', 'Indiquez le motif.', { reason: ['Indiquez le motif de la demande.'] });
      const position = gps.value();
      try {
        await api.post('homecare', {
          patient_id: patient.id,
          reason: reason.input.value.trim(),
          urgency: urgent.input.checked ? 'URGENTE' : 'NORMALE',
          contact_phone: phone.input.value.trim() || null,
          address_text: address.input.value.trim() || null,
          landmark: landmark.input.value.trim() || null,
          ...(position ? { latitude: position.latitude, longitude: position.longitude, gps_accuracy_m: position.accuracy_m, gps_captured_at: position.captured_at } : {}),
        });
      } catch (error) {
        if (error.code === 'HOMECARE_ALREADY_OPEN') throw new ApiError(409, error.code, 'Une visite est déjà en cours pour ce dossier : suivez-la ci-dessous.', {});
        throw error;
      }
      toast('Demande envoyée. Vous serez prévenu quand une équipe sera désignée.');
      onDone();
    },
  });
}

// ---------------------------------------------------------------- Résultats

export async function resultsView(ctx) {
  const { main, setTitle, patient, isCurrent } = ctx;
  setTitle('Résultats');
  const list = h('div', { class: 'stack' }, loadingRegion(skeleton('block')));
  mount(main, title('Mes résultats d’examens', 'Seuls les résultats validés par un médecin sont affichés.'),
    h('p', { class: 'audit-note' }, icon('info'), 'Un résultat signalé « hors valeurs de référence » n’est pas forcément anormal pour vous : parlez-en à votre médecin.'),
    list);
  try {
    const { data } = await api.get(`me/patients/${patient.id}/examinations`);
    if (!isCurrent()) return;
    if (!data.length) {
      mount(list, emptyState({ iconName: 'flask', title: 'Aucun résultat', text: 'Vos résultats apparaîtront ici après validation par le médecin.' }));
      return;
    }
    const value = (r) => (r.value_numeric !== null ? `${String(r.value_numeric).replace('.', ',')}${r.unit ? ` ${r.unit}` : ''}` : r.value_text || '—');
    mount(list, data.map((exam) => card(exam.examination_type.label, h('div', { class: 'stack' },
      h('small', { class: 'vsh-muted' }, `Validé le ${formatDateTime(exam.validated_at)}${exam.validated_by ? ` par ${exam.validated_by.name}` : ''}`),
      (exam.results || []).length ? h('div', { class: 'vsh-table-wrap' }, h('table', { class: 'vsh-table' },
        h('caption', { class: 'sr-only' }, `Résultats : ${exam.examination_type.label}`),
        h('thead', {}, h('tr', {}, ['Paramètre', 'Résultat', 'Référence'].map((th) => h('th', { scope: 'col' }, th)))),
        h('tbody', {}, exam.results.map((r) => h('tr', {},
          h('td', {}, r.label),
          h('td', {}, h('strong', {}, value(r)), r.is_abnormal ? h('div', {}, h('span', { class: 'vsh-badge vsh-badge--warning' }, 'Hors valeurs de référence')) : null),
          h('td', { class: 'vsh-muted' }, r.reference_text || '—')))))) : h('p', { class: 'vsh-muted' }, 'Pas de résultat détaillé.'),
      exam.comment ? h('p', { class: 'note-text' }, exam.comment) : null))));
  } catch (error) {
    if (isCurrent()) mount(list, errorState(error, () => resultsView(ctx)));
  }
}

// ---------------------------------------------------------------- Documents

export async function documentsView(ctx) {
  const { main, setTitle, patient, query, isCurrent } = ctx;
  setTitle('Documents');
  const defs = [{ id: 'ordonnances', label: 'Ordonnances' }, { id: 'factures', label: 'Factures' }];
  const initial = defs.some((d) => d.id === query.onglet) ? query.onglet : 'ordonnances';

  function pdf(path) {
    const btn = button({
      text: 'PDF', iconName: 'fileText', variant: 'secondary', size: 'sm',
      onClick: async () => {
        setBusy(btn, true);
        await downloadWithToast(path);
        setBusy(btn, false);
      },
    });
    return btn;
  }

  async function prescriptions(panel) {
    mount(panel, loadingRegion(skeleton('block')));
    try {
      const { data } = await api.get(`me/patients/${patient.id}/prescriptions`);
      if (!isCurrent()) return;
      if (!data.length) {
        mount(panel, emptyState({ iconName: 'fileText', title: 'Aucune ordonnance', text: 'Vos ordonnances signées par le médecin apparaîtront ici.' }));
        return;
      }
      mount(panel, h('ul', { class: 'plain-list portal-list' }, data.map((p) => h('li', { class: 'portal-item portal-doc' },
        h('div', { class: 'portal-doc__head' },
          h('div', { class: 'stack stack--sm' }, h('strong', {}, `Ordonnance du ${formatDate(p.signed_at)}`), p.prescriber ? h('small', { class: 'vsh-muted' }, p.prescriber.name) : null),
          pdf(`me/patients/${patient.id}/prescriptions/${p.id}/pdf`)),
        (p.items || []).length ? h('ol', { class: 'rx__items' }, p.items.map((it) => h('li', {},
          h('strong', {}, [it.medication_label, it.dosage, it.form].filter(Boolean).join(' ')),
          h('small', {}, [it.posology, it.frequency, it.duration ? `pendant ${it.duration}` : null].filter(Boolean).join(' · ')),
          it.instructions ? h('small', {}, it.instructions) : null))) : null,
        p.notes ? h('p', { class: 'note-text' }, p.notes) : null))));
    } catch (error) {
      if (isCurrent()) mount(panel, errorState(error, () => prescriptions(panel)));
    }
  }

  async function invoices(panel) {
    mount(panel, loadingRegion(skeleton('block')));
    try {
      const { data } = await api.get(`me/patients/${patient.id}/invoices`);
      if (!isCurrent()) return;
      if (!data.length) {
        mount(panel, emptyState({ iconName: 'receipt', title: 'Aucune facture', text: 'Vos factures émises par la clinique apparaîtront ici.' }));
        return;
      }
      mount(panel, h('div', { class: 'stack' },
        h('p', { class: 'audit-note' }, icon('info'), 'Le paiement se fait à la clinique. L’état de règlement affiché est celui enregistré par la clinique.'),
        h('ul', { class: 'plain-list portal-list' }, data.map((inv) => h('li', { class: 'portal-item portal-doc' },
          h('div', { class: 'portal-doc__head' },
            h('div', { class: 'stack stack--sm' }, h('strong', { class: 'mono' }, inv.number), h('small', { class: 'vsh-muted' }, `Émise le ${formatDate(inv.issued_at)}`)),
            pdf(`me/patients/${patient.id}/invoices/${inv.id}/pdf`)),
          h('div', { class: 'row' },
            h('strong', { class: 'portal-big' }, formatMoney(inv.net_amount, inv.currency)),
            inv.status === 'ANNULEE' ? badge('invoice', inv.status) : badge('settlement', inv.settlement_status)),
          inv.status === 'EMISE' && inv.outstanding_amount > 0 ? h('small', {}, `Reste à régler : ${formatMoney(inv.outstanding_amount, inv.currency)}`) : null,
          (inv.items || []).length ? h('details', {}, h('summary', {}, `Détail (${inv.items.length} ligne${inv.items.length > 1 ? 's' : ''})`),
            h('ul', { class: 'plain-list' }, inv.items.map((it) => h('li', { class: 'row' },
              h('span', { class: 'grow' }, `${it.description}${it.quantity > 1 ? ` × ${it.quantity}` : ''}`),
              h('span', { class: 'nowrap' }, formatMoney(it.total_price, inv.currency)))))) : null)))));
    } catch (error) {
      if (isCurrent()) mount(panel, errorState(error, () => invoices(panel)));
    }
  }

  const tabset = tabs(defs, initial, (id, panel) => {
    window.history.replaceState(null, '', `#/documents${id === 'ordonnances' ? '' : `?onglet=${id}`}`);
    if (id === 'ordonnances') prescriptions(panel);
    else invoices(panel);
  });
  mount(main, title('Mes documents', 'Ordonnances signées et factures, à télécharger en PDF.'), tabset.el);
  tabset.select(initial, false);
}
