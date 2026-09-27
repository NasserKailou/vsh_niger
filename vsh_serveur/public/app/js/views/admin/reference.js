/**
 * Référentiels et tarifs : services (et plages horaires), actes médicaux, types de soins, types
 * d'examens (et paramètres de résultats), médicaments ; tarifs datés (un tarif appliqué ne change
 * plus : on crée le suivant). Rien n'est préchargé : la clinique saisit ses propres données.
 * Un élément se désactive, il ne se supprime pas (l'historique des dossiers y fait référence).
 */
import { h, mount } from '../../core/dom.js';
import { icon } from '../../core/icons.js';
import { api, ApiError } from '../../core/api.js';
import { session } from '../../core/session.js';
import { setQuery } from '../../core/router.js';
import { formatDay, formatMoney } from '../../core/format.js';
import { pageHead, card, table, tabs, emptyState, errorState, skeleton, loadingRegion, button, field, checkbox, toast, modal } from '../../ui.js';
import { formDialog } from '../../dialogs.js';
import { templatesPanel } from './templates.js';

const WEEKDAYS = [['1', 'Lundi'], ['2', 'Mardi'], ['3', 'Mercredi'], ['4', 'Jeudi'], ['5', 'Vendredi'], ['6', 'Samedi'], ['7', 'Dimanche']];

/**
 * Description des référentiels : champs du formulaire [nom, libellé, type, options] et colonnes.
 * Les règles de validation restent celles du serveur (ReferenceCatalog).
 */
const CATALOGS = {
  services: {
    title: 'Services',
    hint: 'Services de la clinique ; ceux qui acceptent les rendez-vous ont des plages horaires.',
    fields: [
      ['code', 'Code', 'code'], ['label', 'Libellé', 'text', { required: true, max: 150 }],
      ['description', 'Description', 'text', { max: 500 }], ['accepts_appointments', 'Accepte les rendez-vous', 'bool'],
    ],
    columns: [['label', 'Libellé'], ['code', 'Code']],
    children: {
      schedules: {
        title: 'Plages horaires',
        fields: [
          ['weekday', 'Jour', 'select', { options: WEEKDAYS, required: true }], ['start_time', 'Début', 'time', { required: true }],
          ['end_time', 'Fin', 'time', { required: true }], ['slot_minutes', 'Durée d’un créneau (min)', 'int', { required: true }],
          ['capacity_per_slot', 'Patients par créneau', 'int'],
        ],
        describe: (s) => `${(WEEKDAYS.find((d) => d[0] === String(s.weekday)) || [])[1] || s.weekday} ${s.start_time}–${s.end_time} · créneaux de ${s.slot_minutes} min · ${s.capacity_per_slot || 1} patient(s)`,
      },
    },
  },
  'medical-acts': {
    title: 'Actes médicaux',
    fields: [['code', 'Code', 'code'], ['label', 'Libellé', 'text', { required: true, max: 190 }], ['category', 'Catégorie', 'text', { max: 50 }]],
    columns: [['label', 'Libellé'], ['code', 'Code'], ['category', 'Catégorie']],
  },
  'treatment-types': {
    title: 'Types de soins',
    fields: [['code', 'Code', 'code'], ['label', 'Libellé', 'text', { required: true, max: 190 }], ['description', 'Description', 'text', { max: 500 }]],
    columns: [['label', 'Libellé'], ['code', 'Code']],
  },
  'examination-types': {
    title: 'Types d’examens',
    hint: 'Les paramètres définissent la saisie structurée des résultats et les valeurs de référence affichées (sans interprétation automatique).',
    fields: [
      ['code', 'Code', 'code'], ['label', 'Libellé', 'text', { required: true, max: 190 }], ['category', 'Catégorie', 'text', { max: 50 }],
      ['sample_type', 'Prélèvement', 'text', { max: 100 }], ['instructions', 'Consignes', 'text', { max: 500 }],
    ],
    columns: [['label', 'Libellé'], ['code', 'Code'], ['category', 'Catégorie']],
    children: {
      parameters: {
        title: 'Paramètres de résultats',
        fields: [
          ['code', 'Code', 'code'], ['label', 'Libellé', 'text', { required: true, max: 190 }],
          ['value_type', 'Type de valeur', 'select', { options: [['NUMERIC', 'Numérique'], ['CHOICE', 'Choix'], ['TEXT', 'Texte']], required: true }],
          ['choices', 'Choix possibles', 'list', { hint: 'Un choix par ligne (type « Choix »).' }], ['unit', 'Unité', 'text', { max: 30 }],
          ['ref_min', 'Référence min.', 'number'], ['ref_max', 'Référence max.', 'number'], ['ref_text', 'Référence (texte)', 'text', { max: 190 }],
          ['sex', 'Sexe concerné', 'select', { options: [['', 'Tous'], ['M', 'Hommes'], ['F', 'Femmes']] }],
          ['age_min_months', 'Âge min. (mois)', 'int'], ['age_max_months', 'Âge max. (mois)', 'int'], ['sort_order', 'Ordre d’affichage', 'int'],
        ],
        describe: (p) => {
          let reference = null;
          if (p.ref_min !== null || p.ref_max !== null) reference = `réf. ${p.ref_min !== null ? p.ref_min : '…'} – ${p.ref_max !== null ? p.ref_max : '…'}`;
          else if (p.ref_text) reference = `réf. ${p.ref_text}`;
          return [p.label, p.unit ? `(${p.unit})` : null, reference, p.sex ? (p.sex === 'M' ? 'hommes' : 'femmes') : null].filter(Boolean).join(' · ');
        },
      },
    },
  },
  medications: {
    title: 'Médicaments',
    fields: [
      ['dci', 'DCI', 'text', { required: true, max: 190 }], ['commercial_name', 'Nom commercial', 'text', { max: 190 }],
      ['strength', 'Dosage', 'text', { max: 60 }], ['form', 'Forme', 'text', { max: 60 }], ['route', 'Voie', 'text', { max: 60 }],
    ],
    columns: [['dci', 'DCI'], ['strength', 'Dosage'], ['form', 'Forme'], ['commercial_name', 'Nom commercial']],
  },
};

