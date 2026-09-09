import { forwardRef } from 'react';
import { CalendarDays, Sparkles, X } from 'lucide-react';
import { cn } from '@/lib/format';
import { useUid } from '@/lib/a11y';
import { Field } from './Field';
import { controlBase, controlSizes, iconBtnCls } from './styles';

/**
 * Fecha (input nativo type="date": el mejor en celulares) con botón opcional
 * "Sugerir" (control / retoque) y limpiar.
 * @param {{ value: 'YYYY-MM-DD'|'', onChange: (value: string) => void, label?, help?, error?, required?,
 *   min?, max?, onSuggest?: () => void, suggestLabel?: string, clearable?: boolean, size?, className?, inputClassName?, id?, disabled? }} props
 */
export const DateInput = forwardRef(function DateInput(
  { value, onChange, label, help, error, required, min, max, onSuggest, suggestLabel = 'Sugerir', clearable = false, size = 'md', className, inputClassName, id, hint, disabled, ...rest },
  ref
) {
  const uid = useUid('mjto-date');
  const inputId = id || uid;
  return (
    <Field label={label} help={help} error={error} required={required} htmlFor={inputId} hint={hint} className={className}>
      <div className="flex items-center gap-2">
        <div className="relative flex min-w-0 flex-1 items-center">
          <CalendarDays size={18} className="pointer-events-none absolute left-3.5 text-ink-faint" aria-hidden="true" />
          <input
            ref={ref}
            id={inputId}
            type="date"
            value={value || ''}
            min={min || undefined}
            max={max || undefined}
            disabled={disabled}
            aria-invalid={error ? 'true' : undefined}
            aria-required={required || undefined}
            onChange={(e) => onChange?.(e.target.value || '')}
            className={cn(controlBase, controlSizes[size] || controlSizes.md, 'mjto-tabular pl-10', clearable && value && 'pr-10', inputClassName)}
            {...rest}
          />
          {clearable && value && !disabled && (
            <button type="button" onClick={() => onChange?.('')} className={cn(iconBtnCls, 'absolute right-1.5 size-8')} aria-label="Limpiar fecha">
              <X size={15} aria-hidden="true" />
            </button>
          )}
        </div>
        {onSuggest && (
          <button
            type="button"
            onClick={onSuggest}
            disabled={disabled}
            className={cn(
              'mjto-date__suggest inline-flex shrink-0 items-center gap-1.5 rounded-btn bg-accent-soft px-3.5 text-[13px] font-semibold text-accent transition-colors hover:bg-accent/15 disabled:opacity-40',
              controlSizes[size]?.split(' ')[0] || 'h-11'
            )}
          >
            <Sparkles size={15} aria-hidden="true" />
            {suggestLabel}
          </button>
        )}
      </div>
    </Field>
  );
});
