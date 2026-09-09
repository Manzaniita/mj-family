import { CUR, SITE, WP } from './config';

/** Formateador único según locale y decimales de la moneda configurada. */
const fmt = new Intl.NumberFormat(SITE.locale, {
  minimumFractionDigits: CUR.decimals,
  maximumFractionDigits: CUR.decimals,
});

/** Precio con símbolo y posición de la moneda configurada (CUR.position). */
export const formatPrice = (n) => {
  const amount = fmt.format(Number(n) || 0);
  switch (CUR.position) {
    case 'right':
      return `${amount}${CUR.symbol}`;
    case 'left_space':
      return `${CUR.symbol} ${amount}`;
    case 'right_space':
      return `${amount} ${CUR.symbol}`;
    case 'left':
    default:
      return `${CUR.symbol}${amount}`;
  }
};

/** Importe en dólares: "USD 120" / "USD 120,50" (plugin MJ Dólar). */
const usdFmt = (decimals) =>
  new Intl.NumberFormat(SITE.locale, { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
export const formatUsd = (n) => {
  const value = Number(n) || 0;
  return `USD ${usdFmt(Number.isInteger(value) ? 0 : 2).format(value)}`;
};

export const cn = (...classes) => classes.filter(Boolean).join(' ');

/**
 * Umbral de envío gratis — configurable desde PHP (window.MJ.freeShippingThreshold).
 * 0 (o ausente) = sin umbral → la UI debe ocultar la barra de envío gratis.
 */
export const FREE_SHIPPING_THRESHOLD = Number(WP?.freeShippingThreshold) || 0;

export const EASE = [0.22, 1, 0.36, 1];

/**
 * CUIT/CUIL argentino válido por dígito verificador (módulo 11).
 * Misma regla que MJF_User::cuit_es_valido() del plugin MJ Facturador.
 */
export const isValidCuit = (value) => {
  const cuit = String(value || '').replace(/\D+/g, '');
  if (cuit.length !== 11) return false;
  const coef = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
  let suma = 0;
  for (let i = 0; i < 10; i++) suma += Number(cuit[i]) * coef[i];
  let digito = 11 - (suma % 11);
  if (digito === 11) digito = 0;
  else if (digito === 10) digito = 9;
  return Number(cuit[10]) === digito;
};
