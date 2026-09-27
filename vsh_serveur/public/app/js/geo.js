/**
 * Géolocalisation côté navigateur : acquisition précise, distances, liens de navigation et partage de
 * position en direct pendant une visite. La position n'est lue qu'à la demande de l'utilisateur ou pendant
 * un partage qu'il voit et peut arrêter à tout moment ; le serveur revérifie chaque point reçu.
 */
import { h } from './core/dom.js';
import { icon } from './core/icons.js';
import { api } from './core/api.js';
import { session } from './core/session.js';
import { formatTime } from './core/format.js';

export class GeoError extends Error {
  constructor(code, message) {
    super(message);
    this.code = code;
  }
}

const MESSAGES = {
  UNSUPPORTED: 'Cet appareil ou ce navigateur ne fournit pas de position.',
  INSECURE: 'La position n’est disponible que sur une connexion sécurisée (HTTPS).',
  DENIED: 'Accès à la position refusé. Autorisez la localisation pour ce site dans les réglages du navigateur.',
  UNAVAILABLE: 'Position introuvable pour le moment. Activez le GPS et placez-vous à découvert.',
  TIMEOUT: 'Le GPS n’a pas répondu à temps. Réessayez à découvert.',
};

function geoError(code) {
  return new GeoError(code, MESSAGES[code]);
}

function toPosition(raw) {
  return {
    latitude: Number(raw.coords.latitude.toFixed(7)),
    longitude: Number(raw.coords.longitude.toFixed(7)),
    accuracy_m: Math.round(raw.coords.accuracy),
    captured_at: new Date(raw.timestamp || Date.now()).toISOString(),
  };
}

function precheck() {
  if (!window.isSecureContext) throw geoError('INSECURE');
  if (!navigator.geolocation) throw geoError('UNSUPPORTED');
}

function mapError(error) {
  if (!error) return geoError('UNAVAILABLE');
  if (error.code === 1) return geoError('DENIED');
  if (error.code === 3) return geoError('TIMEOUT');
  return geoError('UNAVAILABLE');
}

/** État de l'autorisation, sans déclencher de demande : 'granted', 'denied', 'prompt' ou 'unknown'. */
export async function permissionState() {
  try {
    const status = await navigator.permissions.query({ name: 'geolocation' });
    return status.state;
  } catch (e) {
    return 'unknown';
  }
}

/**
 * Meilleure position obtenue en `maxWait` ms : le premier relevé d'un GPS est souvent grossier (antenne
 * relais, Wi-Fi) ; on garde le plus précis jusqu'à atteindre `wanted` mètres.
 * @param {{wanted?: number, maxWait?: number, onProgress?: Function, signal?: AbortSignal}} options
 * @returns {Promise<{latitude:number, longitude:number, accuracy_m:number, captured_at:string}>}
 */
export function bestPosition({ wanted = 25, maxWait = 15000, onProgress, signal } = {}) {
  return new Promise((resolve, reject) => {
    try {
      precheck();
    } catch (error) {
      reject(error);
      return;
    }
    let best = null;
    let watchId = null;
    let done = false;
    let timer = null;
    const finish = (error) => {
      if (done) return;
      done = true;
      clearTimeout(timer);
      if (watchId !== null) navigator.geolocation.clearWatch(watchId);
      if (best) resolve(best);
      else reject(error || geoError('TIMEOUT'));
    };
    timer = setTimeout(() => finish(), maxWait);
    if (signal) {
      signal.addEventListener('abort', () => {
        best = null;
        finish(new GeoError('ABORTED', 'Recherche annulée.'));
      });
    }
    watchId = navigator.geolocation.watchPosition((raw) => {
      const position = toPosition(raw);
      if (position.latitude === 0 && position.longitude === 0) return;
      if (!best || position.accuracy_m < best.accuracy_m) {
        best = position;
        if (onProgress) onProgress(best);
      }
      if (best.accuracy_m <= wanted) finish();
    }, (error) => {
      // Refus : inutile d'attendre. Autres erreurs : on garde la meilleure position déjà obtenue.
      if (error.code === 1 || !best) finish(mapError(error));
    }, { enableHighAccuracy: true, timeout: maxWait, maximumAge: 0 });
  });
}

// ---------------------------------------------------------------- Mesures et affichage

/** Distance à vol d'oiseau en mètres (haversine), identique au calcul du serveur. */
export function distance(a, b) {
  const rad = (d) => (d * Math.PI) / 180;
  const dLat = rad(b.latitude - a.latitude);
  const dLng = rad(b.longitude - a.longitude);
  const x = Math.sin(dLat / 2) ** 2 + Math.cos(rad(a.latitude)) * Math.cos(rad(b.latitude)) * Math.sin(dLng / 2) ** 2;
  return 2 * 6371008.8 * Math.asin(Math.min(1, Math.sqrt(x)));
}

const numberFr = (value, digits) => new Intl.NumberFormat('fr-FR', { maximumFractionDigits: digits, minimumFractionDigits: digits }).format(value);

