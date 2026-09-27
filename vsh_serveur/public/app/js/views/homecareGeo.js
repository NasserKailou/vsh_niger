/**
 * Géolocalisation de la fiche de visite : relevé GPS visible aux étapes de terrain, affectation par
 * proximité, carte de suivi (domicile, trajet, dernière position de l'équipe, contrôle d'arrivée),
 * partage de position en direct et relevé du domicile sur place.
 */
import { h, mount, uid } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { api, ApiError } from '../core/api.js';
import { formatTime } from '../core/format.js';
import { card, button, toast, modal, setBusy, errorSummary, checkbox, field } from '../ui.js';
import { formDialog } from '../dialogs.js';
import { createMap, pinIcon, dotIcon, mapButtons, toggleFullscreen, token } from '../map.js';
import { bestPosition, tracker, distance, formatDistance, formatAccuracy, accuracyQuality, formatCoords, ago, links, isTouchDevice } from '../geo.js';
import { locationPicker } from '../locationPicker.js';

export const FIELD_STATUSES = ['EN_ROUTE', 'SUR_PLACE', 'EN_COURS'];

const DEFAULT_SETTINGS = { arrival_radius_m: 300, low_accuracy_m: 100, position_stale_minutes: 10, track_interval_seconds: 30 };

export function geoSettings(visit) {
  return { ...DEFAULT_SETTINGS, ...((visit.geo && visit.geo.settings) || {}) };
}

const shareLabel = (visit) => `Visite ${visit.patient && visit.patient.file_number ? visit.patient.file_number : ''}`.trim();

// ---------------------------------------------------------------- Étape de terrain avec relevé GPS

/**
 * Boîte de confirmation qui relève la position pendant que l'utilisateur lit : précision affichée en
 * direct, possibilité de réessayer ou de continuer sans position (le GPS ne doit jamais bloquer le soin).
 * @param {{title: string, text: string, confirmText: string, run: (position: object|null) => Promise}} options
 */
export function positionStep({ title, text, confirmText, run, wanted = 30 }) {
  const controller = new AbortController();
  const summary = h('div', { hidden: true });
  const state = h('div', { class: 'gps-acquire', role: 'status' });
  const confirm = button({ text: confirmText });
  const retry = button({ text: 'Réessayer', iconName: 'refresh', variant: 'ghost', onClick: () => acquire() });
  retry.hidden = true;
  let position = null;
  const box = modal({ title, content: h('div', { class: 'stack' }, summary, h('p', {}, text), state), size: 'sm', onClose: () => controller.abort() });
  box.footer.append(button({ text: 'Annuler', variant: 'secondary', onClick: () => box.close() }), retry, confirm);

  function show(kind, message, extra = null) {
    state.className = `gps-acquire gps-acquire--${kind}`;
    const iconName = kind === 'error' ? 'alert' : kind === 'ok' ? 'checkCircle' : 'locate';
    state.replaceChildren(icon(iconName, { size: 20 }), h('div', { class: 'stack stack--sm' }, h('span', {}, message), extra));
  }

  function acquire() {
    retry.hidden = true;
    position = null;
    setBusy(confirm, true);
    show('searching', 'Recherche de votre position…');
    bestPosition({
      wanted,
      maxWait: 12000,
      signal: controller.signal,
      onProgress: (p) => show('searching', `Affinage de la position… ${formatAccuracy(p.accuracy_m)}`),
    }).then((p) => {
      position = p;
      const quality = accuracyQuality(p.accuracy_m);
      show('ok', 'Position relevée.', h('span', { class: `vsh-badge vsh-badge--${quality.tone}` }, `${quality.label} (${formatAccuracy(p.accuracy_m)})`));
    }).catch((error) => {
      if (error.code === 'ABORTED') return;
      show('error', `${error.message} L’étape peut être enregistrée sans position.`);
      retry.hidden = false;
    }).finally(() => setBusy(confirm, false));
  }

  confirm.addEventListener('click', async () => {
    setBusy(confirm, true);
    try {
      await run(position);
      box.close();
    } catch (error) {
      errorSummary(summary, error.message || 'Action impossible.', []);
    } finally {
      setBusy(confirm, false);
    }
  });
  acquire();
}

