/**
 * Fiche patient : en-tête d'identité, actions de l'accueil (modifier, valider ou rejeter une inscription,
 * médecin traitant, contacts, adresses, rendez-vous) et onglets (identité, dossier médical, consultations,
 * rendez-vous, factures). Chaque action et chaque onglet n'apparaissent qu'avec le droit correspondant ;
 * le dossier médical n'est renvoyé qu'aux personnes autorisées et sa consultation est tracée (audit).
 */
import { h, mount } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { api } from '../core/api.js';
import { session } from '../core/session.js';
import { setQuery } from '../core/router.js';
import { badge, formatDay, formatDate, formatDateTime, formatPhone, formatMoney, initials, SEX, CONSULTATION_TYPES } from '../core/format.js';
import { pageHead, card, table, tabs, emptyState, errorState, skeleton, loadingRegion, button, field, checkbox, askReason, iconButton, toast } from '../ui.js';
import { confirmAction, formDialog } from '../dialogs.js';
import { locationPicker } from '../locationPicker.js';
import { accuracyQuality, formatAccuracy, links } from '../geo.js';

const HISTORY_TYPES = { MEDICAL: 'Médical', SURGICAL: 'Chirurgical', FAMILY: 'Familial', OBSTETRIC: 'Obstétrical', OTHER: 'Autre' };

function dl(entries) {
  return h('dl', { class: 'dl' }, entries.map(([label, value]) => h('div', {}, h('dt', {}, label), h('dd', {}, value === null || value === undefined || value === '' ? '—' : value))));
}

function list(items, render, empty) {
  if (!items || !items.length) return h('p', { class: 'vsh-muted' }, empty);
  return h('ul', { class: 'item-list' }, items.map((item) => h('li', {}, render(item))));
}

/** Liste chargée à l'ouverture de l'onglet, avec squelette, erreur et état vide. */
function remoteTable(panel, isCurrent, loader, build, empty) {
  mount(panel, loadingRegion(skeleton('block')));
  (async function load() {
    try {
      const rows = await loader();
      if (!isCurrent()) return;
      mount(panel, rows.length ? build(rows) : emptyState(empty));
    } catch (error) {
      if (isCurrent()) mount(panel, errorState(error, load));
    }
  })();
}

