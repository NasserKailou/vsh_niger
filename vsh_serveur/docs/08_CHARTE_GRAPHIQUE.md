# Charte graphique et design system

Aperçu visuel : [charte publiée](https://claude.ai/artifact/4rWXAHdpYrPmN2GLn8T6nv) (page privée). Jetons web : `public/assets/css/vsh.css`. Logo : `public/assets/img/logo-vsh.jpg`.

Direction retenue : style **suisse / minimaliste médical**, dérivé du logo. L'**anneau orange** entoure (repère, accent) et les **mains vertes** protègent (soin, action principale).

## 1. Principes

| Principe | Traduction concrète |
|---|---|
| Rassurer | Fonds clairs, vert de soin, formes douces, aucune couleur criarde. L'orange guide, il n'alarme pas |
| Lisible d'abord | Texte de 16 px minimum, contrastes AA vérifiés, chiffres alignés (constantes, montants, n° de dossier) |
| Pensé pour le terrain | Plein soleil, réseau faible, gants : cibles de 48 dp, une action principale en bas d'écran, synchronisation toujours visible |
| Discret par nature | Aucune donnée médicale sur la carte, dans les notifications ni dans les SMS. Un statut s'affiche toujours en texte |

## 2. Couleurs

Les couleurs exactes du logo n'atteignent que 3,6:1 sur fond blanc. Elles restent donc réservées à la **marque** : logo, icônes, grands aplats, anneau décoratif. Pour le texte et les boutons, on utilise des nuances plus profondes de la même teinte.

| Jeton | Clair | Sombre | Usage | Contraste (clair) |
|---|---|---|---|---|
| `brand-green` | #1E9A3C | — | Marque uniquement | 3,7:1 |
| `brand-orange` | #E8572A | — | Marque uniquement | 3,6:1 |
| `primary` | #15803D | #3FBF66 | Action principale, liens, navigation active | 5,0:1 (texte blanc) |
| `primary-soft` | #EFF8F2 | #16301F | Fonds de sélection | 4,6:1 avec `primary` |
| `accent` | #B93A12 | #F2825A | Accent à petite dose : demande de visite, compteurs | 5,7:1 |
| `bg` / `surface` / `surface-2` | #F5F8F6 / #FFFFFF / #EEF3F0 | #0D1411 / #141D18 / #1B2621 | Fond, cartes, zones secondaires | — |
| `border` / `border-strong` | #DCE4DF / #C2CEC7 | #26332C / #33443B | Séparateurs, champs | — |
| `text` / `text-2` / `text-3` | #14201A / #4B5B53 / #647269 | #E6EEE9 / #AEBDB5 / #8D9C94 | Texte, secondaire, métadonnées | 15,7 / 7,2 / 4,7 |

**Statuts (cahier des charges §8)** — toujours sous forme de pastille, libellé et couleur :

| Statut | Jeton | Clair | Sombre |
|---|---|---|---|
| Terminée / facturée | `success` | #15803D sur #EFF8F2 | #4CC775 |
| En cours (prise en charge, en route, sur place, en cours) | `info` | #1D4ED8 sur #E8EEFD | #7BA2FF |
| En attente | `warning` | #A64B06 sur #FEF1DF (distinct de l'orange de marque) | #F2A33A |
| Urgente / erreur | `danger` | #C62828 sur #FDECEC | #FF7B7B |
| Annulée / échec | `neutral` | #374151 sur #ECEFF1 | #B8C2CC |

Toutes les paires texte/fond ont été vérifiées ≥ 4,5:1 dans les deux thèmes. Le **thème clair** est le thème par défaut (usage en extérieur) ; le **thème sombre** sert aux gardes de nuit.

## 3. Typographie

| Rôle | Police | Taille / graisse |
|---|---|---|
| Titres, interface, chiffres clés | **Figtree** | 36 (chiffre clé) · 30 (page) · 24 (section) · 20 (carte) — 600 à 700 |
| Texte courant | **Noto Sans** | 16 (18 en lecture longue) — 400, interligne 1,55 |
| Tableaux, aides | Noto Sans | 14 — chiffres tabulaires |
| Libellés | Figtree | 12, capitales, espacement +0,06 em |

- Noto Sans couvre le français, et le haoussa et le zarma pour les traductions à venir.
- Les polices sont **auto-hébergées** : servies par la clinique pour le web, embarquées dans l'application. Aucune dépendance à Google Fonts en production, car le réseau est faible.

## 4. Espacements, formes, mouvement

- **Espacements** sur une base de 4 px : 4 · 8 · 12 · 16 · 20 · 24 · 32 · 40 · 48.
- **Rayons** : 6 px pour les champs et boutons, 10 px pour les cartes, 16 px pour les feuilles et modales, 999 px pour les pastilles.
- **Ombres** légères, réservées aux éléments qui flottent (menus, modales, maquettes).
- **Mouvement** : 150 ms pour les micro-interactions, 220 ms pour les panneaux. La préférence « réduire les animations » est respectée. Pas d'animation décorative.

## 5. Composants (web : `vsh.css`)

- **Boutons** `vsh-btn--primary | secondary | accent | danger | ghost`, 44 px de haut minimum (56 px en action principale mobile). Une seule action principale verte par écran.
- **Champs** `vsh-field` : libellé toujours visible (jamais seulement le texte indicatif), aide sous le champ, erreur sous le champ avec icône et formulation qui dit comment corriger.
- **Badges de statut** `vsh-badge--*` avec pastille.
- **Alertes** `vsh-alert--info | warning | danger` : les allergies s'affichent en alerte, au plus près de l'action concernée.
- **Tableaux** `vsh-table` : en-tête en capitales, chiffres tabulaires, défilement horizontal limité au tableau.
- **Indicateur de synchronisation** `vsh-sync--ok | busy | offline` : l'anneau du logo, fixe, tournant ou rouge.
- **Gabarit** `vsh-app` : barre latérale de 264 px et barre supérieure de 64 px. En dessous de 1 024 px, la barre latérale devient un tiroir.

## 6. Application mobile (Flutter, étape 10)

- **Thème** : `ThemeData` Material 3 alimenté par les jetons ci-dessus. `colorScheme.primary` = `primary`, `secondary` = `accent`, `surface`/`background` = `surface`/`bg`, `error` = `danger`, avec les mêmes valeurs en sombre.
- **Mise en page** : une barre supérieure qui affiche la pastille de synchronisation, une navigation du bas à 4 entrées au plus, et l'**action principale en bas d'écran**, à portée de pouce.
- **Cibles tactiles** : 48 dp minimum, 8 dp entre deux cibles. Le clavier numérique s'ouvre pour les constantes. Les étapes des visites s'affichent en frise verticale.
- **Hors ligne** : un bandeau discret qui rassure (« Vos informations restent consultables… »). Aucune action autorisée hors ligne n'est bloquée.
- **Icônes** : Lucide, ou leur équivalent Material Symbols Rounded, au trait, 24 dp, toujours accompagnées d'un libellé ou d'une description d'accessibilité.

## 7. Carte

- **Fond** : OpenStreetMap (Leaflet sur le web, flutter_map sur mobile).
- **Marqueurs** : épingles colorées selon le statut, avec la même légende partout, et regroupement des points proches.
- **Info-bulle** : patient, n° de dossier, type, équipe, statut, date et heure. **Jamais** de motif médical ni de diagnostic.

## 8. Logo

- Sur fond blanc uniquement, entouré d'une marge d'un quart de son diamètre.
- Taille minimale : 32 px sur mobile, 40 px sur le web.
- Ne jamais le recolorer, l'étirer ni le détourer.
- Pour l'icône de l'application et le favicon, une version vectorielle (SVG) du logo est nécessaire : **à fournir par la clinique** ou à redessiner.

## 9. À éviter

- Du texte dans le vert ou l'orange exacts du logo.
- Un statut signalé par la couleur seule.
- Des émojis à la place d'icônes, des dégradés décoratifs, des animations longues.
- Des boutons de moins de 44 px.
- Des couleurs en dur dans les composants.
