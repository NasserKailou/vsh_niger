/**
 * Création et modification d'un dossier patient (accueil).
 * Création : identité + personne à prévenir + adresse (+ médecin traitant) en une seule saisie.
 * Doublon possible : le serveur répond 409 POSSIBLE_DUPLICATE ; l'utilisateur ouvre le dossier existant
 * ou confirme explicitement qu'il s'agit d'une autre personne.
 */
import { h, mount, uid } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { api, ApiError } from '../core/api.js';
import { session } from '../core/session.js';
import { navigate } from '../core/router.js';
import { formatDay, formatPhone } from '../core/format.js';
import { locationPicker } from '../locationPicker.js';
import { pageHead, field, checkbox, button, setBusy, applyErrors, errorSummary, modal, toast, errorState, skeleton, loadingRegion } from '../ui.js';

/** Groupe de boutons radio (sexe). Retourne {el, value(), setError, id}. */
function radioGroup({ legend, name, options, value, required }) {
  const id = uid(name);
  const errorId = `${id}-error`;
  const error = h('p', { id: errorId, class: 'vsh-field__error', hidden: true });
  const inputs = options.map(([optionValue], index) => h('input', {
    type: 'radio', name: id, value: optionValue, checked: optionValue === value, id: index === 0 ? id : undefined,
  }));
  const el = h('fieldset', { class: 'fieldset' },
    h('legend', {}, legend, required ? h('span', { 'aria-hidden': 'true' }, ' *') : null),
    h('div', { class: 'choice-group' }, inputs.map((input, index) => h('label', { class: 'choice' }, input, h('span', {}, options[index][1])))),
    error);
  return {
    el,
    id,
    value: () => {
      const checked = inputs.find((input) => input.checked);
      return checked ? checked.value : null;
    },
    setError: (message) => {
      error.hidden = !message;
      error.replaceChildren();
      if (message) error.append(icon('alert', { size: 16 }), message);
      if (message) el.setAttribute('aria-describedby', errorId);
      else el.removeAttribute('aria-describedby');
    },
  };
}

function textarea(options) {
  const f = field(options);
  const area = h('textarea', { id: f.input.id, name: options.name, class: 'vsh-textarea', maxlength: options.maxlength || '500' });
  const describedBy = f.input.getAttribute('aria-describedby');
  if (describedBy) area.setAttribute('aria-describedby', describedBy);
  area.value = options.value || '';
  f.input.replaceWith(area);
  f.input = area;
  return f;
}

