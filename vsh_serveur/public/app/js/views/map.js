/**
 * Carte de régulation : visites ouvertes (position du domicile) et dernière position connue des équipes.
 * L'API /map/homecare ne renvoie aucune donnée médicale (ni motif, ni nom) : numéro de dossier seulement.
 * Filtres (état, urgence, équipe), « ma position », plein écran, liste accessible au clavier qui centre
 * la carte, actualisation automatique tant que l'écran est affiché.
 */
import { h, mount, uid } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { api } from '../core/api.js';
import { session } from '../core/session.js';
import { badge, formatTime, statusInfo } from '../core/format.js';
import { pageHead, card, emptyState, errorState, button, toast, checkbox, field } from '../ui.js';
import { createMap, pinIcon, dotIcon, mapButtons, toggleFullscreen, token } from '../map.js';
import { bestPosition, formatDistance, formatAccuracy, ago } from '../geo.js';
import { urgencyBadge, waiting } from './homecare.js';

const REFRESH_MS = 60000;
const TO_ASSIGN = ['NOUVELLE', 'EN_ATTENTE'];

function tone(visit) {
  if (visit.urgency === 'URGENTE') return 'danger';
  return TO_ASSIGN.includes(visit.status) ? 'warning' : 'info';
}

export async function mapView({ main, setTitle, isCurrent }) {
  setTitle('Carte des visites');
  const canOpen = session.can('homecare.read');
  const updated = h('span', { class: 'vsh-muted', role: 'status' });
  const actions = [updated, button({ text: 'Actualiser', iconName: 'refresh', variant: 'secondary', onClick: () => load(true) })];
  if (canOpen) actions.push(h('a', { class: 'vsh-btn vsh-btn--secondary', href: '#/homecare' }, icon('home'), 'Liste des visites'));

  // ---------------------------------------------------------------- Filtres
  const groupName = uid('state');
  const states = [['all', 'Toutes'], ['assign', 'À affecter'], ['engaged', 'Équipe engagée']].map(([value, label]) => {
    const input = h('input', { type: 'radio', name: groupName, value, checked: value === 'all' });
    input.addEventListener('change', () => render());
    return h('label', { class: 'choice' }, input, h('span', {}, label));
  });
  const urgentOnly = checkbox({ label: 'Urgentes seulement', name: 'urgent_only' });
  urgentOnly.input.addEventListener('change', () => render());
  const teamFilter = field({ label: 'Équipe', name: 'team', options: [['', 'Toutes les équipes']] });
  teamFilter.input.addEventListener('change', () => render());
  const filters = h('div', { class: 'toolbar map-filters' },
    h('fieldset', { class: 'map-filters__states' }, h('legend', { class: 'sr-only' }, 'État des visites'), h('div', { class: 'choice-group' }, states)),
    urgentOnly.el, teamFilter.el);

  const frame = h('div', { class: 'map-frame', role: 'region', 'aria-label': 'Carte des visites à domicile ouvertes' });
  const summary = h('div', { class: 'stack' });
  const legendPin = (kind, label) => h('li', {}, h('span', { class: `map-pin map-pin--${kind} map-pin--legend`, 'aria-hidden': 'true' }, h('span', { class: 'map-pin__head' })), label);
  const legendDot = (kind, label) => h('li', {}, h('span', { class: `map-dot map-dot--${kind} map-dot--legend`, 'aria-hidden': 'true' }, h('span')), label);
  const legend = h('ul', { class: 'map-legend', 'aria-label': 'Légende' },
    legendPin('warning', 'À affecter'), legendPin('info', 'Équipe engagée'), legendPin('danger', 'Urgente'),
    legendDot('team', 'Équipe (dernière position)'), legendDot('team-stale', 'Position ancienne'));

  mount(main,
    pageHead({ title: 'Carte des visites', subtitle: 'Visites ouvertes, position des domiciles et dernière position transmise par les équipes.', actions }),
    filters,
    h('div', { class: 'workspace workspace--map' }, h('div', { class: 'stack' }, frame, legend), summary));

  let map;
  let L;
  try {
    ({ L, map } = await createMap(frame));
  } catch (error) {
    mount(frame, errorState(error, () => mapView({ main, setTitle, isCurrent })));
    return;
  }
  if (!isCurrent()) return;
  const layer = L.layerGroup().addTo(map);
  const meLayer = L.layerGroup().addTo(map);
  const markers = new Map();
  let visits = [];
  let fitted = false;

  mapButtons(L, map, [
    { icon: icon('locate'), label: 'Ma position', onClick: (btn) => locateMe(btn) },
    { icon: icon('fitBounds'), label: 'Tout afficher', onClick: () => fitAll() },
    { icon: icon('maximize'), label: 'Plein écran', onClick: (btn) => toggleFullscreen(frame, map, btn) },
  ]);

  async function locateMe(btn) {
    btn.disabled = true;
    try {
      const p = await bestPosition({ wanted: 50, maxWait: 10000 });
      meLayer.clearLayers();
      L.circle([p.latitude, p.longitude], { radius: p.accuracy_m, color: token('focus'), weight: 1, fillOpacity: 0.1, interactive: false }).addTo(meLayer);
      L.marker([p.latitude, p.longitude], { icon: dotIcon(L, 'me'), title: 'Ma position', keyboard: false })
        .bindTooltip(h('span', {}, `Ma position (${formatAccuracy(p.accuracy_m)})`)).addTo(meLayer);
      map.setView([p.latitude, p.longitude], Math.max(map.getZoom(), 15));
    } catch (e) {
      toast(e.message, { type: 'error' });
    } finally {
      btn.disabled = false;
    }
  }

  function filtered() {
    const checked = main.querySelector(`input[name="${groupName}"]:checked`);
    const state = checked ? checked.value : 'all';
    return visits.filter((v) => {
      if (state === 'assign' && !TO_ASSIGN.includes(v.status)) return false;
      if (state === 'engaged' && TO_ASSIGN.includes(v.status)) return false;
      if (urgentOnly.input.checked && v.urgency !== 'URGENTE') return false;
      if (teamFilter.input.value && (!v.team || v.team.id !== teamFilter.input.value)) return false;
      return true;
    });
  }

  function popup(v) {
    // Contenu construit en DOM (textContent) : jamais de chaîne HTML issue des données.
    const p = v.team_position;
    return h('div', { class: 'map-popup stack stack--sm' },
      h('div', { class: 'row' }, h('strong', { class: 'mono' }, v.file_number || 'Dossier'), badge('homecare', v.status), urgencyBadge(v.urgency)),
      v.landmark || v.address_text ? h('span', {}, v.landmark || v.address_text) : null,
      h('span', { class: 'vsh-muted' }, `Demandée ${waiting(v.created_at)}`, v.team ? ` · ${v.team.label}` : ''),
      v.imprecise ? h('span', { class: 'vsh-muted' }, `Domicile imprécis (${formatAccuracy(v.accuracy_m)})`) : null,
      p ? h('span', {}, `Équipe à ${formatDistance(p.distance_m)} · ${ago(p.captured_at)}${p.stale ? ' (ancienne)' : ''}`) : null,
      canOpen ? h('a', { href: `#/homecare/${v.id}` }, 'Ouvrir la visite') : null);
  }

  function draw(list) {
    layer.clearLayers();
    markers.clear();
    list.forEach((v) => {
      if (v.latitude !== null) {
        if (v.imprecise && v.accuracy_m) {
          L.circle([v.latitude, v.longitude], { radius: v.accuracy_m, color: token(tone(v)), weight: 1, fillOpacity: 0.08, interactive: false }).addTo(layer);
        }
        const marker = L.marker([v.latitude, v.longitude], {
          icon: pinIcon(L, tone(v)),
          title: `${v.file_number || 'Visite'} · ${statusInfo('homecare', v.status).label}`,
          keyboard: false,
          zIndexOffset: v.urgency === 'URGENTE' ? 1000 : 0,
        }).bindPopup(popup(v)).addTo(layer);
        markers.set(v.id, marker);
      }
      const p = v.team_position;
      if (p) {
        if (p.accuracy_m && p.accuracy_m > 30) {
          L.circle([p.latitude, p.longitude], { radius: p.accuracy_m, color: token('primary'), weight: 1, fillOpacity: 0.06, interactive: false }).addTo(layer);
        }
        const label = v.team ? v.team.label : 'Équipe';
        L.marker([p.latitude, p.longitude], { icon: dotIcon(L, p.stale ? 'team-stale' : 'team'), title: label, keyboard: false, zIndexOffset: 500 })
          .bindTooltip(h('span', {}, `${label} · ${ago(p.captured_at)}${p.distance_m !== null ? ` · à ${formatDistance(p.distance_m)} du domicile` : ''}`))
          .addTo(layer);
        if (v.latitude !== null) {
          L.polyline([[p.latitude, p.longitude], [v.latitude, v.longitude]], {
            color: p.stale ? token('text-3') : token('primary'), weight: 2, dashArray: '4 6', interactive: false,
          }).addTo(layer);
        }
      }
    });
  }

  function fitAll() {
    const points = [];
    filtered().forEach((v) => {
      if (v.latitude !== null) points.push([v.latitude, v.longitude]);
      if (v.team_position) points.push([v.team_position.latitude, v.team_position.longitude]);
    });
    if (points.length > 1) map.fitBounds(points, { padding: [40, 40], maxZoom: 16 });
    else if (points.length === 1) map.setView(points[0], 16);
  }

  function focusVisit(v) {
    const marker = markers.get(v.id);
    if (!marker) return;
    map.setView(marker.getLatLng(), Math.max(map.getZoom(), 16));
    marker.openPopup();
    frame.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
  }

  function side(list) {
    const kpi = (label, value, kind) => h('div', { class: `map-kpi map-kpi--${kind}` }, h('strong', {}, String(value)), h('span', {}, label));
    const located = list.filter((v) => v.latitude !== null);
    const noGps = list.filter((v) => v.latitude === null);
    const staleTeams = list.filter((v) => v.team_position && v.team_position.stale).length;
    const fileLink = (v) => (canOpen
      ? h('a', { class: 'mono', href: `#/homecare/${v.id}` }, v.file_number || 'Visite')
      : h('span', { class: 'mono' }, v.file_number || 'Visite'));
    const line = (v, extra) => h('li', { class: 'stack stack--sm' },
      h('div', { class: 'row' }, fileLink(v), badge('homecare', v.status), urgencyBadge(v.urgency)),
      h('small', { class: 'vsh-muted' }, v.landmark || v.address_text || 'Aucune adresse'),
      extra);
    mount(summary,
      visits.length ? null : emptyState({ iconName: 'map', title: 'Aucune visite ouverte', text: 'Les demandes en cours apparaîtront ici avec leur position.' }),
      h('div', { class: 'map-kpis' },
        kpi('Affichées', list.length, 'neutral'),
        kpi('À affecter', list.filter((v) => TO_ASSIGN.includes(v.status)).length, 'warning'),
        kpi('Urgentes', list.filter((v) => v.urgency === 'URGENTE').length, 'danger')),
      staleTeams ? h('div', { class: 'vsh-alert vsh-alert--warning', role: 'status' }, icon('alert', { size: 20 }),
        h('span', {}, `${staleTeams} équipe(s) sans position récente : réseau, batterie ou partage arrêté ?`)) : null,
      card('Visites localisées', located.length
        ? h('ul', { class: 'plain-list' }, located.map((v) => line(v, h('div', { class: 'row' },
          v.team_position ? h('small', {}, `Équipe à ${formatDistance(v.team_position.distance_m)} · ${ago(v.team_position.captured_at)}`) : null,
          button({ text: 'Centrer', iconName: 'locate', variant: 'ghost', size: 'sm', onClick: () => focusVisit(v) })))))
        : h('p', { class: 'vsh-muted' }, 'Aucune visite localisée pour ces filtres.')),
      card('Sans position GPS', noGps.length
        ? h('ul', { class: 'plain-list' }, noGps.map((v) => line(v, null)))
        : h('p', { class: 'vsh-muted' }, 'Toutes les visites affichées sont localisées.')));
  }

  function render() {
    const list = filtered();
    draw(list);
    side(list);
  }

  function syncTeams() {
    const teams = new Map();
    visits.forEach((v) => { if (v.team) teams.set(v.team.id, v.team.label); });
    const selected = teamFilter.input.value;
    teamFilter.input.replaceChildren(h('option', { value: '' }, 'Toutes les équipes'),
      ...[...teams].sort((a, b) => a[1].localeCompare(b[1], 'fr')).map(([id, label]) => h('option', { value: id, selected: id === selected }, label)));
  }

  let timer = null;
  async function load(manual = false) {
    clearTimeout(timer);
    try {
      const { data } = await api.get('map/homecare');
      if (!isCurrent()) return;
      visits = data;
      syncTeams();
      render();
      if (!fitted) {
        fitAll();
        fitted = true;
      }
      updated.textContent = `Mise à jour à ${formatTime(new Date().toISOString())}`;
      if (manual) toast('Carte actualisée.');
    } catch (error) {
      if (!isCurrent()) return;
      if (manual || !summary.childElementCount) mount(summary, errorState(error, () => load(true)));
    }
    if (!isCurrent()) return;
    timer = setTimeout(() => {
      // Écran quitté : arrêt ; onglet masqué : reprise à son retour.
      if (!isCurrent()) return;
      if (document.hidden) document.addEventListener('visibilitychange', () => { if (isCurrent()) load(); }, { once: true });
      else load();
    }, REFRESH_MS);
  }
  load();
}
