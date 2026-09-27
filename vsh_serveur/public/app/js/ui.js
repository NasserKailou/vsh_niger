/**
 * Composants d'interface partagés. Accessibilité : libellés visibles, erreurs reliées aux champs
 * (aria-describedby), états de chargement annoncés, onglets pilotables au clavier.
 */
import { h, uid } from './core/dom.js';
import { icon } from './core/icons.js';

// ---------------------------------------------------------------- Notifications éphémères

export function toast(message, { type = 'success', duration = 5000 } = {}) {
  const region = document.getElementById('toasts');
  if (!region) return;
  const node = h('div', { class: ['toast', type === 'error' && 'toast--error'] },
    icon(type === 'error' ? 'alert' : 'checkCircle'),
    h('div', { class: 'grow' }, message));
  region.append(node);
  window.setTimeout(() => node.remove(), duration);
}

// ---------------------------------------------------------------- Boutons

export function button({ text, iconName, variant = 'primary', type = 'button', onClick, size, block, ariaLabel }) {
  return h('button', {
    type,
    class: ['vsh-btn', `vsh-btn--${variant}`, size === 'lg' && 'vsh-btn--lg', size === 'sm' && 'vsh-btn--sm', block && 'vsh-btn--block'],
    onclick: onClick,
    'aria-label': ariaLabel,
  }, iconName ? icon(iconName) : null, text);
}

/** Bouton en cours de traitement : désactivé, spinner, libellé conservé pour les lecteurs d'écran. */
export function setBusy(btn, busy) {
  if (busy) {
    btn.setAttribute('aria-busy', 'true');
    btn.disabled = true;
    btn.prepend(h('span', { class: 'spinner', 'aria-hidden': 'true' }));
  } else {
    btn.removeAttribute('aria-busy');
    btn.disabled = false;
    const spinner = btn.querySelector('.spinner');
    if (spinner) spinner.remove();
  }
}

export function iconButton(name, label, onClick, attrs = {}) {
  return h('button', { type: 'button', class: 'icon-btn', 'aria-label': label, title: label, onclick: onClick, ...attrs }, icon(name));
}

// ---------------------------------------------------------------- Champs de formulaire

/**
 * Champ avec libellé, aide et erreur reliés. Retourne {el, input, setError, id}.
 */
export function field({ label, name, type = 'text', value = '', hint, required, autocomplete, inputmode, placeholder, options, attrs = {} }) {
  const id = uid(name || 'field');
  const hintId = hint ? `${id}-hint` : null;
  const errorId = `${id}-error`;
  let input;
  if (options) {
    input = h('select', { id, name, class: 'vsh-select', 'aria-required': required ? 'true' : null, ...attrs },
      options.map(([optionValue, optionLabel]) => h('option', { value: optionValue, selected: String(optionValue) === String(value) }, optionLabel)));
  } else {
    input = h('input', { id, name, type, class: 'vsh-input', value, 'aria-required': required ? 'true' : null, autocomplete, inputmode, placeholder, ...attrs });
  }
  const error = h('p', { id: errorId, class: 'vsh-field__error', hidden: true });
  const sync = () => {
    const value = [hintId, error.hidden ? null : errorId].filter(Boolean).join(' ');
    if (value) input.setAttribute('aria-describedby', value);
    else input.removeAttribute('aria-describedby');
  };
  sync();
  const el = h('div', { class: 'vsh-field' },
    h('label', { class: 'vsh-field__label', for: id }, label, required ? h('span', { 'aria-hidden': 'true' }, ' *') : null),
    input,
    hint ? h('p', { id: hintId, class: 'vsh-field__hint' }, hint) : null,
    error);
  const setError = (message) => {
    if (message) {
      error.replaceChildren(icon('alert', { size: 16 }), message);
      error.hidden = false;
      input.setAttribute('aria-invalid', 'true');
    } else {
      error.hidden = true;
      error.replaceChildren();
      input.removeAttribute('aria-invalid');
    }
    sync();
  };
  return { el, input, setError, id };
}

/** Champ mot de passe avec bouton afficher / masquer. */
export function passwordField({ label, name, autocomplete = 'current-password', hint, required = true }) {
  const f = field({ label, name, type: 'password', autocomplete, hint, required });
  const toggle = h('button', { type: 'button', class: 'icon-btn', 'aria-label': 'Afficher le mot de passe', 'aria-pressed': 'false' }, icon('eye'));
  toggle.addEventListener('click', () => {
    const visible = f.input.type === 'password';
    f.input.type = visible ? 'text' : 'password';
    toggle.setAttribute('aria-pressed', String(visible));
    toggle.setAttribute('aria-label', visible ? 'Masquer le mot de passe' : 'Afficher le mot de passe');
    toggle.replaceChildren(icon(visible ? 'eyeOff' : 'eye'));
  });
  const wrap = h('div', { class: 'field-password' });
  f.input.replaceWith(wrap);
  wrap.append(f.input, toggle);
  return f;
}

