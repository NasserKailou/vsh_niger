/**
 * Administration des équipes (mobiles pour le domicile, ou de service) : création, modification,
 * membres avec rôle et période d'appartenance datée. Une appartenance se termine (date de fin), elle
 * ne s'efface pas : l'historique des visites reste attribuable.
 */
import { h, mount } from '../../core/dom.js';
import { icon } from '../../core/icons.js';
import { api, ApiError } from '../../core/api.js';
import { formatDay } from '../../core/format.js';
import { pageHead, card, table, emptyState, errorState, skeleton, loadingRegion, button, field, checkbox, toast } from '../../ui.js';
import { confirmAction, formDialog } from '../../dialogs.js';

const TEAM_ROLES = [
  ['CHEF', 'Chef d’équipe'], ['MEDECIN', 'Médecin'], ['INFIRMIER', 'Infirmier(ère)'], ['SAGE_FEMME', 'Sage-femme'],
  ['TECHNICIEN', 'Technicien(ne)'], ['CHAUFFEUR', 'Chauffeur'], ['AUTRE', 'Autre'],
];
const ROLE_LABELS = Object.fromEntries(TEAM_ROLES);

/** Jour local de l'établissement (AAAA-MM-JJ). */
function localDay(date = new Date()) {
  return new Intl.DateTimeFormat('fr-CA', { timeZone: 'Africa/Niamey', year: 'numeric', month: '2-digit', day: '2-digit' }).format(date);
}

export async function adminTeamsView({ main, setTitle, isCurrent }) {
  setTitle('Équipes');
  const list = h('div', { class: 'stack' }, loadingRegion(skeleton('block')));
  const detail = h('div', { class: 'stack' });
  let selected = null;
  mount(main,
    pageHead({
      title: 'Équipes',
      subtitle: 'Équipes mobiles (visites à domicile) et équipes de service. Les membres actuels reçoivent les visites de leur équipe.',
      actions: [button({ text: 'Nouvelle équipe', iconName: 'users', onClick: () => teamDialog(null, load) })],
    }),
    h('div', { class: 'workspace' }, list, detail));

  async function load() {
    try {
      const { data } = await api.get('teams');
      if (!isCurrent()) return;
      mount(list, card('Équipes', data.length
        ? table({
          caption: 'Équipes',
          columns: [
            { label: 'Équipe', render: (t) => h('div', {}, h('strong', {}, t.label), h('div', { class: 'vsh-muted mono' }, t.code)) },
            {
              label: 'Type',
              render: (t) => h('span', { class: `vsh-badge vsh-badge--${t.is_mobile ? 'info' : 'neutral'}` }, t.is_mobile ? 'Mobile' : 'Service'),
            },
            {
              label: 'État',
              render: (t) => h('span', { class: `vsh-badge vsh-badge--${t.active ? 'success' : 'neutral'}` }, t.active ? 'Active' : 'Inactive'),
            },
            {
              label: 'Détail',
              render: (t) => button({
                text: 'Ouvrir', variant: 'secondary', size: 'sm',
                onClick: () => {
                  selected = t.id;
                  openTeam();
                },
              }),
            },
          ],
          rows: data,
        })
        : emptyState({ iconName: 'users', title: 'Aucune équipe', text: 'Créez une équipe mobile pour les visites à domicile.' })));
      if (selected) openTeam();
    } catch (error) {
      if (isCurrent()) mount(list, errorState(error, load));
    }
  }

  async function openTeam() {
    mount(detail, loadingRegion(skeleton('block')));
    let team;
    try {
      team = (await api.get(`teams/${selected}`)).data;
    } catch (error) {
      if (isCurrent()) mount(detail, errorState(error, openTeam));
      return;
    }
    if (!isCurrent()) return;
    const current = team.members.filter((m) => m.is_current);
    const past = team.members.filter((m) => !m.is_current);
    const memberRow = (m) => h('li', { class: 'stack stack--sm' },
      h('div', { class: 'row' }, h('strong', {}, m.user.name), h('span', { class: 'vsh-badge vsh-badge--neutral' }, ROLE_LABELS[m.team_role] || m.team_role)),
      h('small', { class: 'vsh-muted' }, `Depuis le ${formatDay(m.from_date)}${m.to_date ? ` · jusqu’au ${formatDay(m.to_date)}` : ''}`),
      m.is_current && !m.to_date ? h('div', {}, button({
        text: 'Retirer de l’équipe', variant: 'ghost', size: 'sm',
        onClick: () => confirmAction({
          title: `Retirer ${m.user.name} de l’équipe ?`, confirmText: 'Retirer',
          text: 'L’appartenance se termine aujourd’hui ; l’historique des visites reste attribué.',
          run: async () => {
            await api.put(`teams/${team.id}/members/${m.id}`, { to_date: localDay() });
            toast('Membre retiré de l’équipe.');
            openTeam();
          },
        }),
      })) : null);
    mount(detail,
      card(team.label, h('div', { class: 'stack' },
        h('dl', { class: 'dl' },
          h('div', {}, h('dt', {}, 'Code'), h('dd', { class: 'mono' }, team.code)),
          h('div', {}, h('dt', {}, 'Type'), h('dd', {}, team.is_mobile ? 'Équipe mobile' : 'Équipe de service')),
          h('div', {}, h('dt', {}, 'État'), h('dd', {}, team.active ? 'Active' : 'Inactive')),
          team.description ? h('div', {}, h('dt', {}, 'Description'), h('dd', {}, team.description)) : null),
        h('h3', { class: 'section-title' }, `Membres actuels (${current.length})`),
        current.length ? h('ul', { class: 'plain-list' }, current.map(memberRow)) : h('p', { class: 'vsh-muted' }, 'Aucun membre actuel.'),
        past.length ? h('details', {}, h('summary', {}, `Anciens membres (${past.length})`), h('ul', { class: 'plain-list' }, past.map(memberRow))) : null), {
        actions: h('div', { class: 'row' },
          button({ text: 'Ajouter un membre', iconName: 'userPlus', variant: 'ghost', onClick: () => memberDialog(team, openTeam) }),
          button({ text: 'Modifier', variant: 'ghost', onClick: () => teamDialog(team, load) })),
      }));
  }

  load();
}

