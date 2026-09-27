/**
 * Tableau de bord : indicateurs clés selon les droits de l'utilisateur, planning du jour,
 * répartition des visites à domicile et des rendez-vous. Aucune donnée médicale.
 */
import { h, mount } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { api } from '../core/api.js';
import { session } from '../core/session.js';
import { badge, statusInfo, formatDateLong, formatTime, formatNumber, formatMoney } from '../core/format.js';
import { pageHead, card, table, emptyState, errorState, skeleton, loadingRegion, button } from '../ui.js';

function kpi({ label, value, iconName, tone, href, hint, alert }) {
  const content = [
    h('div', { class: 'kpi-card__top' },
      h('span', { class: 'vsh-label' }, label),
      h('span', { class: 'kpi-card__icon', 'aria-hidden': 'true' }, icon(iconName))),
    h('span', { class: 'vsh-kpi__value' }, value),
    hint ? h('span', { class: 'kpi-card__hint' }, hint) : null,
  ];
  const cls = ['kpi-card', tone && `kpi-card--${tone}`, alert && 'kpi-card--alert'];
  return href ? h('a', { class: cls, href }, content) : h('div', { class: cls }, content);
}

function sum(counts = {}) {
  return Object.values(counts).reduce((total, n) => total + n, 0);
}

function statusBreakdown(domain, counts, order) {
  const total = sum(counts);
  if (total === 0) return null;
  return h('ul', { class: 'status-list' }, order.filter((status) => counts[status]).map((status) => {
    const { tone } = statusInfo(domain, status);
    const bar = h('span', {});
    bar.style.width = `${Math.max(4, Math.round((counts[status] / total) * 100))}%`;
    return h('li', {},
      badge(domain, status),
      h('span', { class: 'status-list__count' }, formatNumber(counts[status])),
      h('div', { class: `status-list__bar status-list__bar--${tone}`, 'aria-hidden': 'true' }, bar));
  }));
}

