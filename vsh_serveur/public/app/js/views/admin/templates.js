/**
 * Modèles d'ordonnance (rapport C8) : rédaction (brouillon), approbation par une personne habilitée,
 * archivage. Seuls les modèles approuvés sont proposés aux prescripteurs ; modifier un modèle approuvé
 * le repasse en brouillon (nouvelle version à réapprouver). Le contenu clinique vient de la clinique :
 * l'application ne propose aucune posologie.
 */
import { h, mount, uid } from '../../core/dom.js';
import { icon } from '../../core/icons.js';
import { api, ApiError } from '../../core/api.js';
import { session } from '../../core/session.js';
import { formatDateTime } from '../../core/format.js';
import { card, emptyState, errorState, skeleton, loadingRegion, button, field, toast } from '../../ui.js';
import { confirmAction, formDialog } from '../../dialogs.js';

const POPULATIONS = [['ADULTE', 'Adulte'], ['ENFANT', 'Enfant'], ['NOURRISSON', 'Nourrisson'], ['AUTRE', 'Autre']];
const POPULATION_LABELS = Object.fromEntries(POPULATIONS);
const STATUS = { BROUILLON: ['warning', 'Brouillon'], ACTIF: ['success', 'Approuvé'], ARCHIVE: ['neutral', 'Archivé'] };
const LINE_FIELDS = [
  ['dosage', 'Dosage', 100], ['form', 'Forme', 60], ['posology', 'Posologie', 255], ['frequency', 'Fréquence', 100],
  ['duration', 'Durée', 100], ['quantity', 'Quantité', 60], ['route', 'Voie', 60],
];

function statusBadge(status) {
  const [tone, label] = STATUS[status] || ['neutral', status];
  return h('span', { class: `vsh-badge vsh-badge--${tone}` }, label);
}

const text = (value) => (value === null || value === undefined ? '' : String(value));

