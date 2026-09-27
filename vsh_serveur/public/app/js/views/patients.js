/**
 * Patients : recherche (nom, n° de dossier, téléphone, date de naissance) et inscriptions à valider.
 * Un seul champ « intelligent » reconnaît le type de critère ; les filtres sont conservés dans l'URL.
 */
import { h, mount } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { api, ApiError } from '../core/api.js';
import { session } from '../core/session.js';
import { setQuery } from '../core/router.js';
import { badge, formatDay, formatPhone, formatDate, SEX } from '../core/format.js';
import { pageHead, field, button, setBusy, table, tabs, emptyState, errorState, skeleton, loadingRegion, applyErrors } from '../ui.js';

const FILE_NUMBER = /^[A-Za-z]{2,10}-\d{4}-\d{1,8}$/;
const PHONE = /^\+?[\d\s.-]{8,}$/;

function criteriaFrom(term, birthDate, status) {
  const criteria = {};
  const value = term.trim();
  if (value) {
    if (FILE_NUMBER.test(value)) criteria.file_number = value.toUpperCase();
    else if (PHONE.test(value)) criteria.phone = value.replace(/[\s.-]/g, '');
    else criteria.q = value;
  }
  if (birthDate) criteria.birth_date = birthDate;
  if (status) criteria.status = status;
  return criteria;
}

function patientsTable(rows, total) {
  return table({
    caption: 'Résultats de la recherche de patients',
    columns: [
      { label: 'N° de dossier', render: (p) => h('span', { class: 'mono nowrap' }, p.file_number || '—') },
      { label: 'Patient', render: (p) => h('a', { class: 'cell-link', href: `#/patients/${p.id}` }, `${p.last_name.toUpperCase()} ${p.first_name}`) },
      { label: 'Sexe', render: (p) => SEX[p.sex] || '—' },
      { label: 'Âge', cls: 'num', render: (p) => (p.age_years !== null && p.age_years !== undefined ? `${p.age_years} ans` : '—') },
      { label: 'Téléphone', render: (p) => h('span', { class: 'nowrap' }, formatPhone(p.phone)) },
      { label: 'Statut', render: (p) => badge('patient', p.status) },
    ],
    rows,
    // Recherche par nom : le serveur compte jusqu'à 1 000 (au-delà, le total exact n'aide pas à trouver).
    foot: total > rows.length
      ? h('span', {}, `${rows.length} premiers résultats sur ${total >= 1000 ? 'plus de 1 000' : total}. Affinez la recherche (prénom et nom, date de naissance).`)
      : h('span', {}, `${total} résultat(s)`),
  });
}