function teamDialog(team, onDone) {
  const creating = team === null;
  const code = field({ label: 'Code', name: 'code', required: true, value: team ? team.code : '', hint: 'Ex. EQM-NORD. Majuscules, chiffres, tirets.', attrs: { maxlength: '30' } });
  const label = field({ label: 'Nom', name: 'label', required: true, value: team ? team.label : '', attrs: { maxlength: '150' } });
  const description = field({ label: 'Description (facultatif)', name: 'description', value: team && team.description ? team.description : '', attrs: { maxlength: '500' } });
  const mobile = checkbox({ label: 'Équipe mobile (visites à domicile)', name: 'is_mobile', checked: team ? team.is_mobile : true });
  const active = checkbox({ label: 'Équipe active', name: 'active', checked: team ? team.active : true, hint: 'Une équipe inactive ne reçoit plus de visites.' });
  formDialog({
    title: creating ? 'Nouvelle équipe' : `Modifier ${team.label}`,
    fields: creating ? { code, label, description } : { label, description },
    extra: creating ? [mobile] : [mobile, active],
    submitText: creating ? 'Créer l’équipe' : 'Enregistrer',
    submit: async () => {
      const body = { label: label.input.value.trim(), description: description.input.value.trim() || null, is_mobile: mobile.input.checked };
      if (creating) await api.post('teams', { ...body, code: code.input.value.trim().toUpperCase() });
      else await api.put(`teams/${team.id}`, { ...body, active: active.input.checked });
      toast(creating ? 'Équipe créée.' : 'Équipe mise à jour.');
      onDone();
    },
  });
}

function memberDialog(team, onDone) {
  const person = field({ label: 'Membre du personnel', name: 'user_id', required: true, options: [['', 'Chargement…']] });
  const role = field({ label: 'Rôle dans l’équipe', name: 'team_role', required: true, options: TEAM_ROLES });
  const from = field({ label: 'À partir du', name: 'from_date', type: 'date', value: localDay(), required: true });
  const members = new Set(team.members.filter((m) => m.is_current).map((m) => m.user.id));
  api.get('staff/directory').then(({ data }) => {
    const available = data.filter((p) => !members.has(p.id));
    person.input.replaceChildren(h('option', { value: '' }, available.length ? 'Choisir' : 'Tout le personnel est déjà membre'),
      ...available.map((p) => h('option', { value: p.id }, `${p.name}${p.speciality ? ` (${p.speciality})` : ''}`)));
  }).catch(() => person.input.replaceChildren(h('option', { value: '' }, 'Liste indisponible')));
  formDialog({
    title: `Ajouter un membre à ${team.label}`,
    fields: { user_id: person, team_role: role, from_date: from },
    extra: [h('p', { class: 'audit-note' }, icon('info'), 'Une personne ne peut pas avoir deux appartenances qui se chevauchent dans la même équipe.')],
    submitText: 'Ajouter',
    submit: async () => {
      if (!person.input.value) throw new ApiError(422, 'VALIDATION_ERROR', 'Choisissez une personne.', { user_id: ['Choisissez une personne.'] });
      await api.post(`teams/${team.id}/members`, { user_id: person.input.value, team_role: role.input.value, from_date: from.input.value });
      toast('Membre ajouté.');
      onDone();
    },
  });
}
