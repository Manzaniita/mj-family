import { useMemo } from 'react';
import { cn } from '@/lib/format';
import { timeToMinutes } from '@/lib/dates';
import { Skeleton } from './Spinner';

const DEFAULT_GROUPS = { morning: 'Mañana', afternoon: 'Tarde', evening: 'Noche' };

const groupOf = (time) => {
  const min = timeToMinutes(time);
  if (min < 12 * 60) return 'morning';
  if (min < 18 * 60) return 'afternoon';
  return 'evening';
};

/**
 * Horarios libres como chips, agrupados por franja del día.
 * @param {{ slots?: string[] ('HH:MM'), value?: string, onChange?: (time) => void, loading?: boolean,
 *   emptyText?: string, grouped?: boolean, groupLabels?: { morning, afternoon, evening },
 *   disabledSlots?: string[], size?: 'sm'|'md', className? }} props
 */
export function SlotPicker({
  slots = [],
  value,
  onChange,
  loading = false,
  emptyText = 'No quedan horarios libres ese día.',
  grouped = true,
  groupLabels = DEFAULT_GROUPS,
  disabledSlots = [],
  size = 'md',
  className,
}) {
  const groups = useMemo(() => {
    const out = { morning: [], afternoon: [], evening: [] };
    slots.forEach((s) => out[groupOf(s)].push(s));
    return out;
  }, [slots]);

  const chip = (time) => {
    const active = time === value;
    const off = disabledSlots.includes(time);
    return (
      <button
        key={time}
        type="button"
        disabled={off}
        aria-pressed={active}
        onClick={() => onChange?.(time)}
        className={cn(
          'mjto-slot rounded-chip border font-semibold mjto-tabular transition-[background-color,color,border-color,transform] duration-150 active:scale-[0.96] disabled:opacity-35 disabled:line-through',
          size === 'sm' ? 'h-9 text-[13px]' : 'h-11 text-sm',
          active ? 'mjto-slot--active border-accent bg-accent text-on-accent shadow-glow' : 'border-line bg-surface text-ink hover:border-accent/50 hover:bg-accent-soft'
        )}
      >
        {time}
      </button>
    );
  };

  const gridCls = 'grid grid-cols-[repeat(auto-fill,minmax(4.75rem,1fr))] gap-2';

  if (loading) {
    return (
      <div className={cn('mjto-slots', gridCls, className)} aria-busy="true">
        {Array.from({ length: 8 }, (_, i) => (
          <Skeleton key={i} className={cn('rounded-chip', size === 'sm' ? 'h-9' : 'h-11')} />
        ))}
      </div>
    );
  }

  if (!slots.length) {
    return <p className={cn('mjto-slots mjto-slots--empty rounded-tile bg-surface-2 px-4 py-5 text-center text-sm text-ink-soft', className)}>{emptyText}</p>;
  }

  if (!grouped) {
    return <div className={cn('mjto-slots', gridCls, className)}>{slots.map(chip)}</div>;
  }

  return (
    <div className={cn('mjto-slots flex flex-col gap-4', className)}>
      {['morning', 'afternoon', 'evening']
        .filter((k) => groups[k].length)
        .map((k) => (
          <div key={k} className="mjto-slots__group">
            <p className="mb-2 text-[11px] font-semibold uppercase tracking-wider text-ink-faint">{groupLabels[k] || DEFAULT_GROUPS[k]}</p>
            <div className={gridCls}>{groups[k].map(chip)}</div>
          </div>
        ))}
    </div>
  );
}
