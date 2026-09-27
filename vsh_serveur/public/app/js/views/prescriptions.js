/**
 * Ordonnances de la consultation (rapport C8) : rédaction libre ou à partir d'un modèle APPROUVÉ (lignes
 * copiées puis modifiables), alertes d'allergie informatives (jamais bloquantes : le prescripteur décide),
 * signature par le prescripteur, annulation motivée, PDF de l'ordonnance signée.
 * Aucune posologie n'est proposée par l'interface : elle vient du modèle validé ou du prescripteur.
 */
import { h, mount, uid } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { api, ApiError } from '../core/api.js';
import { session } from '../core/session.js';
import { formatDateTime } from '../core/format.js';
import { card, emptyState, errorState, skeleton, loadingRegion, button, field, askReason, toast } from '../ui.js';
import { confirmAction, formDialog } from '../dialogs.js';
import { textArea } from './consultation.js';
import { pdfButton } from './billing.js';

const LINE_FIELDS = [
  ['dosage', 'Dosage', 'Ex. 500 mg', 100],
  ['form', 'Forme', 'Ex. comprimé', 60],
  ['posology', 'Posologie', 'Ex. 1 comprimé', 255],
  ['frequency', 'Fréquence', 'Ex. 3 fois par jour', 100],
  ['duration', 'Durée', 'Ex. 5 jours', 100],
  ['quantity', 'Quantité à délivrer', 'Ex. 1 boîte', 60],
  ['route', 'Voie', 'Ex. orale', 60],
];
const SEVERITY = { LEGERE: 'légère', MODEREE: 'modérée', SEVERE: 'sévère', INCONNUE: 'gravité inconnue' };
const STATUS = { BROUILLON: ['warning', 'Brouillon'], SIGNEE: ['success', 'Signée'], ANNULEE: ['neutral', 'Annulée'] };

function statusBadge(status) {
  const [tone, label] = STATUS[status] || ['neutral', status];
  return h('span', { class: `vsh-badge vsh-badge--${tone}` }, label);
}

/**
 * Carte « Ordonnances » de la fiche de consultation.
 * @param {object} consultation
 * @param {{open: boolean, isCurrent: Function}} options
 */
export function prescriptionsCard(consultation, { open, isCurrent }) {
  const body = h('div', { class: 'stack' }, loadingRegion(skeleton('block')));
  const canWrite = open && session.can('prescriptions.write');
  const patientId = consultation.patient ? consultation.patient.id : consultation.patient_id;

  async function load() {
    mount(body, loadingRegion(skeleton('block')));
    try {
      const { data } = await api.get('prescriptions', { patient_id: patientId, per_page: 100 });
      const mine = data.filter((p) => p.consultation_id === consultation.id);
      const detailed = await Promise.all(mine.map((p) => api.get(`prescriptions/${p.id}`).then((r) => r.data)));
      if (!isCurrent()) return;
      mount(body, detailed.length
        ? h('ul', { class: 'rx-list' }, detailed.map(renderPrescription))
        : emptyState({ iconName: 'fileText', title: 'Aucune ordonnance', text: canWrite ? 'Rédigez une ordonnance libre ou à partir d’un modèle approuvé.' : null }));
    } catch (error) {
      if (isCurrent()) mount(body, errorState(error, load));
    }
  }

  function renderPrescription(p) {
    const actions = [];
    const prescriber = Boolean(p.prescriber && p.prescriber.id === session.user.id);
    if (p.status === 'BROUILLON' && prescriber && open && session.can('prescriptions.write')) {
      actions.push(button({ text: 'Modifier', variant: 'ghost', size: 'sm', onClick: () => editorDialog({ patientId, consultationId: consultation.id, draft: p, onDone: load }) }));
    }
    if (p.status === 'BROUILLON' && prescriber && session.can('prescriptions.sign')) {
      actions.push(button({
        text: 'Signer', iconName: 'checkCircle', size: 'sm',
        onClick: () => confirmAction({
          title: 'Signer l’ordonnance ?', danger: false, confirmText: 'Signer',
          text: 'Une ordonnance signée n’est plus modifiable. Le patient en est informé et peut la consulter.',
          run: async () => {
            await api.post(`prescriptions/${p.id}/sign`);
            toast('Ordonnance signée.');
            load();
          },
        }),
      }));
    }
    if (p.status === 'SIGNEE') actions.push(pdfButton(`prescriptions/${p.id}/pdf`, { text: 'PDF', size: 'sm' }));
    if (p.status !== 'ANNULEE' && prescriber && session.can('prescriptions.write')) {
      actions.push(button({
        text: 'Annuler', variant: 'ghost', size: 'sm',
        onClick: async () => {
          const reason = await askReason({
            title: 'Annuler l’ordonnance', confirmText: 'Annuler l’ordonnance',
            submit: (value) => api.post(`prescriptions/${p.id}/cancel`, { reason: value }),
          });
          if (reason !== null) {
            toast('Ordonnance annulée.');
            load();
          }
        },
      }));
    }
    const alerts = (p.alerts || []).map((a) => h('div', { class: 'vsh-alert vsh-alert--danger', role: 'status' }, icon('alert', { size: 18 }),
      h('span', {}, `Allergie connue : ${a.allergen} (${SEVERITY[a.severity] || a.severity}), ligne ${a.item_index + 1} : ${a.medication_label}. À vérifier par le prescripteur.`)));
    return h('li', { class: 'rx' },
      h('div', { class: 'rx__head' }, h('strong', {}, 'Ordonnance'), statusBadge(p.status),
        h('span', { class: 'vsh-muted' }, p.signed_at ? `Signée le ${formatDateTime(p.signed_at)}` : `Rédigée le ${formatDateTime(p.created_at)}`),
        p.prescriber ? h('span', { class: 'vsh-muted' }, p.prescriber.name) : null,
        p.template ? h('span', { class: 'vsh-muted' }, `Modèle : ${p.template.name}`) : null),
      alerts,
      (p.items || []).length ? h('ol', { class: 'rx__items' }, p.items.map((it) => h('li', {},
        h('strong', {}, [it.medication_label, it.dosage, it.form].filter(Boolean).join(' ')),
        h('small', {}, [it.posology, it.frequency, it.duration ? `pendant ${it.duration}` : null, it.route ? `voie ${it.route}` : null].filter(Boolean).join(' · ')),
        it.quantity ? h('small', {}, `Quantité : ${it.quantity}`) : null,
        it.instructions ? h('small', {}, it.instructions) : null))) : null,
      p.notes ? h('p', { class: 'note-text' }, p.notes) : null,
      p.cancel_reason ? h('small', { class: 'vsh-muted' }, `Annulée : ${p.cancel_reason}`) : null,
      actions.length ? h('div', { class: 'row' }, actions) : null);
  }

  load();
  return card('Ordonnances', body, {
    actions: canWrite ? button({
      text: 'Rédiger', iconName: 'fileText', variant: 'ghost',
      onClick: () => editorDialog({ patientId, consultationId: consultation.id, draft: null, onDone: load }),
    }) : null,
  });
}

