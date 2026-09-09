import { cn } from '@/lib/format';
import { useUid } from '@/lib/a11y';

/**
 * Interruptor accesible (role="switch").
 * @param {{ checked: boolean, onChange: (next: boolean) => void, label?, description?, disabled?,
 *   labelPosition?: 'right'|'left', size?: 'sm'|'md', className?, id? }} props
 */
export function Toggle({ checked = false, onChange, label, description, disabled = false, labelPosition = 'right', size = 'md', className, id }) {
  const uid = useUid('mjto-tg');
  const switchId = id || uid;
  const control = (
    <button
      id={switchId}
      type="button"
      role="switch"
      aria-checked={checked}
      aria-describedby={description ? `${switchId}-desc` : undefined}
      disabled={disabled}
      onClick={() => onChange?.(!checked)}
      className={cn(
        'mjto-switch focus-visible:ring-4 focus-visible:ring-accent/20 focus-visible:outline-none disabled:opacity-50',
        size === 'sm' && 'scale-90'
      )}
      data-on={checked ? 'true' : 'false'}
    />
  );

  if (!label && !description) return <span className={cn('mjto-toggle inline-flex', className)}>{control}</span>;

  return (
    <label
      htmlFor={switchId}
      className={cn(
        'mjto-toggle flex cursor-pointer items-start gap-3 select-none',
        labelPosition === 'left' && 'flex-row-reverse justify-between',
        disabled && 'cursor-not-allowed opacity-70',
        className
      )}
    >
      {control}
      <span className="min-w-0 flex-1 pt-0.5">
        {label && <span className="block text-[15px] font-medium leading-tight text-ink">{label}</span>}
        {description && (
          <span id={`${switchId}-desc`} className="mt-0.5 block text-xs leading-snug text-ink-faint">
            {description}
          </span>
        )}
      </span>
    </label>
  );
}

/**
 * Casilla (checkbox) con el mismo look; para listas de opciones y "Avisar por email".
 * @param {{ checked, onChange: (next: boolean) => void, label?, description?, disabled?, className?, id? }} props
 */
export function Checkbox({ checked = false, onChange, label, description, disabled = false, className, id }) {
  const uid = useUid('mjto-cb');
  const cbId = id || uid;
  return (
    <label htmlFor={cbId} className={cn('mjto-checkbox flex cursor-pointer items-start gap-3 select-none', disabled && 'cursor-not-allowed opacity-60', className)}>
      <span className="relative mt-0.5 inline-grid size-5 shrink-0 place-items-center">
        <input
          id={cbId}
          type="checkbox"
          checked={checked}
          disabled={disabled}
          onChange={(e) => onChange?.(e.target.checked)}
          className="peer size-5 cursor-pointer appearance-none rounded-md border border-line-strong bg-surface transition-colors checked:border-accent checked:bg-accent focus-visible:ring-4 focus-visible:ring-accent/20 focus-visible:outline-none"
        />
        <svg
          viewBox="0 0 16 16"
          className="pointer-events-none absolute size-3.5 text-on-accent opacity-0 transition-opacity peer-checked:opacity-100"
          aria-hidden="true"
        >
          <path d="M3.5 8.5l2.8 2.8L12.5 5" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
      </span>
      {(label || description) && (
        <span className="min-w-0 flex-1">
          {label && <span className="block text-[15px] leading-tight text-ink">{label}</span>}
          {description && <span className="mt-0.5 block text-xs leading-snug text-ink-faint">{description}</span>}
        </span>
      )}
    </label>
  );
}