export function formatDistance(meters) {
  if (meters === null || meters === undefined) return '—';
  if (meters < 100) return `${Math.round(meters)} m`;
  if (meters < 1000) return `${Math.round(meters / 10) * 10} m`;
  return `${numberFr(meters / 1000, meters < 10000 ? 1 : 0)} km`;
}

export function formatAccuracy(meters) {
  return meters === null || meters === undefined ? 'précision inconnue' : `± ${Math.round(meters)} m`;
}

/** Qualité d'une position selon sa précision et le seuil configuré (geo.low_accuracy_m). */
export function accuracyQuality(meters, low = 100) {
  if (meters === null || meters === undefined) return { tone: 'neutral', label: 'Précision inconnue' };
  if (meters <= 20) return { tone: 'success', label: 'Précision excellente' };
  if (meters <= 50) return { tone: 'success', label: 'Bonne précision' };
  if (meters <= low) return { tone: 'warning', label: 'Précision moyenne' };
  return { tone: 'danger', label: 'Position imprécise' };
}

export function formatCoords(p) {
  return `${Number(p.latitude).toFixed(6)}, ${Number(p.longitude).toFixed(6)}`;
}

/** « il y a 3 min » (positions d'équipe). */
export function ago(iso) {
  const minutes = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 60000));
  if (minutes < 1) return 'à l’instant';
  if (minutes < 60) return `il y a ${minutes} min`;
  if (minutes < 1440) return `il y a ${Math.floor(minutes / 60)} h ${String(minutes % 60).padStart(2, '0')}`;
  return `le ${formatTime(iso)}`;
}

/** Liens de navigation : aucune donnée patient, seulement des coordonnées ouvertes à la demande. */
export const links = {
  osm: (p, zoom = 18) => `https://www.openstreetmap.org/?mlat=${p.latitude}&mlon=${p.longitude}#map=${zoom}/${p.latitude}/${p.longitude}`,
  directions: (to, from = null) => `https://www.openstreetmap.org/directions?engine=fossgis_osrm_car&route=${from ? `${from.latitude}%2C${from.longitude}` : ''}%3B${to.latitude}%2C${to.longitude}`,
  // Ouvre l'application de navigation du téléphone (Organic Maps, Google Maps, OsmAnd…).
  native: (p) => `geo:${p.latitude},${p.longitude}?q=${p.latitude},${p.longitude}`,
};

export const isTouchDevice = () => window.matchMedia('(pointer: coarse)').matches;

/** Analyse « 13.5137, 2.1098 » ou « 13,5137 2,1098 » ; renvoie null si invalide. */
export function parseCoords(text) {
  const normalized = String(text).trim();
  let parts = normalized.match(/-?\d+(?:\.\d+)?/g);
  if (!parts || parts.length !== 2) {
    // Virgule décimale française : « 13,5137 2,1098 ».
    parts = normalized.split(/[\s;]+/).filter(Boolean).map((part) => part.replace(',', '.'));
  }
  if (!parts || parts.length !== 2) return null;
  const [latitude, longitude] = parts.map(Number);
  if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) return null;
  if (!(Math.abs(latitude) <= 90 && Math.abs(longitude) <= 180) || (latitude === 0 && longitude === 0)) return null;
  return { latitude, longitude };
}

// ---------------------------------------------------------------- Partage de position pendant une visite

const STORE_KEY = 'vsh.geo.sharing';
const MAX_BUFFER = 500;
const MAX_BATCH = 100;

/**
 * Partage en direct (un seul à la fois) : suivi GPS du navigateur, envoi groupé toutes les
 * `interval` secondes, points conservés en cas de coupure réseau puis renvoyés (le serveur dédoublonne).
 * Un indicateur permanent rappelle que la position est partagée et permet d'arrêter.
 */
