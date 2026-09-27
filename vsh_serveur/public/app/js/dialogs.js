/**
 * Boîtes de dialogue métier réutilisables : confirmation d'une action et formulaire court.
 * Les erreurs de l'API s'affichent dans la boîte, qui reste ouverte pour correction.
 */
import { h } from './core/dom.js';
import { ApiError } from './core/api.js';
import { button, modal, setBusy, applyErrors, errorSummary } from './ui.js';

/** Boîte de confirmation (action sensible). */
export function confirmAction({ title, text, confirmText, danger = true, run }) {
  const summary = h('div', { hidden: true });
  const box = modal({ title, content: h('div', { class: 'stack' }, summary, h('p', {}, text)), size: 'sm' });
  const confirm = button({ text: confirmText, variant: danger ? 'danger' : 'primary' });
  confirm.addEventListener('click', async () => {
    setBusy(confirm, true);
    try {
      await run();
      box.close();
    } catch (error) {
      errorSummary(summary, error.message || 'Action impossible.', []);
    } finally {
      setBusy(confirm, false);
    }
  });
  box.footer.append(button({ text: 'Annuler', variant: 'secondary', onClick: () => box.close() }), confirm);
}

/** Formulaire en boîte de dialogue : `fields` = {nom: champ}, `submit()` appelle l'API. */
export function formDialog({ title, description, fields, extra = [], submitText, submit, size }) {
  const summary = h('div', { hidden: true });
  const form = h('form', { class: 'stack', novalidate: true, id: `form-${Date.now()}` },
    summary, Object.values(fields).map((f) => f.el), extra.map((f) => (f.el ? f.el : f)));
  const confirm = button({ text: submitText, type: 'submit' });
  confirm.setAttribute('form', form.id);
  const box = modal({ title, description, content: form, size });
  box.footer.append(button({ text: 'Annuler', variant: 'secondary', onClick: () => box.close() }), confirm);
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    setBusy(confirm, true);
    try {
      await submit();
      box.close();
    } catch (error) {
      if (error instanceof ApiError && error.code === 'VALIDATION_ERROR') {
        errorSummary(summary, 'Veuillez corriger les champs indiqués.', applyErrors(fields, error.errors));
      } else {
        errorSummary(summary, error.message || 'Enregistrement impossible.', []);
      }
    } finally {
      setBusy(confirm, false);
    }
  });
  return box;
}
