/**
 * Visites à domicile (C5, D-003) : file des demandes (urgences en tête), fiche avec frise des étapes,
 * actions de la régulation (valider, affecter, réaffecter, annuler) et de l'équipe (accepter, se désister,
 * départ, arrivée, démarrage, fin, échec), historique horodaté et géolocalisé.
 * La géolocalisation (relevés GPS, suivi, partage, proximité) est dans homecareGeo.js.
 * La machine à états est celle du serveur : l'interface ne propose que les actions possibles.
 */
import { h, mount } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { api, ApiError } from '../core/api.js';
import { session } from '../core/session.js';
import { navigate, setQuery } from '../core/router.js';
import { badge, formatDateTime, formatPhone, formatTime, statusInfo } from '../core/format.js';
import { pageHead, card, table, tabs, emptyState, errorState, skeleton, loadingRegion, button, field, askReason, toast, checkbox } from '../ui.js';
import { confirmAction, formDialog } from '../dialogs.js';
import { patientPicker } from '../patientPicker.js';
import { textArea } from './consultation.js';
import { createInvoiceFor } from './billing.js';
import { locationPicker } from '../locationPicker.js';
import { tracker } from '../geo.js';
import {
  FIELD_STATUSES, positionStep, assignDialog, homePositionDialog, geoAlerts, sharingPanel, startSharing, stopSharing, flushSharing, trackingCard,
} from './homecareGeo.js';

const STEPS = ['EN_ATTENTE', 'PRISE_EN_CHARGE', 'EN_ROUTE', 'SUR_PLACE', 'EN_COURS', 'TERMINEE'];
const STEP_LABELS = { EN_ATTENTE: 'En attente', PRISE_EN_CHARGE: 'Prise en charge', EN_ROUTE: 'En route', SUR_PLACE: 'Sur place', EN_COURS: 'Soins en cours', TERMINEE: 'Terminée' };
const OPEN = ['NOUVELLE', 'EN_ATTENTE', 'PRISE_EN_CHARGE', 'EN_ROUTE', 'SUR_PLACE', 'EN_COURS'];

/** « depuis 25 min », « depuis 2 h », « depuis 3 j ». */
export function waiting(iso) {
  const minutes = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 60000));
  if (minutes < 60) return `depuis ${minutes} min`;
  if (minutes < 1440) return `depuis ${Math.round(minutes / 60)} h`;
  return `depuis ${Math.round(minutes / 1440)} j`;
}

export function urgencyBadge(urgency) {
  return urgency === 'URGENTE' ? h('span', { class: 'vsh-badge vsh-badge--danger' }, 'Urgent') : null;
}

function place(v) {
  return v.landmark || v.address_text || (v.location ? 'Position GPS' : '—');
}

// ---------------------------------------------------------------- Nouvelle demande

