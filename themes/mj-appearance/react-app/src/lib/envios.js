/**
 * Envíos (plugin MJ Envíos).
 *
 * La tienda no sabe nada de correos ni de precios: todo lo que dibuja el paso de
 * envío llega en window.MJ.shipping — los métodos, qué datos pide cada uno, los
 * textos y las provincias — y los precios llegan con las tarifas del carrito.
 * Sin el plugin, `SHIPPING` es null y el checkout muestra el paso de siempre.
 */

import { IS_WP, WP } from './config';

export const SHIPPING = IS_WP ? (WP?.shipping ?? null) : null;

/** ¿Hay envíos propios configurados? */
export const hasShipping = () => !!(SHIPPING?.enabled && Array.isArray(SHIPPING.methods) && SHIPPING.methods.length > 0);

export const shippingTexts = () => SHIPPING?.texts ?? {};
export const cpConfig = () => SHIPPING?.cp ?? {};
export const provinces = () => {
  const states = SHIPPING?.states ?? {};
  return Object.keys(states).map((code) => ({ code, name: states[code] }));
};

export const methodBySlug = (slug) => (SHIPPING?.methods ?? []).find((m) => m.slug === slug) ?? null;

/*
 * Los datos de contacto los pide el paso 1 y los de la dirección el bloque de
 * dirección del paso de envío: no se vuelven a preguntar en los datos extra del
 * método, aunque el plugin los tenga configurados.
 */
export const ADDRESS_KEYS = ['postcode', 'state', 'city', 'address_1', 'address_2'];
const CONTACT_KEYS = ['first_name', 'last_name', 'phone', 'email', 'company'];

const fieldsOf = (method) => (Array.isArray(method?.fields) ? method.fields : []);

/** Los campos de dirección que este método pide, en su orden. */
export const addressFields = (method) => fieldsOf(method).filter((f) => ADDRESS_KEYS.includes(f.key));

/** Un campo de dirección puntual (para saber si es obligatorio o cómo se llama). */
export const addressField = (method, key) => fieldsOf(method).find((f) => f.key === key) ?? null;

/** Lo que se pregunta después de elegir el método (DNI, quién recibe, horario…). */
export const extraFields = (method) =>
  fieldsOf(method).filter((f) => !ADDRESS_KEYS.includes(f.key) && !CONTACT_KEYS.includes(f.key) && f.key !== 'branch');

/**
 * El contacto y la dirección del formulario, con las claves de MJ Envíos.
 *
 * Se preguntan en el paso 1 y en el bloque de dirección, pero el plugin los valida en el servidor
 * junto con los datos del método: si no viajan, el paso de envío dice «Falta completar "Nombre"»
 * aunque el nombre esté escrito. Por eso van en cada envío de los datos, sumados a los del método.
 */
export const formFields = (form) => ({
  first_name: form?.firstName || '',
  last_name: form?.lastName || '',
  email: form?.email || '',
  phone: form?.phone || '',
  postcode: form?.postcode || '',
  state: form?.state || '',
  city: form?.city || '',
  address_1: form?.address || '',
  address_2: form?.address2 || '',
});

/** El campo de la sucursal, si este método entrega en una. */
export const branchField = (method) => fieldsOf(method).find((f) => f.key === 'branch') ?? null;

/**
 * Qué falta para poder cerrar la compra con este método.
 *
 * Mira los mismos campos que valida el servidor: la tienda avisa antes, en el
 * lugar donde se carga el dato, y el servidor es la última red.
 */
export function missingFor(method, values) {
  if (!method) return [];
  return fieldsOf(method)
    .filter((f) => f.required && !CONTACT_KEYS.includes(f.key))
    .filter((f) => String(values?.[f.key] ?? '').trim() === '')
    .map((f) => f.label);
}

/** ¿El código postal tiene el largo que pide la configuración? */
export function cpLooksValid(cp) {
  const clean = String(cp ?? '').replace(/[^A-Za-z0-9]/g, '');
  const { minLen = 4, maxLen = 8 } = cpConfig();
  return clean.length >= minLen && clean.length <= maxLen;
}

/** Espera a que el usuario deje de escribir antes de preguntarle al servidor. */
export function debounce(fn, wait = 350) {
  let timer = null;
  const run = (...args) => {
    if (timer) clearTimeout(timer);
    timer = setTimeout(() => fn(...args), wait);
  };
  run.cancel = () => timer && clearTimeout(timer);
  return run;
}