/**
 * Applique les erreurs de validation de l'API aux champs, et renvoie la liste pour le résumé.
 * @param {Object<string, {setError: Function, id: string}>} fields
 */
export function applyErrors(fields, errors = {}) {
  const summary = [];
  Object.values(fields).forEach((f) => f.setError(null));
  for (const [name, messages] of Object.entries(errors || {})) {
    const message = Array.isArray(messages) ? messages[0] : String(messages);
    if (fields[name]) {
      fields[name].setError(message);
      summary.push({ id: fields[name].id, message });
    } else {
      summary.push({ id: null, message });
    }
  }
  return summary;
}

/** Résumé d'erreurs focalisable ; chaque erreur renvoie à son champ. */
export function errorSummary(container, title, items) {
  container.replaceChildren();
  if (!title) {
    container.hidden = true;
    return;
  }
  container.hidden = false;
  container.append(
    h('div', { class: 'vsh-alert vsh-alert--danger error-summary', role: 'alert', tabindex: '-1' },
      icon('alert', { size: 20 }),
      h('div', { class: 'stack stack--sm' },
        h('strong', {}, title),
        items && items.length ? h('ul', { class: 'stack stack--sm' }, items.map((item) => h('li', {},
          item.id ? h('a', { href: `#${item.id}`, onclick: (event) => { event.preventDefault(); document.getElementById(item.id).focus(); } }, item.message) : item.message))) : null)));
  container.firstChild.focus();
}

// ---------------------------------------------------------------- États

export function emptyState({ iconName = 'info', title, text, action }) {
  return h('div', { class: 'empty' },
    h('div', { class: 'empty__icon' }, icon(iconName)),
    h('h3', {}, title),
    text ? h('p', {}, text) : null,
    action || null);
}

export function errorState(error, onRetry) {
  return h('div', { class: 'vsh-alert vsh-alert--danger', role: 'alert' },
    icon('alert', { size: 20 }),
    h('div', { class: 'stack stack--sm grow' },
      h('strong', {}, error && error.status === 403 ? 'Accès refusé' : 'Impossible de charger ces informations'),
      h('span', {}, (error && error.message) || 'Erreur inattendue.'),
      onRetry ? h('div', {}, button({ text: 'Réessayer', iconName: 'refresh', variant: 'secondary', onClick: onRetry })) : null));
}

export function skeleton(kind = 'block', count = 1) {
  return Array.from({ length: count }, () => h('div', { class: `skeleton skeleton--${kind}`, 'aria-hidden': 'true' }));
}

export function loadingRegion(children) {
  return h('div', { 'aria-busy': 'true' }, h('span', { class: 'sr-only', role: 'status' }, 'Chargement…'), children);
}

// ---------------------------------------------------------------- Mise en page

export function pageHead({ title, subtitle, actions, back }) {
  return h('header', { class: 'stack stack--sm' },
    back ? h('a', { class: 'back-link', href: back.href }, icon('chevronLeft'), back.label) : null,
    h('div', { class: 'page-head' },
      h('div', { class: 'page-head__text' },
        h('h1', { tabindex: '-1', 'data-page-title': 'true' }, title),
        subtitle ? h('p', {}, subtitle) : null),
      actions ? h('div', { class: 'row' }, actions) : null));
}

export function card(title, content, { actions } = {}) {
  return h('section', { class: 'vsh-card' },
    title ? h('div', { class: 'section-head' }, h('h2', {}, title), actions || null) : null,
    content);
}

/**
 * Tableau accessible ; sur petit écran, défilement horizontal dans son conteneur.
 * @param {{columns: {label: string, cls?: string, render: Function}[], rows: object[], caption?: string, foot?: *}} options
 */
export function table({ columns, rows, caption, foot }) {
  return h('div', { class: 'vsh-table-wrap' },
    h('table', { class: 'vsh-table' },
      caption ? h('caption', { class: 'sr-only' }, caption) : null,
      h('thead', {}, h('tr', {}, columns.map((column) => h('th', { scope: 'col', class: column.cls }, column.label)))),
      h('tbody', {}, rows.map((row) => h('tr', {}, columns.map((column) => h('td', { class: column.cls }, column.render(row))))))),
    foot ? h('div', { class: 'table-foot' }, foot) : null);
}

/** Case à cocher avec libellé cliquable. Retourne {el, input}. */
export function checkbox({ label, name, checked = false, hint }) {
  const id = uid(name || 'check');
  const input = h('input', { type: 'checkbox', id, name, class: 'check__input', checked });
  return {
    input,
    el: h('div', { class: 'check' }, input, h('label', { for: id, class: 'check__label' }, label, hint ? h('small', {}, hint) : null)),
  };
}

// ---------------------------------------------------------------- Boîtes de dialogue

