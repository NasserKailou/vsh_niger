/**
 * Choix d'une position (domicile d'un patient, lieu d'une visite) : GPS de l'appareil avec affinage,
 * clic ou glisser sur la carte, ou saisie des coordonnées (alternative au glisser pour le clavier).
 * Retourne {el, value, set, setError, id}, compatible avec formDialog / applyErrors.
 */
import { h, mount, uid } from './core/dom.js';
import { icon } from './core/icons.js';
import { button, setBusy } from './ui.js';
import { createMap, pinIcon, NIAMEY } from './map.js';
import { bestPosition, accuracyQuality, formatAccuracy, formatCoords, parseCoords } from './geo.js';

const SOURCES = { device: 'Relevée par le GPS de l’appareil', map: 'Placée sur la carte', typed: 'Coordonnées saisies', record: 'Position enregistrée' };
const round7 = (value) => Number(value.toFixed(7));

/**
 * @param {{label?: string, hint?: string, value?: object|null, low?: number, onChange?: Function}} options
 */
export function locationPicker({ label = 'Position GPS', hint, value: initial = null, low = 100, onChange } = {}) {
  const id = uid('loc');
  // « initial » et non « value » : la fonction value() déclarée plus bas masquerait le paramètre.
  let current = initial && initial.latitude !== null && initial.latitude !== undefined ? { ...initial, source: initial.source || 'record' } : null;
  let map = null;
  let L = null;
  let marker = null;
  let circle = null;

  const status = h('div', { class: 'locpick__status', role: 'status', 'aria-live': 'polite' });
  const error = h('p', { class: 'vsh-field__error', id: `${id}-error`, hidden: true });
  const frame = h('div', {
    class: 'map-frame map-frame--picker', role: 'region',
    'aria-label': `${label} : cliquez sur la carte pour placer le point, puis faites glisser l’épingle pour l’ajuster.`,
  });
  const locate = button({ text: 'Ma position actuelle', iconName: 'locate', variant: 'secondary', onClick: useDevice });
  locate.id = id;
  const clear = button({ text: 'Effacer', iconName: 'x', variant: 'ghost', onClick: () => update(null, false) });
  const coordsId = `${id}-coords`;
  const coordsInput = h('input', {
    id: coordsId, class: 'vsh-input', inputmode: 'decimal', autocomplete: 'off', spellcheck: 'false',
    placeholder: '13.513700, 2.109800', 'aria-describedby': `${coordsId}-hint`,
  });
  coordsInput.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
      event.preventDefault();
      applyTyped();
    }
  });
  const manual = h('details', { class: 'locpick__manual' },
    h('summary', {}, 'Saisir des coordonnées'),
    h('div', { class: 'stack stack--sm' },
      h('label', { class: 'vsh-field__label', for: coordsId }, 'Latitude, longitude'),
      h('div', { class: 'locpick__typed' }, coordsInput, button({ text: 'Placer', variant: 'secondary', onClick: applyTyped })),
      h('p', { class: 'vsh-field__hint', id: `${coordsId}-hint` }, 'Degrés décimaux, par exemple 13.5137, 2.1098 (copiés depuis une application de cartes).')));

  const el = h('fieldset', { class: 'vsh-field locpick' },
    h('legend', { class: 'vsh-field__label' }, label),
    hint ? h('p', { class: 'vsh-field__hint' }, hint) : null,
    frame,
    h('div', { class: 'row' }, locate, clear),
    status,
    manual,
    error);

  // Carte créée quand le champ devient visible (boîte de dialogue, onglet) : Leaflet a besoin de dimensions.
  let started = false;
  const observer = new ResizeObserver(() => {
    if (!started && frame.clientWidth > 0) {
      started = true;
      observer.disconnect();
      init();
    }
  });
  observer.observe(frame);

  async function init() {
    try {
      ({ L, map } = await createMap(frame, {
        center: current ? [current.latitude, current.longitude] : NIAMEY,
        zoom: current ? 17 : 12,
      }));
    } catch (e) {
      mount(frame, h('p', { class: 'locpick__fallback' }, icon('wifiOff'), 'Carte indisponible : utilisez « Ma position actuelle » ou saisissez les coordonnées.'));
      return;
    }
    map.on('click', (event) => update({
      latitude: round7(event.latlng.lat), longitude: round7(event.latlng.lng), accuracy_m: null,
      captured_at: new Date().toISOString(), source: 'map',
    }, false));
    draw(false);
  }

  function draw(recenter) {
    if (!map) return;
    if (!current) {
      if (marker) marker.remove();
      if (circle) circle.remove();
      marker = null;
      circle = null;
      return;
    }
    const point = [current.latitude, current.longitude];
    if (!marker) {
      marker = L.marker(point, { icon: pinIcon(L, 'accent'), draggable: true, keyboard: false, title: 'Position choisie' }).addTo(map);
      marker.on('dragend', () => {
        const p = marker.getLatLng();
        update({ latitude: round7(p.lat), longitude: round7(p.lng), accuracy_m: null, captured_at: new Date().toISOString(), source: 'map' }, false);
      });
    } else {
      marker.setLatLng(point);
    }
    if (circle) circle.remove();
    circle = current.accuracy_m ? L.circle(point, { radius: current.accuracy_m, weight: 1, opacity: 0.6, fillOpacity: 0.12, interactive: false }).addTo(map) : null;
    if (recenter) map.setView(point, Math.max(map.getZoom(), 17));
  }

  function renderStatus() {
    if (!current) {
      status.replaceChildren(h('span', { class: 'vsh-muted' }, 'Aucune position. Placez le point sur la carte, utilisez « Ma position actuelle » ou saisissez des coordonnées.'));
      return;
    }
    const known = current.accuracy_m !== null && current.accuracy_m !== undefined;
    const quality = known ? accuracyQuality(current.accuracy_m, low) : null;
    mount(status,
      h('span', { class: 'locpick__coords mono' }, icon('mapPin', { size: 16 }), formatCoords(current)),
      quality ? h('span', { class: `vsh-badge vsh-badge--${quality.tone}` }, `${quality.label} (${formatAccuracy(current.accuracy_m)})`) : null,
      h('small', { class: 'vsh-muted' }, SOURCES[current.source] || SOURCES.record));
  }

  async function useDevice() {
    setError(null);
    setBusy(locate, true);
    status.replaceChildren(h('span', {}, 'Recherche de la position… restez à découvert quelques secondes.'));
    try {
      const position = await bestPosition({
        wanted: 20,
        maxWait: 15000,
        onProgress: (p) => status.replaceChildren(h('span', {}, `Affinage de la position… ${formatAccuracy(p.accuracy_m)}`)),
      });
      update({ ...position, source: 'device' }, true);
      if (position.accuracy_m > low) setError(`Position imprécise (${formatAccuracy(position.accuracy_m)}). Ajustez l’épingle sur la carte si possible.`);
    } catch (e) {
      renderStatus();
      setError(e.message);
    } finally {
      setBusy(locate, false);
    }
  }

  function applyTyped() {
    const parsed = parseCoords(coordsInput.value);
    if (!parsed) {
      setError('Coordonnées invalides : indiquez « latitude, longitude » en degrés décimaux, par exemple 13.5137, 2.1098.');
      coordsInput.focus();
      return;
    }
    update({ ...parsed, accuracy_m: null, captured_at: new Date().toISOString(), source: 'typed' }, true);
  }

  function update(next, recenter) {
    current = next;
    setError(null);
    draw(recenter);
    renderStatus();
    if (onChange) onChange(value());
  }

  function setError(message) {
    error.hidden = !message;
    error.replaceChildren(...(message ? [icon('alert', { size: 16 }), message] : []));
  }

  /** Valeur au format de l'API : {latitude, longitude, accuracy_m, captured_at} ou null. */
  function value() {
    if (!current) return null;
    return {
      latitude: current.latitude,
      longitude: current.longitude,
      accuracy_m: current.accuracy_m === undefined ? null : current.accuracy_m,
      captured_at: current.captured_at || null,
    };
  }

  renderStatus();
  return {
    el,
    id,
    value,
    set: (next) => update(next ? { ...next, source: next.source || 'record' } : null, true),
    setError,
  };
}