export function templatesPanel(panel, isCurrent) {
  const status = field({ label: 'Statut', name: 'status', options: [['', 'Tous'], ['BROUILLON', 'Brouillons'], ['ACTIF', 'Approuvés'], ['ARCHIVE', 'Archivés']] });
  const q = field({ label: 'Rechercher', name: 'q', placeholder: 'Nom ou pathologie', autocomplete: 'off' });
  const results = h('div', { class: 'stack' });
  const filters = h('form', { class: 'toolbar', role: 'search', 'aria-label': 'Filtrer les modèles' },
    q.el, status.el, button({ text: 'Filtrer', iconName: 'search', variant: 'secondary', type: 'submit' }));
  filters.addEventListener('submit', (event) => {
    event.preventDefault();
    load();
  });
  mount(panel, h('div', { class: 'stack' },
    h('p', { class: 'audit-note' }, icon('info'), 'Seuls les modèles approuvés sont proposés à la prescription. Modifier un modèle approuvé le repasse en brouillon, à réapprouver.'),
    session.can('prescription_templates.manage') ? h('div', { class: 'row' }, button({ text: 'Nouveau modèle', iconName: 'fileText', onClick: () => templateDialog(null, load) })) : null,
    filters, results));

  async function load() {
    mount(results, loadingRegion(skeleton('block')));
    try {
      const { data } = await api.get('prescription-templates', { per_page: 100, status: status.input.value || null, q: q.input.value.trim() || null });
      if (!isCurrent()) return;
      if (!data.length) {
        mount(results, emptyState({ iconName: 'fileText', title: 'Aucun modèle', text: 'Les modèles sont rédigés par la clinique puis approuvés avant usage.' }));
        return;
      }
      mount(results, h('div', { class: 'cards-2' }, data.map((t) => card(t.name, h('div', { class: 'stack stack--sm' },
        h('div', { class: 'row' }, statusBadge(t.status), h('span', { class: 'vsh-muted' }, `Version ${t.template_version}`)),
        h('span', {}, `${t.pathology} · ${POPULATION_LABELS[t.population] || t.population}`),
        t.approved_by ? h('small', { class: 'vsh-muted' }, `Approuvé par ${t.approved_by.name} le ${formatDateTime(t.approved_at)}`) : null,
        t.items && t.items.length ? h('ol', { class: 'rx__items' }, t.items.map((it) => h('li', {},
          h('strong', {}, [it.medication_label, it.dosage, it.form].filter(Boolean).join(' ')),
          h('small', {}, [it.posology, it.frequency, it.duration ? `pendant ${it.duration}` : null].filter(Boolean).join(' · '))))) : null,
        t.contraindications_note ? h('p', { class: 'audit-note' }, icon('alert'), t.contraindications_note) : null), {
        actions: templateActions(t),
      }))));
    } catch (error) {
      if (isCurrent()) mount(results, errorState(error, load));
    }
  }

  function templateActions(t) {
    const actions = [];
    if (t.status !== 'ARCHIVE' && session.can('prescription_templates.manage')) {
      actions.push(button({ text: 'Modifier', variant: 'ghost', size: 'sm', onClick: () => templateDialog(t, load) }));
    }
    if (t.status === 'BROUILLON' && session.can('prescription_templates.approve')) {
      actions.push(button({
        text: 'Approuver', iconName: 'checkCircle', size: 'sm',
        onClick: () => confirmAction({
          title: `Approuver « ${t.name} » ?`, danger: false, confirmText: 'Approuver',
          text: 'Le modèle sera proposé aux prescripteurs. Vérifiez chaque ligne : l’approbation est nominative et tracée.',
          run: async () => {
            await api.post(`prescription-templates/${t.id}/approve`);
            toast('Modèle approuvé.');
            load();
          },
        }),
      }));
    }
    if (t.status !== 'ARCHIVE') {
      actions.push(button({
        text: 'Archiver', variant: 'ghost', size: 'sm',
        onClick: () => confirmAction({
          title: `Archiver « ${t.name} » ?`, confirmText: 'Archiver',
          text: 'Il ne sera plus proposé ; les ordonnances déjà rédigées en gardent une copie.',
          run: async () => {
            await api.post(`prescription-templates/${t.id}/archive`);
            toast('Modèle archivé.');
            load();
          },
        }),
      }));
    }
    return actions.length ? h('div', { class: 'row' }, actions) : null;
  }

  load();
}

