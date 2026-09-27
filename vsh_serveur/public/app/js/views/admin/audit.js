/**
 * Traçabilité : journal d'audit en lecture seule (qui, quoi, quand, depuis où, valeurs modifiées) et
 * supervision de la synchronisation hors ligne (appareils, opérations rejetées ou en conflit,
 * révocation d'un appareil perdu). Aucune entrée du journal ne peut être modifiée ou supprimée.
 */
import { h, mount } from '../../core/dom.js';
import { icon } from '../../core/icons.js';
import { api } from '../../core/api.js';
import { session } from '../../core/session.js';
import { setQuery } from '../../core/router.js';
import { formatDateTime } from '../../core/format.js';
import { pageHead, table, tabs, emptyState, errorState, skeleton, loadingRegion, button, field, toast } from '../../ui.js';
import { confirmAction } from '../../dialogs.js';

const PAGE_SIZE = 50;
const OP_STATUS = { APPLIED: ['success', 'Appliquée'], CONFLICT: ['warning', 'Conflit'], REJECTED: ['danger', 'Rejetée'] };

/** « PATIENT_DATA_UPDATED » → « Patient data updated » : lisible sans table de traduction exhaustive. */
function humanize(code) {
  const text = String(code || '').toLowerCase().replace(/_/g, ' ');
  return text.charAt(0).toUpperCase() + text.slice(1);
}