// ---------------------------------------------------------------- Affectation par proximité

export function assignDialog(visit, onDone) {
  const groupId = uid('teams');
  const list = h('div', { class: 'team-options', role: 'radiogroup', 'aria-labelledby': `${groupId}-legend` },
    h('p', { class: 'vsh-muted' }, 'Chargement des équipes…'));
  const error = h('p', { class: 'vsh-field__error', hidden: true });
  const teamField = {
    id: groupId,
    el: h('fieldset', { class: 'vsh-field', id: groupId, tabindex: '-1' },
      h('legend', { class: 'vsh-field__label', id: `${groupId}-legend` }, 'Équipe mobile'),
      h('p', { class: 'vsh-field__hint' }, 'Les équipes en intervention sont classées par distance à vol d’oiseau entre leur dernière position et le domicile.'),
      list, error),
    setError(message) {
      error.hidden = !message;
      error.replaceChildren(...(message ? [icon('alert', { size: 16 }), message] : []));
    },
  };
  const comment = field({ label: 'Consigne (facultatif)', name: 'comment', attrs: { maxlength: '500' } });

  api.get(`homecare/${visit.id}/dispatch-options`).then(({ data }) => {
    if (!data.teams.length) {
      mount(list, h('p', { class: 'vsh-muted' }, 'Aucune équipe mobile active. Créez-en une dans Administration › Équipes.'));
      return;
    }
    let nearestMarked = false;
    mount(list, data.teams.map((team, index) => {
      const p = team.position;
      const usable = Boolean(p && !p.stale && p.distance_m !== null);
      const nearest = usable && !nearestMarked && !team.current;
      if (nearest) nearestMarked = true;
      const input = h('input', { type: 'radio', name: 'team_id', value: team.id, id: `${groupId}-${index}`, disabled: team.current });
      let where = 'Aucune position récente (pas de visite en cours)';
      if (p && p.distance_m !== null) where = `À ${formatDistance(p.distance_m)} du domicile · position ${ago(p.captured_at)}${p.stale ? ' (ancienne)' : ''}`;
      else if (p) where = `Position ${ago(p.captured_at)} (domicile non localisé)`;
      return h('label', { class: 'team-option', for: input.id }, input,
        h('span', { class: 'team-option__body' },
          h('span', { class: 'team-option__head' }, h('strong', {}, team.label),
            team.current ? h('span', { class: 'vsh-badge vsh-badge--neutral' }, 'Équipe actuelle') : null,
            nearest ? h('span', { class: 'vsh-badge vsh-badge--success' }, 'La plus proche') : null),
          h('small', {}, `${team.members} membre(s) · ${team.active_visits} visite(s) en cours`),
          h('small', { class: usable ? '' : 'vsh-muted' }, icon('mapPin', { size: 14 }), where)));
    }));
    if (!data.home_located) list.prepend(h('p', { class: 'audit-note' }, icon('info'), 'Le domicile n’est pas localisé : distances indisponibles.'));
  }).catch((e) => mount(list, h('p', { class: 'vsh-field__error' }, e.message)));

  formDialog({
    title: visit.team ? 'Réaffecter la visite' : 'Affecter une équipe',
    description: 'Les membres de l’équipe sont prévenus ; la visite apparaît sur leurs téléphones.',
    size: 'lg',
    fields: { team_id: teamField, comment },
    submitText: 'Affecter',
    submit: async () => {
      const chosen = list.querySelector('input[name="team_id"]:checked');
      if (!chosen) throw new ApiError(422, 'VALIDATION_ERROR', 'Choisissez une équipe.', { team_id: ['Choisissez une équipe.'] });
      await api.post(`homecare/${visit.id}/assign`, { team_id: chosen.value, comment: comment.input.value.trim() || null });
      toast('Équipe affectée.');
      onDone();
    },
  });
}