export async function patientsView({ main, setTitle, query, isCurrent }) {
  setTitle('Patients');
  const canValidate = session.can('patients.validate_registration');
  const head = pageHead({
    title: 'Patients',
    subtitle: 'Recherchez un dossier par nom, numéro de dossier, téléphone ou date de naissance.',
    actions: session.can('patients.create') ? h('a', { class: 'vsh-btn vsh-btn--primary', href: '#/patients/new' }, icon('userPlus'), 'Nouveau patient') : null,
  });

  if (!canValidate) {
    mount(main, head, searchPanel());
    return;
  }
  const initial = query.tab === 'pending' ? 'pending' : 'search';
  const tabset = tabs([
    { id: 'search', label: 'Recherche' },
    { id: 'pending', label: 'Inscriptions à valider' },
  ], initial, (id, panel) => {
    if (id === 'pending') setQuery({ tab: 'pending' });
    else if (query.tab === 'pending') setQuery({});
    mount(panel, id === 'pending' ? pendingPanel() : searchPanel());
  });
  mount(main, head, tabset.el);
  tabset.select(initial, false);

  // ---------------------------------------------------------------- Recherche
  function searchPanel() {
    const term = field({ label: 'Nom, n° de dossier ou téléphone', name: 'term', value: query.term || '', autocomplete: 'off', attrs: { spellcheck: 'false' } });
    const wrap = h('div', { class: 'input-icon' });
    term.input.replaceWith(wrap);
    wrap.append(icon('search'), term.input);
    const birth = field({ label: 'Date de naissance', name: 'birth_date', type: 'date', value: query.birth || '' });
    const status = field({
      label: 'Statut', name: 'status', value: query.status || '',
      options: [['', 'Tous'], ['ACTIVE', 'Actif'], ['PENDING', 'À valider'], ['INACTIVE', 'Inactif']],
    });
    const submit = button({ text: 'Rechercher', iconName: 'search', type: 'submit' });
    const results = h('div', { 'aria-live': 'polite' });
    const form = h('form', { class: 'vsh-card stack', role: 'search', novalidate: true },
      h('div', { class: 'search-bar' }, term.el, birth.el, status.el, h('div', {}, submit)));

    let searchSeq = 0;
    async function run() {
      const criteria = criteriaFrom(term.input.value, birth.input.value, status.input.value);
      if (!criteria.q && !criteria.file_number && !criteria.phone && !criteria.birth_date) {
        applyErrors({ term }, { term: ['Indiquez un nom (2 lettres au moins), un numéro de dossier, un téléphone ou une date de naissance.'] });
        term.input.focus();
        return;
      }
      applyErrors({ term, birth_date: birth }, {});
      setQuery({ term: term.input.value.trim(), birth: birth.input.value, status: status.input.value });
      setBusy(submit, true);
      mount(results, loadingRegion(skeleton('block')));
      try {
        const request = ++searchSeq;
        const { data, meta } = await api.post('patients/search', criteria, { query: { per_page: 50 } });
        // Recherche au fil de la frappe : une réponse plus ancienne n'écrase pas la plus récente.
        if (!isCurrent() || request !== searchSeq) return;
        mount(results, data.length
          ? patientsTable(data, meta ? meta.total : data.length)
          : emptyState({ iconName: 'search', title: 'Aucun patient trouvé', text: 'Vérifiez l’orthographe, essayez le numéro de dossier ou le téléphone.' }));
      } catch (error) {
        if (!isCurrent()) return;
        if (error instanceof ApiError && error.code === 'VALIDATION_ERROR') {
          const errors = error.errors || {};
          applyErrors({ term, birth_date: birth }, { term: errors.q || errors.file_number || errors.phone, birth_date: errors.birth_date });
          mount(results);
        } else {
          mount(results, errorState(error, run));
        }
      } finally {
        setBusy(submit, false);
      }
    }

    form.addEventListener('submit', (event) => {
      event.preventDefault();
      clearTimeout(typing);
      run();
    });
    // Au fil de la frappe (3 caractères au moins), 400 ms après la dernière touche.
    let typing;
    term.input.addEventListener('input', () => {
      clearTimeout(typing);
      if (term.input.value.trim().length >= 3) typing = setTimeout(run, 400);
    });
    if (query.term || query.birth) run();
    else mount(results, emptyState({ iconName: 'users', title: 'Recherchez un patient', text: 'Saisissez au moins deux lettres du nom, un numéro de dossier (ex. VSH-2026-000123) ou un numéro de téléphone.' }));
    window.setTimeout(() => term.input.focus(), 0);
    return h('div', { class: 'stack' }, form, results);
  }

  // ---------------------------------------------------------------- Inscriptions à valider
  function pendingPanel() {
    const container = h('div', { 'aria-live': 'polite' }, loadingRegion(skeleton('block')));
    (async function load() {
      try {
        const { data, meta } = await api.get('patient-registrations', { per_page: 50 });
        if (!isCurrent()) return;
        mount(container, data.length
          ? table({
            caption: 'Inscriptions en attente de validation',
            columns: [
              { label: 'Patient', render: (p) => h('a', { class: 'cell-link', href: `#/patients/${p.id}` }, `${p.last_name.toUpperCase()} ${p.first_name}`) },
              { label: 'Naissance', render: (p) => formatDay(p.birth_date) },
              { label: 'Téléphone', render: (p) => h('span', { class: 'nowrap' }, formatPhone(p.phone)) },
              { label: 'Inscrit le', render: (p) => formatDate(p.created_at) },
              { label: 'Contrôle', render: (p) => (p.duplicates && p.duplicates.length
                ? h('span', { class: 'vsh-badge vsh-badge--warning' }, `${p.duplicates.length} doublon(s) possible(s)`)
                : h('span', { class: 'vsh-badge vsh-badge--success' }, 'Aucun doublon')) },
            ],
            rows: data,
            foot: h('span', {}, `${meta ? meta.total : data.length} inscription(s) en attente · ouvrez la fiche pour vérifier l’identité puis valider ou rejeter`),
          })
          : emptyState({ iconName: 'userCheck', title: 'Aucune inscription en attente', text: 'Les comptes créés depuis l’application patient apparaîtront ici pour validation.' }));
      } catch (error) {
        if (isCurrent()) mount(container, errorState(error, load));
      }
    })();
    return container;
  }
}
