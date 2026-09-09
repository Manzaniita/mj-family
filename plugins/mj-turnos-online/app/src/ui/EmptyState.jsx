import { cn } from '@/lib/format';

/**
 * Estado vacío amable: ícono en un círculo con el acento, título, texto y acción.
 * @param {{ icon?: Component, title: string, text?: string, action?: ReactNode, compact?: boolean, className? }} props
 */
export function EmptyState({ icon: Icon, title, text, action, compact = false, className }) {
  return (
    <div
      className={cn(
        'mjto-empty flex flex-col items-center justify-center text-center',
        compact ? 'gap-2 px-4 py-8' : 'gap-3 px-6 py-14',
        className
      )}
    >
      {Icon && (
        <span
          className={cn(
            'mjto-empty__icon grid place-items-center rounded-full bg-accent-soft text-accent',
            compact ? 'size-11' : 'size-16'
          )}
          aria-hidden="true"
        >
          <Icon size={compact ? 20 : 28} strokeWidth={1.75} />
        </span>
      )}
      <div className="max-w-sm">
        <p className={cn('mjto-empty__title font-display font-semibold tracking-tight text-ink', compact ? 'text-[15px]' : 'text-lg')}>
          {title}
        </p>
        {text && <p className="mjto-empty__text mt-1 text-sm leading-relaxed text-ink-soft">{text}</p>}
      </div>
      {action && <div className="mjto-empty__action mt-2 flex flex-wrap justify-center gap-2">{action}</div>}
    </div>
  );
}