const TARIFF_TYPES = [
  ['MEDICAL_ACT', 'Actes médicaux', 'medical-acts'], ['TREATMENT_TYPE', 'Types de soins', 'treatment-types'],
  ['EXAMINATION_TYPE', 'Types d’examens', 'examination-types'], ['MEDICATION', 'Médicaments', 'medications'],
];
const TARIFF_STATUS = { CURRENT: ['success', 'En vigueur'], FUTURE: ['info', 'À venir'], PAST: ['neutral', 'Échu'] };

function localDay(date = new Date()) {
  return new Intl.DateTimeFormat('fr-CA', { timeZone: 'Africa/Niamey', year: 'numeric', month: '2-digit', day: '2-digit' }).format(date);
}

function itemLabel(slug, item) {
  if (slug === 'medications') return [item.dci, item.strength, item.form].filter(Boolean).join(' ');
  return item.label;
}

// ---------------------------------------------------------------- Formulaires génériques

function buildFields(specs, values) {
  const fields = {};
  const checks = [];
  specs.forEach(([name, label, type, opts = {}]) => {
    const value = values[name];
    if (type === 'bool') {
      const box = checkbox({ label, name, checked: Boolean(value) });
      checks.push({ name, box });
      return;
    }
    if (type === 'select') {
      fields[name] = field({ label, name, options: opts.options, required: opts.required, value: value === null || value === undefined ? '' : String(value) });
      return;
    }
    if (type === 'list') {
      const f = field({ label, name, hint: opts.hint });
      const area = h('textarea', { class: 'vsh-textarea', id: f.input.id, name, rows: '3' });
      area.value = Array.isArray(value) ? value.join('\n') : '';
      f.input.replaceWith(area);
      f.input = area;
      fields[name] = f;
      return;
    }
    const attrs = {};
    if (opts.max) attrs.maxlength = String(opts.max);
    if (type === 'int') attrs.step = '1';
    // Décimal en champ texte : un champ numérique natif refuse la virgule française (« 0,7 »).
    if (type === 'number') attrs.pattern = '-?[0-9]+([.,][0-9]+)?';
    if (type === 'code') attrs.maxlength = '30';
    fields[name] = field({
      label, name, attrs,
      type: { int: 'number', time: 'time' }[type] || 'text',
      required: opts.required || type === 'code',
      inputmode: type === 'int' || type === 'number' ? 'decimal' : undefined,
      hint: type === 'code' ? 'Majuscules, chiffres, tirets (ex. CS-GEN).' : opts.hint,
      value: value === null || value === undefined ? '' : String(value),
    });
  });
  return { fields, checks };
}