export function newRequestDialog({ initialPatient = null } = {}) {
  const reason = textArea('Motif de la demande', 'reason', '', false, 'Besoin exprimé : pansement, injection, surveillance… Visible par l’équipe.');
  const urgency = field({ label: 'Urgence', name: 'urgency', options: [['NORMALE', 'Normale'], ['URGENTE', 'Urgente']] });
  const preferred = field({ label: 'Heure souhaitée (facultatif)', name: 'preferred_time', type: 'datetime-local' });
  const phone = field({ label: 'Téléphone à joindre', name: 'contact_phone', type: 'tel', inputmode: 'tel', required: true });
  const address = field({ label: 'Adresse', name: 'address_text', attrs: { maxlength: '255' } });
  const landmark = textArea('Repère', 'landmark', '', false, 'Ex. derrière la mosquée, portail bleu. Indispensable à l’équipe.');
  const gps = locationPicker({
    label: 'Position du domicile (recommandée)',
    hint: 'Reprise du dossier si elle existe. Sinon, placez le point sur la carte ou relevez-le sur place avec « Ma position actuelle ».',
  });

  async function prefill(patient) {
    if (!patient) return;
    try {
      const { data } = await api.get(`patients/${patient.id}`);
      if (!phone.input.value && data.phone) phone.input.value = data.phone;
      const home = (data.addresses || []).find((a) => a.is_primary) || (data.addresses || [])[0];
      if (home) {
        if (!address.input.value) address.input.value = [home.address_line, home.district, home.city].filter(Boolean).join(', ');
        if (!landmark.input.value && home.landmark) landmark.input.value = home.landmark;
        if (home.latitude !== null && home.latitude !== undefined) {
          gps.set({ latitude: home.latitude, longitude: home.longitude, accuracy_m: home.gps_accuracy_m, captured_at: home.gps_captured_at, source: 'record' });
        }
      }
    } catch (e) {
      /* préremplissage facultatif */
    }
  }
  const picker = patientPicker({ onChange: prefill });

  formDialog({
    title: 'Nouvelle demande de visite à domicile',
    description: 'La demande rejoint la file des équipes (ou la régulation, selon le mode d’affectation).',
    size: 'lg',
    fields: { patient_id: picker, reason, urgency, preferred_time: preferred, contact_phone: phone, address_text: address, landmark, latitude: gps },
    submitText: 'Enregistrer la demande',
    submit: async () => {
      const patient = picker.value();
      if (!patient) throw new ApiError(422, 'VALIDATION_ERROR', 'Choisissez un patient.', { patient_id: ['Choisissez un patient.'] });
      const position = gps.value();
      try {
        const { data } = await api.post('homecare', {
          patient_id: patient.id,
          reason: reason.input.value.trim(),
          urgency: urgency.input.value,
          preferred_time: preferred.input.value ? new Date(preferred.input.value).toISOString() : null,
          contact_phone: phone.input.value.trim() || null,
          address_text: address.input.value.trim() || null,
          landmark: landmark.input.value.trim() || null,
          ...(position ? {
            latitude: position.latitude,
            longitude: position.longitude,
            gps_accuracy_m: position.accuracy_m,
            gps_captured_at: position.captured_at,
          } : {}),
        });
        toast('Demande enregistrée.');
        navigate(`/homecare/${data.id}`);
      } catch (error) {
        if (error.code === 'HOMECARE_ALREADY_OPEN') {
          throw new ApiError(409, error.code, 'Une visite est déjà ouverte pour ce patient : retrouvez-la dans la file.', {});
        }
        throw error;
      }
    },
  });
  if (initialPatient) picker.select(initialPatient);
}

// ---------------------------------------------------------------- File

