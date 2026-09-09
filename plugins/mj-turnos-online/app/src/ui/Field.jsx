import { cn } from '@/lib/format';
import { labelCls, helpCls, errorCls } from './styles';

/**
 * Envoltorio de un control: etiqueta + ayuda + error.
 * @param {{ label?, help?, error?, required?, htmlFor?, hint?: ReactNode (a la derecha de la etiqueta), className?, children }} props
 */
export function Field({ label, help, error, required, htmlFor, hint, className, children }) {
  return (
    <div className={cn('mjto-field min-w-0', className)}>
      {(label || hint) && (
        <div className="flex items-baseline justify-between gap-3">
          {label ? (
            <label htmlFor={htmlFor} className={labelCls}>
              <span>{label}</span>
              {required && <span className="text-accent" aria-hidden="true">*</span>}
            </label>
          ) : (
            <span />
          )}
          {hint && <span className="mb-1.5 text-xs text-ink-faint">{hint}</span>}
        </div>
      )}
      {children}
      {error ? (
        <p className={errorCls} role="alert">{error}</p>
      ) : help ? (
        <p className={helpCls}>{help}</p>
      ) : null}
    </div>
  );
}

/** Grilla responsive para filas de campos: 1 columna en mobile, `cols` en sm+. */
export function FieldRow({ cols = 2, className, children }) {
  const grid = { 1: 'sm:grid-cols-1', 2: 'sm:grid-cols-2', 3: 'sm:grid-cols-3', 4: 'sm:grid-cols-4' }[cols] || 'sm:grid-cols-2';
  return <div className={cn('mjto-field-row grid grid-cols-1 gap-3', grid, className)}>{children}</div>;
}
