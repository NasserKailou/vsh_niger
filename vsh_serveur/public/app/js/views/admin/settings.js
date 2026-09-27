/**
 * Paramètres métier modifiables par l'administrateur (valeurs typées, validées par le serveur).
 * Les secrets et réglages techniques (base de données, jetons, passerelle SMS) restent dans .env et
 * ne sont jamais exposés ici. Seules les valeurs modifiées sont envoyées ; chaque changement est audité.
 */
import { h, mount } from '../../core/dom.js';
import { icon } from '../../core/icons.js';
import { api } from '../../core/api.js';
import { formatDateTime } from '../../core/format.js';
import { pageHead, card, errorState, skeleton, loadingRegion, button, field, checkbox, toast, setBusy, errorSummary } from '../../ui.js';

const GROUPS = [
  ['app', 'Établissement'], ['documents', 'Documents imprimés'], ['auth', 'Connexion et inscription'], ['patients', 'Dossiers patients'],
  ['appointments', 'Rendez-vous'], ['consultations', 'Consultations'], ['homecare', 'Visites à domicile'], ['geo', 'Géolocalisation'],
  ['invoices', 'Facturation'], ['sync', 'Synchronisation hors ligne'], ['uploads', 'Pièces jointes'], ['notifications', 'Notifications'],
];
const CHOICES = {
  'homecare.dispatch_mode': [
    ['BOTH', 'Régulation et prise en charge par les équipes'], ['SELF_ASSIGN', 'Prise en charge par les équipes uniquement'],
    ['DISPATCH_ONLY', 'Affectation par la régulation uniquement'],
  ],
  'app.default_locale': [['fr', 'Français'], ['en', 'English']],
  'notifications.sms_mode': [
    ['FALLBACK', 'SMS seulement si le patient n’a pas reçu le push'], ['ALWAYS', 'SMS en plus du push'], ['OFF', 'Aucun SMS de notification'],
  ],
};
const LETTERHEAD = [
  ['address', 'Adresse', 'Ex. quartier, rue, ville'], ['phone', 'Téléphone', 'Ex. +227 20 00 00 00'], ['email', 'E-mail', ''],
  ['invoice_footer', 'Mention en bas des factures', 'Ex. NIF, RCCM, conditions'], ['prescription_footer', 'Mention en bas des ordonnances', ''],
];

