/**
 * Facturation (rapport C9, D-004) : suivi des factures et des impayés, fiche de facture (brouillon
 * modifiable, émission numérotée, annulation motivée), déclaration du règlement, document PDF.
 * Les prix viennent des tarifs en vigueur (jamais saisis, sauf ligne libre) ; aucun paiement n'est
 * traité par la plateforme : l'état de règlement est déclaratif.
 */
import { h, mount, uid } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { api, ApiError, download } from '../core/api.js';
import { session } from '../core/session.js';
import { navigate, setQuery } from '../core/router.js';
import { badge, formatDate, formatDateTime, formatMoney } from '../core/format.js';
import {
  pageHead, card, table, tabs, emptyState, errorState, skeleton, loadingRegion, button, iconButton, field, askReason, toast, setBusy,
} from '../ui.js';
import { confirmAction, formDialog } from '../dialogs.js';
import { patientPicker } from '../patientPicker.js';
import { textArea } from './consultation.js';

const ITEM_TYPES = { MEDICAL_ACT: 'Acte', MEDICATION: 'Médicament', TREATMENT: 'Soin', EXAMINATION: 'Examen', OTHER: 'Ligne libre' };
const SETTLEMENT_OPTIONS = [['NON_REGLEE', 'Non réglée'], ['PARTIELLEMENT_REGLEE', 'Partiellement réglée'], ['REGLEE', 'Réglée']];

/** Tarifs manquants signalés à la création d'un brouillon (affichés une fois sur la fiche). */
const missingByInvoice = new Map();

async function downloadWithToast(path) {
  try {
    const name = await download(path);
    toast(`Document téléchargé : ${name}`);
  } catch (error) {
    toast(error.message, { type: 'error' });
  }
}

/** Bouton « PDF » : téléchargement authentifié (jeton en en-tête), erreur affichée en message. */
export function pdfButton(path, { text = 'Télécharger le PDF', variant = 'secondary', size } = {}) {
  const btn = button({
    text, iconName: 'fileText', variant, size,
    onClick: async () => {
      setBusy(btn, true);
      await downloadWithToast(path);
      setBusy(btn, false);
    },
  });
  return btn;
}

/**
 * Brouillon de facture depuis une consultation clôturée ou une visite terminée : les soins et examens
 * réalisés non encore facturés sont repris au tarif en vigueur à leur date (serveur).
 */
export async function createInvoiceFor({ patientId, consultationId = null, homecareRequestId = null }) {
  const { data } = await api.post('invoices', {
    patient_id: patientId,
    consultation_id: consultationId,
    homecare_request_id: homecareRequestId,
  });
  if (data.missing_tariffs && data.missing_tariffs.length) missingByInvoice.set(data.id, data.missing_tariffs);
  toast('Brouillon de facture créé.');
  navigate(`/billing/${data.id}`);
}

// ---------------------------------------------------------------- Liste

