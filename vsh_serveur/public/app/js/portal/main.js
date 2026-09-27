/**
 * Portail web patient (étape 9f, D-001) : connexion sans mot de passe (n° de dossier + téléphone + code
 * reçu par SMS), puis consultation de ses dossiers (un compte peut gérer ceux de sa famille),
 * demandes de rendez-vous et de visite à domicile, résultats validés, ordonnances et factures en PDF.
 * Session distincte de celle du personnel ; un compte du personnel n'est pas accepté ici.
 * Toutes les règles d'accès sont revérifiées par le serveur (dossiers rattachés au compte uniquement).
 */
import { h, mount } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { api, ApiError } from '../core/api.js';
import { session } from '../core/session.js';
import { route, parse } from '../core/router.js';
import { applyTheme } from '../shell.js';
import { field, button, setBusy, applyErrors, errorSummary, toast, emptyState, errorState, iconButton } from '../ui.js';
import { homeView, appointmentsView, homecareView, resultsView, documentsView } from './views.js';

const PATIENT_KEY = 'vsh.portal.patient';
const root = document.getElementById('app');
session.useStorageKey('vsh.portal.rt');

const NAV = [
  { path: '/', label: 'Accueil', icon: 'home', view: homeView },
  { path: '/rendez-vous', label: 'Rendez-vous', icon: 'calendar', view: appointmentsView },
  { path: '/domicile', label: 'Visites', icon: 'mapPin', view: homecareView },
  { path: '/resultats', label: 'Résultats', icon: 'flask', view: resultsView },
  { path: '/documents', label: 'Documents', icon: 'fileText', view: documentsView },
];
NAV.forEach((item) => route(item.path, { view: item.view, title: item.label }));

let patients = [];
let bundle = null;
let shell = null;
let renderSeq = 0;
let notice = null;

// ---------------------------------------------------------------- Dossier courant

function currentPatient() {
  let id = null;
  try {
    id = window.sessionStorage.getItem(PATIENT_KEY);
  } catch (e) {
    id = null;
  }
  return patients.find((p) => p.id === id) || patients[0] || null;
}

function selectPatient(id) {
  try {
    window.sessionStorage.setItem(PATIENT_KEY, id);
  } catch (e) {
    /* le choix dure le temps de la page */
  }
  shell = null;
  render();
}

async function loadAccount() {
  const [list, reference] = await Promise.all([
    api.get('me/patients').then((r) => r.data),
    api.get('reference/bundle').then((r) => r.data).catch(() => null),
  ]);
  patients = list;
  bundle = reference;
}

// ---------------------------------------------------------------- Connexion

function authLayout(content) {
  root.classList.remove('app-boot');
  root.removeAttribute('aria-busy');
  root.replaceChildren(h('div', { class: 'auth portal-auth' },
    h('section', { class: 'auth__brand', 'aria-hidden': 'true' },
      h('div', { class: 'auth__logo' }, h('img', { src: '../assets/img/logo-vsh.jpg', alt: '' }), 'Vision Homecare'),
      h('div', { class: 'auth__pitch' },
        h('h1', {}, 'Votre santé, suivie avec vous.'),
        h('p', {}, 'Rendez-vous, visites à domicile, résultats, ordonnances et factures : votre dossier, où que vous soyez.'),
        h('ul', { class: 'auth__points' },
          h('li', {}, icon('lock'), 'Connexion par code SMS, sans mot de passe'),
          h('li', {}, icon('users'), 'Les dossiers de votre famille dans un seul espace'),
          h('li', {}, icon('shield'), 'Vos données médicales restent confidentielles'))),
      h('p', { class: 'auth__foot' }, 'Niger · Données médicales protégées')),
    h('main', { class: 'auth__panel' }, content)));
  const heading = root.querySelector('.auth__form h2');
  if (heading) heading.focus();
}

function formHeader(title, text) {
  return [
    h('div', { class: 'auth__mobile-logo' }, h('img', { src: '../assets/img/logo-vsh.jpg', alt: '' }), 'Vision Homecare'),
    h('div', { class: 'stack stack--sm' }, h('h2', { tabindex: '-1' }, title), text ? h('p', { class: 'vsh-muted' }, text) : null),
  ];
}

function noticeBox() {
  if (!notice) return null;
  const box = h('div', { class: `vsh-alert vsh-alert--${notice.type}`, role: 'status' },
    icon(notice.type === 'warning' ? 'alert' : 'info', { size: 20 }), h('span', {}, notice.text));
  notice = null;
  return box;
}

function portalError(error, summary, fields) {
  if (error instanceof ApiError && error.code === 'VALIDATION_ERROR') {
    errorSummary(summary, 'Veuillez corriger les champs indiqués.', applyErrors(fields, error.errors));
    return;
  }
  applyErrors(fields, {});
  const messages = {
    INVALID_PORTAL_CREDENTIALS: 'Code incorrect ou expiré, ou informations inexactes. Vérifiez et réessayez.',
    ACCOUNT_DISABLED: 'Ce compte est désactivé. Contactez la clinique.',
    PHONE_USED_BY_STAFF: 'Ce numéro est associé à un compte du personnel. Contactez la clinique pour accéder à votre dossier.',
  };
  let message = messages[error.code] || error.message || 'Une erreur est survenue.';
  if (error.status === 429) message = 'Trop de tentatives. Patientez quelques minutes avant de réessayer.';
  errorSummary(summary, message, []);
}

