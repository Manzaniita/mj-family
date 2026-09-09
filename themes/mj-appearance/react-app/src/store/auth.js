import { create } from 'zustand';
import { WP, SESSION } from '@/lib/config';
import * as wc from '@/lib/wc';

/**
 * Cierre de un login/registro/logout.
 *
 * Con "Recargar la tienda al iniciar sesión" activo (el default), se hace una
 * carga completa del destino en vez de una navegación de React Router. Es lo
 * único que permite que WordPress arme la página ya con la sesión abierta y
 * pinte la barra de administración de quien acaba de entrar; de paso deja
 * frescos el carrito y los nonces del servidor.
 *
 * Devuelve true si se encargó de la navegación (y por lo tanto la pantalla no
 * tiene que navegar por su cuenta).
 */
export function finishAuth(path) {
  if (!SESSION.reloadOnLogin || typeof window === 'undefined') return false;
  window.location.assign(path || SESSION.accountPath);
  return true;
}

/**
 * Sesión de usuario (cuenta WooCommerce) vía la REST propia mj/v1.
 * No importa useCart para evitar importaciones circulares
 * (index.js re-exporta useAuth).
 */
export const useAuth = create((set) => ({
  user: WP?.user ?? null,
  loading: false,
  error: null,

  /** Devuelve true si logueó (para que la página navegue). */
  login: async (username, password, remember = false) => {
    set({ loading: true, error: null });
    try {
      const { user } = await wc.login(username, password, remember);
      set({ user, loading: false });
      return true;
    } catch (e) {
      set({ loading: false, error: e.data?.message || e.message });
      return false;
    }
  },

  /** data: { email, password, first_name?, last_name? }. Devuelve true si registró. */
  register: async (data) => {
    set({ loading: true, error: null });
    try {
      const { user } = await wc.register(data);
      set({ user, loading: false });
      return true;
    } catch (e) {
      set({ loading: false, error: e.data?.message || e.message });
      return false;
    }
  },

  logout: async () => {
    set({ loading: true, error: null });
    try {
      await wc.logout();
    } catch (e) {
      // Aunque falle el request, la sesión local se cierra igual.
      console.warn('[mj] logout falló', e);
    }
    set({ user: null, loading: false });
    // Misma razón que al entrar: la barra de administración sólo desaparece
    // cuando WordPress vuelve a armar la página.
    finishAuth('/');
  },

  /** Siempre devuelve ok del lado del server; true si el request salió. */
  recover: async (login) => {
    set({ loading: true, error: null });
    try {
      await wc.recover(login);
      set({ loading: false });
      return true;
    } catch (e) {
      set({ loading: false, error: e.data?.message || e.message });
      return false;
    }
  },

  clearError: () => set({ error: null }),
}));
