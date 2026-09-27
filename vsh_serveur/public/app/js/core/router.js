/**
 * Routeur par ancre (#/chemin?param=valeur) : liens profonds, bouton « Retour » du navigateur,
 * aucune configuration serveur nécessaire.
 */
const routes = [];

/**
 * @param {string} pattern ex. "/patients/:id"
 * @param {object} definition {view, title, permissions?: string[]}
 */
export function route(pattern, definition) {
  const keys = [];
  const regex = new RegExp('^' + pattern.replace(/\//g, '\\/').replace(/:(\w+)/g, (_, key) => {
    keys.push(key);
    return '([^/]+)';
  }) + '$');
  routes.push({ pattern, regex, keys, ...definition });
}

export function parse(hash = window.location.hash) {
  const raw = hash.replace(/^#/, '') || '/';
  const [path, search = ''] = raw.split('?');
  const query = Object.fromEntries(new URLSearchParams(search));
  for (const definition of routes) {
    const match = definition.regex.exec(path);
    if (match) {
      const params = {};
      definition.keys.forEach((key, index) => {
        params[key] = decodeURIComponent(match[index + 1]);
      });
      return { definition, path, params, query };
    }
  }
  return { definition: null, path, params: {}, query };
}

export function navigate(path, { replace = false } = {}) {
  const target = '#' + path;
  if (replace) {
    window.history.replaceState(null, '', target);
    window.dispatchEvent(new HashChangeEvent('hashchange'));
  } else if (window.location.hash === target) {
    window.dispatchEvent(new HashChangeEvent('hashchange'));
  } else {
    window.location.hash = path;
  }
}

/** Met à jour la chaîne de requête sans ajouter d'entrée d'historique (filtres, onglets). */
export function setQuery(query) {
  const { path } = parse();
  const entries = Object.entries(query).filter(([, value]) => value !== null && value !== undefined && value !== '');
  const search = new URLSearchParams(entries).toString();
  window.history.replaceState(null, '', '#' + path + (search ? '?' + search : ''));
}

export function link(path) {
  return '#' + path;
}
