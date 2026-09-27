/**
 * Gabarit de l'application : lien d'évitement, barre latérale (menu filtré par droits),
 * barre supérieure (menu mobile, état réseau, thème, menu utilisateur), zone principale.
 */
import { h, prefs } from './core/dom.js';
import { icon } from './core/icons.js';
import { initials } from './core/format.js';
import { session } from './core/session.js';
import { iconButton } from './ui.js';

/** Menu : chaque entrée n'apparaît que si l'utilisateur a l'une des permissions indiquées. */
export const NAV = [
  { items: [
    { path: '/dashboard', label: 'Tableau de bord', icon: 'dashboard', perms: ['dashboard.global', 'dashboard.personal'] },
  ] },
  { group: 'Soins', items: [
    { path: '/patients', label: 'Patients', icon: 'users', perms: ['patients.read'] },
    { path: '/appointments', label: 'Rendez-vous', icon: 'calendar', perms: ['appointments.read'] },
    { path: '/consultations', label: 'Consultations', icon: 'stethoscope', perms: ['consultations.read'] },
    { path: '/examinations', label: 'Examens', icon: 'flask', perms: ['examinations.read'] },
  ] },
  { group: 'Domicile', items: [
    { path: '/homecare', label: 'Visites à domicile', icon: 'home', perms: ['homecare.read'] },
    { path: '/map', label: 'Carte', icon: 'map', perms: ['map.read', 'homecare.dispatch'] },
  ] },
  { group: 'Gestion', items: [
    { path: '/billing', label: 'Facturation', icon: 'receipt', perms: ['invoices.read'] },
  ] },
  { group: 'Administration', items: [
    { path: '/admin/users', label: 'Utilisateurs et rôles', icon: 'userCheck', perms: ['users.read', 'roles.manage'] },
    { path: '/admin/teams', label: 'Équipes', icon: 'users', perms: ['teams.manage'] },
    { path: '/admin/reference', label: 'Référentiels et tarifs', icon: 'clipboard', perms: ['reference.manage', 'tariffs.manage', 'prescription_templates.manage', 'prescription_templates.approve'] },
    { path: '/admin/settings', label: 'Paramètres', icon: 'sliders', perms: ['settings.manage'] },
    { path: '/admin/audit', label: 'Journal d’audit', icon: 'shield', perms: ['audit.read', 'sync.supervise'] },
  ] },
];

export function visibleNav() {
  return NAV.map((section) => ({ ...section, items: section.items.filter((item) => session.canAny(...item.perms)) }))
    .filter((section) => section.items.length > 0);
}

export function findNavItem(path) {
  for (const section of NAV) {
    for (const item of section.items) {
      if (path === item.path || path.startsWith(item.path + '/')) return item;
    }
  }
  return null;
}

// ---------------------------------------------------------------- Thème

export function applyTheme(theme = prefs.get('theme')) {
  if (theme === 'dark' || theme === 'light') document.documentElement.dataset.theme = theme;
  else delete document.documentElement.dataset.theme;
}

function currentTheme() {
  const forced = document.documentElement.dataset.theme;
  if (forced) return forced;
  return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
}

function themeLabel() {
  return currentTheme() === 'dark' ? 'Passer au thème clair' : 'Passer au thème sombre';
}

// ---------------------------------------------------------------- Gabarit

