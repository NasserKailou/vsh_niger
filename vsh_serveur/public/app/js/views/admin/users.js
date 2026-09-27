/**
 * Administration du personnel : comptes (création, modification, suspension, réinitialisation du mot
 * de passe) et rôles (permissions groupées par module). Les mots de passe temporaires ne sont affichés
 * qu'une fois et ne sont jamais conservés par l'interface.
 */
import { h, mount, uid } from '../../core/dom.js';
import { icon } from '../../core/icons.js';
import { api, ApiError } from '../../core/api.js';
import { session } from '../../core/session.js';
import { setQuery } from '../../core/router.js';
import { formatDateTime, formatPhone } from '../../core/format.js';
import {
  pageHead, card, table, tabs, emptyState, errorState, skeleton, loadingRegion, button, field, checkbox, toast, modal,
} from '../../ui.js';
import { confirmAction, formDialog } from '../../dialogs.js';

export const PROFESSIONS = [
  ['MEDECIN', 'Médecin'], ['INFIRMIER', 'Infirmier(ère)'], ['SAGE_FEMME', 'Sage-femme'],
  ['TECHNICIEN', 'Technicien(ne)'], ['ADMINISTRATIF', 'Administratif'], ['AUTRE', 'Autre'],
];
const USER_STATUS = {
  ACTIVE: ['success', 'Actif'], SUSPENDED: ['danger', 'Suspendu'], PENDING: ['warning', 'En attente'], REJECTED: ['neutral', 'Rejeté'],
};
const MODULE_LABELS = {
  users: 'Utilisateurs et rôles', settings: 'Paramètres', audit: 'Audit', sync: 'Synchronisation', reference: 'Référentiels et tarifs',
  patients: 'Patients', consultations: 'Consultations', treatments: 'Soins', examinations: 'Examens', prescriptions: 'Ordonnances',
  teams: 'Équipes', homecare: 'Visites à domicile', billing: 'Facturation', appointments: 'Rendez-vous', dashboard: 'Tableau de bord',
  self: 'Espace patient',
};

function statusBadge(status) {
  const [tone, label] = USER_STATUS[status] || ['neutral', status];
  return h('span', { class: `vsh-badge vsh-badge--${tone}` }, label);
}

/** Mot de passe temporaire : affiché une seule fois, à remettre en personne. */
function showTemporaryPassword(name, password) {
  const copy = button({
    text: 'Copier', iconName: 'copy', variant: 'secondary',
    onClick: async () => {
      try {
        await navigator.clipboard.writeText(password);
        toast('Mot de passe copié.');
      } catch (e) {
        toast('Copie impossible : recopiez-le à la main.', { type: 'error' });
      }
    },
  });
  const box = modal({
    title: 'Mot de passe temporaire',
    description: `Pour ${name}. Il ne sera plus affiché : communiquez-le en personne, jamais par SMS ni messagerie.`,
    size: 'sm',
    content: h('div', { class: 'stack' },
      h('p', { class: 'secret-value mono', tabindex: '0', 'aria-label': 'Mot de passe temporaire' }, password),
      h('p', { class: 'audit-note' }, icon('lock'), 'L’utilisateur devra le changer à sa première connexion.')),
  });
  box.footer.append(copy, button({ text: 'J’ai noté le mot de passe', onClick: () => box.close() }));
}

// ---------------------------------------------------------------- Vue

export async function adminUsersView({ main, setTitle, query, isCurrent }) {
  setTitle('Utilisateurs et rôles');
  const defs = [];
  if (session.can('users.read')) defs.push({ id: 'staff', label: 'Personnel' });
  if (session.can('roles.manage')) defs.push({ id: 'roles', label: 'Rôles et permissions' });
  const initial = defs.some((d) => d.id === query.tab) ? query.tab : defs[0].id;
  const tabset = tabs(defs, initial, (id, panel) => {
    setQuery({ tab: id === defs[0].id ? null : id });
    if (id === 'staff') staffPanel(panel, isCurrent);
    else rolesPanel(panel, isCurrent);
  });
  mount(main,
    pageHead({ title: 'Utilisateurs et rôles', subtitle: 'Comptes du personnel, rôles et permissions. Les comptes patients naissent de l’inscription ou de l’accueil.' }),
    tabset.el);
  tabset.select(initial, false);
}

// ---------------------------------------------------------------- Personnel