function templateDialog(template, onDone) {
  const t = template || {};
  const f = {
    name: field({ label: 'Nom du modèle', name: 'name', required: true, value: text(t.name), attrs: { maxlength: '190' } }),
    pathology: field({ label: 'Pathologie / indication', name: 'pathology', required: true, value: text(t.pathology), attrs: { maxlength: '190' } }),
    population: field({ label: 'Population', name: 'population', required: true, options: POPULATIONS, value: t.population || 'ADULTE' }),
    age_min_months: field({ label: 'Âge min. (mois, facultatif)', name: 'age_min_months', type: 'number', value: text(t.age_min_months), attrs: { min: '0', step: '1' } }),
    age_max_months: field({ label: 'Âge max. (mois, facultatif)', name: 'age_max_months', type: 'number', value: text(t.age_max_months), attrs: { min: '0', step: '1' } }),
    weight_min_kg: field({ label: 'Poids min. (kg, facultatif)', name: 'weight_min_kg', type: 'number', value: text(t.weight_min_kg), attrs: { min: '0', step: 'any' } }),
    weight_max_kg: field({ label: 'Poids max. (kg, facultatif)', name: 'weight_max_kg', type: 'number', value: text(t.weight_max_kg), attrs: { min: '0', step: 'any' } }),
    contraindications_note: field({ label: 'Contre-indications et précautions (facultatif)', name: 'contraindications_note', value: text(t.contraindications_note), attrs: { maxlength: '2000' } }),
    usage_notes: field({ label: 'Recommandations au patient (facultatif)', name: 'usage_notes', value: text(t.usage_notes), attrs: { maxlength: '2000' }, hint: 'Reprises dans l’ordonnance.' }),
  };
  const lines = h('div', { class: 'stack' });

  function renumber() {
    [...lines.children].forEach((box, i) => { box.querySelector('legend').textContent = `Médicament ${i + 1}`; });
  }

  function addLine(values = {}) {
    const label = field({ label: 'Médicament', name: 'medication_label', required: true, value: text(values.medication_label), attrs: { maxlength: '190' } });
    const fields = { medication_label: label };
    const grid = h('div', { class: 'form-grid' }, h('div', { class: 'span-2' }, label.el));
    LINE_FIELDS.forEach(([name, labelText, max]) => {
      fields[name] = field({ label: labelText, name, value: text(values[name]), attrs: { maxlength: String(max) } });
      grid.append(fields[name].el);
    });
    fields.instructions = field({ label: 'Consignes', name: 'instructions', value: text(values.instructions), attrs: { maxlength: '500' } });
    grid.append(h('div', { class: 'span-2' }, fields.instructions.el));
    const box = h('fieldset', { class: 'rx-line' },
      h('div', { class: 'rx-line__head' }, h('legend', { class: 'vsh-field__label' }),
        button({ text: 'Retirer', iconName: 'x', variant: 'ghost', size: 'sm', onClick: () => { box.remove(); renumber(); } })),
      grid);
    box.lineFields = fields;
    box.medicationId = values.medication_id || null;
    lines.append(box);
    renumber();
  }

  (t.items && t.items.length ? t.items : [{}]).forEach((item) => addLine(item));
  const linesError = h('p', { class: 'vsh-field__error', hidden: true });
  const linesField = {
    id: uid('lines'),
    el: h('div', { class: 'stack' }, h('p', { class: 'vsh-field__label' }, 'Médicaments'), lines, linesError,
      h('div', {}, button({ text: 'Ajouter un médicament', variant: 'secondary', onClick: () => addLine() }))),
    setError(message) {
      linesError.hidden = !message;
      linesError.replaceChildren(...(message ? [icon('alert', { size: 16 }), message] : []));
    },
  };
  const num = (input) => (input.value === '' ? null : Number(input.value.replace(',', '.')));

  formDialog({
    title: template ? `Modifier « ${template.name} »` : 'Nouveau modèle d’ordonnance',
    description: template && template.status === 'ACTIF'
      ? 'Ce modèle est approuvé : l’enregistrement crée une nouvelle version en brouillon, à réapprouver.'
      : 'Le modèle reste en brouillon jusqu’à son approbation.',
    size: 'lg',
    fields: { ...f, items: linesField },
    submitText: 'Enregistrer',
    submit: async () => {
      const items = [...lines.children].map((box) => {
        const item = { medication_label: box.lineFields.medication_label.input.value.trim() || null, medication_id: box.medicationId };
        [...LINE_FIELDS.map(([name]) => name), 'instructions'].forEach((name) => { item[name] = box.lineFields[name].input.value.trim() || null; });
        return item;
      });
      if (!items.length) throw new ApiError(422, 'VALIDATION_ERROR', 'Ajoutez au moins un médicament.', { items: ['Ajoutez au moins un médicament.'] });
      const body = {
        name: f.name.input.value.trim(),
        pathology: f.pathology.input.value.trim(),
        population: f.population.input.value,
        age_min_months: num(f.age_min_months.input),
        age_max_months: num(f.age_max_months.input),
        weight_min_kg: num(f.weight_min_kg.input),
        weight_max_kg: num(f.weight_max_kg.input),
        contraindications_note: f.contraindications_note.input.value.trim() || null,
        usage_notes: f.usage_notes.input.value.trim() || null,
        items,
      };
      if (template) await api.put(`prescription-templates/${template.id}`, { ...body, version: template.version });
      else await api.post('prescription-templates', body);
      toast(template ? 'Modèle enregistré.' : 'Modèle créé (brouillon).');
      onDone();
    },
  });
}
