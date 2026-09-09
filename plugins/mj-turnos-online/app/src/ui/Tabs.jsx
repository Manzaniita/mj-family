import { useRef, useState } from 'react';
import { cn } from '@/lib/format';

/**
 * Pestañas controladas o no. Sólo la barra: el caller renderiza el contenido
 * según `value` (así cada vista maneja su carga).
 * @param {{ items: Array<{ key: string, label: string, count?: number, icon?: Component, disabled? }>,
 *   value?: string, defaultValue?: string, onChange?: (key: string) => void,
 *   variant?: 'pill'|'line', size?: 'sm'|'md', full?: boolean, className? }} props
 */
export function Tabs({ items = [], value, defaultValue, onChange, variant = 'pill', size = 'md', full = false, className }) {
  const [inner, setInner] = useState(defaultValue ?? items[0]?.key ?? '');
  const active = value !== undefined ? value : inner;
  const listRef = useRef(null);

  const select = (key) => {
    if (value === undefined) setInner(key);
    onChange?.(key);
  };

  const onKeyDown = (e) => {
    const enabled = items.filter((t) => !t.disabled);
    const idx = enabled.findIndex((t) => t.key === active);
    if (idx === -1) return;
    let next = idx;
    if (e.key === 'ArrowRight') next = (idx + 1) % enabled.length;
    else if (e.key === 'ArrowLeft') next = (idx - 1 + enabled.length) % enabled.length;
    else if (e.key === 'Home') next = 0;
    else if (e.key === 'End') next = enabled.length - 1;
    else return;
    e.preventDefault();
    select(enabled[next].key);
    listRef.current?.querySelector(`[data-key="${enabled[next].key}"]`)?.focus();
  };

  const isPill = variant === 'pill';
  const pad = size === 'sm' ? 'h-8 px-3 text-[13px]' : 'h-10 px-4 text-sm';

  return (
    <div
      ref={listRef}
      role="tablist"
      onKeyDown={onKeyDown}
      className={cn(
        'mjto-tabs no-scrollbar flex max-w-full overflow-x-auto',
        isPill ? 'w-fit gap-1 rounded-chip bg-surface-2 p-1' : 'gap-1 mjto-hairline-b',
        full && 'w-full',
        className
      )}
    >
      {items.map((t) => {
        const isActive = t.key === active;
        const Icon = t.icon;
        return (
          <button
            key={t.key}
            type="button"
            role="tab"
            data-key={t.key}
            aria-selected={isActive}
            tabIndex={isActive ? 0 : -1}
            disabled={t.disabled}
            onClick={() => select(t.key)}
            className={cn(
              'mjto-tabs__tab relative inline-flex shrink-0 items-center justify-center gap-1.5 whitespace-nowrap font-semibold transition-colors disabled:opacity-40',
              full && 'flex-1',
              pad,
              isPill
                ? cn('rounded-chip', isActive ? 'mjto-tabs__tab--active bg-surface text-ink shadow-card' : 'text-ink-soft hover:text-ink')
                : cn(
                    '-mb-px border-b-2 px-2',
                    isActive ? 'mjto-tabs__tab--active border-accent text-ink' : 'border-transparent text-ink-soft hover:text-ink'
                  )
            )}
          >
            {Icon && <Icon size={16} aria-hidden="true" />}
            <span>{t.label}</span>
            {t.count !== undefined && t.count !== null && (
              <span
                className={cn(
                  'inline-grid min-w-5 place-items-center rounded-full px-1.5 text-[11px] font-bold leading-5 mjto-tabular',
                  isActive ? 'bg-accent-soft text-accent' : 'bg-ink/8 text-ink-soft'
                )}
              >
                {t.count}
              </span>
            )}
          </button>
        );
      })}
    </div>
  );
}