export async function patientView(ctx) {
  const { main, setTitle, params, query, isCurrent } = ctx;
  setTitle('Dossier patient');
  mount(main, loadingRegion([skeleton('line'), skeleton('block')]));
  let patient;
  try {
    ({ data: patient } = await api.get(`patients/${encodeURIComponent(params.id)}`));
  } catch (error) {
    if (!isCurrent()) return;
    mount(main,
      pageHead({ title: 'Dossier patient', back: { href: '#/patients', label: 'Patients' } }),
      error.status === 404
        ? emptyState({ iconName: 'search', title: 'Dossier introuvable', text: 'Ce dossier n’existe pas ou a été fusionné avec un autre.' })
        : errorState(error, () => patientView(ctx)));
    return;
  }
  if (!isCurrent()) return;

  const items = [{ id: 'identity', label: 'Identité' }];
  if (patient.medical) items.push({ id: 'medical', label: 'Dossier médical' });
  if (session.can('consultations.read')) items.push({ id: 'consultations', label: 'Consultations' });
  if (session.can('appointments.manage')) items.push({ id: 'appointments', label: 'Rendez-vous' });
  if (session.can('invoices.read')) items.push({ id: 'invoices', label: 'Factures' });
  let currentTab = items.some((item) => item.id === query.tab) ? query.tab : 'identity';

  const reload = () => patientView({ ...ctx, query: { tab: currentTab } });
  const name = `${patient.last_name.toUpperCase()} ${patient.first_name}`;
  const open = !['MERGED', 'REJECTED'].includes(patient.status);
  const canEdit = open && session.can('patients.update');
  setTitle(name);

  // ---------------------------------------------------------------- Actions de l'en-tête
  const actions = [];
  if (patient.status === 'PENDING' && session.can('patients.validate_registration')) {
    actions.push(
      button({
        text: 'Valider l’inscription', iconName: 'userCheck', variant: 'primary',
        onClick: () => confirmAction({
          title: 'Valider cette inscription ?', danger: false, confirmText: 'Valider',
          text: 'Vérifiez l’identité du patient (pièce d’identité, téléphone). Le dossier deviendra actif.',
          run: async () => {
            await api.post(`patient-registrations/${patient.id}/approve`);
            toast('Inscription validée.');
            reload();
          },
        }),
      }),
      button({
        text: 'Rejeter', iconName: 'x', variant: 'secondary',
        onClick: async () => {
          const reason = await askReason({
            title: 'Rejeter cette inscription', label: 'Motif du rejet', confirmText: 'Rejeter',
            description: 'Le motif est conservé dans le dossier et le journal d’audit.',
            submit: (value) => api.post(`patient-registrations/${patient.id}/reject`, { reason: value }),
          });
          if (reason !== null) {
            toast('Inscription rejetée.');
            reload();
          }
        },
      }));
  }
  if (session.can('appointments.manage') && ['ACTIVE', 'PENDING'].includes(patient.status)) {
    actions.push(h('a', { class: 'vsh-btn vsh-btn--secondary', href: `#/appointments?new=1&patient=${patient.id}` }, icon('calendar'), 'Rendez-vous'));
  }
  if (canEdit) {
    actions.push(h('a', { class: 'vsh-btn vsh-btn--secondary', href: `#/patients/${patient.id}/edit` }, icon('fileText'), 'Modifier'));
  }

  const header = h('section', { class: 'vsh-card' },
    h('div', { class: 'patient-head' },
      h('span', { class: ['avatar', patient.sex === 'F' && 'avatar--f'], 'aria-hidden': 'true' }, initials(`${patient.first_name} ${patient.last_name}`)),
      h('div', {},
        h('div', { class: 'patient-head__name' }, h('h1', { tabindex: '-1', 'data-page-title': 'true' }, name), badge('patient', patient.status),
          patient.has_account ? h('span', { class: 'vsh-badge vsh-badge--info' }, 'Compte patient') : null),
        h('div', { class: 'patient-head__meta' },
          h('span', { class: 'mono' }, icon('fileText'), patient.file_number || 'Sans numéro'),
          h('span', {}, icon('user'), [SEX[patient.sex], patient.age_years !== null && patient.age_years !== undefined ? `${patient.age_years} ans` : null].filter(Boolean).join(' · ') || '—'),
          h('span', {}, icon('phone'), formatPhone(patient.phone)),
          patient.attending_physician ? h('span', {}, icon('stethoscope'), `Médecin traitant : ${patient.attending_physician.name}`) : null)),
      h('div', { class: 'row' }, actions)));

  const alerts = [];
  if (patient.status === 'PENDING') {
    alerts.push(h('div', { class: 'vsh-alert vsh-alert--warning', role: 'status' }, icon('alert', { size: 20 }),
      h('span', {}, 'Inscription en attente de validation : l’identité doit être vérifiée par l’accueil.')));
  }
  if (patient.status === 'REJECTED' && patient.rejection_reason) {
    alerts.push(h('div', { class: 'vsh-alert vsh-alert--danger', role: 'status' }, icon('alert', { size: 20 }), h('span', {}, `Inscription rejetée : ${patient.rejection_reason}`)));
  }
  if (patient.possible_duplicate_of) {
    alerts.push(h('div', { class: 'vsh-alert vsh-alert--warning', role: 'status' }, icon('alert', { size: 20 }),
      h('span', {}, 'Doublon possible : ce dossier ressemble à ', h('a', { href: `#/patients/${patient.possible_duplicate_of}` }, 'un autre dossier'), '. À vérifier par l’accueil.')));
  }
  if (patient.merged_into) {
    alerts.push(h('div', { class: 'vsh-alert vsh-alert--info', role: 'status' }, icon('info', { size: 20 }),
      h('span', {}, 'Ce dossier a été fusionné. ', h('a', { href: `#/patients/${patient.merged_into}` }, 'Ouvrir le dossier conservé'))));
  }

  const tabset = tabs(items, currentTab, (id, panel) => {
    currentTab = id;
    setQuery(id === 'identity' ? {} : { tab: id });
    renderTab(id, panel);
  });
  mount(main, h('a', { class: 'back-link', href: '#/patients' }, icon('chevronLeft'), 'Patients'), header, alerts, tabset.el);
  tabset.select(currentTab, false);

  // ---------------------------------------------------------------- Actions d'identité
  function changeAttending() {
    const select = field({ label: 'Médecin traitant', name: 'attending_physician_id', options: [['', 'Chargement…']] });
    formDialog({
      title: 'Médecin traitant',
      description: 'Il suit le patient et peut valider ses résultats d’examens (D-005).',
      fields: { attending_physician_id: select },
      submitText: 'Enregistrer',
      submit: async () => {
        await api.put(`patients/${patient.id}/attending-physician`, { attending_physician_id: select.input.value || null });
        toast('Médecin traitant mis à jour.');
        reload();
      },
    });
    api.get('staff/directory', { profession: 'MEDECIN' }).then(({ data }) => {
      const current = patient.attending_physician ? patient.attending_physician.id : '';
      select.input.replaceChildren(h('option', { value: '' }, 'Aucun'),
        ...data.map((doctor) => h('option', { value: doctor.id, selected: doctor.id === current }, doctor.speciality ? `${doctor.name} — ${doctor.speciality}` : doctor.name)));
    }).catch(() => select.input.replaceChildren(h('option', { value: '' }, 'Liste indisponible')));
  }

  function addContact() {
    const f = {
      full_name: field({ label: 'Nom complet', name: 'full_name', required: true, autocomplete: 'off' }),
      relationship: field({ label: 'Lien de parenté', name: 'relationship', hint: 'Ex. mère, époux, fils' }),
      phone: field({ label: 'Téléphone', name: 'phone', type: 'tel', inputmode: 'tel', required: true }),
    };
    const emergency = checkbox({ label: 'Contact d’urgence', name: 'is_emergency', checked: true });
    formDialog({
      title: 'Ajouter une personne à prévenir', fields: f, extra: [emergency], submitText: 'Ajouter',
      submit: async () => {
        await api.post(`patients/${patient.id}/contacts`, {
          full_name: f.full_name.input.value.trim(), relationship: f.relationship.input.value.trim() || null,
          phone: f.phone.input.value.trim(), is_emergency: emergency.input.checked,
        });
        toast('Personne à prévenir ajoutée.');
        reload();
      },
    });
  }

  function addAddress() {
    const f = {
      label: field({ label: 'Libellé', name: 'label', value: 'Domicile' }),
      district: field({ label: 'Quartier', name: 'district' }),
      city: field({ label: 'Ville', name: 'city', value: 'Niamey' }),
      address_line: field({ label: 'Adresse', name: 'address_line' }),
      landmark: field({ label: 'Repère', name: 'landmark', hint: 'Ex. derrière la mosquée, portail bleu' }),
    };
    const gps = locationPicker({ label: 'Position GPS (recommandée)', hint: 'Indispensable pour guider les équipes de soins à domicile.' });
    const primary = checkbox({ label: 'Adresse principale', name: 'is_primary', checked: !(patient.addresses || []).length });
    formDialog({
      title: 'Ajouter une adresse', size: 'lg', fields: { ...f, latitude: gps }, extra: [primary], submitText: 'Ajouter',
      submit: async () => {
        const values = Object.fromEntries(Object.entries(f).map(([key, item]) => [key, item.input.value.trim() || null]));
        const position = gps.value();
        await api.post(`patients/${patient.id}/addresses`, {
          ...values,
          ...(position ? { latitude: position.latitude, longitude: position.longitude, gps_accuracy_m: position.accuracy_m, gps_captured_at: position.captured_at } : {}),
          is_primary: primary.input.checked,
        });
        toast('Adresse ajoutée.');
        reload();
      },
    });
  }

  /** Relevé ou correction de la position GPS d'une adresse existante. */
  function locateAddress(address) {
    const gps = locationPicker({
      label: 'Position GPS',
      value: address.latitude !== null && address.latitude !== undefined
        ? { latitude: Number(address.latitude), longitude: Number(address.longitude), accuracy_m: address.gps_accuracy_m, captured_at: address.gps_captured_at }
        : null,
      hint: 'Sur place : « Ma position actuelle ». Depuis la clinique : placez l’épingle sur l’entrée du domicile.',
    });
    formDialog({
      title: `Localiser : ${address.label || 'adresse'}`,
      description: [address.address_line, address.district, address.city].filter(Boolean).join(', ') || (address.landmark ? `Repère : ${address.landmark}` : null),
      size: 'lg', fields: { latitude: gps }, submitText: 'Enregistrer la position',
      submit: async () => {
        const position = gps.value();
        await api.put(`patients/${patient.id}/addresses/${address.id}`, position
          ? { latitude: position.latitude, longitude: position.longitude, gps_accuracy_m: position.accuracy_m, gps_captured_at: position.captured_at }
          : { latitude: null, longitude: null, gps_accuracy_m: null, gps_captured_at: null });
        toast(position ? 'Position enregistrée.' : 'Position retirée.');
        reload();
      },
    });
  }

  function removeChild(kind, item, label) {
    confirmAction({
      title: `Retirer ${label} ?`, confirmText: 'Retirer',
      text: 'L’élément sera retiré du dossier. L’opération est tracée dans le journal d’audit.',
      run: async () => {
        await api.del(`patients/${patient.id}/${kind}/${item.id}`);
        toast('Élément retiré.');
        reload();
      },
    });
  }

  // ---------------------------------------------------------------- Onglets
  function renderTab(id, panel) {
    if (id === 'identity') {
      mount(panel, h('div', { class: 'cards-2' },
        card('Identité', dl([
          ['Nom', patient.last_name],
          ['Prénom', patient.first_name],
          ['Sexe', SEX[patient.sex] || null],
          ['Date de naissance', patient.birth_date ? formatDay(patient.birth_date) + (patient.birth_date_is_estimated ? ' (approximative)' : '') : null],
          ['Téléphone', formatPhone(patient.phone)],
          ['Médecin traitant', patient.attending_physician ? patient.attending_physician.name : 'Aucun'],
          ['Dossier validé le', patient.validated_at ? formatDate(patient.validated_at) : null],
        ]), {
          actions: open && session.can('patients.assign_attending')
            ? button({ text: 'Médecin traitant', iconName: 'stethoscope', variant: 'ghost', onClick: changeAttending }) : null,
        }),
        card('Personnes à prévenir', list(patient.contacts, (c) => [
          h('div', { class: 'grow' }, h('strong', {}, c.full_name), h('small', {}, [c.relationship, formatPhone(c.phone)].filter(Boolean).join(' · '))),
          h('div', { class: 'row-actions' },
            c.is_emergency ? h('span', { class: 'vsh-badge vsh-badge--danger' }, 'Urgence') : null,
            canEdit ? iconButton('x', `Retirer ${c.full_name}`, () => removeChild('contacts', c, 'cette personne')) : null),
        ], 'Aucune personne à prévenir enregistrée.'), {
          actions: canEdit ? button({ text: 'Ajouter', iconName: 'userPlus', variant: 'ghost', onClick: addContact }) : null,
        }),
        card('Adresses', list(patient.addresses, (a) => [
          h('div', { class: 'grow' }, h('strong', {}, a.label || 'Adresse'), h('small', {}, [a.address_line, a.district, a.city].filter(Boolean).join(', ') || '—'),
            a.landmark ? h('small', {}, `Repère : ${a.landmark}`) : null),
          h('div', { class: 'row-actions' },
            a.is_primary ? h('span', { class: 'vsh-badge vsh-badge--success' }, 'Principale') : null,
            a.latitude !== null && a.latitude !== undefined
              ? h('a', {
                class: `vsh-badge vsh-badge--${accuracyQuality(a.gps_accuracy_m).tone}`, href: links.osm({ latitude: a.latitude, longitude: a.longitude }),
                target: '_blank', rel: 'noopener noreferrer', title: 'Voir sur OpenStreetMap',
              }, icon('mapPin', { size: 12 }), a.gps_accuracy_m ? `GPS ${formatAccuracy(a.gps_accuracy_m)}` : 'GPS')
              : h('span', { class: 'vsh-badge vsh-badge--warning' }, 'Sans GPS'),
            canEdit ? iconButton('mapPin', `Localiser l’adresse ${a.label || ''}`.trim(), () => locateAddress(a)) : null,
            canEdit ? iconButton('x', `Retirer l’adresse ${a.label || ''}`.trim(), () => removeChild('addresses', a, 'cette adresse')) : null),
        ], 'Aucune adresse enregistrée.'), {
          actions: canEdit ? button({ text: 'Ajouter', iconName: 'mapPin', variant: 'ghost', onClick: addAddress }) : null,
        })));
      return;
    }
    if (id === 'medical') {
      const medical = patient.medical;
      mount(panel, h('div', { class: 'stack' },
        h('p', { class: 'audit-note' }, icon('shield'), 'Données médicales confidentielles : chaque consultation de ce dossier est enregistrée dans le journal d’audit.'),
        h('div', { class: 'cards-2' },
          card('Profil médical', dl([
            ['Groupe sanguin', medical.profile ? medical.profile.blood_group : null],
            ['Observations', medical.profile ? medical.profile.observations : null],
          ])),
          card('Allergies', list(medical.allergies, (a) => [
            h('div', {}, h('strong', {}, a.allergen), a.reaction ? h('small', {}, a.reaction) : null),
            badge('severity', a.severity),
          ], 'Aucune allergie connue.')),
          card('Antécédents', list(medical.medical_history, (m) => [
            h('div', {}, h('strong', {}, m.description), h('small', {}, [HISTORY_TYPES[m.history_type] || m.history_type, m.since_date ? `depuis le ${formatDay(m.since_date)}` : null].filter(Boolean).join(' · '))),
          ], 'Aucun antécédent enregistré.')),
          card('Traitements en cours', list(medical.current_treatments, (t) => [
            h('div', {}, h('strong', {}, t.label), h('small', {}, [t.dosage, t.started_on ? `depuis le ${formatDay(t.started_on)}` : null].filter(Boolean).join(' · '))),
          ], 'Aucun traitement en cours.')))));
      return;
    }
    if (id === 'consultations') {
      remoteTable(panel, isCurrent,
        async () => (await api.get(`patients/${patient.id}/consultations`, { per_page: 50 })).data,
        (rows) => table({
          caption: 'Consultations du patient',
          columns: [
            { label: 'Date', render: (c) => h('span', { class: 'nowrap' }, formatDateTime(c.started_at)) },
            { label: 'Type', render: (c) => CONSULTATION_TYPES[c.consultation_type] || c.consultation_type },
            { label: 'Praticien', render: (c) => (c.practitioner ? c.practitioner.name : '—') },
            { label: 'Statut', render: (c) => badge('consultation', c.status) },
          ],
          rows,
        }),
        { iconName: 'stethoscope', title: 'Aucune consultation', text: 'Les consultations de ce patient apparaîtront ici.' });
      return;
    }
    if (id === 'appointments') {
      remoteTable(panel, isCurrent,
        async () => (await api.get('appointments', { patient_id: patient.id, per_page: 50 })).data,
        (rows) => table({
          caption: 'Rendez-vous du patient',
          columns: [
            { label: 'Date', render: (a) => h('span', { class: 'nowrap' }, formatDateTime(a.scheduled_start)) },
            { label: 'Service', render: (a) => a.service.label },
            { label: 'Praticien', render: (a) => (a.practitioner ? a.practitioner.name : 'À attribuer') },
            { label: 'Statut', render: (a) => badge('appointment', a.status) },
          ],
          rows,
        }),
        { iconName: 'calendar', title: 'Aucun rendez-vous', text: 'Les rendez-vous de ce patient apparaîtront ici.' });
      return;
    }
    if (id === 'invoices') {
      remoteTable(panel, isCurrent,
        async () => (await api.get('invoices', { patient_id: patient.id, per_page: 50 })).data,
        (rows) => table({
          caption: 'Factures du patient',
          columns: [
            { label: 'N°', render: (i) => h('span', { class: 'mono nowrap' }, i.number || 'Brouillon') },
            { label: 'Date', render: (i) => formatDate(i.issued_at || i.created_at) },
            { label: 'Montant net', cls: 'num', render: (i) => h('span', { class: 'money' }, formatMoney(i.net_amount, i.currency)) },
            { label: 'Statut', render: (i) => badge('invoice', i.status) },
            { label: 'Règlement', render: (i) => (i.status === 'EMISE' ? badge('settlement', i.settlement_status) : '—') },
          ],
          rows,
        }),
        { iconName: 'receipt', title: 'Aucune facture', text: 'Les factures de ce patient apparaîtront ici.' });
    }
  }
}