function readFields(specs, fields, checks) {
  const body = {};
  specs.forEach(([name, , type]) => {
    if (type === 'bool') {
      body[name] = checks.find((c) => c.name === name).box.input.checked;
      return;
    }
    const input = fields[name].input;
    // Entier ou liste vides : non envoyés (le serveur applique sa valeur par défaut).
    if (type === 'int') {
      if (input.value !== '') body[name] = parseInt(input.value, 10);
    } else if (type === 'number') {
      body[name] = input.value === '' ? null : Number(input.value.replace(',', '.'));
    } else if (type === 'list') {
      const items = input.value.split('\n').map((s) => s.trim()).filter(Boolean);
      if (items.length) body[name] = items;
    } else if (type === 'code') {
      body[name] = input.value.trim().toUpperCase();
    } else {
      body[name] = input.value.trim() || null;
    }
  });
  return body;
}

function editDialog({ title, specs, values, withActive, submit }) {
  const { fields, checks } = buildFields(specs, values || {});
  const extra = checks.map((c) => c.box);
  let active = null;
  if (withActive) {
    active = checkbox({ label: 'Actif', name: 'active', checked: values ? values.active !== false : true, hint: 'Un élément inactif n’est plus proposé, mais reste dans l’historique.' });
    extra.push(active);
  }
  formDialog({
    title,
    size: 'lg',
    fields,
    extra,
    submitText: 'Enregistrer',
    submit: async () => {
      const body = readFields(specs, fields, checks);
      if (active) body.active = active.input.checked;
      await submit(body);
    },
  });
}

// ---------------------------------------------------------------- Vue

export async function adminReferenceView({ main, setTitle, query, isCurrent }) {
  setTitle('Référentiels et tarifs');
  const defs = [];
  if (session.can('reference.manage')) Object.entries(CATALOGS).forEach(([slug, def]) => defs.push({ id: slug, label: def.title }));
  if (session.can('tariffs.manage')) defs.push({ id: 'tariffs', label: 'Tarifs' });
  if (session.canAny('prescription_templates.manage', 'prescription_templates.approve')) defs.push({ id: 'templates', label: 'Modèles d’ordonnance' });
  const initial = defs.some((d) => d.id === query.tab) ? query.tab : defs[0].id;
  const tabset = tabs(defs, initial, (id, panel) => {
    setQuery({ tab: id === defs[0].id ? null : id });
    if (id === 'tariffs') tariffsPanel(panel, isCurrent);
    else if (id === 'templates') templatesPanel(panel, isCurrent);
    else catalogPanel(id, panel, isCurrent);
  });
  mount(main,
    pageHead({ title: 'Référentiels et tarifs', subtitle: 'Données de la clinique utilisées par les soins, les examens, la prescription et la facturation. Toute modification est tracée.' }),
    tabset.el);
  tabset.select(initial, false);
}

// ---------------------------------------------------------------- Référentiel

function catalogPanel(slug, panel, isCurrent) {
  const def = CATALOGS[slug];
  const search = field({ label: 'Rechercher', name: 'search', autocomplete: 'off' });
  const state = field({ label: 'État', name: 'active', options: [['1', 'Actifs'], ['0', 'Inactifs'], ['', 'Tous']] });
  const results = h('div', { class: 'stack' });
  const filters = h('form', { class: 'toolbar', role: 'search', 'aria-label': `Filtrer : ${def.title}` },
    search.el, state.el, button({ text: 'Filtrer', iconName: 'search', variant: 'secondary', type: 'submit' }));
  filters.addEventListener('submit', (event) => {
    event.preventDefault();
    load();
  });
  mount(panel, h('div', { class: 'stack' },
    def.hint ? h('p', { class: 'audit-note' }, icon('info'), def.hint) : null,
    h('div', { class: 'row' }, button({
      text: 'Ajouter', iconName: 'clipboard',
      onClick: () => editDialog({
        title: `${def.title} : nouvel élément`, specs: def.fields, values: null, withActive: false,
        submit: async (body) => {
          await api.post(slug, body);
          toast('Élément ajouté.');
          load();
        },
      }),
    })),
    filters, results));

  async function load() {
    mount(results, loadingRegion(skeleton('block')));
    try {
      const { data, meta } = await api.get(slug, {
        per_page: 100, search: search.input.value.trim() || null, active: state.input.value === '' ? null : state.input.value,
      });
      if (!isCurrent()) return;
      if (!data.length) {
        mount(results, emptyState({ iconName: 'clipboard', title: 'Aucun élément', text: 'La clinique saisit ici ses propres données : rien n’est préchargé.' }));
        return;
      }
      mount(results, table({
        caption: def.title,
        columns: [
          ...def.columns.map(([name, label]) => ({ label, render: (item) => (name === 'code' ? h('span', { class: 'mono' }, item[name] || '—') : item[name] || '—') })),
          { label: 'État', render: (item) => h('span', { class: `vsh-badge vsh-badge--${item.active ? 'success' : 'neutral'}` }, item.active ? 'Actif' : 'Inactif') },
          {
            label: 'Actions',
            render: (item) => h('div', { class: 'row-actions' },
              button({
                text: 'Modifier', variant: 'secondary', size: 'sm',
                onClick: () => editDialog({
                  title: `Modifier : ${itemLabel(slug, item)}`, specs: def.fields, values: item, withActive: true,
                  submit: async (body) => {
                    await api.put(`${slug}/${item.id}`, { ...body, version: item.version });
                    toast('Élément mis à jour.');
                    load();
                  },
                }),
              }),
              ...Object.entries(def.children || {}).map(([child, childDef]) => button({
                text: childDef.title, variant: 'ghost', size: 'sm', onClick: () => childrenDialog(slug, item, child, childDef),
              }))),
          },
        ],
        rows: data,
        foot: meta && meta.total > data.length ? `${data.length} éléments affichés sur ${meta.total}. Affinez la recherche.` : null,
      }));
    } catch (error) {
      if (isCurrent()) mount(results, errorState(error, load));
    }
  }
  load();
}