export async function homecareView({ main, setTitle, query, isCurrent }) {
  setTitle('Visites à domicile');
  const dispatch = session.can('homecare.dispatch');
  const intervene = session.can('homecare.intervene');
  const actions = [];
  if (session.canAny('map.read', 'homecare.dispatch')) actions.push(h('a', { class: 'vsh-btn vsh-btn--secondary', href: '#/map' }, icon('map'), 'Carte'));
  if (session.can('homecare.request')) actions.push(button({ text: 'Nouvelle demande', iconName: 'home', onClick: () => newRequestDialog() }));
  const head = pageHead({
    title: 'Visites à domicile',
    subtitle: dispatch ? 'Régulation : demandes à valider et à affecter, visites en cours.' : 'Demandes à prendre en charge et visites de votre équipe.',
    actions,
  });

  const mine = !dispatch && intervene ? checkbox({ label: 'Seulement mon équipe', name: 'mine', checked: query.mine !== '0' }) : null;
  const defs = [{ id: 'queue', label: 'À traiter' }, { id: 'active', label: 'En cours' }, { id: 'closed', label: 'Terminées' }];
  const initial = defs.some((d) => d.id === query.tab) ? query.tab : 'queue';
  let current = { id: initial, panel: null };
  const tabset = tabs(defs, initial, (id, panel) => {
    current = { id, panel };
    setQuery({ tab: id === 'queue' ? null : id });
    load();
  });
  if (mine) mine.input.addEventListener('change', () => load());
  mount(main, head, tabset.el);
  tabset.select(initial, false);

  async function load() {
    const { id, panel } = current;
    const params = { per_page: 100 };
    if (id === 'queue') params.status = dispatch ? 'NOUVELLE,EN_ATTENTE' : 'EN_ATTENTE';
    if (id === 'active') {
      params.status = 'PRISE_EN_CHARGE,EN_ROUTE,SUR_PLACE,EN_COURS';
      if (mine && mine.input.checked) params.mine = 1;
    }
    if (id === 'closed') params.status = 'TERMINEE,FACTUREE,ECHEC,ANNULEE_PATIENT,ANNULEE_CLINIQUE';
    mount(panel, loadingRegion(skeleton('block')));
    try {
      const { data } = await api.get('homecare', params);
      if (!isCurrent() || panel !== current.panel) return;
      const visible = data.filter((v) => v.available !== false);
      const rows = id === 'closed' ? visible.slice().reverse() : visible;
      mount(panel, h('div', { class: 'stack' },
        id === 'active' && mine ? h('div', { class: 'row' }, mine.el) : null,
        rows.length
          ? table({
            caption: 'Visites à domicile',
            columns: [
              { label: 'Urgence', render: (v) => urgencyBadge(v.urgency) || h('span', { class: 'vsh-muted' }, 'Normale') },
              {
                label: 'Demande',
                render: (v) => h('div', {}, h('span', { class: 'nowrap' }, formatDateTime(v.created_at)), OPEN.includes(v.status) ? h('div', { class: 'vsh-muted' }, waiting(v.created_at)) : null),
              },
              {
                label: 'Patient',
                render: (v) => (v.patient
                  ? h('div', {}, h('a', { class: 'cell-link', href: `#/homecare/${v.id}` }, v.patient.name), h('div', { class: 'vsh-muted mono' }, v.patient.file_number || ''))
                  : h('a', { class: 'cell-link', href: `#/homecare/${v.id}` }, 'Ouvrir')),
              },
              { label: 'Lieu', render: (v) => place(v) },
              { label: 'Équipe', render: (v) => (v.team ? v.team.label : h('span', { class: 'vsh-muted' }, 'Aucune')) },
              { label: 'Statut', render: (v) => badge('homecare', v.status) },
              { label: 'Action', render: (v) => h('a', { class: 'vsh-btn vsh-btn--secondary vsh-btn--sm', href: `#/homecare/${v.id}` }, 'Ouvrir') },
            ],
            rows,
          })
          : emptyState({
            iconName: 'home',
            title: id === 'queue' ? 'Aucune demande en attente' : id === 'active' ? 'Aucune visite en cours' : 'Aucune visite terminée',
            text: id === 'queue' ? 'Les nouvelles demandes (accueil ou application patient) apparaîtront ici, les urgences en premier.' : null,
          })));
    } catch (error) {
      if (isCurrent() && panel === current.panel) mount(panel, errorState(error, load));
    }
  }
}

// ---------------------------------------------------------------- Fiche

function stepper(status) {
  const failed = ['ECHEC', 'ANNULEE_PATIENT', 'ANNULEE_CLINIQUE'].includes(status);
  const index = ['TERMINEE', 'FACTUREE'].includes(status) ? STEPS.length : STEPS.indexOf(status === 'NOUVELLE' ? 'EN_ATTENTE' : status);
  return h('ol', { class: 'stepper', 'aria-label': 'Étapes de la visite' }, STEPS.map((step, i) => {
    let state = 'todo';
    if (!failed && i < index) state = 'done';
    else if (!failed && i === index) state = 'current';
    return h('li', { class: `stepper__step stepper__step--${state}`, 'aria-current': state === 'current' ? 'step' : null },
      h('span', { class: 'stepper__dot', 'aria-hidden': 'true' }, state === 'done' ? icon('checkCircle', { size: 14 }) : String(i + 1)),
      h('span', { class: 'stepper__label' }, STEP_LABELS[step], state === 'done' ? h('span', { class: 'sr-only' }, ' (fait)') : null));
  }));
}

