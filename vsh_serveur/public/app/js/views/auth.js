/**
 * Connexion du personnel, mot de passe oublié (code SMS) et changement de mot de passe.
 * Erreurs : sous chaque champ (aria-describedby) + résumé focalisable en tête de formulaire.
 */
import { h } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { api, ApiError } from '../core/api.js';
import { session } from '../core/session.js';
import { field, passwordField, button, setBusy, applyErrors, errorSummary, toast } from '../ui.js';

const PASSWORD_HINT = '8 caractères au moins, avec au moins une lettre et un chiffre.';

function authLayout(root, content) {
  root.classList.remove('app-boot');
  root.removeAttribute('aria-busy');
  root.replaceChildren(
    h('div', { class: 'auth' },
      h('section', { class: 'auth__brand', 'aria-hidden': 'true' },
        h('div', { class: 'auth__logo' }, h('img', { src: '../assets/img/logo-vsh.jpg', alt: '' }), 'Vision Homecare'),
        h('div', { class: 'auth__pitch' },
          h('h1', {}, 'Des soins de qualité, en clinique comme à domicile.'),
          h('p', {}, 'L’espace de travail de l’équipe : patients, rendez-vous, visites à domicile et facturation.'),
          h('ul', { class: 'auth__points' },
            h('li', {}, icon('users'), 'Dossier patient partagé et sécurisé'),
            h('li', {}, icon('home'), 'Visites à domicile suivies en temps réel'),
            h('li', {}, icon('shield'), 'Accès selon votre rôle, actions tracées'))),
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

function noticeBox(notice) {
  if (!notice) return null;
  return h('div', { class: `vsh-alert vsh-alert--${notice.type || 'info'}`, role: 'status' },
    icon(notice.type === 'warning' ? 'alert' : 'info', { size: 20 }), h('span', {}, notice.text));
}

function handleError(error, fields, summary) {
  if (error instanceof ApiError && error.code === 'VALIDATION_ERROR') {
    const items = applyErrors(fields, error.errors);
    errorSummary(summary, 'Veuillez corriger les champs indiqués.', items);
  } else {
    applyErrors(fields, {});
    errorSummary(summary, error.message || 'Une erreur est survenue.', []);
  }
}

// ---------------------------------------------------------------- Connexion

export function renderLogin(root, { onSuccess, notice } = {}) {
  const phone = field({
    label: 'Numéro de téléphone', name: 'phone', type: 'tel', required: true, autocomplete: 'username', inputmode: 'tel',
    hint: 'Exemple : 90 12 34 56 ou +227 90 12 34 56',
  });
  const password = passwordField({ label: 'Mot de passe', name: 'password' });
  const fields = { phone, password };
  const summary = h('div', { hidden: true });
  const submit = button({ text: 'Se connecter', type: 'submit', size: 'lg', block: true });

  const form = h('form', { class: 'auth__form', novalidate: true },
    formHeader('Connexion', 'Espace réservé au personnel de la clinique.'),
    noticeBox(notice),
    summary,
    phone.el,
    password.el,
    submit,
    h('button', { type: 'button', class: 'link-btn', onclick: () => renderForgot(root, { onSuccess }) }, 'Mot de passe oublié ?'),
    h('p', { class: 'vsh-muted portal-link' }, 'Vous êtes patient ? ', h('a', { href: 'portail.html' }, 'Accéder à mon espace patient')));

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const missing = {};
    if (!phone.input.value.trim()) missing.phone = ['Indiquez votre numéro de téléphone.'];
    if (!password.input.value) missing.password = ['Indiquez votre mot de passe.'];
    if (Object.keys(missing).length) {
      errorSummary(summary, 'Veuillez compléter les champs indiqués.', applyErrors(fields, missing));
      return;
    }
    setBusy(submit, true);
    try {
      const user = await session.login(phone.input.value.trim(), password.input.value);
      onSuccess(user);
    } catch (error) {
      password.input.value = '';
      handleError(error, fields, summary);
    } finally {
      setBusy(submit, false);
    }
  });

  authLayout(root, form);
}

// ---------------------------------------------------------------- Mot de passe oublié

function renderForgot(root, { onSuccess }) {
  const phone = field({ label: 'Numéro de téléphone', name: 'phone', type: 'tel', required: true, autocomplete: 'username', inputmode: 'tel' });
  const summary = h('div', { hidden: true });
  const submit = button({ text: 'Recevoir un code par SMS', type: 'submit', size: 'lg', block: true });
  const form = h('form', { class: 'auth__form', novalidate: true },
    formHeader('Mot de passe oublié', 'Un code à usage unique vous sera envoyé par SMS.'),
    summary, phone.el, submit,
    h('button', { type: 'button', class: 'link-btn', onclick: () => renderLogin(root, { onSuccess }) }, 'Retour à la connexion'));

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!phone.input.value.trim()) {
      errorSummary(summary, 'Veuillez compléter les champs indiqués.', applyErrors({ phone }, { phone: ['Indiquez votre numéro de téléphone.'] }));
      return;
    }
    setBusy(submit, true);
    try {
      await api.post('auth/password/forgot', { phone: phone.input.value.trim() }, { auth: false });
      renderReset(root, { onSuccess, phone: phone.input.value.trim() });
    } catch (error) {
      handleError(error, { phone }, summary);
    } finally {
      setBusy(submit, false);
    }
  });
  authLayout(root, form);
}

