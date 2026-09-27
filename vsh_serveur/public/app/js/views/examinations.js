/**
 * Examens : file du technicien (urgences en tête), résultats à valider, fiche d'examen avec saisie
 * structurée selon les paramètres configurés du type d'examen, clôture et validation (D-005).
 * « Hors valeurs de référence » applique seulement les bornes saisies par la clinique : aucune interprétation.
 */
import { h, mount, uid } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { api, ApiError } from '../core/api.js';
import { session } from '../core/session.js';
import { setQuery } from '../core/router.js';
import { formatDateTime, formatDay, SEX } from '../core/format.js';
import { pageHead, card, table, tabs, emptyState, errorState, skeleton, loadingRegion, button, askReason, toast, setBusy, errorSummary, iconButton } from '../ui.js';
import { confirmAction } from '../dialogs.js';
import { EXAM_STATUS, textArea } from './consultation.js';

function statusBadge(status) {
  const [tone, text] = EXAM_STATUS[status] || ['neutral', status];
  return h('span', { class: `vsh-badge vsh-badge--${tone}` }, text);
}

const priorityBadge = (priority) => (priority === 'URGENTE' ? h('span', { class: 'vsh-badge vsh-badge--danger' }, 'Urgent') : h('span', { class: 'vsh-muted' }, 'Normale'));
const number = (value) => (value === null || value === undefined ? '' : String(value).replace('.', ','));

function referenceText(p) {
  if (p.ref_text) return p.ref_text;
  if (p.ref_min === null && p.ref_max === null) return null;
  return `${p.ref_min !== null ? number(p.ref_min) : '…'} – ${p.ref_max !== null ? number(p.ref_max) : '…'}${p.unit ? ` ${p.unit}` : ''}`;
}

// ---------------------------------------------------------------- File de travail

export async function examinationsView({ main, setTitle, query, isCurrent }) {
  setTitle('Examens');
  const defs = [];
  if (session.can('examinations.perform')) defs.push({ id: 'queue', label: 'À réaliser' });
  if (session.can('examinations.validate')) defs.push({ id: 'validate', label: 'À valider' });
  defs.push({ id: 'all', label: 'Tous' });
  const initial = defs.some((d) => d.id === query.tab) ? query.tab : defs[0].id;
  let current = null;

  const tabset = tabs(defs, initial, (id, panel) => {
    current = panel;
    setQuery(id === defs[0].id ? {} : { tab: id });
    load(id, panel);
  });
  mount(main, pageHead({ title: 'Examens', subtitle: 'Examens prescrits, résultats à saisir et à valider. Les urgents apparaissent en tête.' }), tabset.el);
  tabset.select(initial, false);

  async function load(id, panel) {
    mount(panel, loadingRegion(skeleton('block')));
    const params = { per_page: 100 };
    if (id === 'queue') params.queue = 1;
    if (id === 'validate') params.status = 'TERMINE';
    try {
      const { data } = await api.get('examinations', params);
      if (!isCurrent() || panel !== current) return;
      mount(panel, data.length
        ? table({
          caption: 'Examens',
          columns: [
            { label: 'Priorité', render: (e) => priorityBadge(e.priority) },
            { label: 'Prescrit le', render: (e) => h('span', { class: 'nowrap' }, formatDateTime(e.prescribed_at)) },
            { label: 'Patient', render: (e) => h('div', {}, h('strong', {}, e.patient.name), h('div', { class: 'vsh-muted mono' }, e.patient.file_number || '')) },
            { label: 'Examen', render: (e) => h('a', { class: 'cell-link', href: `#/examinations/${e.id}` }, e.examination_type.label) },
            { label: 'Prescripteur', render: (e) => (e.prescribed_by ? e.prescribed_by.name : '—') },
            { label: 'Statut', render: (e) => statusBadge(e.status) },
            {
              label: 'Action',
              render: (e) => h('a', { class: 'vsh-btn vsh-btn--secondary vsh-btn--sm', href: `#/examinations/${e.id}` },
                id === 'queue' ? 'Saisir les résultats' : id === 'validate' ? 'Relire et valider' : 'Ouvrir'),
            },
          ],
          rows: data,
        })
        : emptyState({
          iconName: 'flask',
          title: id === 'queue' ? 'Aucun examen à réaliser' : id === 'validate' ? 'Aucun résultat à valider' : 'Aucun examen',
          text: id === 'queue' ? 'Les examens prescrits apparaîtront ici, les urgents en premier.' : null,
        }));
    } catch (error) {
      if (isCurrent() && panel === current) mount(panel, errorState(error, () => load(id, panel)));
    }
  }
}

