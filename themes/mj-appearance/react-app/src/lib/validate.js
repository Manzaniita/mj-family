/**
 * Validación de formularios, en vivo y al enviar.
 *
 * Cada regla devuelve el mensaje para el cliente o '' si el valor está bien.
 * Las reglas son deliberadamente amplias: un teléfono con guiones o un CPA
 * (letra + 4 números + 3 letras) tienen que pasar; lo estricto lo decide el
 * servidor al crear el pedido. Acá se trata de avisar antes de apretar.
 */

import { t } from './copy';

const digits = (v) => String(v || '').replace(/\D+/g, '');

export const rules = {
  required: (v) => (String(v ?? '').trim() ? '' : t('validate_required')),
  email: (v) => {
    const s = String(v || '').trim();
    if (!s) return '';
    return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(s) ? '' : t('validate_email');
  },
  phone: (v) => {
    const s = String(v || '').trim();
    if (!s) return '';
    const d = digits(s);
    if (d.length < 8) return t('validate_phone_short');
    if (d.length > 15) return t('validate_phone_long');
    return /^[+\d\s().-]+$/.test(s) ? '' : t('validate_phone_chars');
  },
  postcode: (v) => {
    const s = String(v || '').trim().toUpperCase();
    if (!s) return '';
    return /^(\d{4}|[A-Z]\d{4}[A-Z]{3})$/.test(s) ? '' : t('validate_postcode');
  },
  name: (v) => {
    const s = String(v || '').trim();
    if (!s) return '';
    return s.length >= 2 ? '' : t('validate_name');
  },
};

/**
 * Valida un valor contra una lista de reglas, en orden; devuelve el primer
 * mensaje. `required` va primero para que un campo vacío diga "completá"
 * y no "revisá el email".
 */
export function validate(value, list = []) {
  for (const rule of list) {
    const fn = typeof rule === 'function' ? rule : rules[rule];
    const msg = fn ? fn(value) : '';
    if (msg) return msg;
  }
  return '';
}

/**
 * Valida un formulario entero con un esquema { campo: [reglas] }.
 * Devuelve { campo: mensaje } solo con los campos que fallan.
 */
export function validateAll(form, schema) {
  const errors = {};
  for (const [key, list] of Object.entries(schema)) {
    const msg = validate(form[key], list);
    if (msg) errors[key] = msg;
  }
  return errors;
}

/** Lleva el foco al primer campo con error (busca por atributo name). */
export function focusFirstError(errors, root = document) {
  const key = Object.keys(errors)[0];
  if (!key) return;
  const el = root.querySelector(`[name="${key}"]`);
  if (el?.focus) {
    el.focus({ preventScroll: true });
    el.scrollIntoView?.({ block: 'center', behavior: 'smooth' });
  }
}
