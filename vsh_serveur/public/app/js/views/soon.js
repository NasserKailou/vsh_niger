/**
 * Écrans des prochains blocs de l'étape 9 : l'API correspondante est déjà en service ;
 * la page indique honnêtement ce qui arrive et quand.
 */
import { h, mount } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { findNavItem } from '../shell.js';
import { pageHead } from '../ui.js';

const PLANNED = {
  '/appointments': ['Agenda par service et par praticien', 'Créneaux libres calculés par le serveur', 'Confirmation, déplacement, annulation, arrivée du patient'],
  '/consultations': ['File des consultations du jour', 'Saisie : constantes, diagnostics, notes, soins', 'Clôture et addendums'],
  '/examinations': ['File du technicien', 'Saisie des résultats structurés', 'Validation par le médecin (D-005)'],
  '/homecare': ['File des demandes, urgences en tête', 'Affectation et réaffectation des équipes (D-003)', 'Suivi en temps réel et historique GPS'],
  '/map': ['Carte OpenStreetMap des visites en cours', 'Position des équipes, couleurs par statut', 'Aucune donnée médicale affichée'],
  '/billing': ['Brouillon généré depuis les soins et examens', 'Émission, annulation, règlement déclaré', 'Facture PDF'],
  '/admin/users': ['Comptes du personnel, rôles et permissions', 'Mots de passe provisoires, verrouillage'],
  '/admin/teams': ['Équipes mobiles et membres (appartenance datée)'],
  '/admin/reference': ['Services, actes, soins, examens, médicaments', 'Tarifs datés, modèles d’ordonnance'],
  '/admin/settings': ['Paramètres de la clinique (délais, préfixes, affectation D-003)'],
  '/admin/audit': ['Journal d’audit filtrable', 'Supervision de la synchronisation et des appareils'],
};

export function soonView({ main, setTitle, path }) {
  const item = findNavItem(path);
  const title = item ? item.label : 'Écran à venir';
  setTitle(title);
  mount(main,
    pageHead({ title }),
    h('section', { class: 'vsh-card' },
      h('div', { class: 'soon' },
        h('span', { class: 'soon__tag' }, `Étape ${item && item.soon ? item.soon : '9'} · en préparation`),
        h('p', {}, 'Le service correspondant est déjà en fonctionnement et testé côté serveur. Cet écran est livré dans le prochain bloc de l’interface web.'),
        h('ul', {}, (PLANNED[item ? item.path : path] || []).map((point) => h('li', {}, point))),
        h('a', { class: 'vsh-btn vsh-btn--secondary', href: '#/dashboard' }, icon('dashboard'), 'Retour au tableau de bord'))));
}