// ---------------------------------------------------------------- Relevé ou correction du domicile

export function homePositionDialog(visit, { onSite, onDone }) {
  const settings = geoSettings(visit);
  const picker = locationPicker({
    label: 'Position du domicile',
    low: settings.low_accuracy_m,
    value: visit.location,
    hint: onSite
      ? 'Placez-vous devant l’entrée du domicile, puis « Ma position actuelle ». Ajustez l’épingle si besoin.'
      : 'Placez l’épingle sur l’entrée du domicile, en vous repérant aux rues.',
  });
  const landmark = field({ label: 'Repère (facultatif)', name: 'landmark', value: visit.landmark || '', attrs: { maxlength: '255' } });
  const patientFile = checkbox({ label: 'Enregistrer aussi cette position dans le dossier du patient', name: 'update_patient_address', checked: true, hint: 'Les prochaines visites seront localisées d’emblée.' });
  formDialog({
    title: onSite ? 'Relever la position du domicile' : 'Corriger la position du domicile',
    description: 'La position est transmise aux équipes et tracée dans le journal d’audit.',
    size: 'lg',
    fields: { latitude: picker, landmark },
    extra: [patientFile],
    submitText: 'Enregistrer la position',
    submit: async () => {
      const value = picker.value();
      if (!value) throw new ApiError(422, 'VALIDATION_ERROR', 'Indiquez une position.', { latitude: ['Indiquez une position.'] });
      await api.post(`homecare/${visit.id}/home-location`, {
        latitude: value.latitude,
        longitude: value.longitude,
        accuracy_m: value.accuracy_m,
        captured_at: value.captured_at,
        landmark: landmark.input.value.trim() || null,
        update_patient_address: patientFile.input.checked,
      });
      toast('Position du domicile enregistrée.');
      onDone();
    },
  });
}

// ---------------------------------------------------------------- Alertes

export function geoAlerts(visit, { dispatch }) {
  const geo = visit.geo;
  if (!geo) return [];
  const settings = geoSettings(visit);
  const alerts = [];
  const alert = (tone, text) => h('div', { class: `vsh-alert vsh-alert--${tone}`, role: 'status' },
    icon(tone === 'info' ? 'info' : 'alert', { size: 20 }), h('span', {}, text));
  if (geo.arrival && geo.arrival.far) {
    alerts.push(alert('warning', `Arrivée enregistrée à ${formatDistance(geo.arrival.distance_m)} du domicile (${formatAccuracy(geo.arrival.accuracy_m)}), au-delà du rayon attendu de ${formatDistance(settings.arrival_radius_m)}. Vérifiez l’adresse, ou relevez la position exacte du domicile sur place.`));
  }
  if (geo.home_imprecise && visit.location) {
    alerts.push(alert('info', `La position du domicile est imprécise (${formatAccuracy(visit.location.accuracy_m)}). Fiez-vous au repère, et relevez la position sur place.`));
  }
  if (dispatch && geo.team_position && geo.team_position.stale && FIELD_STATUSES.includes(visit.status)) {
    alerts.push(alert('warning', `Dernière position de l’équipe ${ago(geo.team_position.captured_at)} : partage interrompu (réseau, batterie ou page fermée) ?`));
  }
  return alerts;
}

// ---------------------------------------------------------------- Partage en direct

