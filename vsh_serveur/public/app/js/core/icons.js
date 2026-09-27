/**
 * Icônes SVG (tracés Lucide, licence ISC — https://lucide.dev). Jamais d'emoji comme icône.
 * Décoratives par défaut (aria-hidden) ; passer `label` pour une icône porteuse de sens.
 */
const NS = 'http://www.w3.org/2000/svg';

const P = (d) => ['path', { d }];
const C = (cx, cy, r) => ['circle', { cx, cy, r }];
const R = (x, y, width, height, rx) => ['rect', { x, y, width, height, rx }];

const ICONS = {
  dashboard: [R(3, 3, 7, 9, 1), R(14, 3, 7, 5, 1), R(14, 12, 7, 9, 1), R(3, 16, 7, 5, 1)],
  users: [P('M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2'), C(9, 7, 4), P('M22 21v-2a4 4 0 0 0-3-3.87'), P('M16 3.13a4 4 0 0 1 0 7.75')],
  user: [C(12, 8, 5), P('M20 21a8 8 0 0 0-16 0')],
  userCheck: [P('M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2'), C(9, 7, 4), P('m16 11 2 2 4-4')],
  calendar: [R(3, 4, 18, 18, 2), P('M16 2v4'), P('M8 2v4'), P('M3 10h18')],
  stethoscope: [P('M4.8 2.3A.3.3 0 1 0 5 2H4a2 2 0 0 0-2 2v5a6 6 0 0 0 6 6a6 6 0 0 0 6-6V4a2 2 0 0 0-2-2h-1a.2.2 0 1 0 .3.3'), P('M8 15v1a6 6 0 0 0 6 6a6 6 0 0 0 6-6v-4'), C(20, 10, 2)],
  home: [P('M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z'), P('M9 22V12h6v10')],
  map: [P('M9 3 3 6v15l6-3 6 3 6-3V3l-6 3-6-3z'), P('M9 3v15'), P('M15 6v15')],
  mapPin: [P('M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z'), C(12, 10, 3)],
  flask: [P('M10 2v7.527a2 2 0 0 1-.211.896L4.72 20.55a1 1 0 0 0 .9 1.45h12.76a1 1 0 0 0 .9-1.45l-5.069-10.127A2 2 0 0 1 14 9.527V2'), P('M8.5 2h7'), P('M7 16h10')],
  pill: [P('m10.5 20.5 10-10a4.95 4.95 0 1 0-7-7l-10 10a4.95 4.95 0 1 0 7 7Z'), P('m8.5 8.5 7 7')],
  receipt: [P('M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z'), P('M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8'), P('M12 17.5v-11')],
  sliders: [P('M21 4h-7'), P('M10 4H3'), P('M21 12h-9'), P('M8 12H3'), P('M21 20h-5'), P('M12 20H3'), P('M14 2v4'), P('M8 10v4'), P('M16 18v4')],
  shield: [P('M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z')],
  logout: [P('M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4'), P('m16 17 5-5-5-5'), P('M21 12H9')],
  sun: [C(12, 12, 4), P('M12 2v2'), P('M12 20v2'), P('m4.93 4.93 1.41 1.41'), P('m17.66 17.66 1.41 1.41'), P('M2 12h2'), P('M20 12h2'), P('m6.34 17.66-1.41 1.41'), P('m19.07 4.93-1.41 1.41')],
  moon: [P('M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z')],
  menu: [P('M4 6h16'), P('M4 12h16'), P('M4 18h16')],
  search: [C(11, 11, 8), P('m21 21-4.3-4.3')],
  chevronRight: [P('m9 18 6-6-6-6')],
  chevronLeft: [P('m15 18-6-6 6-6')],
  chevronDown: [P('m6 9 6 6 6-6')],
  x: [P('M18 6 6 18'), P('m6 6 12 12')],
  alert: [C(12, 12, 10), P('M12 8v4'), P('M12 16h.01')],
  checkCircle: [P('M22 11.08V12a10 10 0 1 1-5.93-9.14'), P('m9 11 3 3L22 4')],
  refresh: [P('M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8'), P('M3 3v5h5'), P('M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16'), P('M16 16h5v5')],
  eye: [P('M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z'), C(12, 12, 3)],
  eyeOff: [P('M9.88 9.88a3 3 0 1 0 4.24 4.24'), P('M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68'), P('M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61'), P('m2 2 20 20')],
  phone: [P('M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z')],
  clock: [C(12, 12, 10), P('M12 6v6l4 2')],
  activity: [P('M22 12h-4l-3 9L9 3l-3 9H2')],
  wallet: [P('M19 7V4a1 1 0 0 0-1-1H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v4h-3a2 2 0 0 0 0 4h3a1 1 0 0 0 1-1v-2a1 1 0 0 0-1-1'), P('M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-4')],
  clipboard: [R(8, 2, 8, 4, 1), P('M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2'), P('M12 11h4'), P('M12 16h4'), P('M8 11h.01'), P('M8 16h.01')],
  userPlus: [P('M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2'), C(9, 7, 4), P('M19 8v6'), P('M22 11h-6')],
  wifiOff: [P('M12 20h.01'), P('M8.5 16.43a5 5 0 0 1 7 0'), P('M2 8.82a15 15 0 0 1 4.17-2.65'), P('M10.66 5c4.01-.36 8.14.9 11.34 3.76'), P('M16.85 11.25a10 10 0 0 1 2.22 1.68'), P('M5 12.86a10 10 0 0 1 5.17-2.69'), P('m2 2 20 20')],
  heartPulse: [P('M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z'), P('M3.22 12H9.5l.5-1 2 4.5 2-7 1.5 3.5h5.27')],
  lock: [R(3, 11, 18, 11, 2), P('M7 11V7a5 5 0 0 1 10 0v4')],
  info: [C(12, 12, 10), P('M12 16v-4'), P('M12 8h.01')],
  history: [P('M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8'), P('M3 3v5h5'), P('M12 7v5l4 2')],
  arrowRight: [P('M5 12h14'), P('m12 5 7 7-7 7')],
  locate: [P('M2 12h3'), P('M19 12h3'), P('M12 2v3'), P('M12 19v3'), C(12, 12, 7), C(12, 12, 3)],
  maximize: [P('M8 3H5a2 2 0 0 0-2 2v3'), P('M21 8V5a2 2 0 0 0-2-2h-3'), P('M3 16v3a2 2 0 0 0 2 2h3'), P('M16 21h3a2 2 0 0 0 2-2v-3')],
  minimize: [P('M8 3v3a2 2 0 0 1-2 2H3'), P('M21 8h-3a2 2 0 0 1-2-2V3'), P('M3 16h3a2 2 0 0 1 2 2v3'), P('M16 21v-3a2 2 0 0 1 2-2h3')],
  navigation: [P('m3 11 19-9-9 19-2-8-8-2z')],
  copy: [R(8, 8, 14, 14, 2), P('M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2')],
  radio: [P('M4.9 19.1C1 15.2 1 8.8 4.9 4.9'), P('M7.8 16.2c-2.3-2.3-2.3-6.1 0-8.5'), C(12, 12, 2), P('M16.2 7.8c2.3 2.3 2.3 6.1 0 8.5'), P('M19.1 4.9C23 8.8 23 15.1 19.1 19')],
  route: [C(6, 19, 3), P('M9 19h8.5a3.5 3.5 0 0 0 0-7h-11a3.5 3.5 0 0 1 0-7H15'), C(18, 5, 3)],
  pause: [R(6, 4, 4, 16, 1), R(14, 4, 4, 16, 1)],
  fitBounds: [P('M15 3h6v6'), P('M9 21H3v-6'), P('M21 3l-7 7'), P('M3 21l7-7')],
  fileText: [P('M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z'), P('M14 2v4a2 2 0 0 0 2 2h4'), P('M10 9H8'), P('M16 13H8'), P('M16 17H8')],
};

/**
 * @param {string} name
 * @param {{label?: string, size?: number, class?: string}} [options]
 * @returns {SVGSVGElement}
 */
export function icon(name, options = {}) {
  const svg = document.createElementNS(NS, 'svg');
  const attrs = {
    viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', 'stroke-width': '2',
    'stroke-linecap': 'round', 'stroke-linejoin': 'round', focusable: 'false',
  };
  if (options.size) {
    attrs.width = String(options.size);
    attrs.height = String(options.size);
  }
  if (options.class) attrs.class = options.class;
  for (const [key, value] of Object.entries(attrs)) svg.setAttribute(key, value);
  if (options.label) {
    svg.setAttribute('role', 'img');
    svg.setAttribute('aria-label', options.label);
  } else {
    svg.setAttribute('aria-hidden', 'true');
  }
  for (const [tag, props] of ICONS[name] || ICONS.info) {
    const node = document.createElementNS(NS, tag);
    for (const [key, value] of Object.entries(props)) node.setAttribute(key, String(value));
    svg.append(node);
  }
  return svg;
}