async function staffPanel(panel, isCurrent) {
  const search = field({ label: 'Rechercher', name: 'search', placeholder: 'Nom ou téléphone', autocomplete: 'off' });
  const status = field({ label: 'Statut', name: 'status', options: [['', 'Tous'], ['ACTIVE', 'Actifs'], ['SUSPENDED', 'Suspendus']] });
  const role = field({ label: 'Rôle', name: 'role', options: [['', 'Tous']] });
  const results = h('div', { class: 'stack' });
  let roles = [];
  const filters = h('form', { class: 'toolbar', role: 'search', 'aria-label': 'Filtrer le personnel' },
    search.el, status.el, role.el, button({ text: 'Filtrer', iconName: 'search', variant: 'secondary', type: 'submit' }));
  filters.addEventListener('submit', (event) => {
    event.preventDefault();
    load();
  });
  const actions = session.can('users.manage')
    ? h('div', { class: 'row' }, button({ text: 'Nouveau membre du personnel', iconName: 'userPlus', onClick: () => userDialog(null, roles, load) }))
    : null;
  mount(panel, h('div', { class: 'stack' }, actions, filters, results));

  try {
    roles = (await api.get('roles')).data.filter((r) => r.code !== 'PATIENT');
    role.input.replaceChildren(h('option', { value: '' }, 'Tous'), ...roles.map((r) => h('option', { value: r.code }, r.label)));
  } catch (e) {
    role.el.hidden = true;
  }
  const roleLabels = Object.fromEntries(roles.map((r) => [r.code, r.label]));

  async function load() {
    mount(results, loadingRegion(skeleton('block')));
    try {
      const { data, meta } = await api.get('users', {
        per_page: 100, search: search.input.value.trim() || null, status: status.input.value || null, role: role.input.value || null,
      });
      if (!isCurrent()) return;
      if (!data.length) {
        mount(results, emptyState({ iconName: 'users', title: 'Aucun compte', text: 'Aucun membre du personnel ne correspond à ces critères.' }));
        return;
      }
      mount(results, table({
        caption: 'Personnel',
        columns: [
          {
            label: 'Nom',
            render: (u) => h('div', {}, h('strong', {}, `${u.last_name.toUpperCase()} ${u.first_name}`), h('div', { class: 'vsh-muted' }, formatPhone(u.phone))),
          },
          { label: 'Rôles', render: (u) => h('div', { class: 'row' }, u.roles.map((code) => h('span', { class: 'vsh-badge vsh-badge--neutral' }, roleLabels[code] || code))) },
          {
            label: 'Statut',
            render: (u) => h('div', { class: 'stack stack--sm' }, statusBadge(u.status),
              u.must_change_password ? h('small', { class: 'vsh-muted' }, 'Mot de passe à changer') : null,
              u.locked_until ? h('small', { class: 'vsh-muted' }, 'Verrouillé temporairement') : null),
          },
          { label: 'Dernière connexion', render: (u) => (u.last_login_at ? formatDateTime(u.last_login_at) : h('span', { class: 'vsh-muted' }, 'Jamais')) },
          { label: 'Actions', render: (u) => userActions(u) },
        ],
        rows: data,
        foot: meta && meta.total > data.length ? `${data.length} comptes affichés sur ${meta.total}. Affinez la recherche.` : null,
      }));
    } catch (error) {
      if (isCurrent()) mount(results, errorState(error, load));
    }
  }

  function userActions(u) {
    if (!session.can('users.manage')) return '—';
    const self = Boolean(session.user && session.user.id === u.id);
    const name = `${u.first_name} ${u.last_name}`;
    return h('div', { class: 'row-actions' },
      button({
        text: 'Modifier', variant: 'secondary', size: 'sm',
        onClick: async () => {
          try {
            const { data } = await api.get(`users/${u.id}`);
            userDialog(data, roles, load);
          } catch (error) {
            toast(error.message, { type: 'error' });
          }
        },
      }),
      u.status === 'ACTIVE' && !self ? button({
        text: 'Suspendre', variant: 'ghost', size: 'sm',
        onClick: () => confirmAction({
          title: `Suspendre ${name} ?`, confirmText: 'Suspendre',
          text: 'Le compte ne peut plus se connecter et ses sessions sont fermées. Ses données et son historique sont conservés.',
          run: async () => {
            await api.post(`users/${u.id}/suspend`);
            toast('Compte suspendu.');
            load();
          },
        }),
      }) : null,
      u.status === 'SUSPENDED' ? button({
        text: 'Réactiver', variant: 'ghost', size: 'sm',
        onClick: () => confirmAction({
          title: `Réactiver ${name} ?`, confirmText: 'Réactiver', danger: false, text: 'Le compte pourra de nouveau se connecter.',
          run: async () => {
            await api.post(`users/${u.id}/activate`);
            toast('Compte réactivé.');
            load();
          },
        }),
      }) : null,
      !self ? button({
        text: 'Mot de passe', iconName: 'lock', variant: 'ghost', size: 'sm',
        onClick: () => confirmAction({
          title: `Réinitialiser le mot de passe de ${name} ?`, confirmText: 'Réinitialiser',
          text: 'Un mot de passe temporaire est généré ; les sessions en cours sont fermées.',
          run: async () => {
            const { data } = await api.post(`users/${u.id}/reset-password`);
            showTemporaryPassword(name, data.temporary_password);
            load();
          },
        }),
      }) : null);
  }

  load();
}

