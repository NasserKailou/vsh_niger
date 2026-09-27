/**
 * Construction du DOM sans innerHTML : toute donnée passe par textContent ou setAttribute,
 * ce qui empêche l'injection de code (XSS) même si une donnée contient du HTML.
 */

/**
 * @param {string} tag
 * @param {Object<string, *>} [attrs] class, text, on<Event>, dataset, attributs ARIA…
 * @param {...*} children Nœuds, textes, tableaux ; null/undefined/false sont ignorés
 * @returns {HTMLElement}
 */
export function h(tag, attrs = {}, ...children) {
  const el = document.createElement(tag);
  for (const [key, value] of Object.entries(attrs || {})) {
    if (value === null || value === undefined || value === false) continue;
    if (key === 'class') {
      el.className = Array.isArray(value) ? value.filter(Boolean).join(' ') : String(value);
    } else if (key === 'text') {
      el.textContent = String(value);
    } else if (key.startsWith('on') && typeof value === 'function') {
      el.addEventListener(key.slice(2).toLowerCase(), value);
    } else if (key === 'dataset') {
      Object.assign(el.dataset, value);
    } else if (value === true) {
      el.setAttribute(key, '');
    } else {
      el.setAttribute(key, String(value));
    }
  }
  append(el, children);
  return el;
}

export function append(parent, children) {
  for (const child of [].concat(children).flat(Infinity)) {
    if (child === null || child === undefined || child === false) continue;
    parent.append(child instanceof Node ? child : document.createTextNode(String(child)));
  }
  return parent;
}

export function mount(parent, ...children) {
  parent.replaceChildren();
  return append(parent, children);
}

let counter = 0;
export function uid(prefix = 'id') {
  counter += 1;
  return `${prefix}-${counter}`;
}

/** Préférences propres au navigateur (thème) : jamais de donnée métier ni de jeton ici. */
export const prefs = {
  get(key, fallback = null) {
    try {
      const value = window.localStorage.getItem(`vsh.${key}`);
      return value === null ? fallback : value;
    } catch (e) {
      return fallback;
    }
  },
  set(key, value) {
    try {
      window.localStorage.setItem(`vsh.${key}`, value);
    } catch (e) {
      /* stockage indisponible (navigation privée) : sans conséquence */
    }
  },
};
