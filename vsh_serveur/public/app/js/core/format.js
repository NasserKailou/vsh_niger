/**
 * Formats d'affichage (fr, fuseau de la clinique) et statuts. Un statut s'affiche toujours avec
 * sa couleur ET son libellé : jamais la couleur seule.
 */
import { h } from './dom.js';

export const TIMEZONE = 'Africa/Niamey';

const fmt = {
  date: new Intl.DateTimeFormat('fr-FR', { timeZone: TIMEZONE, day: '2-digit', month: '2-digit', year: 'numeric' }),
  dateLong: new Intl.DateTimeFormat('fr-FR', { timeZone: TIMEZONE, weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }),
  time: new Intl.DateTimeFormat('fr-FR', { timeZone: TIMEZONE, hour: '2-digit', minute: '2-digit' }),
  dateTime: new Intl.DateTimeFormat('fr-FR', { timeZone: TIMEZONE, day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }),
  number: new Intl.NumberFormat('fr-FR'),
};

const toDate = (value) => (value instanceof Date ? value : new Date(value));

export function formatDate(value) {
  return value ? fmt.date.format(toDate(value)) : '—';
}

/** Date calendaire « AAAA-MM-JJ » (naissance, etc.) sans conversion de fuseau. */
export function formatDay(value) {
  if (!value) return '—';
  const [year, month, day] = String(value).split('-');
  return `${day}/${month}/${year}`;
}

export function formatDateLong(value = new Date()) {
  const text = fmt.dateLong.format(toDate(value));
  return text.charAt(0).toUpperCase() + text.slice(1);
}

export function formatTime(value) {
  return value ? fmt.time.format(toDate(value)) : '—';
}

export function formatDateTime(value) {
  return value ? fmt.dateTime.format(toDate(value)) : '—';
}

export function formatNumber(value) {
  return fmt.number.format(Number(value) || 0);
}

export function formatMoney(value, currency = 'XOF') {
  const label = currency === 'XOF' ? 'FCFA' : currency;
  return `${fmt.number.format(Number(value) || 0)} ${label}`;
}

export function formatPhone(value) {
  if (!value) return '—';
  const match = /^\+227(\d{2})(\d{2})(\d{2})(\d{2})$/.exec(value);
  return match ? `+227 ${match[1]} ${match[2]} ${match[3]} ${match[4]}` : value;
}

export function initials(name = '') {
  return name.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part.charAt(0).toUpperCase()).join('') || '?';
}

const STATUS = {
  patient: {
    PENDING: ['warning', 'À valider'], ACTIVE: ['success', 'Actif'], INACTIVE: ['neutral', 'Inactif'],
    REJECTED: ['danger', 'Rejeté'], MERGED: ['neutral', 'Fusionné'],
  },
  appointment: {
    DEMANDE: ['warning', 'Demandé'], CONFIRME: ['info', 'Confirmé'], DEPLACE: ['accent', 'Déplacé'],
    ANNULE: ['neutral', 'Annulé'], HONORE: ['success', 'Honoré'], ABSENT: ['danger', 'Absent'],
  },
  consultation: {
    OUVERTE: ['info', 'Ouverte'], EN_COURS: ['info', 'En cours'], CLOTUREE: ['success', 'Clôturée'], ANNULEE: ['neutral', 'Annulée'],
  },
  homecare: {
    NOUVELLE: ['warning', 'Nouvelle'], EN_ATTENTE: ['warning', 'En attente'], PRISE_EN_CHARGE: ['info', 'Prise en charge'],
    EN_ROUTE: ['info', 'En route'], SUR_PLACE: ['info', 'Sur place'], EN_COURS: ['info', 'En cours'],
    TERMINEE: ['success', 'Terminée'], FACTUREE: ['success', 'Facturée'], ANNULEE_PATIENT: ['neutral', 'Annulée (patient)'],
    ANNULEE_CLINIQUE: ['neutral', 'Annulée (clinique)'], ECHEC: ['danger', 'Échec'],
  },
  invoice: { BROUILLON: ['neutral', 'Brouillon'], EMISE: ['info', 'Émise'], ANNULEE: ['neutral', 'Annulée'] },
  settlement: { NON_REGLEE: ['warning', 'Non réglée'], PARTIELLEMENT_REGLEE: ['accent', 'Partiellement réglée'], REGLEE: ['success', 'Réglée'] },
  severity: { LEGERE: ['warning', 'Légère'], MODEREE: ['accent', 'Modérée'], SEVERE: ['danger', 'Sévère'], INCONNUE: ['neutral', 'Gravité inconnue'] },
};

export function statusInfo(domain, status) {
  const entry = (STATUS[domain] || {})[status];
  return entry ? { tone: entry[0], label: entry[1] } : { tone: 'neutral', label: status || '—' };
}

export function badge(domain, status) {
  const { tone, label } = statusInfo(domain, status);
  return h('span', { class: `vsh-badge vsh-badge--${tone}` }, label);
}

export const CONSULTATION_TYPES = { CLINIQUE: 'Clinique', DOMICILE: 'À domicile', SUIVI: 'Suivi', URGENCE: 'Urgence' };
export const SEX = { M: 'Homme', F: 'Femme' };