function childrenDialog(slug, parent, child, childDef) {
  const listBox = h('div', { class: 'stack' }, loadingRegion(skeleton('block')));
  const box = modal({
    title: `${childDef.title} — ${itemLabel(slug, parent)}`,
    size: 'lg',
    content: listBox,
  });
  box.footer.append(
    button({
      text: 'Ajouter', variant: 'secondary',
      onClick: () => editDialog({
        title: `${childDef.title} : ajout`, specs: childDef.fields, values: null, withActive: false,
        submit: async (body) => {
          await api.post(`${slug}/${parent.id}/${child}`, body);
          toast('Ajouté.');
          load();
        },
      }),
    }),
    button({ text: 'Fermer', onClick: () => box.close() }));

  async function load() {
    try {
      const { data } = await api.get(`${slug}/${parent.id}/${child}`);
      mount(listBox, data.length
        ? h('ul', { class: 'plain-list' }, data.map((item) => h('li', { class: 'row' },
          h('span', { class: 'grow' }, childDef.describe(item)),
          h('span', { class: `vsh-badge vsh-badge--${item.active === false ? 'neutral' : 'success'}` }, item.active === false ? 'Inactif' : 'Actif'),
          button({
            text: 'Modifier', variant: 'ghost', size: 'sm',
            onClick: () => editDialog({
              title: `${childDef.title} : modification`, specs: childDef.fields, values: item, withActive: true,
              submit: async (body) => {
                await api.put(`${slug}/${parent.id}/${child}/${item.id}`, { ...body, version: item.version });
                toast('Mis à jour.');
                load();
              },
            }),
          }))))
        : h('p', { class: 'vsh-muted' }, 'Aucun élément.'));
    } catch (error) {
      mount(listBox, errorState(error, load));
    }
  }
  load();
}

// ---------------------------------------------------------------- Tarifs