function renderIdentity(prefill = {}) {
  const fileNumber = field({
    label: 'Numéro de dossier', name: 'file_number', required: true, autocomplete: 'off', value: prefill.fileNumber || '',
    placeholder: 'VSH-2026-000123', hint: 'Il figure sur vos documents de la clinique (factures, ordonnances).',
    attrs: { autocapitalize: 'characters', spellcheck: 'false' },
  });
  const phone = field({
    label: 'Téléphone enregistré au dossier', name: 'phone', type: 'tel', inputmode: 'tel', required: true, autocomplete: 'tel', value: prefill.phone || '',
    hint: 'Exemple : 90 12 34 56. Le code vous sera envoyé par SMS sur ce numéro.',
  });
  const fields = { file_number: fileNumber, phone };
  const summary = h('div', { hidden: true });
  const submit = button({ text: 'Recevoir mon code par SMS', type: 'submit', size: 'lg', block: true });
  const form = h('form', { class: 'auth__form', novalidate: true },
    formHeader('Mon espace patient', 'Connexion sans mot de passe : un code à usage unique vous est envoyé par SMS.'),
    noticeBox(), summary, fileNumber.el, phone.el, submit,
    h('p', { class: 'vsh-muted portal-staff-link' }, 'Vous êtes membre du personnel ? ', h('a', { href: './' }, 'Espace de la clinique')));
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const missing = {};
    if (!fileNumber.input.value.trim()) missing.file_number = ['Indiquez votre numéro de dossier.'];
    if (!phone.input.value.trim()) missing.phone = ['Indiquez votre numéro de téléphone.'];
    if (Object.keys(missing).length) {
      errorSummary(summary, 'Veuillez compléter les champs indiqués.', applyErrors(fields, missing));
      return;
    }
    setBusy(submit, true);
    const identity = { fileNumber: fileNumber.input.value.trim().toUpperCase(), phone: phone.input.value.trim() };
    try {
      // Réponse identique que les informations soient exactes ou non (pas d'énumération des dossiers).
      await api.post('auth/patient-portal/code', { file_number: identity.fileNumber, phone: identity.phone }, { auth: false });
      renderCode(identity);
    } catch (error) {
      portalError(error, summary, fields);
    } finally {
      setBusy(submit, false);
    }
  });
  authLayout(form);
}

function renderCode(identity) {
  const code = field({
    label: 'Code reçu par SMS', name: 'code', required: true, inputmode: 'numeric', autocomplete: 'one-time-code',
    attrs: { maxlength: '8', class: 'vsh-input otp-input' },
  });
  const fields = { code };
  const summary = h('div', { hidden: true });
  const submit = button({ text: 'Me connecter', type: 'submit', size: 'lg', block: true });
  const resend = h('button', { type: 'button', class: 'link-btn', disabled: true });
  let seconds = 60;
  const tick = () => {
    if (!resend.isConnected) return;
    resend.disabled = seconds > 0;
    resend.textContent = seconds > 0 ? `Renvoyer le code (dans ${seconds} s)` : 'Renvoyer le code';
    if (seconds > 0) {
      seconds -= 1;
      setTimeout(tick, 1000);
    }
  };
  resend.addEventListener('click', async () => {
    resend.disabled = true;
    try {
      await api.post('auth/patient-portal/code', { file_number: identity.fileNumber, phone: identity.phone }, { auth: false });
      toast('Si les informations sont exactes, un nouveau code vous a été envoyé.');
      seconds = 60;
      tick();
    } catch (error) {
      portalError(error, summary, fields);
      resend.disabled = false;
    }
  });
  const form = h('form', { class: 'auth__form', novalidate: true },
    formHeader('Saisissez votre code', `Si le dossier ${identity.fileNumber} correspond à ce numéro, un code vient d’être envoyé par SMS. Il est valable quelques minutes.`),
    summary, code.el, submit, resend,
    h('button', { type: 'button', class: 'link-btn', onclick: () => renderIdentity(identity) }, 'Modifier le dossier ou le téléphone'));
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const value = code.input.value.replace(/\s/g, '');
    if (!/^\d{4,8}$/.test(value)) {
      errorSummary(summary, 'Veuillez corriger les champs indiqués.', applyErrors(fields, { code: ['Saisissez le code à chiffres reçu par SMS.'] }));
      return;
    }
    setBusy(submit, true);
    try {
      const user = await session.portalLogin(identity.fileNumber, identity.phone, value);
      if (user.account_type !== 'PATIENT') {
        await session.logout();
        throw new ApiError(403, 'STAFF_ACCOUNT', 'Cet espace est réservé aux patients. Utilisez l’espace de la clinique.');
      }
      await loadAccount();
      shell = null;
      window.history.replaceState(null, '', '#/');
      render();
    } catch (error) {
      code.input.value = '';
      portalError(error, summary, fields);
    } finally {
      setBusy(submit, false);
    }
  });
  authLayout(form);
  tick();
  code.input.focus();
}

