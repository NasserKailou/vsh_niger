/**
 * Cartes (Leaflet 1.9.4 auto-hébergé, fond OpenStreetMap). Chargé à la demande.
 * Tuiles OSM : attribution affichée, en-tête Referer limité à l'origine (politique d'usage OSM),
 * aucune donnée patient dans les URL de tuiles. En production à fort trafic, remplacer TILE_URL
 * par un fournisseur de tuiles dédié (et l'autoriser dans la CSP de public/app/.htaccess).
 */
import { statusInfo } from './core/format.js';

export const TILE_URL = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
// Texte constant (jamais de donnée) : Leaflet l'insère tel quel dans le cartouche d'attribution.
export const ATTRIBUTION = '&copy; <a href="https://www.openstreetmap.org/copyright" rel="noopener">OpenStreetMap</a>';
export const NIAMEY = [13.5116, 2.1254];

let loading = null;

/** Charge Leaflet (script et feuille de style locaux) une seule fois. */
export function loadLeaflet() {
  if (window.L) return Promise.resolve(window.L);
  if (!loading) {
    loading = new Promise((resolve, reject) => {
      const css = document.createElement('link');
      css.rel = 'stylesheet';
      css.href = '../assets/vendor/leaflet/leaflet.css';
      document.head.append(css);
      const script = document.createElement('script');
      script.src = '../assets/vendor/leaflet/leaflet.js';
      script.onload = () => resolve(window.L);
      script.onerror = () => {
        loading = null;
        reject(new Error('La carte n’a pas pu être chargée.'));
      };
      document.head.append(script);
    });
  }
  return loading;
}

/** Couleur d'un jeton de la charte (suit le thème clair ou sombre). */
export function token(name) {
  return getComputedStyle(document.documentElement).getPropertyValue(`--vsh-${name}`).trim();
}

export function statusColor(domain, status) {
  const { tone } = statusInfo(domain, status);
  return token(tone) || token('primary');
}

/**
 * @param {HTMLElement} element
 * @param {{center?: number[], zoom?: number}} options
 */
export async function createMap(element, { center = NIAMEY, zoom = 12 } = {}) {
  const L = await loadLeaflet();
  const map = L.map(element, { center, zoom, scrollWheelZoom: true });
  L.tileLayer(TILE_URL, {
    maxZoom: 19,
    attribution: ATTRIBUTION,
    referrerPolicy: 'strict-origin-when-cross-origin',
  }).addTo(map);
  // Le conteneur peut changer de taille (onglets, tiroir, rotation) : Leaflet doit recalculer.
  new ResizeObserver(() => map.invalidateSize()).observe(element);
  return { L, map };
}

// ---------------------------------------------------------------- Marqueurs et commandes

/**
 * Épingle (domicile) dessinée en CSS : le HTML passé à Leaflet est une constante, jamais une donnée.
 * @param {'primary'|'accent'|'warning'|'info'|'danger'|'neutral'|'success'} tone
 */
export function pinIcon(L, tone = 'primary') {
  return L.divIcon({
    className: `map-pin map-pin--${tone}`,
    html: '<span class="map-pin__head"></span>',
    iconSize: [28, 36],
    iconAnchor: [14, 34],
    popupAnchor: [0, -30],
    tooltipAnchor: [12, -22],
  });
}

/** Point rond (équipe, départ, arrivée, ma position). */
export function dotIcon(L, variant) {
  return L.divIcon({ className: `map-dot map-dot--${variant}`, html: '<span></span>', iconSize: [20, 20], iconAnchor: [10, 10], tooltipAnchor: [10, 0] });
}

/**
 * Boutons de carte accessibles (libellé, focus clavier) regroupés dans une barre Leaflet.
 * @param {{icon: SVGElement, label: string, onClick: Function}[]} buttons
 * @returns {HTMLButtonElement[]}
 */
export function mapButtons(L, map, buttons, position = 'topright') {
  const elements = [];
  const Control = L.Control.extend({
    onAdd() {
      const bar = document.createElement('div');
      bar.className = 'leaflet-bar map-tools';
      for (const item of buttons) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'map-tools__btn';
        btn.setAttribute('aria-label', item.label);
        btn.title = item.label;
        btn.append(item.icon);
        btn.addEventListener('click', (event) => {
          event.preventDefault();
          item.onClick(btn);
        });
        bar.append(btn);
        elements.push(btn);
      }
      L.DomEvent.disableClickPropagation(bar);
      L.DomEvent.disableScrollPropagation(bar);
      return bar;
    },
  });
  new Control({ position }).addTo(map);
  return elements;
}

/**
 * Plein écran par classe CSS (fonctionne aussi sur iPhone, sans API Fullscreen). Échap pour sortir.
 * @returns {boolean} nouvel état
 */
export function toggleFullscreen(frame, map, button) {
  const full = !frame.classList.contains('map-frame--full');
  frame.classList.toggle('map-frame--full', full);
  document.body.classList.toggle('map-fullscreen', full);
  if (button) {
    button.setAttribute('aria-pressed', String(full));
    button.setAttribute('aria-label', full ? 'Quitter le plein écran' : 'Plein écran');
    button.title = button.getAttribute('aria-label');
  }
  if (full) {
    const onKey = (event) => {
      if (event.key === 'Escape') toggleFullscreen(frame, map, button);
    };
    escapeHandlers.set(frame, onKey);
    document.addEventListener('keydown', onKey);
  } else if (escapeHandlers.has(frame)) {
    document.removeEventListener('keydown', escapeHandlers.get(frame));
    escapeHandlers.delete(frame);
  }
  setTimeout(() => map.invalidateSize(), 50);
  return full;
}

const escapeHandlers = new WeakMap();