export function sharingPanel(visit, { isCurrent }) {
  const settings = geoSettings(visit);
  const box = h('div', { class: 'share-panel' });
  let unsubscribe = () => {};
  function render(state) {
    if (!isCurrent()) {
      unsubscribe();
      return;
    }
    if (!window.isSecureContext) {
      mount(box, h('p', { class: 'vsh-muted' }, icon('lock'), 'Le partage de position exige une connexion sécurisée (HTTPS).'));
      return;
    }
    if (state.active && state.visitId === visit.id) {
      let detail = 'Recherche du signal GPS…';
      if (state.offline) detail = `Hors connexion : ${state.pending} point(s) seront envoyés au retour du réseau.`;
      else if (state.last) detail = `Dernier point à ${formatTime(state.last.captured_at)} (${formatAccuracy(state.last.accuracy_m)}) · ${state.sent} envoyé(s).`;
      mount(box,
        h('div', { class: 'share-panel__head' }, h('span', { class: 'share-pill__dot', 'aria-hidden': 'true' }), h('strong', {}, 'Votre position est partagée avec la régulation')),
        h('small', { class: 'vsh-muted' }, `Envoi toutes les ${settings.track_interval_seconds} s tant que l’application reste ouverte. ${detail}`),
        h('div', {}, button({ text: 'Arrêter le partage', iconName: 'pause', variant: 'secondary', onClick: () => tracker.stop() })));
      return;
    }
    mount(box,
      h('div', { class: 'share-panel__head' }, icon('radio', { size: 18 }), h('strong', {}, 'Partager ma position pendant la visite')),
      h('small', { class: 'vsh-muted' }, 'La régulation suit votre trajet et votre arrivée. Le partage s’arrête à la fin de la visite, ou quand vous le décidez.'),
      state.error && state.visitId === visit.id ? h('p', { class: 'vsh-field__error' }, icon('alert', { size: 16 }), state.error) : null,
      h('div', {}, button({
        text: 'Démarrer le partage', iconName: 'radio', variant: 'secondary',
        onClick: () => {
          try {
            tracker.start({ visitId: visit.id, label: shareLabel(visit), interval: settings.track_interval_seconds });
          } catch (e) {
            toast(e.message, { type: 'error' });
          }
        },
      })));
  }
  unsubscribe = tracker.onChange(render);
  render(tracker.state);
  return box;
}

export function startSharing(visit) {
  try {
    tracker.start({ visitId: visit.id, label: shareLabel(visit), interval: geoSettings(visit).track_interval_seconds });
  } catch (e) {
    /* GPS indisponible ou connexion non sécurisée : le panneau de partage l'explique */
  }
}

/** Envoie les points en attente : à appeler AVANT une action qui clôt la visite (le serveur refuserait ensuite). */
export async function flushSharing(visit) {
  if (tracker.state.active && tracker.state.visitId === visit.id) await tracker.flush();
}

export function stopSharing(visit) {
  if (tracker.state.active && tracker.state.visitId === visit.id) tracker.stop(null, { sendRest: false });
}

// ---------------------------------------------------------------- Carte de suivi

/**
 * @param {object} visit
 * @param {{canTrack: boolean, actions?: HTMLElement[], isCurrent: Function}} options
 */