function renderReset(root, { onSuccess, phone: phoneValue }) {
  const code = field({ label: 'Code reçu par SMS', name: 'code', required: true, inputmode: 'numeric', autocomplete: 'one-time-code', attrs: { maxlength: '8' } });
  const newPassword = passwordField({ label: 'Nouveau mot de passe', name: 'new_password', autocomplete: 'new-password', hint: PASSWORD_HINT });
  const confirm = passwordField({ label: 'Confirmer le mot de passe', name: 'confirm', autocomplete: 'new-password' });
  const fields = { code, new_password: newPassword, confirm };
  const summary = h('div', { hidden: true });
  const submit = button({ text: 'Enregistrer le mot de passe', type: 'submit', size: 'lg', block: true });
  const form = h('form', { class: 'auth__form', novalidate: true },
    formHeader('Nouveau mot de passe', 'Si ce numéro correspond à un compte, un code vient d’être envoyé. Il est valable quelques minutes.'),
    summary, code.el, newPassword.el, confirm.el, submit,
    h('button', { type: 'button', class: 'link-btn', onclick: () => renderLogin(root, { onSuccess }) }, 'Retour à la connexion'));

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (newPassword.input.value !== confirm.input.value) {
      errorSummary(summary, 'Veuillez corriger les champs indiqués.', applyErrors(fields, { confirm: ['Les deux mots de passe ne correspondent pas.'] }));
      return;
    }
    setBusy(submit, true);
    try {
      await api.post('auth/password/reset', { phone: phoneValue, code: code.input.value.trim(), new_password: newPassword.input.value }, { auth: false });
      renderLogin(root, { onSuccess, notice: { type: 'info', text: 'Mot de passe modifié. Connectez-vous avec votre nouveau mot de passe.' } });
    } catch (error) {
      handleError(error, fields, summary);
    } finally {
      setBusy(submit, false);
    }
  });
  authLayout(root, form);
}

// ---------------------------------------------------------------- Changement de mot de passe

/**
 * @param {HTMLElement} container Page entière (changement imposé) ou zone principale
 * @param {{forced: boolean, onDone: Function, onCancel?: Function}} options
 */
export function renderPasswordChange(container, { forced, onDone, onCancel }) {
  const current = passwordField({ label: forced ? 'Mot de passe provisoire' : 'Mot de passe actuel', name: 'current_password' });
  const newPassword = passwordField({ label: 'Nouveau mot de passe', name: 'new_password', autocomplete: 'new-password', hint: PASSWORD_HINT });
  const confirm = passwordField({ label: 'Confirmer le nouveau mot de passe', name: 'confirm', autocomplete: 'new-password' });
  const fields = { current_password: current, new_password: newPassword, confirm };
  const summary = h('div', { hidden: true });
  const submit = button({ text: 'Enregistrer', type: 'submit', size: forced ? 'lg' : undefined, block: forced });

  const form = h('form', { class: forced ? 'auth__form' : 'stack', novalidate: true },
    forced ? formHeader('Choisissez votre mot de passe', 'Pour votre sécurité, remplacez le mot de passe provisoire reçu avant de continuer.') : null,
    summary, current.el, newPassword.el, confirm.el,
    h('div', { class: 'row' }, submit,
      forced
        ? h('button', { type: 'button', class: 'link-btn', onclick: () => session.logout() }, 'Se déconnecter')
        : button({ text: 'Annuler', variant: 'secondary', onClick: onCancel })));

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (newPassword.input.value !== confirm.input.value) {
      errorSummary(summary, 'Veuillez corriger les champs indiqués.', applyErrors(fields, { confirm: ['Les deux mots de passe ne correspondent pas.'] }));
      return;
    }
    setBusy(submit, true);
    try {
      await api.put('auth/password', { current_password: current.input.value, new_password: newPassword.input.value });
      await session.reloadProfile();
      toast('Mot de passe modifié. Vos autres sessions ont été fermées.');
      onDone();
    } catch (error) {
      handleError(error, fields, summary);
    } finally {
      setBusy(submit, false);
    }
  });

  if (forced) {
    authLayout(container, form);
  } else {
    container.replaceChildren(
      h('header', { class: 'page-head' }, h('div', { class: 'page-head__text' },
        h('h1', { tabindex: '-1', 'data-page-title': 'true' }, 'Changer mon mot de passe'),
        h('p', {}, 'Vos autres sessions (autres navigateurs, téléphones) seront fermées.'))),
      h('section', { class: 'vsh-card' }, h('div', { class: 'auth__form' }, form)));
  }
}
