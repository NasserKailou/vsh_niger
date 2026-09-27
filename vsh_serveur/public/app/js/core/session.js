/**
 * Session web.
 *  - Jeton d'accès : en mémoire uniquement (perdu à la fermeture de l'onglet).
 *  - Jeton de renouvellement : sessionStorage (limité à l'onglet ; rotation à chaque usage côté serveur,
 *    détection de réutilisation). La politique CSP stricte de l'application limite le risque XSS.
 */
import { api, configureAuth } from './api.js';

// Clé du jeton de renouvellement ; le portail patient utilise la sienne (sessions jamais mélangées).
let RT_KEY = 'vsh.rt';

const state = {
  accessToken: null,
  user: null,
  permissions: new Set(),
  refreshing: null,
};

const listeners = new Set();

function readRefreshToken() {
  try {
    return window.sessionStorage.getItem(RT_KEY);
  } catch (e) {
    return null;
  }
}

function storeTokens(tokens) {
  state.accessToken = tokens.access_token;
  try {
    window.sessionStorage.setItem(RT_KEY, tokens.refresh_token);
  } catch (e) {
    /* sans sessionStorage : la session dure le temps de la page */
  }
}

function setUser(user) {
  state.user = user;
  state.permissions = new Set(user && Array.isArray(user.permissions) ? user.permissions : []);
}

function clear() {
  state.accessToken = null;
  setUser(null);
  try {
    window.sessionStorage.removeItem(RT_KEY);
  } catch (e) {
    /* rien à faire */
  }
}

async function refresh() {
  const refreshToken = readRefreshToken();
  if (!refreshToken) return false;
  if (!state.refreshing) {
    state.refreshing = api.post('auth/refresh', { refresh_token: refreshToken }, { auth: false })
      .then(({ data }) => {
        storeTokens(data.tokens);
        return true;
      })
      .catch(() => false)
      .finally(() => {
        state.refreshing = null;
      });
  }
  return state.refreshing;
}

configureAuth({
  token: () => state.accessToken,
  refresh,
  unauthorized: () => {
    const wasLogged = state.user !== null;
    clear();
    if (wasLogged) listeners.forEach((listener) => listener('expired'));
  },
});

export const session = {
  get user() {
    return state.user;
  },
  get loggedIn() {
    return state.user !== null && state.accessToken !== null;
  },
  can(permission) {
    return state.permissions.has(permission);
  },
  canAny(...permissions) {
    return permissions.some((permission) => state.permissions.has(permission));
  },
  onChange(listener) {
    listeners.add(listener);
    return () => listeners.delete(listener);
  },

  /** Reprend une session existante dans l'onglet (rechargement de page). */
  async restore() {
    if (!readRefreshToken()) return false;
    if (!(await refresh())) {
      clear();
      return false;
    }
    try {
      const { data } = await api.get('me');
      setUser(data);
      return true;
    } catch (e) {
      clear();
      return false;
    }
  },

  async login(phone, password) {
    const { data } = await api.post('auth/login', { phone, password }, { auth: false });
    if (data.user && data.user.account_type === 'PATIENT') {
      // Le portail patient aura son propre espace ; cette interface est réservée au personnel.
      state.accessToken = data.tokens.access_token;
      await api.post('auth/logout').catch(() => {});
      clear();
      const error = new Error('Cet espace est réservé au personnel de la clinique.');
      error.code = 'STAFF_ONLY';
      throw error;
    }
    storeTokens(data.tokens);
    setUser(data.user);
    listeners.forEach((listener) => listener('login'));
    return data.user;
  },

  /** Espace distinct (portail patient) : à appeler avant restore(). */
  useStorageKey(key) {
    RT_KEY = key;
  },

  /**
   * Portail patient (D-001) : n° de dossier + téléphone + code reçu par SMS. Pas de mot de passe.
   */
  async portalLogin(fileNumber, phone, code) {
    const { data } = await api.post('auth/patient-portal/login', { file_number: fileNumber, phone, code }, { auth: false });
    storeTokens(data.tokens);
    setUser(data.user);
    listeners.forEach((listener) => listener('login'));
    return data.user;
  },

  async reloadProfile() {
    const { data } = await api.get('me');
    setUser(data);
    return data;
  },

  async logout() {
    try {
      await api.post('auth/logout');
    } catch (e) {
      /* la session locale est fermée quoi qu'il arrive */
    }
    clear();
    listeners.forEach((listener) => listener('logout'));
  },
};