// ---------------------------------------------------------------- Fiche d'examen

export async function examinationView(ctx) {
  const { main, setTitle, params, isCurrent } = ctx;
  setTitle('Examen');
  mount(main, loadingRegion([skeleton('line'), skeleton('block')]));
  let exam;
  try {
    ({ data: exam } = await api.get(`examinations/${encodeURIComponent(params.id)}`));
  } catch (error) {
    if (!isCurrent()) return;
    mount(main, h('a', { class: 'back-link', href: '#/examinations' }, icon('chevronLeft'), 'Examens'),
      error.status === 404 ? emptyState({ iconName: 'search', title: 'Examen introuvable' }) : errorState(error, () => examinationView(ctx)));
    return;
  }
  if (!isCurrent()) return;
  const reload = () => examinationView(ctx);
  setTitle(`${exam.examination_type.label} · ${exam.patient.name}`);

  const active = ['PRESCRIT', 'EN_COURS'].includes(exam.status);
  const canPerform = active && session.can('examinations.perform');
  const hasResults = Array.isArray(exam.results) && exam.results.length > 0;

  // ---------------------------------------------------------------- En-tête et actions
  const actions = [];
  if (exam.status === 'TERMINE' && session.can('examinations.validate')) {
    actions.push(button({
      text: 'Valider les résultats', iconName: 'checkCircle',
      onClick: () => confirmAction({
        title: 'Valider ces résultats ?', danger: false, confirmText: 'Valider',
        text: 'Après validation, les résultats deviennent visibles par le patient dans son espace. Seuls le médecin traitant, le prescripteur ou l’équipe en charge peuvent valider (D-005).',
        run: async () => {
          await api.post(`examinations/${exam.id}/validate`);
          toast('Résultats validés ; le patient est prévenu.');
          reload();
        },
      }),
    }));
  }
  if (active && session.canAny('examinations.prescribe', 'examinations.perform')) {
    actions.push(button({
      text: 'Annuler l’examen', variant: 'secondary',
      onClick: async () => {
        const reason = await askReason({
          title: 'Annuler l’examen', label: 'Motif', confirmText: 'Annuler l’examen',
          submit: (value) => api.post(`examinations/${exam.id}/cancel`, { reason: value }),
        });
        if (reason !== null) {
          toast('Examen annulé.');
          reload();
        }
      },
    }));
  }

  const header = h('section', { class: 'vsh-card' },
    h('div', { class: 'page-head' },
      h('div', { class: 'page-head__text' },
        h('div', { class: 'patient-head__name' },
          h('h1', { tabindex: '-1', 'data-page-title': 'true' }, exam.examination_type.label),
          statusBadge(exam.status), exam.priority === 'URGENTE' ? h('span', { class: 'vsh-badge vsh-badge--danger' }, 'Urgent') : null),
        h('div', { class: 'patient-head__meta' },
          h('span', {}, icon('user'), `${exam.patient.name}${SEX[exam.patient.sex] ? ` · ${SEX[exam.patient.sex]}` : ''}${exam.patient.birth_date ? ` · né(e) le ${formatDay(exam.patient.birth_date)}` : ''}`),
          h('span', { class: 'mono' }, icon('fileText'), exam.patient.file_number || '—'),
          exam.examination_type.sample_type ? h('span', {}, icon('flask'), `Prélèvement : ${exam.examination_type.sample_type}`) : null)),
      actions.length ? h('div', { class: 'row' }, actions) : null));

  const timeline = h('dl', { class: 'dl' },
    [['Prescrit', exam.prescribed_at, exam.prescribed_by], ['Réalisé', exam.performed_at, exam.technician], ['Terminé', exam.completed_at, null], ['Validé', exam.validated_at, exam.validated_by]]
      .map(([label, at, who]) => h('div', {}, h('dt', {}, label), h('dd', {}, at ? `${formatDateTime(at)}${who ? ` · ${who.name}` : ''}` : '—'))));

  const info = [card('Suivi', timeline)];
  if (exam.clinical_info !== undefined) {
    info.push(card('Renseignements cliniques', h('p', {}, exam.clinical_info || 'Aucun renseignement transmis.')));
  }
  if (exam.cancel_reason) {
    info.unshift(h('div', { class: 'vsh-alert vsh-alert--warning', role: 'status' }, icon('alert', { size: 20 }), h('span', {}, `Examen annulé : ${exam.cancel_reason}`)));
  }

  const top = [h('a', { class: 'back-link', href: '#/examinations' }, icon('chevronLeft'), 'Examens'), header];

  if (exam.results === undefined) {
    mount(main, top, h('div', { class: 'cards-2' }, info), h('div', { class: 'vsh-alert vsh-alert--info', role: 'status' }, icon('lock', { size: 20 }),
      h('span', {}, 'Les résultats sont réservés aux soignants autorisés.')));
    return;
  }

  // ---------------------------------------------------------------- Résultats (lecture)
  const resultsTable = hasResults
    ? table({
      caption: 'Résultats',
      columns: [
        { label: 'Paramètre', render: (r) => h('strong', {}, r.label) },
        { label: 'Résultat', cls: 'num', render: (r) => h('span', { class: 'time-cell' }, r.value_numeric !== null ? number(r.value_numeric) : r.value_text || '—') },
        { label: 'Unité', render: (r) => r.unit || '' },
        { label: 'Référence', render: (r) => r.reference_text || '—' },
        {
          label: 'Repère',
          render: (r) => (r.is_abnormal
            ? h('span', { class: 'vsh-badge vsh-badge--warning' }, 'Hors valeurs de référence')
            : r.is_abnormal === false ? h('span', { class: 'vsh-badge vsh-badge--success' }, 'Dans les valeurs') : '—'),
        },
      ],
      rows: exam.results,
    })
    : h('p', { class: 'vsh-muted' }, 'Aucun résultat saisi pour le moment.');
  const readCard = card('Résultats', h('div', { class: 'stack' }, resultsTable,
    exam.comment ? h('p', {}, h('strong', {}, 'Commentaire : '), exam.comment) : null,
    h('p', { class: 'audit-note' }, icon('info'), 'Le repère compare la valeur aux bornes configurées par la clinique ; il ne constitue pas une interprétation.')));

  if (!canPerform) {
    mount(main, top, h('div', { class: 'workspace' }, h('div', { class: 'stack' }, readCard), h('div', { class: 'stack' }, info)));
    return;
  }

  // ---------------------------------------------------------------- Saisie des résultats
  let parameters = [];
  try {
    parameters = (await api.get(`examination-types/${exam.examination_type.id}/parameters`, { per_page: 200 })).data.filter((p) => p.active !== false);
  } catch (e) {
    parameters = [];
  }
  if (!isCurrent()) return;
  const existing = new Map((exam.results || []).filter((r) => r.parameter_id).map((r) => [r.parameter_id, r]));
  const inputs = parameters.map((p) => {
    const id = uid('param');
    const previous = existing.get(p.id);
    let input;
    if (p.value_type === 'CHOICE') {
      input = h('select', { id, class: 'vsh-select' }, h('option', { value: '' }, '—'),
        ...(p.choices || []).map((choice) => h('option', { value: choice, selected: Boolean(previous && previous.value_text === choice) }, choice)));
    } else {
      input = h('input', {
        id, class: 'vsh-input', inputmode: p.value_type === 'NUMERIC' ? 'decimal' : null, autocomplete: 'off',
        value: previous ? (p.value_type === 'NUMERIC' ? number(previous.value_numeric) : previous.value_text || '') : '',
      });
    }
    const ref = referenceText(p);
    const error = h('p', { class: 'vsh-field__error', hidden: true, id: `${id}-error` });
    return {
      p, input, error,
      row: h('div', { class: 'result-row' },
        h('label', { for: id, class: 'result-row__label' }, p.label, ref ? h('small', {}, `Réf. ${ref}`) : null),
        h('div', { class: 'result-row__input' }, input, p.unit ? h('span', { class: 'result-row__unit' }, p.unit) : null),
        error),
    };
  });
  const freeRows = h('div', { class: 'stack stack--sm' });
  const freeResults = [];
  function addFree(result = {}) {
    const label = h('input', { class: 'vsh-input', 'aria-label': 'Libellé du résultat libre', placeholder: 'Libellé', value: result.label || '' });
    const value = h('input', {
      class: 'vsh-input', 'aria-label': 'Valeur', placeholder: 'Valeur',
      value: result.value_numeric !== null && result.value_numeric !== undefined ? number(result.value_numeric) : result.value_text || '',
    });
    const unit = h('input', { class: 'vsh-input', 'aria-label': 'Unité', placeholder: 'Unité', value: result.unit || '' });
    const entry = { label, value, unit };
    const row = h('div', { class: 'free-row' }, label, value, unit, iconButton('x', 'Retirer ce résultat libre', () => {
      freeResults.splice(freeResults.indexOf(entry), 1);
      row.remove();
    }));
    freeResults.push(entry);
    freeRows.append(row);
    label.focus();
  }
  (exam.results || []).filter((r) => !r.parameter_id).forEach(addFree);

  const comment = textArea('Commentaire du laboratoire (facultatif)', 'comment', exam.comment, false);
  const summary = h('div', { hidden: true });
  const save = button({ text: 'Enregistrer les résultats', iconName: 'checkCircle', type: 'submit' });
  const complete = button({ text: 'Terminer l’examen', variant: 'secondary', iconName: 'arrowRight' });
  complete.disabled = !hasResults;
  const form = h('form', { class: 'stack', novalidate: true },
    summary,
    parameters.length
      ? h('div', { class: 'stack stack--sm' }, inputs.map((i) => i.row))
      : h('p', { class: 'vsh-muted' }, 'Aucun paramètre configuré pour cet examen : utilisez les résultats libres.'),
    h('div', { class: 'stack stack--sm' }, h('h3', {}, 'Résultats libres'), freeRows,
      h('div', {}, button({ text: 'Ajouter un résultat libre', variant: 'ghost', iconName: 'clipboard', onClick: () => addFree() }))),
    comment.el,
    h('div', { class: 'row row--between' },
      h('p', { class: 'vsh-muted' }, 'Enregistrer remplace la saisie précédente. « Terminer » transmet l’examen pour validation.'),
      h('div', { class: 'row' }, complete, save)));

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const results = [];
    inputs.forEach(({ p, input }) => {
      const raw = input.value.trim();
      if (raw === '') return;
      if (p.value_type === 'NUMERIC') results.push({ parameter_id: p.id, value_numeric: Number(raw.replace(',', '.')) });
      else results.push({ parameter_id: p.id, value_text: raw });
    });
    freeResults.forEach(({ label, value, unit }) => {
      const raw = value.value.trim();
      if (!label.value.trim() && !raw) return;
      const numeric = /^-?\d+([.,]\d+)?$/.test(raw);
      results.push({
        label: label.value.trim(), unit: unit.value.trim() || null,
        ...(numeric ? { value_numeric: Number(raw.replace(',', '.')) } : { value_text: raw }),
      });
    });
    inputs.forEach(({ error, input }) => {
      error.hidden = true;
      input.removeAttribute('aria-invalid');
    });
    if (!results.length) {
      errorSummary(summary, 'Saisissez au moins un résultat.', []);
      return;
    }
    setBusy(save, true);
    try {
      await api.post(`examinations/${exam.id}/results`, { results, comment: comment.input.value.trim() || null });
      toast('Résultats enregistrés.');
      reload();
    } catch (error) {
      if (error instanceof ApiError && error.code === 'VALIDATION_ERROR') {
        const items = [];
        Object.entries(error.errors || {}).forEach(([key, messages]) => {
          const match = /^results\.(\d+)\./.exec(key);
          const message = Array.isArray(messages) ? messages[0] : String(messages);
          const target = match ? results[Number(match[1])] : null;
          const entry = target && target.parameter_id ? inputs.find((i) => i.p.id === target.parameter_id) : null;
          if (entry) {
            entry.error.hidden = false;
            entry.error.replaceChildren(icon('alert', { size: 16 }), message);
            entry.input.setAttribute('aria-invalid', 'true');
            entry.input.setAttribute('aria-describedby', entry.error.id);
            items.push({ id: entry.input.id, message: `${entry.p.label} : ${message}` });
          } else {
            items.push({ id: null, message });
          }
        });
        errorSummary(summary, 'Certains résultats sont invalides.', items);
      } else {
        errorSummary(summary, error.message || 'Enregistrement impossible.', []);
      }
    } finally {
      setBusy(save, false);
    }
  });
  complete.addEventListener('click', () => confirmAction({
    title: 'Terminer l’examen ?', danger: false, confirmText: 'Terminer',
    text: 'Les résultats enregistrés seront transmis pour validation médicale. Ils ne seront plus modifiables.',
    run: async () => {
      await api.post(`examinations/${exam.id}/complete`);
      toast('Examen terminé : en attente de validation.');
      reload();
    },
  }));

  mount(main, top,
    h('div', { class: 'workspace' },
      h('div', { class: 'stack' }, card('Saisie des résultats', form), hasResults ? readCard : null),
      h('div', { class: 'stack' }, info)));
}
