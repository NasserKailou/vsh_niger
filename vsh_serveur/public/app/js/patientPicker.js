/**
 * Sélecteur de patient (motif WAI-ARIA combobox) : recherche différée, navigation au clavier
 * (flèches, Entrée, Échap), nombre de résultats annoncé aux lecteurs d'écran.
 */
import { h, uid } from './core/dom.js';
import { icon } from './core/icons.js';
import { api } from './core/api.js';
import { formatPhone } from './core/format.js';
import { field, button } from './ui.js';

const FILE_NUMBER = /^[A-Za-z]{2,10}-\d{4}-\d{1,8}$/;
const PHONE = /^\+?[\d\s.-]{8,}$/;

function describe(p) {
  return [p.file_number, p.age_years !== null && p.age_years !== undefined ? `${p.age_years} ans` : null, p.phone ? formatPhone(p.phone) : null].filter(Boolean).join(' · ');
}

/**
 * @param {{label?: string, initial?: object|null, onChange?: Function}} options
 * @returns {{el: HTMLElement, value: () => object|null, setError: Function, id: string}}
 */
export function patientPicker({ label = 'Patient', initial = null, onChange } = {}) {
  const f = field({ label, name: 'patient', required: true, autocomplete: 'off', placeholder: 'Nom, n° de dossier ou téléphone', attrs: { spellcheck: 'false' } });
  const listId = uid('patients-list');
  const input = f.input;
  input.setAttribute('role', 'combobox');
  input.setAttribute('aria-autocomplete', 'list');
  input.setAttribute('aria-expanded', 'false');
  input.setAttribute('aria-controls', listId);
  const list = h('ul', { id: listId, class: 'suggest__list', role: 'listbox', hidden: true, 'aria-label': 'Patients trouvés' });
  const status = h('span', { class: 'sr-only', role: 'status' });
  const box = h('div', { class: 'suggest' });
  input.replaceWith(box);
  box.append(h('div', { class: 'input-icon' }, icon('search'), input), list, status);
  const picked = h('div', { hidden: true });
  f.el.append(picked);

  let selected = null;
  let results = [];
  let active = -1;
  let timer = null;
  let seq = 0;

  function close() {
    list.hidden = true;
    input.setAttribute('aria-expanded', 'false');
    input.removeAttribute('aria-activedescendant');
    active = -1;
  }

  function highlight(index) {
    active = index;
    [...list.children].forEach((item, i) => item.setAttribute('aria-selected', String(i === index)));
    if (index >= 0 && list.children[index]) {
      input.setAttribute('aria-activedescendant', list.children[index].id);
      list.children[index].scrollIntoView({ block: 'nearest' });
    }
  }

  function choose(patient) {
    selected = patient;
    close();
    box.hidden = true;
    f.setError(null);
    picked.hidden = false;
    picked.replaceChildren(h('div', { class: 'picked' },
      h('div', {}, h('strong', {}, `${patient.last_name.toUpperCase()} ${patient.first_name}`), h('small', {}, describe(patient))),
      button({ text: 'Changer', variant: 'ghost', onClick: () => reset() })));
    if (onChange) onChange(patient);
  }

  function reset() {
    selected = null;
    picked.hidden = true;
    box.hidden = false;
    input.value = '';
    input.focus();
    if (onChange) onChange(null);
  }

  async function search(term) {
    const value = term.trim();
    if (value.length < 2) {
      close();
      return;
    }
    const criteria = FILE_NUMBER.test(value) ? { file_number: value.toUpperCase() }
      : PHONE.test(value) ? { phone: value.replace(/[\s.-]/g, '') } : { q: value };
    const current = ++seq;
    try {
      const { data } = await api.post('patients/search', criteria, { query: { per_page: 8 } });
      if (current !== seq) return;
      results = data.filter((p) => ['ACTIVE', 'PENDING'].includes(p.status));
    } catch (e) {
      if (current !== seq) return;
      results = [];
    }
    list.replaceChildren(...(results.length
      ? results.map((p, i) => h('li', {
        id: `${listId}-${i}`, class: 'suggest__item', role: 'option', 'aria-selected': 'false',
        onmousedown: (event) => { event.preventDefault(); choose(p); },
      }, h('strong', {}, `${p.last_name.toUpperCase()} ${p.first_name}`), h('small', {}, describe(p))))
      : [h('li', { class: 'suggest__empty', role: 'presentation' }, 'Aucun patient trouvé. Créez d’abord son dossier.')]));
    list.hidden = false;
    input.setAttribute('aria-expanded', 'true');
    status.textContent = results.length ? `${results.length} patient(s) trouvé(s)` : 'Aucun patient trouvé';
    active = -1;
  }

  input.addEventListener('input', () => {
    window.clearTimeout(timer);
    timer = window.setTimeout(() => search(input.value), 300);
  });
  input.addEventListener('keydown', (event) => {
    if (list.hidden || !results.length) return;
    if (event.key === 'ArrowDown') {
      event.preventDefault();
      highlight((active + 1) % results.length);
    } else if (event.key === 'ArrowUp') {
      event.preventDefault();
      highlight((active - 1 + results.length) % results.length);
    } else if (event.key === 'Enter' && active >= 0) {
      event.preventDefault();
      choose(results[active]);
    } else if (event.key === 'Escape') {
      event.stopPropagation();
      event.preventDefault();
      close();
    }
  });
  input.addEventListener('blur', () => window.setTimeout(close, 150));

  if (initial) choose(initial);
  return { el: f.el, value: () => selected, setError: f.setError, id: f.id, select: choose };
}