export async function billingView({ main, setTitle, query, isCurrent }) {
  setTitle('Facturation');
  const actions = [];
  if (session.can('invoices.manage')) actions.push(button({ text: 'Nouvelle facture', iconName: 'receipt', onClick: newInvoiceDialog }));
  const head = pageHead({ title: 'Facturation', subtitle: 'Factures, règlements déclarés et reste à percevoir. Le paiement se fait hors plateforme.', actions });

  const from = field({ label: 'Du', name: 'from', type: 'date', value: query.from || '' });
  const to = field({ label: 'Au', name: 'to', type: 'date', value: query.to || '' });
  const number = field({ label: 'N° de facture', name: 'number', value: query.number || '', placeholder: 'FAC-2026-…', autocomplete: 'off' });
  const settlement = field({ label: 'Règlement', name: 'settlement_status', options: [['', 'Tous'], ...SETTLEMENT_OPTIONS], value: query.settlement || '' });
  const summaryBox = h('div', { class: 'kpis' });
  const filters = h('form', { class: 'toolbar', role: 'search', 'aria-label': 'Filtrer les factures' },
    from.el, to.el, number.el, settlement.el, button({ text: 'Filtrer', iconName: 'search', variant: 'secondary', type: 'submit' }));
  filters.addEventListener('submit', (event) => {
    event.preventDefault();
    setQuery({ from: from.input.value || null, to: to.input.value || null, number: number.input.value.trim() || null, settlement: settlement.input.value || null });
    loadSummary();
    load();
  });

  const defs = [{ id: 'EMISE', label: 'Émises' }, { id: 'BROUILLON', label: 'Brouillons' }, { id: 'ANNULEE', label: 'Annulées' }, { id: 'all', label: 'Toutes' }];
  const initial = defs.some((d) => d.id === query.tab) ? query.tab : 'EMISE';
  let current = { id: initial, panel: null };
  const tabset = tabs(defs, initial, (id, panel) => {
    current = { id, panel };
    setQuery({ tab: id === 'EMISE' ? null : id });
    load();
  });
  mount(main, head, summaryBox, filters, tabset.el);
  loadSummary();
  tabset.select(initial, false);

  async function loadSummary() {
    mount(summaryBox, skeleton('kpi', 4));
    try {
      const { data } = await api.get('invoices/summary', { from: from.input.value || null, to: to.input.value || null });
      if (!isCurrent()) return;
      const kpi = (label, value, iconName, tone, hint) => h('div', { class: ['kpi-card', tone && `kpi-card--${tone}`] },
        h('div', { class: 'kpi-card__top' }, h('span', { class: 'vsh-label' }, label), h('span', { class: 'kpi-card__icon', 'aria-hidden': 'true' }, icon(iconName))),
        h('span', { class: 'vsh-kpi__value kpi-card__money' }, value),
        hint ? h('span', { class: 'kpi-card__hint' }, hint) : null);
      mount(summaryBox,
        kpi('Factures émises', String(data.issued_count), 'receipt', null, data.from || data.to ? 'Sur la période choisie' : 'Depuis le début'),
        kpi('Montant net facturé', formatMoney(data.net_amount, data.currency), 'wallet', 'info'),
        kpi('Règlements déclarés', formatMoney(data.declared_paid_amount, data.currency), 'checkCircle', null,
          `${data.by_settlement.REGLEE} réglée(s), ${data.by_settlement.PARTIELLEMENT_REGLEE} partielle(s)`),
        kpi('Reste à percevoir', formatMoney(data.outstanding_amount, data.currency), 'alert', data.outstanding_amount > 0 ? 'warning' : null,
          `${data.by_settlement.NON_REGLEE} facture(s) non réglée(s)`));
    } catch (error) {
      if (isCurrent()) mount(summaryBox, errorState(error, loadSummary));
    }
  }

  async function load() {
    const { id, panel } = current;
    if (!panel) return;
    const params = {
      per_page: 100,
      status: id === 'all' ? null : id,
      from: from.input.value || null,
      to: to.input.value || null,
      number: number.input.value.trim() || null,
      settlement_status: id === 'EMISE' || id === 'all' ? settlement.input.value || null : null,
    };
    mount(panel, loadingRegion(skeleton('block')));
    try {
      const { data, meta } = await api.get('invoices', params);
      if (!isCurrent() || panel !== current.panel) return;
      if (!data.length) {
        mount(panel, emptyState({
          iconName: 'receipt',
          title: id === 'BROUILLON' ? 'Aucun brouillon' : 'Aucune facture',
          text: 'Les factures se créent depuis une consultation clôturée, une visite à domicile terminée, ou avec « Nouvelle facture ».',
        }));
        return;
      }
      mount(panel, table({
        caption: 'Factures',
        columns: [
          { label: 'N°', render: (inv) => h('a', { class: 'cell-link mono', href: `#/billing/${inv.id}` }, inv.number || 'Brouillon') },
          { label: 'Date', render: (inv) => h('span', { class: 'nowrap' }, formatDate(inv.issued_at || inv.created_at)) },
          { label: 'Patient', render: (inv) => h('div', {}, inv.patient.name, h('div', { class: 'vsh-muted mono' }, inv.patient.file_number || '')) },
          { label: 'Net', cls: 'num', render: (inv) => h('strong', { class: 'nowrap' }, formatMoney(inv.net_amount, inv.currency)) },
          { label: 'État', render: (inv) => (inv.status === 'EMISE' ? badge('settlement', inv.settlement_status) : badge('invoice', inv.status)) },
          { label: 'Reste dû', cls: 'num', render: (inv) => (inv.status === 'EMISE' ? h('span', { class: 'nowrap' }, formatMoney(inv.outstanding_amount, inv.currency)) : '—') },
          {
            label: 'Actions',
            render: (inv) => h('div', { class: 'row-actions' },
              h('a', { class: 'vsh-btn vsh-btn--secondary vsh-btn--sm', href: `#/billing/${inv.id}` }, 'Ouvrir'),
              iconButton('fileText', `PDF de la facture ${inv.number || 'brouillon'}`, () => downloadWithToast(`invoices/${inv.id}/pdf`))),
          },
        ],
        rows: data,
        foot: meta && meta.total > data.length ? `${data.length} factures affichées sur ${meta.total}. Affinez avec les filtres.` : null,
      }));
    } catch (error) {
      if (isCurrent() && panel === current.panel) mount(panel, errorState(error, load));
    }
  }
}