export const tracker = (() => {
  let state = { active: false, visitId: null, label: '', interval: 30, sent: 0, pending: 0, last: null, error: null, offline: false };
  let buffer = [];
  let watchId = null;
  let timer = null;
  let wakeLock = null;
  let pill = null;
  let lastQueued = null;
  const listeners = new Set();

  function emit() {
    state = { ...state, pending: buffer.length };
    renderPill();
    listeners.forEach((listener) => listener(state));
  }

  function persist() {
    try {
      if (state.active) window.sessionStorage.setItem(STORE_KEY, JSON.stringify({ visitId: state.visitId, label: state.label, interval: state.interval, buffer }));
      else window.sessionStorage.removeItem(STORE_KEY);
    } catch (e) {
      /* stockage indisponible : le partage continue en mémoire */
    }
  }

  function queue(position) {
    // Point trop proche du précédent et trop récent : ignoré (données mobiles et batterie).
    if (lastQueued) {
      const moved = distance(lastQueued, position);
      const seconds = (new Date(position.captured_at) - new Date(lastQueued.captured_at)) / 1000;
      if (moved < 15 && seconds < state.interval * 2) return;
    }
    lastQueued = position;
    buffer.push(position);
    if (buffer.length > MAX_BUFFER) buffer = buffer.slice(-MAX_BUFFER);
    state.last = position;
    persist();
    emit();
  }

  async function flush() {
    if (!state.active || !buffer.length) return;
    const batch = buffer.slice(0, MAX_BATCH);
    try {
      await api.post(`homecare/${state.visitId}/track`, { points: batch });
      buffer = buffer.slice(batch.length);
      state.sent += batch.length;
      state.offline = false;
      state.error = null;
      persist();
      emit();
    } catch (error) {
      if (error.status === 0) {
        state.offline = true;
        emit();
      } else if ([403, 404, 409].includes(error.status)) {
        // Visite terminée, réaffectée ou inaccessible : le partage n'a plus lieu d'être.
        stop(error.message);
      } else if (error.status === 422) {
        buffer = buffer.slice(batch.length);
        persist();
        emit();
      }
    }
  }

  async function keepAwake() {
    try {
      if ('wakeLock' in navigator && document.visibilityState === 'visible' && !wakeLock) {
        wakeLock = await navigator.wakeLock.request('screen');
        wakeLock.addEventListener('release', () => { wakeLock = null; });
      }
    } catch (e) {
      wakeLock = null;
    }
  }

  function onVisibility() {
    if (state.active && document.visibilityState === 'visible') {
      keepAwake();
      flush();
    }
  }

  function start({ visitId, label, interval = 30, restoredBuffer = [] }) {
    if (state.active && state.visitId === visitId) return;
    if (state.active) stop();
    precheck();
    buffer = restoredBuffer.slice(-MAX_BUFFER);
    lastQueued = null;
    state = { active: true, visitId, label, interval: Math.max(10, interval), sent: 0, pending: buffer.length, last: null, error: null, offline: false };
    watchId = navigator.geolocation.watchPosition((raw) => {
      const position = toPosition(raw);
      if (position.latitude === 0 && position.longitude === 0) return;
      state.error = null;
      queue(position);
    }, (error) => {
      state.error = mapError(error).message;
      if (error.code === 1) stop(state.error);
      else emit();
    }, { enableHighAccuracy: true, maximumAge: 5000, timeout: 60000 });
    timer = setInterval(flush, state.interval * 1000);
    document.addEventListener('visibilitychange', onVisibility);
    window.addEventListener('online', flush);
    keepAwake();
    persist();
    emit();
  }

  /**
   * @param {string|null} reason Motif affiché (erreur, visite close) ; null = arrêt volontaire.
   * @param {{sendRest?: boolean}} options sendRest : envoyer les derniers points (inutile si déjà envoyés).
   */
  function stop(reason = null, { sendRest = true } = {}) {
    if (!state.active) return;
    const visitId = state.visitId;
    const rest = buffer.slice();
    if (watchId !== null) navigator.geolocation.clearWatch(watchId);
    clearInterval(timer);
    document.removeEventListener('visibilitychange', onVisibility);
    window.removeEventListener('online', flush);
    if (wakeLock) wakeLock.release().catch(() => {});
    watchId = null;
    timer = null;
    wakeLock = null;
    // Derniers points : envoi au mieux, sans bloquer l'arrêt.
    if (rest.length && !reason && sendRest) api.post(`homecare/${visitId}/track`, { points: rest.slice(0, MAX_BATCH) }).catch(() => {});
    buffer = [];
    state = { ...state, active: false, error: reason };
    persist();
    emit();
  }

  /** Reprise après rechargement de la page, pour la même visite, si l'autorisation est déjà accordée. */
  async function resume(visitId) {
    if (state.active) return state.visitId === visitId;
    let saved = null;
    try {
      saved = JSON.parse(window.sessionStorage.getItem(STORE_KEY) || 'null');
    } catch (e) {
      saved = null;
    }
    if (!saved || saved.visitId !== visitId || (await permissionState()) !== 'granted') return false;
    start({ visitId, label: saved.label, interval: saved.interval, restoredBuffer: saved.buffer || [] });
    return true;
  }

  function renderPill() {
    if (!state.active) {
      if (pill) pill.remove();
      pill = null;
      return;
    }
    if (!pill) {
      pill = h('div', { class: 'share-pill', role: 'status' });
      document.body.append(pill);
    }
    let detail = 'recherche du signal GPS…';
    if (state.offline) detail = `hors connexion · ${state.pending} point(s) en attente`;
    else if (state.last) detail = `dernier point ${formatTime(state.last.captured_at)} · ${formatAccuracy(state.last.accuracy_m)}`;
    const stopButton = h('button', { type: 'button', class: 'share-pill__stop' }, icon('pause', { size: 16 }), 'Arrêter');
    stopButton.addEventListener('click', () => stop());
    pill.replaceChildren(
      h('span', { class: `share-pill__dot${state.offline ? ' share-pill__dot--offline' : ''}`, 'aria-hidden': 'true' }),
      h('a', { class: 'share-pill__text', href: `#/homecare/${state.visitId}` }, h('strong', {}, 'Position partagée'), h('small', {}, `${state.label} · ${detail}`)),
      stopButton);
  }

  session.onChange((event) => {
    if (event === 'logout' || event === 'expired') stop('Session terminée.');
  });

  return {
    start,
    stop,
    resume,
    flush,
    get state() {
      return state;
    },
    onChange(listener) {
      listeners.add(listener);
      return () => listeners.delete(listener);
    },
  };
})();