export async function patientFormView({ main, setTitle, params, isCurrent }) {
  const editing = Boolean(params.id);
  setTitle(editing ? 'Modifier le dossier' : 'Nouveau patient');
  let patient = null;
  if (editing) {
    mount(main, loadingRegion(skeleton('block')));
    try {
      ({ data: patient } = await api.get(`patients/${encodeURIComponent(params.id)}`));
    } catch (error) {
      if (isCurrent()) mount(main, errorState(error, () => patientFormView({ main, setTitle, params, isCurrent })));
      return;
    }
    if (!isCurrent()) return;
  }
  const back = editing ? { href: `#/patients/${patient.id}`, label: 'Retour au dossier' } : { href: '#/patients', label: 'Patients' };

  // ---------------------------------------------------------------- Identité
  const lastName = field({ label: 'Nom', name: 'last_name', required: true, autocomplete: 'off', value: patient ? patient.last_name : '' });
  const firstName = field({ label: 'Prénom(s)', name: 'first_name', required: true, autocomplete: 'off', value: patient ? patient.first_name : '' });
  const sex = radioGroup({ legend: 'Sexe', name: 'sex', required: true, value: patient ? patient.sex : null, options: [['F', 'Femme'], ['M', 'Homme']] });
  const birth = field({ label: 'Date de naissance', name: 'birth_date', type: 'date', value: patient && patient.birth_date ? patient.birth_date : '', attrs: { max: new Date().toISOString().slice(0, 10) } });
  const estimated = checkbox({ label: 'Date approximative', name: 'estimated', checked: patient ? patient.birth_date_is_estimated : false, hint: 'Cochez si seule l’année (ou l’âge) est connue.' });
  const phone = field({ label: 'Téléphone', name: 'phone', type: 'tel', inputmode: 'tel', value: patient && patient.phone ? patient.phone : '', hint: 'Ex. 96 11 22 33 — sert au portail patient et aux rappels.' });
  const fields = { last_name: lastName, first_name: firstName, sex, birth_date: birth, phone };

  const sections = [
    h('section', { class: 'form-section' },
      h('h2', {}, 'Identité'),
      h('div', { class: 'form-grid' }, lastName.el, firstName.el, h('div', { class: 'span-2' }, sex.el), birth.el, h('div', {}, estimated.el), phone.el)),
  ];

  // ---------------------------------------------------------------- Création : contact, adresse, médecin traitant
  let contact = null;
  let address = null;
  let attending = null;
  if (!editing) {
    contact = {
      full_name: field({ label: 'Nom complet', name: 'contact_name', autocomplete: 'off' }),
      relationship: field({ label: 'Lien de parenté', name: 'contact_relationship', hint: 'Ex. mère, époux, fils' }),
      phone: field({ label: 'Téléphone', name: 'contact_phone', type: 'tel', inputmode: 'tel' }),
    };
    address = {
      district: field({ label: 'Quartier', name: 'district' }),
      city: field({ label: 'Ville', name: 'city', value: 'Niamey' }),
      address_line: field({ label: 'Adresse', name: 'address_line', hint: 'Rue, porte, bâtiment… si connus' }),
      landmark: textarea({ label: 'Repère', name: 'landmark', hint: 'Ex. derrière la mosquée, portail bleu. Indispensable pour les visites à domicile.', maxlength: '255' }),
    };
    const gps = locationPicker({ label: 'Position GPS du domicile', hint: 'Facultatif. Placez le point sur la carte, ou relevez-le avec le téléphone lors d’une visite.' });
    Object.entries(contact).forEach(([key, f]) => { fields[`contacts.0.${key}`] = f; });
    Object.entries(address).forEach(([key, f]) => { fields[`addresses.0.${key}`] = f; });
    fields['addresses.0.latitude'] = gps;
    sections.push(
      h('section', { class: 'form-section' },
        h('h2', {}, 'Personne à prévenir'),
        h('p', { class: 'form-section__hint' }, 'Facultatif. Contact d’urgence de la famille.'),
        h('div', { class: 'form-grid' }, h('div', { class: 'span-2' }, contact.full_name.el), contact.relationship.el, contact.phone.el)),
      h('section', { class: 'form-section' },
        h('h2', {}, 'Adresse'),
        h('p', { class: 'form-section__hint' }, 'Facultatif. Adresse et position guident les équipes de soins à domicile.'),
        h('div', { class: 'form-grid' }, address.district.el, address.city.el, h('div', { class: 'span-2' }, address.address_line.el), h('div', { class: 'span-2' }, address.landmark.el),
          h('div', { class: 'span-2' }, gps.el))));
    if (session.can('patients.assign_attending')) {
      attending = field({ label: 'Médecin traitant', name: 'attending_physician_id', options: [['', 'Chargement…']], hint: 'Facultatif. Il pourra valider les résultats d’examens (D-005).' });
      fields.attending_physician_id = attending;
      sections.push(h('section', { class: 'form-section' }, h('h2', {}, 'Suivi'), h('div', { class: 'form-grid' }, attending.el)));
      api.get('staff/directory', { profession: 'MEDECIN' }).then(({ data }) => {
        attending.input.replaceChildren(h('option', { value: '' }, 'Aucun pour l’instant'),
          ...data.map((doctor) => h('option', { value: doctor.id }, doctor.speciality ? `${doctor.name} — ${doctor.speciality}` : doctor.name)));
      }).catch(() => attending.input.replaceChildren(h('option', { value: '' }, 'Liste indisponible')));
    }
  }

  const summary = h('div', { hidden: true });
  const submit = button({ text: editing ? 'Enregistrer les modifications' : 'Créer le dossier', iconName: editing ? 'checkCircle' : 'userPlus', type: 'submit' });
  const form = h('form', { class: 'vsh-card stack', novalidate: true },
    summary, sections,
    h('div', { class: 'form-actions' }, h('a', { class: 'vsh-btn vsh-btn--secondary', href: back.href }, 'Annuler'), submit));

  mount(main, pageHead({
    title: editing ? `Modifier : ${patient.last_name.toUpperCase()} ${patient.first_name}` : 'Nouveau patient',
    subtitle: editing ? `Dossier ${patient.file_number || ''}` : 'Les champs marqués * sont obligatoires.',
    back,
  }), form);

  function payload() {
    const body = {
      last_name: lastName.input.value.trim(),
      first_name: firstName.input.value.trim(),
      sex: sex.value(),
      birth_date: birth.input.value || null,
      birth_date_is_estimated: estimated.input.checked,
      phone: phone.input.value.trim() || null,
    };
    if (!editing) {
      if (contact.full_name.input.value.trim() || contact.phone.input.value.trim()) {
        body.contacts = [{
          full_name: contact.full_name.input.value.trim(),
          relationship: contact.relationship.input.value.trim() || null,
          phone: contact.phone.input.value.trim(),
          is_emergency: true,
        }];
      }
      const addressValues = Object.fromEntries(Object.entries(address).map(([key, f]) => [key, f.input.value.trim() || null]));
      const position = gps.value();
      if (position) {
        Object.assign(addressValues, { latitude: position.latitude, longitude: position.longitude, gps_accuracy_m: position.accuracy_m, gps_captured_at: position.captured_at });
      }
      if (addressValues.district || addressValues.address_line || addressValues.landmark || position) {
        body.addresses = [{ ...addressValues, label: 'Domicile', is_primary: true }];
      }
      if (attending && attending.input.value) body.attending_physician_id = attending.input.value;
    } else {
      body.version = patient.version;
    }
    return body;
  }

  function localCheck(body) {
    const errors = {};
    if (!body.last_name) errors.last_name = ['Indiquez le nom.'];
    if (!body.first_name) errors.first_name = ['Indiquez le prénom.'];
    if (!body.sex) errors.sex = ['Choisissez le sexe.'];
    if (body.contacts) {
      if (!body.contacts[0].full_name) errors['contacts.0.full_name'] = ['Indiquez le nom de la personne à prévenir.'];
      if (!body.contacts[0].phone) errors['contacts.0.phone'] = ['Indiquez son téléphone.'];
    }
    return errors;
  }

  async function save(body) {
    setBusy(submit, true);
    try {
      const { data } = editing
        ? await api.put(`patients/${patient.id}`, body)
        : await api.post('patients', body);
      toast(editing ? 'Dossier mis à jour.' : `Dossier créé : ${data.file_number}.`);
      navigate(`/patients/${data.id}`);
    } catch (error) {
      if (error instanceof ApiError && error.code === 'POSSIBLE_DUPLICATE') {
        showDuplicates(body, (error.errors && error.errors.duplicates) || []);
      } else if (error instanceof ApiError && error.code === 'VALIDATION_ERROR') {
        errorSummary(summary, 'Veuillez corriger les champs indiqués.', applyErrors(fields, error.errors));
      } else if (error instanceof ApiError && error.code === 'VERSION_CONFLICT') {
        errorSummary(summary, 'Ce dossier a été modifié par un collègue pendant votre saisie. Rechargez la page pour voir la dernière version.', []);
      } else {
        errorSummary(summary, error.message || 'Enregistrement impossible.', []);
      }
    } finally {
      setBusy(submit, false);
    }
  }

  function showDuplicates(body, duplicates) {
    const box = modal({
      title: 'Ce patient existe peut-être déjà',
      description: 'Un ou plusieurs dossiers ressemblent à cette saisie. Vérifiez avant de créer un nouveau dossier.',
      content: h('ul', { class: 'dup-list' }, duplicates.map((d) => h('li', {},
        h('div', {}, h('strong', {}, `${d.last_name.toUpperCase()} ${d.first_name}`),
          h('small', {}, [d.file_number, d.birth_date ? `né(e) le ${formatDay(d.birth_date)}` : null, d.phone ? formatPhone(d.phone) : null].filter(Boolean).join(' · '))),
        h('a', { class: 'vsh-btn vsh-btn--secondary vsh-btn--sm', href: `#/patients/${d.id}`, onclick: () => box.close() }, 'Ouvrir')))),
    });
    box.footer.append(
      button({ text: 'Revenir à la saisie', variant: 'secondary', onClick: () => box.close() }),
      button({ text: 'C’est une autre personne : créer', variant: 'primary', onClick: () => { box.close(); save({ ...body, confirm_not_duplicate: true }); } }));
  }

  form.addEventListener('submit', (event) => {
    event.preventDefault();
    const body = payload();
    const errors = localCheck(body);
    if (Object.keys(errors).length) {
      errorSummary(summary, 'Veuillez compléter les champs indiqués.', applyErrors(fields, errors));
      return;
    }
    errorSummary(summary, null);
    applyErrors(fields, {});
    save(body);
  });
}