/** Valeurs JSON stockées sous forme de texte (paramètres) décodées pour la lecture. */
function readable(data) {
  return Object.fromEntries(Object.entries(data).map(([key, value]) => {
    if (typeof value === 'string' && /^[[{]/.test(value)) {
      try {
        return [key, JSON.parse(value)];
      } catch (e) {
        return [key, value];
      }
    }
    return [key, value];
  }));
}

function values(title, data) {
  if (!data || !Object.keys(data).length) return null;
  return h('div', { class: 'stack stack--sm' }, h('strong', {}, title), h('pre', { class: 'json-block' }, JSON.stringify(readable(data), null, 2)));
}

export async function adminAuditView({ main, setTitle, query, isCurrent }) {
  setTitle('Journal d’audit');
  const defs = [];
  if (session.can('audit.read')) defs.push({ id: 'audit', label: 'Journal d’audit' });
  if (session.can('sync.supervise')) defs.push({ id: 'sync', label: 'Synchronisation' });
  const initial = defs.some((d) => d.id === query.tab) ? query.tab : defs[0].id;
  const tabset = tabs(defs, initial, (id, panel) => {
    setQuery({ tab: id === defs[0].id ? null : id });
    if (id === 'audit') auditPanel(panel, isCurrent);
    else syncPanel(panel, isCurrent);
  });
  mount(main,
    pageHead({ title: 'Journal d’audit', subtitle: 'Les actions sensibles sont tracées : consultation de dossiers médicaux, modifications, exports, connexions. Le journal est en lecture seule.' }),
    tabset.el);
  tabset.select(initial, false);
}

// ---------------------------------------------------------------- Journal

async function auditPanel(panel, isCurrent) {
  const action = field({ label: 'Action', name: 'action', options: [['', 'Toutes']] });
  const entityType = field({ label: 'Type d’objet', name: 'entity_type', options: [['', 'Tous']] });
  const entityId = field({ label: 'Identifiant de l’objet', name: 'entity_id', placeholder: 'UUID', autocomplete: 'off' });
  const from = field({ label: 'Du', name: 'from', type: 'date' });
  const to = field({ label: 'Au', name: 'to', type: 'date' });
  const results = h('div', { class: 'stack' });
  const more = button({ text: 'Voir plus', variant: 'secondary', onClick: () => load(true) });
  more.hidden = true;
  const filters = h('form', { class: 'toolbar', role: 'search', 'aria-label': 'Filtrer le journal' },
    action.el, entityType.el, entityId.el, from.el, to.el, button({ text: 'Filtrer', iconName: 'search', variant: 'secondary', type: 'submit' }));
  filters.addEventListener('submit', (event) => {
    event.preventDefault();
    load(false);
  });
  mount(panel, h('div', { class: 'stack' }, filters, results, h('div', {}, more)));

  try {
    const { data } = await api.get('audit-logs/facets');
    action.input.replaceChildren(h('option', { value: '' }, 'Toutes'), ...data.actions.map((a) => h('option', { value: a }, humanize(a))));
    entityType.input.replaceChildren(h('option', { value: '' }, 'Tous'), ...data.entity_types.map((t) => h('option', { value: t }, humanize(t))));
  } catch (e) {
    /* filtres libres seulement */
  }

  let page = 1;
  let rows = [];
  async function load(append) {
    page = append ? page + 1 : 1;
    if (!append) mount(results, loadingRegion(skeleton('block')));
    try {
      const { data, meta } = await api.get('audit-logs', {
        page, per_page: PAGE_SIZE,
        action: action.input.value || null, entity_type: entityType.input.value || null,
        entity_id: entityId.input.value.trim() || null, from: from.input.value || null, to: to.input.value || null,
      });
      if (!isCurrent()) return;
      rows = append ? rows.concat(data) : data;
      more.hidden = !(meta && meta.total > rows.length);
      if (!rows.length) {
        mount(results, emptyState({ iconName: 'shield', title: 'Aucune entrée', text: 'Aucune action ne correspond à ces critères.' }));
        return;
      }
      mount(results, table({
        caption: 'Journal d’audit',
        columns: [
          { label: 'Date', render: (e) => h('span', { class: 'nowrap' }, formatDateTime(e.created_at)) },
          {
            label: 'Auteur',
            render: (e) => (e.user
              ? h('div', {}, e.user.name, e.user.is_patient ? h('div', { class: 'vsh-muted' }, 'Compte patient') : null)
              : h('span', { class: 'vsh-muted' }, 'Système')),
          },
          { label: 'Action', render: (e) => h('div', {}, h('strong', {}, humanize(e.action)), h('div', { class: 'vsh-muted mono' }, e.action)) },
          {
            label: 'Objet',
            render: (e) => (e.entity_type ? h('div', {}, humanize(e.entity_type), e.entity_id ? h('div', {}, h('button', {
              type: 'button', class: 'link-button mono', title: 'Filtrer sur cet objet',
              onclick: () => {
                entityId.input.value = e.entity_id;
                load(false);
              },
            }, e.entity_id.slice(0, 8))) : null) : '—'),
          },
          {
            label: 'Détail',
            render: (e) => {
              const content = [values('Avant', e.old_values), values('Après', e.new_values)].filter(Boolean);
              const tech = [e.ip_address ? `IP ${e.ip_address}` : null, e.device ? `appareil ${e.device.name || e.device.id.slice(0, 8)}` : null, e.request_id ? `requête ${e.request_id.slice(0, 8)}` : null].filter(Boolean);
              return h('details', { class: 'audit-details' },
                h('summary', {}, 'Voir'),
                h('div', { class: 'stack stack--sm' },
                  h('small', { class: 'vsh-muted' }, tech.length ? tech.join(' · ') : 'Aucune information technique'),
                  content.length ? content : h('small', { class: 'vsh-muted' }, 'Pas de valeur enregistrée pour cette action.')));
            },
          },
        ],
        rows,
        foot: meta ? `${rows.length} entrée(s) affichée(s) sur ${meta.total}.` : null,
      }));
    } catch (error) {
      if (isCurrent()) mount(results, errorState(error, () => load(false)));
    }
  }
  load(false);
}

// ---------------------------------------------------------------- Synchronisation

function syncPanel(panel, isCurrent) {
  const devicesBox = h('div', { class: 'stack' }, loadingRegion(skeleton('block')));
  const status = field({ label: 'Opérations', name: 'status', options: [['REJECTED', 'Rejetées'], ['CONFLICT', 'En conflit'], ['APPLIED', 'Appliquées']] });
  const opsBox = h('div', { class: 'stack' });
  status.input.addEventListener('change', loadOperations);
  mount(panel, h('div', { class: 'stack' },
    h('p', { class: 'audit-note' }, icon('info'), 'Un appareil perdu ou volé doit être révoqué : il ne pourra plus synchroniser et ses sessions sont fermées.'),
    h('h2', { class: 'section-title' }, 'Appareils'), devicesBox,
    h('h2', { class: 'section-title' }, 'Opérations reçues'), h('div', { class: 'toolbar' }, status.el), opsBox));

  async function loadDevices() {
    try {
      const { data } = await api.get('sync/devices', { per_page: 100 });
      if (!isCurrent()) return;
      mount(devicesBox, data.length
        ? table({
          caption: 'Appareils',
          columns: [
            { label: 'Utilisateur', render: (d) => d.user.name },
            { label: 'Appareil', render: (d) => h('div', {}, d.device_name || 'Sans nom', h('div', { class: 'vsh-muted' }, [d.platform, d.app_version].filter(Boolean).join(' · '))) },
            { label: 'Dernière synchro', render: (d) => (d.last_sync_at ? formatDateTime(d.last_sync_at) : h('span', { class: 'vsh-muted' }, 'Jamais')) },
            {
              label: 'Anomalies',
              render: (d) => h('div', { class: 'row' },
                d.rejected_operations ? h('span', { class: 'vsh-badge vsh-badge--danger' }, `${d.rejected_operations} rejet(s)`) : null,
                d.open_conflicts ? h('span', { class: 'vsh-badge vsh-badge--warning' }, `${d.open_conflicts} conflit(s)`) : null,
                !d.rejected_operations && !d.open_conflicts ? h('span', { class: 'vsh-muted' }, 'Aucune') : null),
            },
            {
              label: 'État',
              render: (d) => (d.revoked_at
                ? h('span', { class: 'vsh-badge vsh-badge--neutral' }, `Révoqué le ${formatDateTime(d.revoked_at)}`)
                : button({
                  text: 'Révoquer', variant: 'ghost', size: 'sm',
                  onClick: () => confirmAction({
                    title: 'Révoquer cet appareil ?', confirmText: 'Révoquer',
                    text: `${d.device_name || 'Appareil'} de ${d.user.name} ne pourra plus synchroniser. Les données non envoyées depuis cet appareil seront perdues.`,
                    run: async () => {
                      await api.post(`sync/devices/${d.id}/revoke`);
                      toast('Appareil révoqué.');
                      loadDevices();
                    },
                  }),
                })),
            },
          ],
          rows: data,
        })
        : emptyState({ iconName: 'activity', title: 'Aucun appareil', text: 'Les téléphones et tablettes apparaissent ici après leur première synchronisation.' }));
    } catch (error) {
      if (isCurrent()) mount(devicesBox, errorState(error, loadDevices));
    }
  }

  async function loadOperations() {
    mount(opsBox, loadingRegion(skeleton('block')));
    try {
      const { data } = await api.get('sync/operations', { status: status.input.value, per_page: 100 });
      if (!isCurrent()) return;
      const reason = (o) => {
        if (!o.error) return '—';
        if (typeof o.error === 'object') return o.error.message || o.error.code || '—';
        return String(o.error);
      };
      mount(opsBox, data.length
        ? table({
          caption: 'Opérations de synchronisation',
          columns: [
            { label: 'Reçue', render: (o) => h('span', { class: 'nowrap' }, formatDateTime(o.received_at)) },
            { label: 'Auteur', render: (o) => (o.user ? o.user.name : '—') },
            { label: 'Objet', render: (o) => h('div', {}, humanize(o.entity), h('div', { class: 'vsh-muted mono' }, `${o.operation} · ${String(o.entity_id).slice(0, 8)}`)) },
            {
              label: 'État',
              render: (o) => {
                const [tone, label] = OP_STATUS[o.status] || ['neutral', o.status];
                return h('span', { class: `vsh-badge vsh-badge--${tone}` }, label);
              },
            },
            { label: 'Motif', render: reason },
          ],
          rows: data,
        })
        : emptyState({ iconName: 'checkCircle', title: 'Aucune opération', text: 'Rien à signaler pour ce filtre.' }));
    } catch (error) {
      if (isCurrent()) mount(opsBox, errorState(error, loadOperations));
    }
  }

  loadDevices();
  loadOperations();
}
