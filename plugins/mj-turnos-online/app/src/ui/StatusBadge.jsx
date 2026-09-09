import { cn } from '@/lib/format';

/** Orden canónico de los estados de un turno. */
export const STATUS_ORDER = ['pending', 'awaiting_deposit', 'confirmed', 'completed', 'cancelled', 'no_show'];

/** Estados "vivos" (ocupan agenda). */
export const ACTIVE_STATUSES = ['pending', 'awaiting_deposit', 'confirmed'];

/** Tono visual por estado. */
export const STATUS_TONES = {
  pending: 'warning',
  awaiting_deposit: 'violet',
  confirmed: 'success',
  completed: 'info',
  cancelled: 'neutral',
  no_show: 'danger',
};

export const statusTone = (status) => STATUS_TONES[status] || 'neutral';

const tones = {
  neutral: 'mjto-badge--neutral bg-surface-3 text-ink-soft',
  accent: 'mjto-badge--accent bg-accent-soft text-accent',
  success: 'mjto-badge--success bg-success-soft text-success',
  warning: 'mjto-badge--warning bg-warning-soft text-warning',
  danger: 'mjto-badge--danger bg-danger-soft text-danger',
  info: 'mjto-badge--info bg-info-soft text-info',
  violet: 'mjto-badge--violet bg-violet-soft text-violet',
  solid: 'mjto-badge--solid bg-accent text-on-accent',
  ink: 'mjto-badge--ink bg-ink text-bg',
};

const dotColors = {
  neutral: 'bg-ink-faint',
  accent: 'bg-accent',
  success: 'bg-success',
  warning: 'bg-warning',
  danger: 'bg-danger',
  info: 'bg-info',
  violet: 'bg-violet',
  solid: 'bg-on-accent',
  ink: 'bg-bg',
};

const sizes = {
  sm: 'h-5 px-2 text-[10.5px]',
  md: 'h-6 px-2.5 text-[11px]',
  lg: 'h-7 px-3 text-xs',
};

/**
 * Etiqueta chica de estado/categoría.
 * @param {{ tone?: keyof tones, size?: 'sm'|'md'|'lg', dot?: boolean, icon?: Component, className?, children }} props
 */
export function Badge({ tone = 'neutral', size = 'md', dot = false, icon: Icon, className, children, ...rest }) {
  return (
    <span
      className={cn(
        'mjto-badge inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-full font-bold uppercase tracking-wider',
        tones[tone] || tones.neutral,
        sizes[size] || sizes.md,
        className
      )}
      {...rest}
    >
      {dot && <span className={cn('size-1.5 rounded-full', dotColors[tone] || dotColors.neutral)} aria-hidden="true" />}
      {Icon && <Icon size={12} aria-hidden="true" />}
      {children}
    </span>
  );
}

/**
 * Estado de un turno con su color. `label` viene de options.labels (status_labels);
 * `labels` acepta el mapa completo { pending: 'Pendiente', … } para no repetir.
 * @param {{ status: string, label?: string, labels?: Record<string,string>, size?, dot?, className? }} props
 */
export function StatusBadge({ status, label, labels, size = 'md', dot = true, className, ...rest }) {
  const text = label ?? labels?.[status] ?? labels?.statuses?.[status] ?? status;
  return (
    <Badge tone={statusTone(status)} size={size} dot={dot} className={cn(`mjto-status mjto-status--${status}`, className)} {...rest}>
      {text}
    </Badge>
  );
}