function userDialog(user, roles, onDone) {
  const creating = user === null;
  const profile = (user && user.staff_profile) || {};
  const f = {
    first_name: field({ label: 'Prénom', name: 'first_name', required: true, value: user ? user.first_name : '', autocomplete: 'off' }),
    last_name: field({ label: 'Nom', name: 'last_name', required: true, value: user ? user.last_name : '', autocomplete: 'off' }),
    phone: field({ label: 'Téléphone', name: 'phone', type: 'tel', inputmode: 'tel', required: true, value: user ? user.phone : '', hint: 'Identifiant de connexion.' }),
    email: field({ label: 'E-mail (facultatif)', name: 'email', type: 'email', value: user && user.email ? user.email : '' }),
    profession: field({ label: 'Profession', name: 'profession', required: true, options: [['', 'Choisir'], ...PROFESSIONS], value: profile.profession || '' }),
    speciality: field({ label: 'Spécialité (facultatif)', name: 'speciality', value: profile.speciality || '' }),
    license_number: field({ label: 'N° d’inscription à l’ordre (facultatif)', name: 'license_number', value: profile.license_number || '', hint: 'Imprimé sur les ordonnances.' }),
  };
  const groupId = uid('roles');
  const boxes = roles.map((r) => ({ code: r.code, box: checkbox({ label: r.label, name: 'roles', checked: Boolean(user && user.roles.includes(r.code)), hint: r.description || null }) }));
  const rolesError = h('p', { class: 'vsh-field__error', hidden: true });
  const rolesField = {
    id: groupId,
    el: h('fieldset', { class: 'vsh-field', id: groupId, tabindex: '-1' },
      h('legend', { class: 'vsh-field__label' }, 'Rôles'),
      h('div', { class: 'check-grid' }, boxes.map((b) => b.box.el)),
      rolesError),
    setError(message) {
      rolesError.hidden = !message;
      rolesError.replaceChildren(...(message ? [icon('alert', { size: 16 }), message] : []));
    },
  };
  formDialog({
    title: creating ? 'Nouveau membre du personnel' : `Modifier ${user.first_name} ${user.last_name}`,
    description: creating ? 'Un mot de passe temporaire sera affiché une seule fois, à remettre en personne.' : null,
    size: 'lg',
    fields: { ...f, roles: rolesField },
    submitText: creating ? 'Créer le compte' : 'Enregistrer',
    submit: async () => {
      const body = Object.fromEntries(Object.entries(f).map(([key, item]) => [key, item.input.value.trim() || null]));
      body.roles = boxes.filter((b) => b.box.input.checked).map((b) => b.code);
      if (!body.roles.length) throw new ApiError(422, 'VALIDATION_ERROR', 'Choisissez au moins un rôle.', { roles: ['Choisissez au moins un rôle.'] });
      if (creating) {
        const { data } = await api.post('users', body);
        toast('Compte créé.');
        showTemporaryPassword(`${data.user.first_name} ${data.user.last_name}`, data.temporary_password);
      } else {
        await api.put(`users/${user.id}`, body);
        toast('Compte mis à jour.');
      }
      onDone();
    },
  });
}

// ---------------------------------------------------------------- Rôles