function tariffsPanel(panel, isCurrent) {
  const type = field({ label: 'Type de prestation', name: 'billable_type', options: TARIFF_TYPES.map(([value, label]) => [value, label]) });
  const search = field({ label: 'Élément', name: 'search', placeholder: 'Rechercher (2 lettres au moins)', autocomplete: 'off' });
  const item = field({ label: 'Choisir', name: 'billable_id', options: [['', 'Tapez pour rechercher']] });
  const history = h('div', { class: 'stack' });
  mount(panel, h('div', { class: 'stack' },
    h('p', { class: 'audit-note' }, icon('info'), 'Un tarif en vigueur ne se modifie pas : créez le suivant à partir de la date voulue ; le précédent est clôturé la veille automatiquement. Les factures gardent le prix appliqué à leur date.'),
    h('div', { class: 'toolbar' }, type.el, search.el, item.el),
    history));
  let timer = null;
  const slugOf = () => TARIFF_TYPES.find((t) => t[0] === type.input.value)[2];

  async function runSearch() {
    const term = search.input.value.trim();
    if (term.length < 2) return;
    try {
      const { data } = await api.get(slugOf(), { search: term, per_page: 30 });
      item.input.replaceChildren(h('option', { value: '' }, data.length ? `${data.length} résultat(s) : choisissez` : 'Aucun résultat'),
        ...data.map((d) => h('option', { value: d.id }, `${itemLabel(slugOf(), d)}${d.active ? '' : ' (inactif)'}`)));
    } catch (error) {
      toast(error.message, { type: 'error' });
    }
  }
  search.input.addEventListener('input', () => {
    clearTimeout(timer);
    timer = setTimeout(runSearch, 250);
  });
  type.input.addEventListener('change', () => {
    item.input.replaceChildren(h('option', { value: '' }, 'Tapez pour rechercher'));
    mount(history);
    runSearch();
  });
  item.input.addEventListener('change', loadHistory);

  async function loadHistory() {
    if (!item.input.value) {
      mount(history);
      return;
    }
    const name = item.input.selectedOptions[0].textContent;
    mount(history, loadingRegion(skeleton('block')));
    try {
      const { data } = await api.get('tariffs', { billable_type: type.input.value, billable_id: item.input.value });
      if (!isCurrent()) return;
      mount(history, card(`Tarifs : ${name}`, data.length
        ? table({
          caption: `Historique des tarifs de ${name}`,
          columns: [
            { label: 'Montant', cls: 'num', render: (t) => h('strong', { class: 'nowrap' }, formatMoney(t.amount, t.currency)) },
            { label: 'Du', render: (t) => formatDay(t.valid_from) },
            { label: 'Au', render: (t) => (t.valid_to ? formatDay(t.valid_to) : 'sans fin') },
            {
              label: 'État',
              render: (t) => {
                const [tone, label] = TARIFF_STATUS[t.status] || ['neutral', t.status];
                return h('span', { class: `vsh-badge vsh-badge--${tone}` }, label);
              },
            },
            {
              label: 'Actions',
              render: (t) => (t.status === 'PAST' ? '—' : button({
                text: t.status === 'CURRENT' ? 'Fixer une fin' : 'Modifier', variant: 'ghost', size: 'sm', onClick: () => tariffDialog(t),
              })),
            },
          ],
          rows: data,
        })
        : emptyState({ iconName: 'wallet', title: 'Aucun tarif', text: 'Sans tarif en vigueur, cette prestation ne peut pas être facturée (sauf ligne libre).' }), {
        actions: button({ text: 'Nouveau tarif', iconName: 'wallet', variant: 'ghost', onClick: () => tariffDialog(null) }),
      }));
    } catch (error) {
      if (isCurrent()) mount(history, errorState(error, loadHistory));
    }
  }

  function tariffDialog(tariff) {
    const inEffect = Boolean(tariff && tariff.status === 'CURRENT');
    const amount = field({ label: 'Montant (FCFA)', name: 'amount', type: 'number', inputmode: 'numeric', required: true, value: tariff ? String(tariff.amount) : '', attrs: { min: '0', step: '1' } });
    const from = field({ label: 'Applicable à partir du', name: 'valid_from', type: 'date', required: true, value: tariff ? tariff.valid_from : localDay(), attrs: { min: localDay() } });
    const to = field({ label: 'Jusqu’au (facultatif)', name: 'valid_to', type: 'date', value: tariff && tariff.valid_to ? tariff.valid_to : '', attrs: { min: localDay() } });
    formDialog({
      title: tariff ? (inEffect ? 'Fixer la fin du tarif en vigueur' : 'Modifier le tarif à venir') : 'Nouveau tarif',
      description: inEffect ? 'Le montant et la date de début d’un tarif appliqué ne changent plus.' : 'Le tarif en cours sans date de fin sera clôturé la veille de la date de début.',
      fields: inEffect ? { valid_to: to } : { amount, valid_from: from, valid_to: to },
      submitText: 'Enregistrer',
      submit: async () => {
        const body = { valid_to: to.input.value || null };
        if (!inEffect) {
          if (amount.input.value === '') throw new ApiError(422, 'VALIDATION_ERROR', 'Indiquez le montant.', { amount: ['Indiquez le montant.'] });
          body.amount = parseInt(amount.input.value, 10);
          body.valid_from = from.input.value;
        }
        if (tariff) await api.put(`tariffs/${tariff.id}`, body);
        else await api.post('tariffs', { ...body, billable_type: type.input.value, billable_id: item.input.value });
        toast('Tarif enregistré.');
        loadHistory();
      },
    });
  }
}