/**
 * Boîte de dialogue modale (élément <dialog> natif : focus piégé, Échap, fond inerte).
 * Le focus revient à l'élément déclencheur à la fermeture.
 * @returns {{dialog: HTMLDialogElement, body: HTMLElement, footer: HTMLElement, close: Function}}
 */
export function modal({ title, description, content, size = 'md', onClose }) {
  const titleId = uid('dialog-title');
  const trigger = document.activeElement;
  const body = h('div', { class: 'dialog__body' }, content);
  const footer = h('div', { class: 'dialog__footer' });
  const dialog = h('dialog', { class: `dialog dialog--${size}`, 'aria-labelledby': titleId },
    h('div', { class: 'dialog__head' },
      h('div', { class: 'stack stack--sm' }, h('h2', { id: titleId }, title), description ? h('p', { class: 'vsh-muted' }, description) : null),
      iconButton('x', 'Fermer', () => close())),
    body,
    footer);
  let closed = false;
  function close(result) {
    if (closed) return;
    closed = true;
    dialog.close();
    dialog.remove();
    if (trigger && typeof trigger.focus === 'function' && document.contains(trigger)) trigger.focus();
    if (onClose) onClose(result);
  }
  dialog.addEventListener('cancel', (event) => {
    event.preventDefault();
    close();
  });
  document.body.append(dialog);
  dialog.showModal();
  const first = body.querySelector('input, select, textarea, button');
  if (first) first.focus();
  return { dialog, body, footer, close };
}

/**
 * Demande un motif (annulation, rejet…). Résout avec le texte, ou null si l'utilisateur renonce.
 * `submit(reason)` peut rejeter une ApiError : l'erreur s'affiche dans la boîte, qui reste ouverte.
 */
export function askReason({ title, description, label = 'Motif', confirmText = 'Confirmer', danger = true, required = true, submit }) {
  return new Promise((resolve) => {
    const reason = field({ label, name: 'reason', required, attrs: { maxlength: '500' } });
    const summary = h('div', { hidden: true });
    const confirm = button({ text: confirmText, variant: danger ? 'danger' : 'primary', type: 'submit' });
    const form = h('form', { class: 'stack', novalidate: true, id: uid('reason-form') }, summary, reason.el);
    confirm.setAttribute('form', form.id);
    let done = false;
    const box = modal({ title, description, content: form, size: 'sm', onClose: () => { if (!done) resolve(null); } });
    box.footer.append(button({ text: 'Annuler', variant: 'secondary', onClick: () => box.close() }), confirm);
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const value = reason.input.value.trim();
      if (required && !value) {
        reason.setError('Indiquez le motif.');
        reason.input.focus();
        return;
      }
      setBusy(confirm, true);
      try {
        if (submit) await submit(value);
        done = true;
        box.close();
        resolve(value);
      } catch (error) {
        const errors = (error && error.errors) || {};
        if (errors.reason) reason.setError(Array.isArray(errors.reason) ? errors.reason[0] : String(errors.reason));
        else errorSummary(summary, (error && error.message) || 'Action impossible.', []);
      } finally {
        setBusy(confirm, false);
      }
    });
  });
}

/**
 * Onglets (motif WAI-ARIA) : flèches gauche/droite, Début/Fin.
 * @param {{id: string, label: string, count?: number}[]} items
 */
export function tabs(items, selected, onSelect) {
  const baseId = uid('tabs');
  const panel = h('div', { class: 'tab-panel', role: 'tabpanel', tabindex: '-1', id: `${baseId}-panel` });
  const buttons = items.map((item) => {
    const isSelected = item.id === selected;
    return h('button', {
      type: 'button', role: 'tab', class: 'tab', id: `${baseId}-${item.id}`,
      'aria-selected': String(isSelected), 'aria-controls': `${baseId}-panel`, tabindex: isSelected ? '0' : '-1',
      onclick: () => select(item.id, false),
    }, item.label, item.count !== undefined && item.count !== null ? h('span', { class: 'tab__count' }, String(item.count)) : null);
  });
  const list = h('div', { class: 'tabs', role: 'tablist' }, buttons);
  list.addEventListener('keydown', (event) => {
    const index = buttons.indexOf(document.activeElement);
    if (index < 0) return;
    let next = null;
    if (event.key === 'ArrowRight') next = (index + 1) % buttons.length;
    if (event.key === 'ArrowLeft') next = (index - 1 + buttons.length) % buttons.length;
    if (event.key === 'Home') next = 0;
    if (event.key === 'End') next = buttons.length - 1;
    if (next !== null) {
      event.preventDefault();
      select(items[next].id, true);
    }
  });
  function select(id, focus) {
    buttons.forEach((btn, i) => {
      const active = items[i].id === id;
      btn.setAttribute('aria-selected', String(active));
      btn.tabIndex = active ? 0 : -1;
      if (active) {
        panel.setAttribute('aria-labelledby', btn.id);
        if (focus) btn.focus();
      }
    });
    onSelect(id, panel);
  }
  return { el: h('div', {}, list, panel), panel, select };
}