export async function homecareDetailView(ctx) {
  const { main, setTitle, params, isCurrent } = ctx;
  setTitle('Visite à domicile');
  mount(main, loadingRegion([skeleton('line'), skeleton('block')]));
  let visit;
  let myTeams = [];
  try {
    const [visitResponse, teamsResponse] = await Promise.all([
      api.get(`homecare/${encodeURIComponent(params.id)}`),
      session.can('homecare.intervene') ? api.get('me/teams').catch(() => ({ data: [] })) : Promise.resolve({ data: [] }),
    ]);
    visit = visitResponse.data;
    myTeams = teamsResponse.data.filter((t) => t.is_mobile);
  } catch (error) {
    if (!isCurrent()) return;
    mount(main, h('a', { class: 'back-link', href: '#/homecare' }, icon('chevronLeft'), 'Visites à domicile'),
      error.status === 404
        ? emptyState({ iconName: 'search', title: 'Visite introuvable', text: 'Elle n’existe pas, ou elle a été prise en charge par une autre équipe.' })
        : errorState(error, () => homecareDetailView(ctx)));
    return;
  }
  if (!isCurrent()) return;
  const reload = () => homecareDetailView(ctx);
  const patientName = visit.patient ? visit.patient.name : 'Patient';
  setTitle(`Visite · ${patientName}`);

  const dispatch = session.can('homecare.dispatch');
  const member = Boolean(visit.team && myTeams.some((t) => t.id === visit.team.id));
  const status = visit.status;

  // ---------------------------------------------------------------- Actions
  const actions = [];
  const withReason = (label, title, path, message, description) => button({
    text: label, variant: 'secondary',
    onClick: async () => {
      const reason = await askReason({
        title, label: 'Motif', confirmText: label, description,
        submit: async (value) => {
          // Points de trajet en attente envoyés avant la clôture (le serveur les refuserait ensuite).
          if (path === 'release' || path === 'fail' || path === 'cancel') await flushSharing(visit);
          return api.post(`homecare/${visit.id}/${path}`, { reason: value });
        },
      });
      if (reason !== null) {
        if (path === 'release' || path === 'fail') stopSharing(visit);
        toast(message);
        reload();
      }
    },
  });
  // Départ et arrivée : position relevée et affichée avant confirmation ; les autres étapes n'en ont pas besoin.
  const fieldStep = (label, iconName, path, message, confirmText) => button({
    text: label, iconName,
    onClick: () => {
      const run = async (position) => {
        const body = position ? { latitude: position.latitude, longitude: position.longitude, accuracy_m: position.accuracy_m } : {};
        if (path === 'complete') await flushSharing(visit);
        const { data } = await api.post(`homecare/${visit.id}/${path}`, body);
        toast(message);
        if (path === 'depart') startSharing(data);
        if (path === 'complete') stopSharing(visit);
        if (path === 'start' && data.consultation_id) navigate(`/consultations/${data.consultation_id}`);
        else reload();
      };
      if (path === 'depart' || path === 'arrive') {
        positionStep({
          title: `${label} ?`,
          confirmText: confirmText || label,
          text: path === 'depart'
            ? 'L’heure et votre position sont enregistrées ; le partage de position démarre ensuite pour que la régulation suive le trajet.'
            : 'L’heure et votre position sont enregistrées : la distance au domicile est contrôlée (information, sans blocage).',
          run,
        });
      } else {
        confirmAction({ title: `${label} ?`, danger: false, confirmText: confirmText || label, text: 'L’heure est enregistrée dans l’historique.', run: () => run(null) });
      }
    },
  });

  if (dispatch && status === 'NOUVELLE') {
    actions.push(button({
      text: 'Valider la demande', iconName: 'checkCircle',
      onClick: () => confirmAction({
        title: 'Valider la demande ?', danger: false, confirmText: 'Valider', text: 'Elle rejoindra la file des équipes.',
        run: async () => {
          await api.post(`homecare/${visit.id}/approve`);
          toast('Demande validée.');
          reload();
        },
      }),
    }));
  }
  if (dispatch && ['NOUVELLE', 'EN_ATTENTE', 'PRISE_EN_CHARGE'].includes(status)) {
    actions.push(button({ text: visit.team ? 'Réaffecter' : 'Affecter une équipe', iconName: 'users', variant: visit.team ? 'secondary' : 'primary', onClick: () => assignDialog(visit, reload) }));
  }
  if (session.can('homecare.intervene') && status === 'EN_ATTENTE') {
    actions.push(button({ text: 'Prendre en charge', iconName: 'userCheck', onClick: () => acceptDialog() }));
  }
  if (member) {
    if (status === 'PRISE_EN_CHARGE') actions.push(fieldStep('Départ', 'arrowRight', 'depart', 'Départ enregistré : le patient est prévenu.', 'Confirmer le départ'));
    if (status === 'EN_ROUTE') actions.push(fieldStep('Arrivée sur place', 'mapPin', 'arrive', 'Arrivée enregistrée.', 'Confirmer l’arrivée'));
    if (status === 'SUR_PLACE') actions.push(fieldStep('Démarrer les soins', 'stethoscope', 'start', 'Visite démarrée : consultation à domicile ouverte.', 'Démarrer'));
    if (status === 'EN_COURS') actions.push(fieldStep('Terminer la visite', 'checkCircle', 'complete', 'Visite terminée.', 'Terminer'));
    if (status === 'PRISE_EN_CHARGE') actions.push(withReason('Se désister', 'Remettre la visite en attente', 'release', 'Visite remise dans la file.', 'La visite retourne dans la file des équipes.'));
    if (['PRISE_EN_CHARGE', 'EN_ROUTE', 'SUR_PLACE', 'EN_COURS'].includes(status)) {
      actions.push(withReason('Échec', 'Déclarer un échec de la visite', 'fail', 'Échec enregistré.', 'Par exemple : patient absent, adresse introuvable, refus de soins.'));
    }
  }
  if (status === 'TERMINEE' && session.can('invoices.manage') && visit.patient) {
    // Brouillon : soins et examens de la visite repris au tarif en vigueur ; l'émission la passe à « facturée ».
    actions.push(button({
      text: 'Facturer la visite', iconName: 'receipt', variant: 'secondary',
      onClick: async () => {
        try {
          await createInvoiceFor({ patientId: visit.patient.id, homecareRequestId: visit.id });
        } catch (error) {
          toast(error.message, { type: 'error' });
        }
      },
    }));
  }
  const ownPending = visit.requested_by && visit.requested_by.id === session.user.id && ['NOUVELLE', 'EN_ATTENTE'].includes(status);
  if ((dispatch && ['NOUVELLE', 'EN_ATTENTE', 'PRISE_EN_CHARGE', 'EN_ROUTE', 'SUR_PLACE'].includes(status)) || ownPending) {
    actions.push(withReason('Annuler', 'Annuler la visite', 'cancel', 'Visite annulée.', 'Le patient et l’équipe éventuelle sont prévenus.'));
  }

  function acceptDialog() {
    if (myTeams.length <= 1) {
      confirmAction({
        title: 'Prendre en charge cette visite ?', danger: false, confirmText: 'Prendre en charge',
        text: myTeams.length ? `Elle sera attribuée à l’équipe ${myTeams[0].label}.` : 'Vous devez appartenir à une équipe mobile active.',
        run: async () => {
          await api.post(`homecare/${visit.id}/accept`, myTeams.length ? { team_id: myTeams[0].id } : {});
          toast('Visite prise en charge par votre équipe.');
          reload();
        },
      });
      return;
    }
    const team = field({ label: 'Équipe', name: 'team_id', options: myTeams.map((t) => [t.id, t.label]) });
    formDialog({
      title: 'Prendre en charge', description: 'Vous appartenez à plusieurs équipes : laquelle intervient ?', fields: { team_id: team }, submitText: 'Prendre en charge',
      submit: async () => {
        await api.post(`homecare/${visit.id}/accept`, { team_id: team.input.value });
        toast('Visite prise en charge.');
        reload();
      },
    });
  }

  // ---------------------------------------------------------------- Contenu
  const { tone } = statusInfo('homecare', status);
  const header = h('section', { class: 'vsh-card stack' },
    h('div', { class: 'page-head' },
      h('div', { class: 'page-head__text' },
        h('div', { class: 'patient-head__name' }, h('h1', { tabindex: '-1', 'data-page-title': 'true' }, patientName), badge('homecare', status), urgencyBadge(visit.urgency)),
        h('div', { class: 'patient-head__meta' },
          visit.patient ? h('a', { class: 'mono', href: `#/patients/${visit.patient.id}` }, icon('fileText'), visit.patient.file_number || 'Dossier') : null,
          h('span', {}, icon('clock'), `Demandée le ${formatDateTime(visit.created_at)}${OPEN.includes(status) ? ` (${waiting(visit.created_at)})` : ''}`),
          h('span', {}, icon('users'), visit.team ? visit.team.label : 'Aucune équipe'))),
      actions.length ? h('div', { class: 'row' }, actions) : null),
    h('div', { class: `stepper-wrap stepper-wrap--${tone}` }, stepper(status)));

  const alerts = [];
  if (visit.cancel_reason) alerts.push(h('div', { class: 'vsh-alert vsh-alert--warning', role: 'status' }, icon('alert', { size: 20 }), h('span', {}, `Annulée : ${visit.cancel_reason}`)));
  if (visit.failure_reason) alerts.push(h('div', { class: 'vsh-alert vsh-alert--danger', role: 'status' }, icon('alert', { size: 20 }), h('span', {}, `Échec : ${visit.failure_reason}`)));
  if (visit.consultation_id) {
    alerts.push(h('div', { class: 'vsh-alert vsh-alert--info', role: 'status' }, icon('stethoscope', { size: 20 }),
      h('span', {}, 'Consultation à domicile : ', h('a', { href: `#/consultations/${visit.consultation_id}` }, 'ouvrir la consultation'), ' (constantes, soins, examens).')));
  }

  const request = card('Demande', h('dl', { class: 'dl' },
    [
      ['Motif', visit.reason],
      ['Heure souhaitée', visit.preferred_time ? formatDateTime(visit.preferred_time) : 'Dès que possible'],
      ['Téléphone', visit.contact_phone ? h('a', { href: `tel:${visit.contact_phone}` }, formatPhone(visit.contact_phone)) : null],
      ['Demandée par', visit.requested_by ? `${visit.requested_by.name}${visit.requested_by.is_patient ? ' (application patient)' : ''}` : null],
    ].map(([label, value]) => h('div', {}, h('dt', {}, label), h('dd', {}, value || '—')))));

  const geoActions = [];
  if (dispatch && OPEN.includes(status)) {
    geoActions.push(button({
      text: visit.location ? 'Corriger la position' : 'Placer le domicile', iconName: 'mapPin', variant: 'ghost', size: 'sm',
      onClick: () => homePositionDialog(visit, { onSite: false, onDone: reload }),
    }));
  } else if (member && ['SUR_PLACE', 'EN_COURS'].includes(status)) {
    geoActions.push(button({
      text: 'Relever la position du domicile', iconName: 'locate', variant: 'ghost', size: 'sm',
      onClick: () => homePositionDialog(visit, { onSite: true, onDone: reload }),
    }));
  }
  const location = trackingCard(visit, { canTrack: dispatch || member, actions: geoActions, isCurrent });
  const sharing = member && FIELD_STATUSES.includes(status) ? card('Partage de position', sharingPanel(visit, { isCurrent })) : null;

  const history = card('Historique', (visit.history || []).length
    ? h('ol', { class: 'timeline' }, visit.history.slice().reverse().map((entry) => h('li', { class: 'timeline__item' },
      h('span', { class: 'timeline__dot', 'aria-hidden': 'true' }),
      h('div', { class: 'stack stack--sm' },
        h('div', { class: 'row' }, badge('homecare', entry.to), h('span', { class: 'vsh-muted' }, `${formatDateTime(entry.at)}${entry.by ? ` · ${entry.by.name}` : ''}`)),
        entry.comment ? h('small', {}, entry.comment) : null,
        entry.latitude !== null && entry.latitude !== undefined ? h('small', { class: 'vsh-muted' }, icon('mapPin', { size: 14 }), 'Position de l’équipe enregistrée') : null,
        entry.received_at && Math.abs(new Date(entry.received_at) - new Date(entry.at)) > 120000
          ? h('small', { class: 'vsh-muted' }, `Reçu à ${formatTime(entry.received_at)} (saisi hors ligne)`) : null))))
    : h('p', { class: 'vsh-muted' }, 'Aucun événement.'));

  mount(main, h('a', { class: 'back-link', href: '#/homecare' }, icon('chevronLeft'), 'Visites à domicile'), header, alerts, geoAlerts(visit, { dispatch }),
    h('div', { class: 'workspace' }, h('div', { class: 'stack' }, location, request), h('div', { class: 'stack' }, sharing, history)));

  // Partage interrompu par un rechargement de page : reprise automatique si l'autorisation est déjà accordée.
  if (member && FIELD_STATUSES.includes(status)) tracker.resume(visit.id);
  else if (!FIELD_STATUSES.includes(status)) stopSharing(visit);
}