// ---------------------------------------------------------------- Rédaction

function editorDialog({ patientId, consultationId, draft, onDone }) {
  const datalistId = uid('medications');
  const datalist = h('datalist', { id: datalistId });
  const known = new Map();
  const lines = h('div', { class: 'stack' });
  let templateId = draft && draft.template ? draft.template.id : null;

  // Suggestions du référentiel : la saisie libre reste possible (médicament absent du référentiel).
  let timer = null;
  function suggest(term) {
    clearTimeout(timer);
    if (term.trim().length < 2) return;
    timer = setTimeout(async () => {
      try {
        const { data } = await api.get('medications', { search: term.trim(), active: 1, per_page: 20 });
        datalist.replaceChildren(...data.map((m) => {
          const label = [m.dci, m.strength, m.form].filter(Boolean).join(' ');
          known.set(label, m);
          return h('option', { value: label });
        }));
      } catch (e) {
        /* suggestions facultatives */
      }
    }, 250);
  }

  function addLine(values = {}) {
    const label = field({
      label: 'Médicament', name: 'medication_label', required: true, value: values.medication_label || '',
      autocomplete: 'off', attrs: { list: datalistId, maxlength: '190' }, hint: 'Suggestions du référentiel ; saisie libre possible.',
    });
    label.input.dataset.medicationId = values.medication_id || '';
    const lineFields = { medication_label: label };
    label.input.addEventListener('input', () => {
      const picked = known.get(label.input.value);
      if (picked) {
        // Suggestion choisie : DCI dans le libellé, dosage, forme et voie repris du référentiel s'ils sont vides.
        label.input.dataset.medicationId = picked.id;
        label.input.value = picked.dci;
        [['dosage', picked.strength], ['form', picked.form], ['route', picked.route]].forEach(([name, value]) => {
          if (value && lineFields[name] && !lineFields[name].input.value) lineFields[name].input.value = value;
        });
        return;
      }
      label.input.dataset.medicationId = '';
      suggest(label.input.value);
    });
    const grid = h('div', { class: 'form-grid' }, h('div', { class: 'span-2' }, label.el));
    LINE_FIELDS.forEach(([name, text, placeholder, max]) => {
      const f = field({ label: text, name, value: values[name] || '', placeholder, attrs: { maxlength: String(max) } });
      lineFields[name] = f;
      grid.append(f.el);
    });
    const instructions = field({ label: 'Consignes', name: 'instructions', value: values.instructions || '', attrs: { maxlength: '500' } });
    lineFields.instructions = instructions;
    grid.append(h('div', { class: 'span-2' }, instructions.el));
    const legend = h('legend', { class: 'vsh-field__label' });
    const box = h('fieldset', { class: 'rx-line' },
      h('div', { class: 'rx-line__head' }, legend,
        button({ text: 'Retirer', iconName: 'x', variant: 'ghost', size: 'sm', onClick: () => { box.remove(); renumber(); } })),
      grid);
    box.lineFields = lineFields;
    lines.append(box);
    renumber();
    return box;
  }

  function renumber() {
    [...lines.children].forEach((box, i) => {
      box.querySelector('legend').textContent = `Médicament ${i + 1}`;
    });
  }

  function collect() {
    return [...lines.children].map((box) => {
      const f = box.lineFields;
      const label = f.medication_label.input.value.trim();
      const match = known.get(label);
      const item = { medication_label: label || null, medication_id: f.medication_label.input.dataset.medicationId || (match ? match.id : null) || null };
      [...LINE_FIELDS.map(([name]) => name), 'instructions'].forEach((name) => {
        item[name] = f[name].input.value.trim() || null;
      });
      return item;
    });
  }

  /** Erreurs « items.2.posology » renvoyées sur le bon champ de la bonne ligne. */
  function applyLineErrors(errors) {
    const boxes = [...lines.children];
    boxes.forEach((box) => Object.values(box.lineFields).forEach((f) => f.setError(null)));
    let first = null;
    Object.entries(errors || {}).forEach(([key, messages]) => {
      const match = key.match(/^items\.(\d+)\.(\w+)$/);
      if (!match || !boxes[Number(match[1])]) return;
      const target = boxes[Number(match[1])].lineFields[match[2]];
      if (target) {
        target.setError(Array.isArray(messages) ? messages[0] : String(messages));
        first = first || target;
      }
    });
    if (first) first.input.focus();
    return first !== null;
  }

  // Modèles approuvés (facultatif) : leurs lignes sont copiées puis restent modifiables.
  const template = field({ label: 'À partir d’un modèle approuvé (facultatif)', name: 'template_id', options: [['', 'Aucun (ordonnance libre)']] });
  const notes = textArea('Recommandations (facultatif)', 'notes', draft ? draft.notes || '' : '', false, 'Imprimées sur l’ordonnance.');
  if (draft) {
    template.el.hidden = true;
  } else if (session.can('prescription_templates.read')) {
    api.get('prescription-templates', { status: 'ACTIF', per_page: 100 }).then(({ data }) => {
      template.input.replaceChildren(h('option', { value: '' }, 'Aucun (ordonnance libre)'),
        ...data.map((t) => h('option', { value: t.id }, `${t.name}${t.pathology ? ` — ${t.pathology}` : ''}`)));
      if (!data.length) template.el.hidden = true;
    }).catch(() => { template.el.hidden = true; });
    template.input.addEventListener('change', async () => {
      templateId = template.input.value || null;
      if (!templateId) return;
      try {
        const { data } = await api.get(`prescription-templates/${templateId}`);
        lines.replaceChildren();
        (data.items || []).forEach((item) => addLine(item));
        if (!lines.children.length) addLine();
        if (data.usage_notes && !notes.input.value) notes.input.value = data.usage_notes;
        toast(`Modèle « ${data.name} » appliqué : vérifiez et adaptez chaque ligne.`, { type: 'info' });
      } catch (error) {
        toast(error.message, { type: 'error' });
      }
    });
  } else {
    template.el.hidden = true;
  }

  (draft && draft.items && draft.items.length ? draft.items : [{}]).forEach((item) => addLine(item));
  const addButton = button({
    text: 'Ajouter un médicament', iconName: 'fileText', variant: 'secondary',
    onClick: () => addLine().querySelector('input').focus(),
  });
  const itemsError = h('p', { class: 'vsh-field__error', hidden: true });
  const itemsField = {
    id: uid('items'),
    el: h('div', { class: 'stack' }, lines, itemsError, h('div', {}, addButton)),
    setError(message) {
      itemsError.hidden = !message;
      itemsError.replaceChildren(...(message ? [icon('alert', { size: 16 }), message] : []));
    },
  };

  formDialog({
    title: draft ? 'Modifier l’ordonnance' : 'Nouvelle ordonnance',
    description: 'Brouillon jusqu’à la signature. Les alertes d’allergie s’affichent après l’enregistrement, sans bloquer.',
    size: 'lg',
    fields: { template_id: template, items: itemsField, notes },
    extra: [datalist],
    submitText: draft ? 'Enregistrer' : 'Enregistrer le brouillon',
    submit: async () => {
      const items = collect();
      if (!items.length) throw new ApiError(422, 'VALIDATION_ERROR', 'Ajoutez au moins un médicament.', { items: ['Ajoutez au moins un médicament.'] });
      const body = { items, notes: notes.input.value.trim() || null };
      try {
        if (draft) {
          await api.put(`prescriptions/${draft.id}`, { ...body, version: draft.version });
          toast('Ordonnance mise à jour.');
        } else {
          const { data } = await api.post('prescriptions', { ...body, patient_id: patientId, consultation_id: consultationId, template_id: templateId });
          const alert = data.alerts && data.alerts.length;
          toast(alert ? 'Brouillon enregistré : allergie connue signalée, à vérifier.' : 'Brouillon d’ordonnance enregistré.', { type: alert ? 'error' : 'success' });
        }
      } catch (error) {
        if (error.code === 'VALIDATION_ERROR' && applyLineErrors(error.errors)) {
          throw new ApiError(422, 'VALIDATION_ERROR', 'Corrigez les lignes signalées.', {});
        }
        throw error;
      }
      onDone();
    },
  });
}
