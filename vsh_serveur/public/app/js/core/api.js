/**
 * Client de l'API REST (/api/v1). Enveloppe {success, message, data, meta} ou {success:false, code, errors}.
 * Sur 401, la session est renouvelée une seule fois (appels concurrents mutualisés), puis la requête rejouée.
 */
const BASE = new URL('../api/v1/', window.location.href);

export class ApiError extends Error {
  constructor(status, code, message, errors = {}) {
    super(message);
    this.status = status;
    this.code = code;
    this.errors = errors || {};
  }
}

const hooks = {
  token: () => null,
  refresh: async () => false,
  unauthorized: () => {},
};

/** Branché par session.js : fournit le jeton, le renouvellement et la réaction à une session perdue. */
export function configureAuth(options) {
  Object.assign(hooks, options);
}

/**
 * @param {string} method
 * @param {string} path  Chemin relatif, ex. "patients/search"
 * @param {{body?: object, query?: object, auth?: boolean, retry?: boolean, signal?: AbortSignal}} [options]
 * @returns {Promise<{data: *, meta: *, message: string}>}
 */
export async function request(method, path, options = {}) {
  const { body, query, auth = true, retry = true, signal } = options;
  const url = new URL(path.replace(/^\//, ''), BASE);
  for (const [key, value] of Object.entries(query || {})) {
    if (value !== null && value !== undefined && value !== '') url.searchParams.set(key, String(value));
  }
  const headers = { Accept: 'application/json' };
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  const token = auth ? hooks.token() : null;
  if (token) headers.Authorization = `Bearer ${token}`;

  let response;
  try {
    response = await fetch(url, {
      method,
      headers,
      body: body !== undefined ? JSON.stringify(body) : undefined,
      credentials: 'omit',
      cache: 'no-store',
      signal,
    });
  } catch (error) {
    if (error && error.name === 'AbortError') throw error;
    throw new ApiError(0, 'NETWORK', 'Connexion au serveur impossible. Vérifiez votre réseau puis réessayez.');
  }

  let payload = null;
  try {
    payload = await response.json();
  } catch (e) {
    payload = null;
  }

  if (response.status === 401 && auth && retry) {
    const renewed = await hooks.refresh();
    if (renewed) return request(method, path, { ...options, retry: false });
    hooks.unauthorized();
  }
  if (!response.ok || !payload || payload.success === false) {
    const message = (payload && payload.message) || messageForStatus(response.status);
    throw new ApiError(response.status, (payload && payload.code) || 'HTTP_' + response.status, message, payload && payload.errors);
  }
  return { data: payload.data, meta: payload.meta || null, message: payload.message };
}

function messageForStatus(status) {
  if (status === 403) return 'Vous n’avez pas les droits nécessaires.';
  if (status === 404) return 'Élément introuvable.';
  if (status === 429) return 'Trop de tentatives. Patientez quelques minutes.';
  if (status >= 500) return 'Le serveur a rencontré un problème. Réessayez dans un instant.';
  return 'La requête n’a pas abouti.';
}

/**
 * Télécharge un document généré (PDF) avec le jeton en en-tête : jamais de jeton ni de donnée dans l'URL.
 * Le fichier est proposé à l'enregistrement sous le nom donné par le serveur.
 */
export async function download(path, fallbackName = 'document.pdf', { retry = true } = {}) {
  const url = new URL(path.replace(/^\//, ''), BASE);
  const token = hooks.token();
  let response;
  try {
    response = await fetch(url, { headers: token ? { Authorization: `Bearer ${token}` } : {}, credentials: 'omit', cache: 'no-store' });
  } catch (error) {
    throw new ApiError(0, 'NETWORK', 'Connexion au serveur impossible. Vérifiez votre réseau puis réessayez.');
  }
  if (response.status === 401 && retry) {
    if (await hooks.refresh()) return download(path, fallbackName, { retry: false });
    hooks.unauthorized();
  }
  if (!response.ok) {
    let payload = null;
    try {
      payload = await response.json();
    } catch (e) {
      payload = null;
    }
    throw new ApiError(response.status, (payload && payload.code) || 'HTTP_' + response.status, (payload && payload.message) || messageForStatus(response.status));
  }
  const disposition = response.headers.get('Content-Disposition') || '';
  const match = disposition.match(/filename="([^"]+)"/);
  const blob = await response.blob();
  const href = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = href;
  link.download = match ? match[1] : fallbackName;
  document.body.append(link);
  link.click();
  link.remove();
  setTimeout(() => URL.revokeObjectURL(href), 30000);
  return link.download;
}

export const api = {
  get: (path, query, options = {}) => request('GET', path, { ...options, query }),
  post: (path, body = {}, options = {}) => request('POST', path, { ...options, body }),
  put: (path, body = {}, options = {}) => request('PUT', path, { ...options, body }),
  del: (path, options = {}) => request('DELETE', path, options),
};