async function rolesPanel(panel, isCurrent) {
  mount(panel, loadingRegion(skeleton('block')));
  let roles;
  let permissions;
  try {
    [roles, permissions] = await Promise.all([api.get('roles').then((r) => r.data), api.get('permissions').then((r) => r.data)]);
  } catch (error) {
    if (isCurrent()) mount(panel, errorState(error, () => rolesPanel(panel, isCurrent)));
    return;
  }
  if (!isCurrent()) return;
  const reload = () => rolesPanel(panel, isCurrent);
  const labels = {};
  Object.values(permissions).forEach((list) => list.forEach((p) => { labels[p.code] = p.label; }));
  mount(panel, h('div', { class: 'stack' },
    h('div', { class: 'row' }, button({ text: 'Nouveau rôle', iconName: 'shield', onClick: () => roleDialog(null, permissions, reload) })),
    h('p', { class: 'audit-note' }, icon('info'), 'Les rôles système peuvent être ajustés mais pas supprimés. Toute modification est tracée dans le journal d’audit.'),
    h('div', { class: 'cards-2' }, roles.map((r) => card(r.label, h('div', { class: 'stack stack--sm' },
      h('div', { class: 'row' },
        h('span', { class: 'mono vsh-muted' }, r.code),
        r.is_system ? h('span', { class: 'vsh-badge vsh-badge--info' }, 'Système') : null,
        h('span', { class: 'vsh-badge vsh-badge--neutral' }, `${r.user_count} compte(s)`)),
      r.description ? h('p', { class: 'vsh-muted' }, r.description) : null,
      h('details', { class: 'perm-details' }, h('summary', {}, `${r.permissions.length} permission(s)`),
        h('ul', { class: 'perm-list' }, r.permissions.map((code) => h('li', {}, labels[code] || code))))), {
      actions: h('div', { class: 'row' },
        button({ text: 'Modifier', variant: 'ghost', size: 'sm', onClick: () => roleDialog(r, permissions, reload) }),
        !r.is_system && r.user_count === 0 ? button({
          text: 'Supprimer', variant: 'ghost', size: 'sm',
          onClick: () => confirmAction({
            title: `Supprimer le rôle ${r.label} ?`, confirmText: 'Supprimer', text: 'Aucun compte ne l’utilise.',
            run: async () => {
              await api.del(`roles/${encodeURIComponent(r.code)}`);
              toast('Rôle supprimé.');
              reload();
            },
          }),
        }) : null),
    })))));
}

function roleDialog(role, permissions, onDone) {
  const creating = role === null;
  const code = field({ label: 'Code', name: 'code', required: true, value: role ? role.code : '', hint: 'Majuscules, chiffres et _ (ex. SECRETAIRE). Non modifiable ensuite.', attrs: { maxlength: '50' } });
  const label = field({ label: 'Libellé', name: 'label', required: true, value: role ? role.label : '', attrs: { maxlength: '100' } });
  const description = field({ label: 'Description (facultatif)', name: 'description', value: role && role.description ? role.description : '', attrs: { maxlength: '255' } });
  const current = new Set(role ? role.permissions : []);
  const boxes = [];
  const groups = Object.entries(permissions).map(([module, list]) => h('fieldset', { class: 'perm-group' },
    h('legend', {}, MODULE_LABELS[module] || module),
    list.map((p) => {
      const box = checkbox({ label: p.label, name: 'permissions', checked: current.has(p.code), hint: p.code });
      boxes.push({ code: p.code, box });
      return box.el;
    })));
  const permError = h('p', { class: 'vsh-field__error', hidden: true });
  const permField = {
    id: uid('perms'),
    el: h('div', { class: 'stack' }, h('p', { class: 'vsh-field__label' }, 'Permissions'), h('div', { class: 'perm-grid' }, groups), permError),
    setError(message) {
      permError.hidden = !message;
      permError.replaceChildren(...(message ? [icon('alert', { size: 16 }), message] : []));
    },
  };
  formDialog({
    title: creating ? 'Nouveau rôle' : `Rôle ${role.label}`,
    description: 'Les droits sont revérifiés par le serveur à chaque requête. Les permissions « Espace patient » sont réservées au rôle patient.',
    size: 'lg',
    fields: creating ? { code, label, description, permissions: permField } : { label, description, permissions: permField },
    submitText: creating ? 'Créer le rôle' : 'Enregistrer',
    submit: async () => {
      const body = {
        label: label.input.value.trim(),
        description: description.input.value.trim() || null,
        permissions: boxes.filter((b) => b.box.input.checked).map((b) => b.code),
      };
      if (creating) await api.post('roles', { code: code.input.value.trim().toUpperCase(), ...body });
      else await api.put(`roles/${encodeURIComponent(role.code)}`, body);
      toast(creating ? 'Rôle créé.' : 'Rôle mis à jour.');
      onDone();
    },
  });
}