// ---------------------------------------------------------------- Coquille

function createShell() {
  root.classList.remove('app-boot');
  root.removeAttribute('aria-busy');
  const patient = currentPatient();
  const main = h('main', { id: 'main', class: 'portal-main', tabindex: '-1' });
  const live = h('span', { class: 'sr-only', 'aria-live': 'polite' });
  const link = (item) => h('a', { class: 'portal-nav__link', href: `#${item.path}`, 'data-path': item.path }, icon(item.icon), h('span', {}, item.label));
  const switcher = patients.length > 1
    ? h('label', { class: 'portal-switch' }, h('span', { class: 'sr-only' }, 'Dossier affiché'),
      h('select', { class: 'vsh-select', onchange: (event) => selectPatient(event.target.value) },
        patients.map((p) => h('option', { value: p.id, selected: Boolean(patient && p.id === patient.id) }, `${p.first_name} ${p.last_name}`))))
    : null;
  const header = h('header', { class: 'portal-header' },
    h('a', { class: 'skip-link', href: '#main' }, 'Aller au contenu'),
    h('a', { class: 'portal-brand', href: '#/' }, h('img', { src: '../assets/img/logo-vsh.jpg', alt: '', width: '36', height: '36' }), h('span', {}, 'Mon espace patient')),
    h('nav', { class: 'portal-nav portal-nav--top', 'aria-label': 'Navigation principale' }, NAV.map(link)),
    h('div', { class: 'portal-header__end' }, switcher,
      iconButton('logout', 'Se déconnecter', async () => {
        await session.logout();
      })));
  const bottom = h('nav', { class: 'portal-nav portal-nav--bottom', 'aria-label': 'Navigation' }, NAV.map(link));
  root.replaceChildren(header, h('div', { class: 'portal-body' }, main), bottom, live);
  return {
    main,
    setTitle(text) {
      document.title = `${text} · Mon espace patient`;
      live.textContent = text;
    },
    setActive(path) {
      root.querySelectorAll('.portal-nav__link').forEach((a) => {
        const active = a.getAttribute('data-path') === path;
        a.classList.toggle('is-active', active);
        if (active) a.setAttribute('aria-current', 'page');
        else a.removeAttribute('aria-current');
      });
    },
  };
}

// ---------------------------------------------------------------- Rendu

async function render() {
  const seq = ++renderSeq;
  const isCurrent = () => seq === renderSeq;
  if (!session.loggedIn) {
    shell = null;
    renderIdentity();
    document.title = 'Connexion · Mon espace patient';
    return;
  }
  const { definition, path, params, query } = parse();
  if (!shell) shell = createShell();
  shell.setActive(definition ? path : '');
  const patient = currentPatient();
  if (!patient) {
    shell.setTitle('Aucun dossier');
    mount(shell.main, emptyState({ iconName: 'user', title: 'Aucun dossier rattaché', text: 'Aucun dossier n’est rattaché à ce compte. Contactez l’accueil de la clinique.' }));
    return;
  }
  const ctx = {
    main: shell.main, setTitle: shell.setTitle, params, query, path, isCurrent, patient, patients, bundle,
    user: session.user,
  };
  if (!definition) {
    shell.setTitle('Page introuvable');
    mount(shell.main, emptyState({ iconName: 'search', title: 'Cette page n’existe pas', action: h('a', { class: 'vsh-btn vsh-btn--secondary', href: '#/' }, 'Retour à l’accueil') }));
    return;
  }
  window.scrollTo(0, 0);
  try {
    await definition.view(ctx);
  } catch (error) {
    if (isCurrent()) mount(shell.main, errorState(error, () => render()));
  }
  if (isCurrent()) {
    const heading = shell.main.querySelector('[data-page-title]');
    if (heading) heading.focus({ preventScroll: true });
  }
}

session.onChange((event) => {
  if (event === 'expired' || event === 'logout') {
    notice = event === 'expired'
      ? { type: 'warning', text: 'Votre session a expiré. Reconnectez-vous pour continuer.' }
      : { type: 'info', text: 'Vous êtes déconnecté. À bientôt.' };
    shell = null;
    try {
      window.sessionStorage.removeItem(PATIENT_KEY);
    } catch (e) {
      /* rien à faire */
    }
    window.history.replaceState(null, '', '#/');
    render();
  }
});

window.addEventListener('hashchange', () => render());

(async function boot() {
  applyTheme();
  try {
    if (await session.restore()) {
      if (session.user.account_type !== 'PATIENT') await session.logout();
      else await loadAccount();
    }
  } catch (e) {
    /* pas de session à reprendre */
  }
  render();
}());
