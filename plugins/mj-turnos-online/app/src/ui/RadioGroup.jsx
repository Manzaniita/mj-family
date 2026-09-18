import { cn } from '@/lib/format';
import { useUid } from '@/lib/a11y';
import { errorCls, helpCls, labelCls } from './styles';

/**
 * Grupo de opciones de una sola respuesta (radios nativos con cara de pastilla).
 * Pensado para pocas opciones cortas (Sí / No): en el celular se tocan fácil y
 * las flechas del teclado funcionan solas porque los radios son nativos.
 * @param {{ label?, help?, error?, required?, name?, value: string, onChange: (value: string) => void,
 *   options: Array<{ value, label } | string>, disabled?, className?, id? }} props
 */
export function RadioGroup({ label, help, error, required, name, value, onChange, options = [], disabled = false, className, id }) {
  const uid = useUid('mjto-rg');
  const groupId = id || uid;
  const groupName = name || groupId;
  const describedBy = error || help ? `${groupId}-msg` : undefined;
  return (
    <fieldset
      id={groupId}
      className={cn('mjto-field mjto-radio-group min-w-0 border-0 p-0', className)}
      aria-invalid={error ? 'true' : undefined}
      aria-required={required || undefined}
      aria-describedby={describedBy}
      disabled={disabled}
    >
      {label && (
        <legend className={cn(labelCls, 'p-0')}>
          <span>{label}</span>
          {required && <span className="text-accent" aria-hidden="true">*</span>}
        </legend>
      )}
      <div className="flex flex-wrap gap-2">
        {options.map((o) => {
          const opt = typeof o === 'string' ? { value: o, label: o } : o;
          const v = String(opt.value ?? '');
          const checked = String(value ?? '') === v;
          return (
            <label
              key={v}
              className={cn(
                'mjto-radio-group__option relative inline-flex h-11 min-w-[4.5rem] cursor-pointer select-none items-center justify-center rounded-chip border px-4 text-[15px] font-medium transition-[background-color,color,border-color] duration-150',
                'has-[:focus-visible]:ring-4 has-[:focus-visible]:ring-accent/20',
                checked ? 'border-accent bg-accent text-on-accent shadow-glow' : error ? 'border-danger bg-surface-2 text-ink' : 'border-line bg-surface-2 text-ink hover:border-accent/40',
                disabled && 'cursor-not-allowed opacity-50'
              )}
            >
              <input
                type="radio"
                name={groupName}
                value={v}
                checked={checked}
                disabled={disabled}
                onChange={() => onChange?.(v)}
                className="absolute inset-0 size-full cursor-pointer appearance-none rounded-chip opacity-0"
              />
              <span>{opt.label ?? v}</span>
            </label>
          );
        })}
      </div>
      {error ? (
        <p id={describedBy} className={errorCls} role="alert">{error}</p>
      ) : help ? (
        <p id={describedBy} className={helpCls}>{help}</p>
      ) : null}
    </fieldset>
  );
}

export default RadioGroup;
