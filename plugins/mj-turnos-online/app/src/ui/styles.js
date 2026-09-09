/**
 * Clases compartidas por los controles de formulario (Input, Select, Textarea,
 * MoneyInput, DateInput). Un solo lugar para que todos se vean iguales.
 */
export const controlBase =
  'mjto-control block w-full min-w-0 appearance-none rounded-input border border-line bg-surface-2 text-ink placeholder:text-ink-faint transition-[border-color,background-color,box-shadow] duration-150 outline-none focus:border-accent focus:bg-surface focus:ring-4 focus:ring-accent/15 disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-danger aria-invalid:focus:ring-danger/15';

/** Alturas: 40 / 44 / 48 px (44 = objetivo táctil cómodo). */
export const controlSizes = {
  sm: 'h-10 px-3.5 text-sm',
  md: 'h-11 px-4 text-[15px]',
  lg: 'h-12 px-4 text-base',
};

export const labelCls = 'mjto-field__label mb-1.5 flex items-center gap-1 text-[13px] font-semibold text-ink-soft';
export const helpCls = 'mjto-field__help mt-1.5 text-xs leading-snug text-ink-faint';
export const errorCls = 'mjto-field__error mt-1.5 text-xs font-medium leading-snug text-danger';

/** Botón de ícono chico y redondo (cerrar, limpiar, navegar). */
export const iconBtnCls =
  'mjto-iconbtn inline-grid shrink-0 place-items-center rounded-full text-ink-soft transition-colors hover:bg-surface-2 hover:text-ink disabled:opacity-40 disabled:pointer-events-none';