function newInvoiceDialog() {
  const picker = patientPicker();
  const notes = textArea('Observations (facultatif)', 'notes', '', false, 'Imprimées sur la facture.');
  formDialog({
    title: 'Nouvelle facture',
    description: 'Un brouillon est créé : ajoutez ensuite les prestations. Pour facturer une consultation ou une visite, partez plutôt de leur fiche (reprise automatique des soins et examens).',
    fields: { patient_id: picker, notes },
    submitText: 'Créer le brouillon',
    submit: async () => {
      const patient = picker.value();
      if (!patient) throw new ApiError(422, 'VALIDATION_ERROR', 'Choisissez un patient.', { patient_id: ['Choisissez un patient.'] });
      const { data } = await api.post('invoices', { patient_id: patient.id, notes: notes.input.value.trim() || null });
      toast('Brouillon de facture créé.');
      navigate(`/billing/${data.id}`);
    },
  });
}

// ---------------------------------------------------------------- Fiche

export async function invoiceView(ctx) {
  const { main, setTitle, params, isCurrent } = ctx;
  setTitle('Facture');
  mount(main, loadingRegion([skeleton('line'), skeleton('block')]));
  let invoice;
  try {
    invoice = (await api.get(`invoices/${encodeURIComponent(params.id)}`)).data;
  } catch (error) {
    if (!isCurrent()) return;
    mount(main, h('a', { class: 'back-link', href: '#/billing' }, icon('chevronLeft'), 'Facturation'),
      error.status === 404 ? emptyState({ iconName: 'search', title: 'Facture introuvable' }) : errorState(error, () => invoiceView(ctx)));
    return;
  }
  if (!isCurrent()) return;
  const reload = () => invoiceView(ctx);
  const draft = invoice.status === 'BROUILLON';
  const issued = invoice.status === 'EMISE';
  const canManage = session.can('invoices.manage') && draft;
  const currencyLabel = invoice.currency === 'XOF' ? 'FCFA' : invoice.currency;
  const money = (value) => formatMoney(value, invoice.currency);
  const title = invoice.number ? `Facture ${invoice.number}` : 'Brouillon de facture';
  setTitle(title);

  // ---------------------------------------------------------------- Actions
  const actions = [pdfButton(`invoices/${invoice.id}/pdf`, { text: draft ? 'Aperçu PDF' : 'Télécharger le PDF' })];
  if (draft && session.can('invoices.issue')) {
    actions.push(button({
      text: 'Émettre la facture', iconName: 'checkCircle',
      onClick: () => confirmAction({
        title: 'Émettre la facture ?', danger: false, confirmText: 'Émettre',
        text: `Un numéro définitif est attribué et la facture n’est plus modifiable. Net à payer : ${money(invoice.net_amount)}. Le patient est prévenu, sans montant dans la notification.`,
        run: async () => {
          const { data } = await api.post(`invoices/${invoice.id}/issue`);
          toast(`Facture ${data.number} émise.`);
          reload();
        },
      }),
    }));
  }
  if (issued && session.can('invoices.settlement_declare')) {
    actions.push(button({ text: 'Déclarer le règlement', iconName: 'wallet', variant: 'secondary', onClick: settlementDialog }));
  }
  if ((draft || issued) && session.can('invoices.issue')) {
    actions.push(button({
      text: 'Annuler', variant: 'secondary',
      onClick: async () => {
        const reason = await askReason({
          title: 'Annuler la facture', confirmText: 'Annuler la facture',
          description: issued ? 'La facture reste consultable avec la mention « annulée ». Une visite facturée redevient facturable.' : 'Le brouillon est abandonné.',
          submit: (value) => api.post(`invoices/${invoice.id}/cancel`, { reason: value }),
        });
        if (reason !== null) {
          toast('Facture annulée.');
          reload();
        }
      },
    }));
  }

  function settlementDialog() {
    const groupId = uid('settle');
    const radios = SETTLEMENT_OPTIONS.map(([value, label]) => {
      const input = h('input', { type: 'radio', name: 'settlement_status', value, checked: value === invoice.settlement_status });
      return h('label', { class: 'choice' }, input, h('span', {}, label));
    });
    const statusField = {
      id: groupId,
      el: h('fieldset', { class: 'vsh-field', id: groupId, tabindex: '-1' }, h('legend', { class: 'vsh-field__label' }, 'État du règlement'), h('div', { class: 'choice-group' }, radios)),
      setError: () => {},
    };
    const amount = field({
      label: `Montant déclaré réglé (${currencyLabel})`, name: 'declared_paid_amount', type: 'number', inputmode: 'numeric',
      value: invoice.settlement_status === 'PARTIELLEMENT_REGLEE' ? String(invoice.declared_paid_amount) : '',
      hint: `Entre 1 et ${money(invoice.net_amount - 1)} pour un règlement partiel.`, attrs: { min: '1', step: '1', max: String(invoice.net_amount - 1) },
    });
    const comment = field({ label: 'Commentaire (facultatif)', name: 'comment', hint: 'Ex. espèces à l’accueil, mobile money, prise en charge.', attrs: { maxlength: '500' } });
    const sync = () => {
      const selected = statusField.el.querySelector('input:checked');
      amount.el.hidden = !selected || selected.value !== 'PARTIELLEMENT_REGLEE';
    };
    statusField.el.addEventListener('change', sync);
    sync();
    formDialog({
      title: 'Déclarer le règlement',
      description: `Information déclarative : aucun paiement n’est traité par la plateforme (D-004). Net à payer : ${money(invoice.net_amount)}.`,
      fields: { settlement_status: statusField, declared_paid_amount: amount, comment },
      submitText: 'Enregistrer',
      submit: async () => {
        const selected = statusField.el.querySelector('input:checked').value;
        await api.post(`invoices/${invoice.id}/settlement`, {
          settlement_status: selected,
          declared_paid_amount: selected === 'PARTIELLEMENT_REGLEE' ? Number(amount.input.value || 0) : null,
          comment: comment.input.value.trim() || null,
        });
        toast('État de règlement enregistré.');
        reload();
      },
    });
  }

  // ---------------------------------------------------------------- En-tête
  const header = h('section', { class: 'vsh-card stack' },
    h('div', { class: 'page-head' },
      h('div', { class: 'page-head__text' },
        h('div', { class: 'patient-head__name' }, h('h1', { tabindex: '-1', 'data-page-title': 'true' }, title), badge('invoice', invoice.status), issued ? badge('settlement', invoice.settlement_status) : null),
        h('div', { class: 'patient-head__meta' },
          h('a', { href: `#/patients/${invoice.patient.id}` }, icon('user'), `${invoice.patient.name} · ${invoice.patient.file_number || 'dossier'}`),
          h('span', {}, icon('clock'), invoice.issued_at ? `Émise le ${formatDateTime(invoice.issued_at)}` : `Créée le ${formatDateTime(invoice.created_at)}`),
          invoice.consultation_id ? h('a', { href: `#/consultations/${invoice.consultation_id}` }, icon('stethoscope'), 'Consultation') : null,
          invoice.homecare_request_id ? h('a', { href: `#/homecare/${invoice.homecare_request_id}` }, icon('home'), 'Visite à domicile') : null)),
      h('div', { class: 'row' }, actions)));

  const alerts = [];
  if (draft) alerts.push(h('div', { class: 'vsh-alert vsh-alert--info', role: 'status' }, icon('info', { size: 20 }), h('span', {}, 'Brouillon modifiable. Le numéro définitif est attribué à l’émission.')));
  if (invoice.status === 'ANNULEE') {
    alerts.push(h('div', { class: 'vsh-alert vsh-alert--warning', role: 'status' }, icon('alert', { size: 20 }), h('span', {}, `Annulée le ${formatDateTime(invoice.cancelled_at)} : ${invoice.cancel_reason || ''}`)));
  }
  const missing = missingByInvoice.get(invoice.id);
  if (missing && draft) {
    alerts.push(h('div', { class: 'vsh-alert vsh-alert--warning', role: 'status' }, icon('alert', { size: 20 }),
      h('div', { class: 'stack stack--sm' },
        h('strong', {}, `${missing.length} prestation(s) sans tarif en vigueur n’ont pas été reprises :`),
        h('ul', {}, missing.map((m) => h('li', {}, `${m.description} (${formatDate(m.date)})`))),
        h('span', {}, 'Faites saisir le tarif (Administration › Tarifs), ou ajoutez une ligne libre.'))));
    missingByInvoice.delete(invoice.id);
  }

  // ---------------------------------------------------------------- Prestations
  const itemsTable = invoice.items.length
    ? table({
      caption: 'Prestations',
      columns: [
        { label: 'Désignation', render: (it) => h('div', {}, it.description, h('div', { class: 'vsh-muted' }, ITEM_TYPES[it.item_type] || it.item_type)) },
        { label: 'Qté', cls: 'num', render: (it) => String(it.quantity) },
        { label: 'Prix unitaire', cls: 'num', render: (it) => h('span', { class: 'nowrap' }, money(it.unit_price)) },
        { label: 'Montant', cls: 'num', render: (it) => h('strong', { class: 'nowrap' }, money(it.total_price)) },
        ...(canManage ? [{
          label: 'Retirer',
          render: (it) => iconButton('x', `Retirer ${it.description}`, () => confirmAction({
            title: 'Retirer cette ligne ?', confirmText: 'Retirer', text: it.description,
            run: async () => {
              await api.del(`invoices/${invoice.id}/items/${it.id}`);
              toast('Ligne retirée.');
              reload();
            },
          })),
        }] : []),
      ],
      rows: invoice.items,
    })
    : emptyState({ iconName: 'receipt', title: 'Aucune prestation', text: canManage ? 'Ajoutez un acte, un médicament ou une ligne libre.' : null });

  const totals = h('dl', { class: 'totals' },
    h('div', {}, h('dt', {}, 'Total'), h('dd', {}, money(invoice.total_amount))),
    invoice.discount_amount > 0 ? h('div', {}, h('dt', {}, 'Remise'), h('dd', {}, `- ${money(invoice.discount_amount)}`)) : null,
    h('div', { class: 'totals__net' }, h('dt', {}, 'Net à payer'), h('dd', {}, money(invoice.net_amount))),
    issued ? h('div', {}, h('dt', {}, 'Règlement déclaré'), h('dd', {}, money(invoice.declared_paid_amount))) : null,
    issued ? h('div', { class: 'totals__due' }, h('dt', {}, 'Reste dû'), h('dd', {}, money(invoice.outstanding_amount))) : null);

  const itemsCard = card('Prestations', h('div', { class: 'stack' }, itemsTable, totals), {
    actions: canManage ? button({ text: 'Ajouter une ligne', iconName: 'receipt', variant: 'ghost', onClick: () => addItemDialog(invoice, reload) }) : null,
  });

  let adjustCard = null;
  if (canManage) {
    const discount = field({
      label: `Remise (${currencyLabel})`, name: 'discount_amount', type: 'number', inputmode: 'numeric',
      value: String(invoice.discount_amount), attrs: { min: '0', step: '1', max: String(invoice.total_amount) }, hint: `Au plus ${money(invoice.total_amount)}.`,
    });
    const notes = textArea('Observations', 'notes', invoice.notes || '', false, 'Imprimées sur la facture.');
    const save = button({ text: 'Enregistrer', variant: 'secondary', type: 'submit' });
    const form = h('form', { class: 'stack', novalidate: true }, discount.el, notes.el, h('div', {}, save));
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      discount.setError(null);
      setBusy(save, true);
      try {
        await api.put(`invoices/${invoice.id}`, { discount_amount: Number(discount.input.value || 0), notes: notes.input.value.trim() || null, version: invoice.version });
        toast('Facture mise à jour.');
        reload();
      } catch (error) {
        if (error.errors && error.errors.discount_amount) discount.setError(error.errors.discount_amount[0]);
        else toast(error.message, { type: 'error' });
      } finally {
        setBusy(save, false);
      }
    });
    adjustCard = card('Remise et observations', form);
  } else if (invoice.notes) {
    adjustCard = card('Observations', h('p', { class: 'note-text' }, invoice.notes));
  }

  const historyCard = card('Historique', (invoice.history || []).length
    ? h('ol', { class: 'timeline' }, invoice.history.slice().reverse().map((entry) => h('li', { class: 'timeline__item' },
      h('span', { class: 'timeline__dot', 'aria-hidden': 'true' }),
      h('div', { class: 'stack stack--sm' },
        h('div', { class: 'row' }, entry.field === 'SETTLEMENT' ? badge('settlement', entry.to) : badge('invoice', entry.to),
          h('span', { class: 'vsh-muted' }, `${formatDateTime(entry.at)} · ${entry.by.name}`)),
        entry.amount !== null && entry.field === 'SETTLEMENT' ? h('small', {}, `Montant déclaré : ${money(entry.amount)}`) : null,
        entry.comment ? h('small', {}, entry.comment) : null))))
    : h('p', { class: 'vsh-muted' }, 'Aucun événement.'));

  mount(main, h('a', { class: 'back-link', href: '#/billing' }, icon('chevronLeft'), 'Facturation'), header, alerts,
    h('div', { class: 'workspace' }, h('div', { class: 'stack' }, itemsCard), h('div', { class: 'stack' }, adjustCard, historyCard)));
}

