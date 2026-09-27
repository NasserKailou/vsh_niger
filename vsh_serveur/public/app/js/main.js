/**
 * Point d'entrée de l'interface web Vision Homecare.
 * Enchaînement : thème → reprise de session → routage (connexion, mot de passe imposé, écrans).
 * Les droits affichés ici ne sont qu'un confort d'affichage : le serveur revérifie chaque requête.
 */
import { h, mount } from './core/dom.js';
import { route, parse, navigate } from './core/router.js';
import { session } from './core/session.js';
import { createShell, applyTheme, NAV, visibleNav } from './shell.js';
import { pageHead, emptyState, errorState, toast } from './ui.js';
import { renderLogin, renderPasswordChange } from './views/auth.js';
import { dashboardView } from './views/dashboard.js';
import { patientsView } from './views/patients.js';
import { patientView } from './views/patient.js';
import { patientFormView } from './views/patientForm.js';
import { appointmentsView } from './views/appointments.js';
import { consultationsView } from './views/consultations.js';
import { consultationView } from './views/consultation.js';
import { examinationsView, examinationView } from './views/examinations.js';
import { homecareView, homecareDetailView } from './views/homecare.js';
import { mapView } from './views/map.js';
import { billingView, invoiceView } from './views/billing.js';
import { adminUsersView } from './views/admin/users.js';
import { adminTeamsView } from './views/admin/teams.js';
import { adminReferenceView } from './views/admin/reference.js';
import { adminSettingsView } from './views/admin/settings.js';
import { adminAuditView } from './views/admin/audit.js';
import { soonView } from './views/soon.js';

const root = document.getElementById('app');
let shell = null;
let renderSeq = 0;
let pendingNotice = null;

// ---------------------------------------------------------------- Routes

route('/dashboard', { view: dashboardView, perms: ['dashboard.global', 'dashboard.personal'] });
route('/patients', { view: patientsView, perms: ['patients.read'] });
route('/patients/new', { view: patientFormView, perms: ['patients.create'] });
route('/patients/:id/edit', { view: patientFormView, perms: ['patients.update'] });
route('/patients/:id', { view: patientView, perms: ['patients.read'] });
route('/appointments', { view: appointmentsView, perms: ['appointments.read'] });
route('/consultations', { view: consultationsView, perms: ['consultations.read'] });
route('/consultations/:id', { view: consultationView, perms: ['consultations.read'] });
route('/examinations', { view: examinationsView, perms: ['examinations.read'] });
route('/examinations/:id', { view: examinationView, perms: ['examinations.read'] });
route('/homecare', { view: homecareView, perms: ['homecare.read'] });
route('/homecare/:id', { view: homecareDetailView, perms: ['homecare.read'] });
route('/map', { view: mapView, perms: ['map.read', 'homecare.dispatch'] });
route('/billing', { view: billingView, perms: ['invoices.read'] });
route('/billing/:id', { view: invoiceView, perms: ['invoices.read'] });
route('/admin/users', { view: adminUsersView, perms: ['users.read', 'roles.manage'] });
route('/admin/teams', { view: adminTeamsView, perms: ['teams.manage'] });
route('/admin/reference', { view: adminReferenceView, perms: ['reference.manage', 'tariffs.manage', 'prescription_templates.manage', 'prescription_templates.approve'] });
route('/admin/settings', { view: adminSettingsView, perms: ['settings.manage'] });
route('/admin/audit', { view: adminAuditView, perms: ['audit.read', 'sync.supervise'] });
route('/account/password', {
  view: ({ main, setTitle }) => {
    setTitle('Mon mot de passe');
    renderPasswordChange(main, { forced: false, onDone: () => navigate(homePath()), onCancel: () => window.history.back() });
  },
});
for (const section of NAV) {
  for (const item of section.items) {
    if (item.soon) route(item.path, { view: soonView, perms: item.perms });
  }
}

/** Première page disponible pour l'utilisateur (hors écrans « bientôt »). */
function homePath() {
  for (const section of visibleNav()) {
    for (const item of section.items) {
      if (!item.soon) return item.path;
    }
  }
  return '/account/password';
}

// ---------------------------------------------------------------- Rendu

async function render() {
  const seq = ++renderSeq;
  const isCurrent = () => seq === renderSeq;
  const { definition, path, params, query } = parse();

  if (!session.loggedIn) {
    shell = null;
    const target = path !== '/' && path !== '/login' ? path : null;
    const notice = pendingNotice;
    pendingNotice = null;
    renderLogin(root, {
      notice,
      onSuccess: () => navigate(target || homePath(), { replace: true }),
    });
    document.title = 'Connexion · Vision Homecare';
    return;
  }

  if (session.user.must_change_password) {
    shell = null;
    document.title = 'Nouveau mot de passe · Vision Homecare';
    renderPasswordChange(root, { forced: true, onDone: () => navigate(homePath(), { replace: true }) });
    return;
  }

  if (path === '/' || path === '/login') {
    navigate(homePath(), { replace: true });
    return;
  }

  if (!shell) {
    shell = createShell(root, {
      onLogout: async () => {
        await session.logout();
      },
      onChangePassword: () => navigate('/account/password'),
    });
  }
  shell.setActive(path);
  const ctx = { main: shell.main, setTitle: shell.setTitle, params, query, path, isCurrent };

  if (!definition) {
    shell.setTitle('Page introuvable');
    mount(shell.main, pageHead({ title: 'Page introuvable' }),
      emptyState({
        iconName: 'search', title: 'Cette page n’existe pas', text: 'Le lien est peut-être incorrect ou périmé.',
        action: h('a', { class: 'vsh-btn vsh-btn--secondary', href: '#' + homePath() }, 'Retour à l’accueil'),
      }));
  } else if (definition.perms && !session.canAny(...definition.perms)) {
    shell.setTitle('Accès refusé');
    mount(shell.main, pageHead({ title: 'Accès refusé' }),
      emptyState({ iconName: 'lock', title: 'Vous n’avez pas accès à cet écran', text: 'Si vous pensez qu’il s’agit d’une erreur, contactez l’administrateur de la clinique.' }));
  } else {
    window.scrollTo(0, 0);
    try {
      await definition.view(ctx);
    } catch (error) {
      if (isCurrent()) mount(shell.main, errorState(error, () => render()));
    }
  }

  // Changement de page annoncé aux lecteurs d'écran : le focus va au titre de la page.
  if (isCurrent() && shell) {
    const heading = shell.main.querySelector('[data-page-title]');
    if (heading) heading.focus({ preventScroll: true });
  }
}

session.onChange((event) => {
  if (event === 'expired') {
    pendingNotice = { type: 'warning', text: 'Votre session a expiré. Reconnectez-vous pour continuer.' };
    render();
  } else if (event === 'logout') {
    pendingNotice = { type: 'info', text: 'Vous êtes déconnecté.' };
    window.history.replaceState(null, '', '#/');
    render();
  }
});

window.addEventListener('hashchange', () => render());

// ---------------------------------------------------------------- Démarrage

(async function boot() {
  applyTheme();
  try {
    await session.restore();
  } catch (e) {
    /* pas de session à reprendre */
  }
  await render();
  window.addEventListener('unhandledrejection', (event) => {
    if (event.reason && event.reason.name === 'AbortError') return;
    toast('Une erreur inattendue est survenue.', { type: 'error' });
  });
})();
