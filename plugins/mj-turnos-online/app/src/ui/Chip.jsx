import { X } from 'lucide-react';
import { cn } from '@/lib/format';

const tones = {
  neutral: 'mjto-chip--neutral bg-surface-2 text-ink-soft border-line hover:text-ink',
  accent: 'mjto-chip--accent bg-accent-soft text-accent border-accent/20',
  success: 'mjto-chip--success bg-success-soft text-success border-success/20',
  warning: 'mjto-chip--warning bg-warning-soft text-warning border-warning/20',
  danger: 'mjto-chip--danger bg-danger-soft text-danger border-danger/20',
  info: 'mjto-chip--info bg-info-soft text-info border-info/20',
  violet: 'mjto-chip--violet bg-violet-soft text-violet border-violet/20',
  outline: 'mjto-chip--outline bg-transparent text-ink border-line-strong',
};

const sizes = {
  sm: 'h-7 px-2.5 text-xs gap-1',
  md: 'h-9 px-3.5 text-sm gap-1.5',
  lg: 'h-11 px-4 text-[15px] gap-2',
};

/**
 * Chip: filtro, opción seleccionable o etiqueta.
 * Con `onClick` es un botón (aria-pressed = active); si no, un span.
 * @param {{ active?, onClick?, tone?: keyof tones, size?: 'sm'|'md'|'lg', icon?: Component, onRemove?, count?,
 *   disabled?, className?, children }} props
 */
export function Chip({ active = false, onClick, tone = 'neutral', size = 'md', icon: Icon, onRemove, count, disabled = false, className, children, ...rest }) {
  const cls = cn(
    'mjto-chip inline-flex max-w-full shrink-0 select-none items-center whitespace-nowrap rounded-chip border font-medium transition-[background-color,color,border-color,transform] duration-150',
    active
      ? 'mjto-chip--active border-accent bg-accent text-on-accent shadow-glow'
      : tones[tone] || tones.neutral,
    sizes[size] || sizes.md,
    onClick && !disabled && 'cursor-pointer active:scale-[0.96]',
    disabled && 'opacity-45 pointer-events-none',
    className
  );
  const iconPx = size === 'sm' ? 13 : size === 'lg' ? 17 : 15;
  const inner = (
    <>
      {Icon && <Icon size={iconPx} className="shrink-0" aria-hidden="true" />}
      <span className="truncate">{children}</span>
      {count !== undefined && count !== null && (
        <span
          className={cn(
            'ml-0.5 inline-grid min-w-5 place-items-center rounded-full px-1 text-[11px] font-bold leading-5 mjto-tabular',
            active ? 'bg-on-accent/20 text-on-accent' : 'bg-ink/8 text-ink-soft'
          )}
        >
          {count}
        </span>
      )}
      {onRemove && (
        <span
          role="button"
          tabIndex={0}
          aria-label="Quitar"
          onClick={(e) => {
            e.stopPropagation();
            onRemove();
          }}
          onKeyDown={(e) => {
            if (e.key === 'Enter' || e.key === ' ') {
              e.preventDefault();
              e.stopPropagation();
              onRemove();
            }
          }}
          className="-mr-1 ml-0.5 grid size-5 place-items-center rounded-full opacity-70 hover:bg-black/10 hover:opacity-100"
        >
          <X size={12} aria-hidden="true" />
        </span>
      )}
    </>
  );
  if (onClick) {
    return (
      <button type="button" aria-pressed={active} disabled={disabled} onClick={onClick} className={cls} {...rest}>
        {inner}
      </button>
    );
  }
  return (
    <span className={cls} {...rest}>
      {inner}
    </span>
  );
}

/** Fila de chips: scroll horizontal (`scroll`) o envolvente (`wrap`, default). */
export function ChipGroup({ scroll = false, className, children, ...rest }) {
  return (
    <div
      className={cn(
        'mjto-chip-group flex gap-2',
        scroll ? 'mjto-scroll-x no-scrollbar -mx-4 px-4 pb-1' : 'flex-wrap',
        className
      )}
      {...rest}
    >
      {children}
    </div>
  );
}