export function createShell(root, { onLogout, onChangePassword }) {
  const user = session.user;
  const fullName = `${user.first_name} ${user.last_name}`;
  const roleLabel = (user.roles || []).map(roleName).join(', ') || 'Personnel';

  const main = h('main', { id: 'main', class: 'vsh-main page', tabindex: '-1' });
  const title = h('span', { class: 'topbar__title' });
  const links = new Map();

  const nav = h('nav', { class: 'nav', 'aria-label': 'Navigation principale' },
    visibleNav().map((section) => [
      section.group ? h('div', { class: 'vsh-nav-group vsh-label' }, section.group) : null,
      section.items.map((item) => {
        const a = h('a', { class: 'vsh-nav-link', href: '#' + item.path, onclick: () => closeSidebar() },
          icon(item.icon), h('span', {}, item.label),
          item.soon ? h('span', { class: 'nav-soon', title: `Écran prévu à l’étape ${item.soon}` }, 'bientôt') : null);
        links.set(item.path, a);
        return a;
      }),
    ]));

  const sidebar = h('aside', { class: 'vsh-sidebar', id: 'sidebar', 'aria-label': 'Menu' },
    h('a', { class: 'brand', href: '#/' },
      h('img', { src: '../assets/img/logo-vsh.jpg', alt: '', width: '40', height: '40' }),
      h('span', { class: 'brand__name' }, 'Vision Homecare', h('span', {}, 'Clinique & domicile'))),
    nav,
    h('div', { class: 'sidebar__foot' }, 'Vision Homecare · Niger'));

  const backdrop = h('div', { class: 'backdrop', hidden: true, onclick: () => closeSidebar() });
  const menuButton = iconButton('menu', 'Ouvrir le menu', () => openSidebar(), { class: 'icon-btn topbar__menu', 'aria-controls': 'sidebar', 'aria-expanded': 'false' });

  const net = h('span', { class: 'net', role: 'status', hidden: navigator.onLine }, icon('wifiOff'), 'Hors connexion');
  window.addEventListener('online', () => { net.hidden = true; });
  window.addEventListener('offline', () => { net.hidden = false; });

  const themeButton = iconButton(currentTheme() === 'dark' ? 'sun' : 'moon', themeLabel(), () => {
    const next = currentTheme() === 'dark' ? 'light' : 'dark';
    prefs.set('theme', next);
    applyTheme(next);
    themeButton.replaceChildren(icon(next === 'dark' ? 'sun' : 'moon'));
    themeButton.setAttribute('aria-label', themeLabel());
    themeButton.setAttribute('title', themeLabel());
  });

  // Menu utilisateur (bouton + panneau, fermeture par Échap ou clic extérieur)
  const panel = h('div', { class: 'user-menu__panel', id: 'user-menu', hidden: true, role: 'menu', 'aria-label': 'Mon compte' },
    h('div', { class: 'menu-item', role: 'presentation' }, h('div', { class: 'user-menu__who' }, h('strong', {}, fullName), h('small', {}, roleLabel))),
    h('div', { class: 'menu-sep', role: 'separator' }),
    h('button', { type: 'button', class: 'menu-item', role: 'menuitem', onclick: () => { toggleMenu(false); onChangePassword(); } }, icon('lock'), 'Changer mon mot de passe'),
    h('button', { type: 'button', class: 'menu-item menu-item--danger', role: 'menuitem', onclick: () => { toggleMenu(false); onLogout(); } }, icon('logout'), 'Se déconnecter'));
  const userButton = h('button', {
    type: 'button', class: 'user-menu__btn', 'aria-haspopup': 'menu', 'aria-expanded': 'false', 'aria-controls': 'user-menu',
    'aria-label': `Mon compte : ${fullName}`, onclick: () => toggleMenu(),
  },
  h('span', { class: 'avatar', 'aria-hidden': 'true' }, initials(fullName)),
  h('span', { class: 'user-menu__who', 'aria-hidden': 'true' }, h('span', {}, fullName), h('small', {}, roleLabel)),
  icon('chevronDown'));
  const userMenu = h('div', { class: 'user-menu' }, userButton, panel);

  function toggleMenu(force) {
    const open = force !== undefined ? force : panel.hidden;
    panel.hidden = !open;
    userButton.setAttribute('aria-expanded', String(open));
    if (open) {
      const first = panel.querySelector('[role="menuitem"]');
      if (first) first.focus();
    }
  }
  document.addEventListener('click', (event) => {
    if (!panel.hidden && !userMenu.contains(event.target)) toggleMenu(false);
  });

  function openSidebar() {
    sidebar.dataset.open = 'true';
    backdrop.hidden = false;
    menuButton.setAttribute('aria-expanded', 'true');
    const first = sidebar.querySelector('a');
    if (first) first.focus();
  }
  function closeSidebar() {
    if (sidebar.dataset.open !== 'true') return;
    delete sidebar.dataset.open;
    backdrop.hidden = true;
    menuButton.setAttribute('aria-expanded', 'false');
  }
  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    if (!panel.hidden) {
      toggleMenu(false);
      userButton.focus();
    } else if (sidebar.dataset.open === 'true') {
      closeSidebar();
      menuButton.focus();
    }
  });

  const topbar = h('header', { class: 'vsh-topbar' }, menuButton, title, h('span', { class: 'topbar__spacer' }), net, themeButton, userMenu);

  root.replaceChildren(
    h('a', { class: 'skip-link', href: '#main', onclick: (event) => { event.preventDefault(); main.focus(); } }, 'Aller au contenu'),
    h('div', { class: 'vsh-app shell' }, sidebar, backdrop, h('div', { class: 'shell__body' }, topbar, main)));
  root.classList.remove('app-boot');
  root.removeAttribute('aria-busy');

  return {
    main,
    setTitle(text) {
      title.textContent = text;
      document.title = `${text} · Vision Homecare`;
    },
    setActive(path) {
      links.forEach((a, itemPath) => {
        if (path === itemPath || path.startsWith(itemPath + '/')) a.setAttribute('aria-current', 'page');
        else a.removeAttribute('aria-current');
      });
    },
  };
}

const ROLE_NAMES = {
  ADMIN: 'Administration', MEDECIN: 'Médecin', INFIRMIER: 'Infirmier·ère', TECHNICIEN: 'Technicien·ne', ACCUEIL: 'Accueil',
};

function roleName(role) {
  const code = typeof role === 'string' ? role : (role && role.code) || '';
  return ROLE_NAMES[code] || code;
}