export async function dashboardView({ main, setTitle, isCurrent }) {
  setTitle('Tableau de bord');
  const user = session.user;
  const refresh = button({ text: 'Actualiser', iconName: 'refresh', variant: 'secondary', onClick: () => load() });
  const head = pageHead({ title: `Bonjour, ${user.first_name}`, subtitle: formatDateLong(new Date()), actions: refresh });
  const body = h('div', { class: 'stack' });
  mount(main, head, body);

  async function load() {
    mount(body, loadingRegion(h('div', { class: 'kpis' }, skeleton('kpi', 4))));
    let data;
    try {
      ({ data } = await api.get('dashboard'));
    } catch (error) {
      if (isCurrent()) mount(body, errorState(error, load));
      return;
    }
    if (isCurrent()) render(data);
  }

  function render(data) {
    const cards = [];
    const currency = data.currency;
    if (data.activity) {
      cards.push(
        kpi({ label: 'Patients actifs', value: formatNumber(data.activity.patients_active), iconName: 'users', href: '#/patients' }),
        kpi({ label: 'Nouveaux ce mois', value: formatNumber(data.activity.patients_new_month), iconName: 'userPlus' }),
        kpi({ label: 'Consultations du jour', value: formatNumber(data.activity.consultations_today), iconName: 'stethoscope', tone: 'info' }),
        kpi({ label: 'Soins réalisés du jour', value: formatNumber(data.activity.treatments_today), iconName: 'activity', tone: 'info' }));
    }
    if (data.registrations_pending !== undefined) {
      cards.push(kpi({
        label: 'Inscriptions à valider', value: formatNumber(data.registrations_pending), iconName: 'userCheck',
        tone: data.registrations_pending > 0 ? 'warning' : null, href: '#/patients?tab=pending',
        hint: data.registrations_pending > 0 ? 'Comptes créés depuis l’application' : 'Aucune en attente',
      }));
    }
    if (data.appointments_today) {
      cards.push(kpi({
        label: 'Rendez-vous du jour', value: formatNumber(sum(data.appointments_today)), iconName: 'calendar', href: '#/appointments',
        hint: data.appointments_to_confirm ? `${formatNumber(data.appointments_to_confirm)} demande(s) à confirmer` : 'Aucune demande à confirmer',
        tone: data.appointments_to_confirm ? 'warning' : null,
      }));
    }
    if (data.homecare) {
      const urgent = data.homecare.urgent_waiting;
      cards.push(
        kpi({
          label: 'Urgences à domicile', value: formatNumber(urgent), iconName: 'alert', tone: urgent > 0 ? 'danger' : null, alert: urgent > 0,
          href: '#/homecare', hint: urgent > 0 ? 'En attente d’une équipe' : 'Aucune urgence en attente',
        }),
        kpi({ label: 'Visites ouvertes', value: formatNumber(sum(data.homecare.by_status)), iconName: 'home', href: '#/homecare', hint: `${formatNumber(data.homecare.completed_today)} terminée(s) aujourd’hui` }));
    }
    if (data.my_team_visits) {
      cards.push(
        kpi({ label: 'Visites en attente', value: formatNumber(data.my_team_visits.waiting), iconName: 'clock', tone: data.my_team_visits.waiting > 0 ? 'warning' : null, href: '#/homecare' }),
        kpi({ label: 'Visites de mon équipe', value: formatNumber(data.my_team_visits.in_progress), iconName: 'home', tone: 'info', href: '#/homecare' }));
    }
    if (data.my_open_consultations !== undefined) {
      cards.push(kpi({ label: 'Mes consultations ouvertes', value: formatNumber(data.my_open_consultations), iconName: 'stethoscope', href: '#/consultations' }));
    }
    if (data.exam_queue !== undefined) {
      cards.push(kpi({ label: 'Examens à réaliser', value: formatNumber(data.exam_queue), iconName: 'flask', tone: data.exam_queue > 0 ? 'info' : null, href: '#/examinations' }));
    }
    if (data.exams_to_validate !== undefined) {
      cards.push(kpi({ label: 'Résultats à valider', value: formatNumber(data.exams_to_validate), iconName: 'checkCircle', tone: data.exams_to_validate > 0 ? 'warning' : null, href: '#/examinations' }));
    }
    if (data.billing_month) {
      const b = data.billing_month;
      cards.push(
        kpi({ label: 'Facturé ce mois', value: formatMoney(b.net_amount, currency), iconName: 'receipt', href: '#/billing', hint: `${formatNumber(b.issued_count)} facture(s) émise(s)` }),
        kpi({
          label: 'Reste à encaisser', value: formatMoney(b.outstanding_amount, currency), iconName: 'wallet', tone: 'accent', href: '#/billing',
          hint: `${formatNumber(b.drafts)} brouillon(s) · ${formatNumber(b.visits_to_invoice)} visite(s) à facturer`,
        }));
    }

    const left = [];
    const right = [];
    if (data.my_agenda) {
      left.push(card('Mon planning du jour', data.my_agenda.length
        ? table({
          caption: 'Mes rendez-vous du jour',
          columns: [
            { label: 'Heure', render: (row) => h('span', { class: 'time-cell' }, formatTime(row.scheduled_start)) },
            { label: 'Patient', render: (row) => h('div', {}, h('a', { class: 'cell-link', href: `#/patients/${row.patient.id}` }, row.patient.name), h('div', { class: 'vsh-muted mono' }, row.patient.file_number || '')) },
            { label: 'Service', render: (row) => row.service },
            { label: 'Statut', render: (row) => badge('appointment', row.status) },
          ],
          rows: data.my_agenda,
        })
        : emptyState({ iconName: 'calendar', title: 'Aucun rendez-vous aujourd’hui', text: 'Les rendez-vous qui vous sont attribués apparaîtront ici.' })));
    }
    if (data.homecare) {
      const breakdown = statusBreakdown('homecare', data.homecare.by_status, ['NOUVELLE', 'EN_ATTENTE', 'PRISE_EN_CHARGE', 'EN_ROUTE', 'SUR_PLACE', 'EN_COURS']);
      right.push(card('Visites à domicile en cours', breakdown || emptyState({ iconName: 'home', title: 'Aucune visite ouverte', text: 'Les nouvelles demandes apparaîtront ici.' })));
    }
    if (data.appointments_today) {
      const breakdown = statusBreakdown('appointment', data.appointments_today, ['DEMANDE', 'CONFIRME', 'DEPLACE', 'HONORE', 'ABSENT', 'ANNULE']);
      (left.length ? right : left).push(card('Rendez-vous du jour', breakdown || emptyState({ iconName: 'calendar', title: 'Aucun rendez-vous aujourd’hui' })));
    }

    if (!cards.length && !left.length && !right.length) {
      mount(body, emptyState({ iconName: 'dashboard', title: 'Rien à afficher pour le moment', text: 'Votre rôle ne comporte pas d’indicateur de tableau de bord.' }));
      return;
    }
    mount(body,
      cards.length ? h('section', { 'aria-label': 'Indicateurs clés' }, h('div', { class: 'kpis' }, cards)) : null,
      left.length || right.length ? h('div', { class: 'dash-grid' }, h('div', { class: 'stack' }, left), h('div', { class: 'stack' }, right)) : null,
      h('p', { class: 'audit-note' }, icon('info'), 'Chiffres calculés à l’ouverture de la page. Utilisez « Actualiser » pour les mettre à jour.'));
  }

  await load();
}
