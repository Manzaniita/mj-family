import { forwardRef } from 'react';
import { ChevronDown } from 'lucide-react';
import { cn } from '@/lib/format';
import { useUid } from '@/lib/a11y';
import { Field } from './Field';
import { controlBase, controlSizes } from './styles';

/**
 * Select nativo (el mejor en celulares) con chevron propio.
 * @param {{ label?, help?, error?, required?, id?, size?, placeholder? (opción vacía deshabilitada),
 *   options?: Array<{ value, label, disabled? } | string>, children?, className?, selectClassName?, ...select }} props
 */
export const Select = forwardRef(function Select(
  { label, help, error, required, id, size = 'md', placeholder, options, children, className, selectClassName, hint, ...rest },
  ref
) {
  const uid = useUid('mjto-sel');
  const selectId = id || uid;
  return (
    <Field label={label} help={help} error={error} required={required} htmlFor={selectId} hint={hint} className={className}>
      <div className="relative flex items-center">
        <select
          ref={ref}
          id={selectId}
          aria-invalid={error ? 'true' : undefined}
          aria-required={required || undefined}
          className={cn(controlBase, controlSizes[size] || controlSizes.md, 'cursor-pointer pr-10', selectClassName)}
          {...rest}
        >
          {placeholder !== undefined && (
            <option value="" disabled={!!required}>
              {placeholder}
            </option>
          )}
          {Array.isArray(options)
            ? options.map((o) => {
                const opt = typeof o === 'string' ? { value: o, label: o } : o;
                return (
                  <option key={String(opt.value)} value={opt.value} disabled={opt.disabled}>
                    {opt.label ?? opt.value}
                  </option>
                );
              })
            : children}
        </select>
        <ChevronDown size={18} className="pointer-events-none absolute right-3.5 text-ink-faint" aria-hidden="true" />
      </div>
    </Field>
  );
});