export async function adminSettingsView(ctx) {
  const { main, setTitle, isCurrent } = ctx;
  setTitle('Paramètres');
  mount(main, loadingRegion([skeleton('line'), skeleton('block')]));
  let settings;
  try {
    settings = (await api.get('settings')).data;
  } catch (error) {
    if (isCurrent()) mount(main, errorState(error, () => adminSettingsView(ctx)));
    return;
  }
  if (!isCurrent()) return;

  const controls = new Map();
  const summary = h('div', { hidden: true });

  function control(setting) {
    const id = `setting-${setting.key.replace(/\W/g, '-')}`;
    const error = h('p', { class: 'vsh-field__error', hidden: true });
    const setError = (message) => {
      error.hidden = !message;
      error.replaceChildren(...(message ? [icon('alert', { size: 16 }), message] : []));
    };
    const hint = setting.description || '';
    let el;
    let read;
    let focusId;
    if (setting.key === 'documents.letterhead') {
      const value = setting.value && typeof setting.value === 'object' && !Array.isArray(setting.value) ? setting.value : {};
      const inputs = LETTERHEAD.map(([name, label, placeholder]) => field({ label, name, value: value[name] || '', placeholder, attrs: { maxlength: '255' } }));
      el = h('fieldset', { class: 'vsh-field', id, tabindex: '-1' },
        h('legend', { class: 'vsh-field__label' }, 'En-tête et mentions des documents'),
        h('p', { class: 'vsh-field__hint' }, 'Champs vides omis. Le nom affiché est celui de l’établissement (app.clinic_name).'),
        h('div', { class: 'form-grid' }, inputs.map((f) => f.el)), error);
      read = () => Object.fromEntries(LETTERHEAD.map(([name], i) => [name, inputs[i].input.value.trim()]));
      focusId = id;
    } else if (setting.type === 'BOOL') {
      const box = checkbox({ label: setting.key, name: setting.key, checked: Boolean(setting.value), hint });
      el = h('div', {}, box.el, error);
      read = () => box.input.checked;
      focusId = box.input.id;
    } else if (setting.type === 'JSON') {
      const list = Array.isArray(setting.value);
      const f = field({ label: setting.key, name: setting.key, hint: list ? `${hint} Une valeur par ligne.` : `${hint} Format JSON.` });
      const area = h('textarea', { class: 'vsh-textarea mono', id: f.input.id, name: setting.key, rows: '3' });
      area.value = list ? setting.value.join('\n') : JSON.stringify(setting.value, null, 2);
      f.input.replaceWith(area);
      el = h('div', {}, f.el, error);
      read = () => {
        if (list) return area.value.split('\n').map((s) => s.trim()).filter(Boolean);
        try {
          return JSON.parse(area.value);
        } catch (e) {
          return area.value;
        }
      };
      focusId = area.id;
    } else {
      const f = field({
        label: setting.key, name: setting.key, hint,
        type: setting.type === 'INT' ? 'number' : 'text',
        inputmode: setting.type === 'INT' ? 'numeric' : undefined,
        options: CHOICES[setting.key],
        value: String(setting.value === null ? '' : setting.value),
        attrs: setting.type === 'INT' ? { min: '0', step: '1' } : { maxlength: '500' },
      });
      el = h('div', {}, f.el, error);
      read = () => {
        if (setting.type !== 'INT') return f.input.value.trim();
        return f.input.value === '' ? null : parseInt(f.input.value, 10);
      };
      focusId = f.input.id;
    }
    controls.set(setting.key, { setting, read, setError, focusId });
    return h('div', { class: 'setting' }, el,
      h('small', { class: 'vsh-muted' }, `${setting.is_public ? 'Transmis aux applications' : 'Réservé au serveur'} · modifié le ${formatDateTime(setting.updated_at)}`));
  }

  const byGroup = new Map(GROUPS.map(([prefix]) => [prefix, []]));
  const others = [];
  settings.forEach((s) => {
    const prefix = s.key.split('.')[0];
    if (byGroup.has(prefix)) byGroup.get(prefix).push(s);
    else others.push(s);
  });
  const cards = GROUPS.filter(([prefix]) => byGroup.get(prefix).length)
    .map(([prefix, title]) => card(title, h('div', { class: 'stack' }, byGroup.get(prefix).map(control))));
  if (others.length) cards.push(card('Autres', h('div', { class: 'stack' }, others.map(control))));

  const save = button({ text: 'Enregistrer les modifications', type: 'submit' });
  const form = h('form', { class: 'stack', novalidate: true }, summary, h('div', { class: 'cards-2' }, cards), h('div', { class: 'form-actions' }, save));
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const values = {};
    controls.forEach(({ setting, read, setError }, key) => {
      setError(null);
      const value = read();
      if (JSON.stringify(value) !== JSON.stringify(setting.value)) values[key] = value;
    });
    if (!Object.keys(values).length) {
      toast('Aucune modification à enregistrer.', { type: 'info' });
      return;
    }
    setBusy(save, true);
    try {
      await api.put('settings', { values });
      toast(`${Object.keys(values).length} paramètre(s) enregistré(s).`);
      adminSettingsView(ctx);
    } catch (error) {
      const items = Object.entries(error.errors || {}).map(([key, messages]) => {
        const c = controls.get(key);
        const message = Array.isArray(messages) ? messages[0] : String(messages);
        if (c) c.setError(message);
        return { id: c ? c.focusId : null, message: `${key} : ${message}` };
      });
      errorSummary(summary, error.message || 'Enregistrement impossible.', items);
    } finally {
      setBusy(save, false);
    }
  });

  mount(main,
    pageHead({ title: 'Paramètres', subtitle: 'Réglages métier de la clinique. Les secrets techniques restent dans la configuration du serveur. Chaque modification est tracée dans le journal d’audit.' }),
    form);
}