// ---------------------------------------------------------------- Ajout d'une ligne

function addItemDialog(invoice, onDone) {
  const currencyLabel = invoice.currency === 'XOF' ? 'FCFA' : invoice.currency;
  const typeName = uid('type');
  const types = [['MEDICAL_ACT', 'Acte médical'], ['MEDICATION', 'Médicament'], ['OTHER', 'Ligne libre']];
  const typeGroup = h('div', { class: 'choice-group' }, types.map(([value, label], index) => {
    const input = h('input', { type: 'radio', name: typeName, value, checked: index === 0 });
    return h('label', { class: 'choice' }, input, h('span', {}, label));
  }));
  const typeField = {
    id: uid('type-field'),
    el: h('fieldset', { class: 'vsh-field' }, h('legend', { class: 'vsh-field__label' }, 'Type de ligne'), typeGroup),
    setError: () => {},
  };
  const search = field({ label: 'Rechercher dans le référentiel', name: 'search', autocomplete: 'off', placeholder: 'Libellé ou code (2 lettres au moins)' });
  const reference = field({ label: 'Élément', name: 'reference_id', options: [['', 'Tapez au moins 2 lettres']], required: true });
  const price = h('p', { class: 'audit-note', role: 'status' });
  const description = field({ label: 'Libellé', name: 'description', required: true, attrs: { maxlength: '255' } });
  const unitPrice = field({ label: `Prix unitaire (${currencyLabel})`, name: 'unit_price', type: 'number', inputmode: 'numeric', required: true, attrs: { min: '0', step: '1' } });
  const quantity = field({ label: 'Quantité', name: 'quantity', type: 'number', inputmode: 'numeric', value: '1', attrs: { min: '1', max: '1000', step: '1' } });

  const selectedType = () => typeGroup.querySelector('input:checked').value;
  let timer = null;
  let seq = 0;

  function syncType() {
    const free = selectedType() === 'OTHER';
    search.el.hidden = free;
    reference.el.hidden = free;
    price.hidden = free;
    description.el.hidden = !free;
    unitPrice.el.hidden = !free;
    reference.input.replaceChildren(h('option', { value: '' }, 'Tapez au moins 2 lettres'));
    price.replaceChildren();
    if (!free && search.input.value.trim().length >= 2) runSearch();
  }

  async function runSearch() {
    const term = search.input.value.trim();
    const slug = selectedType() === 'MEDICAL_ACT' ? 'medical-acts' : 'medications';
    const mine = ++seq;
    if (term.length < 2) return;
    try {
      const { data } = await api.get(slug, { search: term, active: 1, per_page: 30 });
      if (mine !== seq) return;
      const label = (item) => (slug === 'medications'
        ? [item.dci, item.strength, item.form, item.commercial_name ? `(${item.commercial_name})` : null].filter(Boolean).join(' ')
        : `${item.label}${item.code ? ` · ${item.code}` : ''}`);
      reference.input.replaceChildren(
        h('option', { value: '' }, data.length ? `${data.length} résultat(s) : choisissez` : 'Aucun résultat'),
        ...data.map((item) => h('option', { value: item.id }, label(item))));
      price.replaceChildren();
    } catch (error) {
      reference.input.replaceChildren(h('option', { value: '' }, 'Recherche indisponible'));
    }
  }

  typeGroup.addEventListener('change', syncType);
  search.input.addEventListener('input', () => {
    clearTimeout(timer);
    timer = setTimeout(runSearch, 250);
  });
  reference.input.addEventListener('change', async () => {
    price.replaceChildren();
    if (!reference.input.value) return;
    try {
      const { data } = await api.get('tariffs/current', { billable_type: selectedType(), billable_id: reference.input.value });
      price.replaceChildren(...(data
        ? [icon('wallet'), `Tarif en vigueur : ${formatMoney(data.amount, invoice.currency)}`]
        : [icon('alert'), 'Aucun tarif en vigueur : la ligne serait refusée. Faites saisir le tarif, ou utilisez une ligne libre.']));
    } catch (error) {
      price.replaceChildren(icon('alert'), 'Tarif indisponible.');
    }
  });
  syncType();

  formDialog({
    title: 'Ajouter une ligne',
    description: 'Actes et médicaments sont facturés au tarif en vigueur. La ligne libre porte son propre prix.',
    fields: {
      type: typeField, search, 'items.0.reference_id': reference, 'items.0.description': description,
      'items.0.unit_price': unitPrice, 'items.0.quantity': quantity,
    },
    extra: [price],
    submitText: 'Ajouter',
    submit: async () => {
      const type = selectedType();
      const body = { item_type: type, quantity: Number(quantity.input.value || 1) };
      if (type === 'OTHER') {
        body.description = description.input.value.trim();
        body.unit_price = unitPrice.input.value === '' ? null : Number(unitPrice.input.value);
      } else {
        if (!reference.input.value) {
          throw new ApiError(422, 'VALIDATION_ERROR', 'Choisissez un élément.', { 'items.0.reference_id': ['Choisissez un élément du référentiel.'] });
        }
        body.reference_id = reference.input.value;
      }
      await api.post(`invoices/${invoice.id}/items`, body);
      toast('Ligne ajoutée.');
      onDone();
    },
  });
}