export function trackingCard(visit, { canTrack, actions = [], isCurrent }) {
  const settings = geoSettings(visit);
  const home = visit.location;
  const stats = h('div', { class: 'geo-stats', hidden: true });
  const frame = h('div', { class: 'map-frame map-frame--detail', role: 'region', 'aria-label': 'Carte de suivi de la visite' });
  const body = h('div', { class: 'stack' },
    h('dl', { class: 'dl' },
      h('div', {}, h('dt', {}, 'Adresse'), h('dd', {}, visit.address_text || '—')),
      h('div', {}, h('dt', {}, 'Repère'), h('dd', {}, visit.landmark || '—'))),
    stats);

  if (home) {
    const quality = accuracyQuality(home.accuracy_m, settings.low_accuracy_m);
    const copy = button({
      text: 'Copier les coordonnées', iconName: 'copy', variant: 'ghost', size: 'sm',
      onClick: async () => {
        try {
          await navigator.clipboard.writeText(formatCoords(home));
          toast('Coordonnées copiées.');
        } catch (e) {
          toast(`Coordonnées : ${formatCoords(home)}`, { type: 'info' });
        }
      },
    });
    body.append(
      h('p', { class: 'locpick__status' },
        h('span', { class: 'locpick__coords mono' }, icon('mapPin', { size: 16 }), formatCoords(home)),
        h('span', { class: `vsh-badge vsh-badge--${quality.tone}` }, home.accuracy_m ? `${quality.label} (${formatAccuracy(home.accuracy_m)})` : 'Précision inconnue')),
      frame,
      h('div', { class: 'row' },
        h('a', { class: 'vsh-btn vsh-btn--primary vsh-btn--sm', href: links.directions(home), target: '_blank', rel: 'noopener noreferrer' }, icon('route'), 'Itinéraire'),
        isTouchDevice() ? h('a', { class: 'vsh-btn vsh-btn--secondary vsh-btn--sm', href: links.native(home) }, icon('navigation'), 'Application de navigation') : null,
        h('a', { class: 'vsh-btn vsh-btn--ghost vsh-btn--sm', href: links.osm(home), target: '_blank', rel: 'noopener noreferrer' }, icon('map'), 'OpenStreetMap'),
        copy));
  } else {
    body.append(h('p', { class: 'vsh-muted' }, 'Pas de position GPS pour ce domicile : l’équipe se guide avec l’adresse et le repère.'));
    if (canTrack && visit.team) body.append(frame);
  }

  let map = null;
  let L = null;
  let layer = null;
  let fitted = false;
  let timer = null;

  function statItem(iconName, label, value, tone = null) {
    return h('div', { class: `geo-stat${tone ? ` geo-stat--${tone}` : ''}` },
      icon(iconName, { size: 18 }), h('div', {}, h('small', {}, label), h('strong', {}, value)));
  }

  function renderStats(track) {
    const items = [];
    const geo = visit.geo || {};
    const segment = track && track.segments.length ? track.segments[track.segments.length - 1] : null;
    const points = segment ? segment.points : [];
    const last = points.length ? points[points.length - 1] : null;
    if (segment && segment.distance_m > 0) items.push(statItem('route', 'Distance parcourue', `≈ ${formatDistance(segment.distance_m)}`));
    if (last && FIELD_STATUSES.includes(visit.status)) {
      const stale = (Date.now() - new Date(last.captured_at).getTime()) / 60000 > settings.position_stale_minutes;
      const away = home ? formatDistance(distance(last, home)) : null;
      items.push(statItem('users', `Équipe · ${ago(last.captured_at)}`, away ? `à ${away} du domicile` : 'position reçue', stale ? 'warning' : null));
    }
    if (geo.arrival) {
      items.push(statItem(geo.arrival.far ? 'alert' : 'checkCircle', 'Arrivée enregistrée',
        geo.arrival.distance_m !== null ? `à ${formatDistance(geo.arrival.distance_m)} du domicile` : formatAccuracy(geo.arrival.accuracy_m),
        geo.arrival.far ? 'warning' : 'success'));
    }
    mount(stats, items);
    stats.hidden = !items.length;
  }

  function draw(track) {
    if (!map) return;
    layer.clearLayers();
    const bounds = [];
    if (home) {
      if (home.accuracy_m) {
        L.circle([home.latitude, home.longitude], { radius: home.accuracy_m, color: token('accent'), weight: 1, fillOpacity: 0.1, interactive: false }).addTo(layer);
      }
      // Rayon d'arrivée attendu (paramètre geo.arrival_radius_m), en pointillés.
      L.circle([home.latitude, home.longitude], { radius: settings.arrival_radius_m, color: token('text-3'), weight: 1, dashArray: '4 6', fill: false, interactive: false }).addTo(layer);
      L.marker([home.latitude, home.longitude], { icon: pinIcon(L, 'accent'), title: 'Domicile', keyboard: false })
        .bindTooltip(h('span', {}, visit.landmark || 'Domicile')).addTo(layer);
      bounds.push([home.latitude, home.longitude]);
    }
    (track ? track.segments : []).forEach((segment) => {
      const line = segment.points.filter((p) => p.accuracy_m === null || p.accuracy_m <= settings.low_accuracy_m).map((p) => [p.latitude, p.longitude]);
      if (line.length > 1) {
        L.polyline(line, segment.active
          ? { color: token('primary'), weight: 4, opacity: 0.85 }
          : { color: token('text-3'), weight: 3, opacity: 0.7, dashArray: '6 6' })
          .bindTooltip(h('span', {}, `${segment.team.label}${segment.active ? '' : ' (désistée)'}`)).addTo(layer);
      }
      segment.points.forEach((p) => {
        if (p.kind === 'DEPART' || p.kind === 'ARRIVEE') {
          const name = p.kind === 'DEPART' ? 'Départ' : 'Arrivée';
          L.marker([p.latitude, p.longitude], { icon: dotIcon(L, p.kind === 'DEPART' ? 'depart' : 'arrive'), title: name, keyboard: false })
            .bindTooltip(h('span', {}, `${name} · ${formatTime(p.captured_at)} · ${formatAccuracy(p.accuracy_m)}`)).addTo(layer);
        }
        bounds.push([p.latitude, p.longitude]);
      });
      const last = segment.points[segment.points.length - 1];
      if (segment.active && last && FIELD_STATUSES.includes(visit.status)) {
        const stale = (Date.now() - new Date(last.captured_at).getTime()) / 60000 > settings.position_stale_minutes;
        if (last.accuracy_m) {
          L.circle([last.latitude, last.longitude], { radius: last.accuracy_m, color: token('primary'), weight: 1, fillOpacity: 0.08, interactive: false }).addTo(layer);
        }
        L.marker([last.latitude, last.longitude], { icon: dotIcon(L, stale ? 'team-stale' : 'team'), title: segment.team.label, zIndexOffset: 500, keyboard: false })
          .bindTooltip(h('span', {}, `${segment.team.label} · ${ago(last.captured_at)}`)).addTo(layer);
      }
    });
    if (!fitted) {
      if (bounds.length > 1) map.fitBounds(bounds, { padding: [36, 36], maxZoom: 17 });
      else if (bounds.length === 1) map.setView(bounds[0], 16);
      fitted = true;
    }
  }

  async function loadTrack() {
    if (!canTrack || !visit.team) return null;
    try {
      const { data } = await api.get(`homecare/${visit.id}/track`);
      return data;
    } catch (e) {
      return null;
    }
  }

  async function refresh() {
    const track = await loadTrack();
    if (!isCurrent()) return;
    renderStats(track);
    draw(track);
  }

  // Carte initialisée une fois le cadre affiché (Leaflet a besoin de dimensions).
  const observer = new ResizeObserver(async () => {
    if (map || frame.clientWidth === 0) return;
    observer.disconnect();
    try {
      ({ L, map } = await createMap(frame, { zoom: 15 }));
    } catch (e) {
      mount(frame, h('p', { class: 'locpick__fallback' }, icon('wifiOff'), 'Carte indisponible (connexion).'));
      return;
    }
    layer = L.layerGroup().addTo(map);
    const buttons = mapButtons(L, map, [
      { icon: icon('fitBounds'), label: 'Tout afficher', onClick: () => { fitted = false; refresh(); } },
      { icon: icon('maximize'), label: 'Plein écran', onClick: (btn) => toggleFullscreen(frame, map, btn) },
    ]);
    buttons[1].setAttribute('aria-pressed', 'false');
    await refresh();
    // Suivi en direct : la régulation et l'équipe voient le trajet progresser.
    if (canTrack && FIELD_STATUSES.includes(visit.status)) {
      timer = setInterval(() => {
        if (!isCurrent()) {
          clearInterval(timer);
          return;
        }
        if (document.visibilityState === 'visible') refresh();
      }, settings.track_interval_seconds * 1000);
    }
  });
  observer.observe(frame);
  if (!frame.isConnected && !home && !(canTrack && visit.team)) renderStats(null);

  return card('Localisation et suivi', body, { actions: actions.length ? h('div', { class: 'row' }, actions) : null });
}
