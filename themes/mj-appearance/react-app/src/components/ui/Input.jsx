import { cn } from '@/lib/format';

const labelCls = 'mj-field__label mb-1.5 block text-xs font-medium uppercase tracking-wider text-ink-faint';
const fieldCls =
  'mj-input w-full rounded-(--mj-radius-input) border border-line bg-surface px-4 text-sm text-ink placeholder:text-ink-faint transition-colors focus:border-accent/60 focus:bg-surface-2 outline-none';

/* Mensaje de error bajo el campo: los lectores de pantalla lo leen por aria-describedby. */
function FieldError({ id, error }) {
  if (!error) return null;
  return (
    <span id={id} role="alert" className="mj-field__error mt-1.5 block text-xs font-medium text-danger">
      {error}
    </span>
  );
}

const errorCls = 'border-danger/60 bg-danger/5 focus:border-danger';
const errIdOf = (id, name, error) => (error ? `${id || name || 'field'}-error` : undefined);

/**
 * `error`: texto del problema. Marca el campo (aria-invalid + borde rojo) y
 * lo muestra debajo; vacío = campo sano. `name` identifica el campo para
 * poder llevarle el foco desde la validación del formulario.
 */
export function Input({ label, className, id, error, name, ...rest }) {
  const errId = errIdOf(id, name, error);
  return (
    <label className="mj-field block">
      {label && <span className={labelCls}>{label}</span>}
      <input id={id} name={name} aria-invalid={error ? true : undefined} aria-describedby={errId} className={cn(fieldCls, 'h-11', error && errorCls, className)} {...rest} />
      <FieldError id={errId} error={error} />
    </label>
  );
}

export function Select({ label, className, id, error, name, children, ...rest }) {
  const errId = errIdOf(id, name, error);
  return (
    <label className="mj-field block">
      {label && <span className={labelCls}>{label}</span>}
      <select id={id} name={name} aria-invalid={error ? true : undefined} aria-describedby={errId} className={cn(fieldCls, 'h-11 disabled:opacity-50', error && errorCls, className)} {...rest}>
        {children}
      </select>
      <FieldError id={errId} error={error} />
    </label>
  );
}

export function Textarea({ label, className, id, error, name, ...rest }) {
  const errId = errIdOf(id, name, error);
  return (
    <label className="mj-field block">
      {label && <span className={labelCls}>{label}</span>}
      <textarea id={id} name={name} aria-invalid={error ? true : undefined} aria-describedby={errId} className={cn(fieldCls, 'min-h-32 py-3', error && errorCls, className)} {...rest} />
      <FieldError id={errId} error={error} />
    </label>
  );
}
