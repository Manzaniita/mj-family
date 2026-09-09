import { cn } from '@/lib/format';

/**
 * Horarios libres de un día (chips 'HH:MM'), paso 2 del wizard de reserva.
 *  - slots: ['09:00', '09:30', …] (respuesta de GET /availability para el día).
 *  - value: horario elegido | null; onChange('HH:MM').
 *  - emptyText: qué mostrar si no hay horarios (booking_no_slots del plugin);
 *    sin texto, no renderiza nada.
 */
export function SlotPicker({ slots = [], value = null, onChange, emptyText, disabled = false, className }) {
  const list = Array.isArray(slots) ? slots.filter(Boolean) : [];

  if (list.length === 0) {
    return emptyText ? (
      <p className={cn('mj-booking__slots-empty rounded-xl2 border border-line bg-surface p-5 text-center text-sm text-ink-soft', className)}>
        {emptyText}
      </p>
    ) : null;
  }

  return (
    <div role="group" className={cn('mj-booking__slots grid grid-cols-3 gap-2 sm:grid-cols-4', className)}>
      {list.map((time) => {
        const active = time === value;
        return (
          <button
            key={time}
            type="button"
            aria-pressed={active}
            disabled={disabled}
            onClick={() => onChange?.(time)}
            className={cn(
              'mj-booking__slot mj-chip h-11 rounded-(--mj-radius-chip) border text-sm font-medium tabular-nums transition-all duration-200 disabled:opacity-40 disabled:pointer-events-none',
              active
                ? 'mj-booking__slot--active mj-chip--active border-accent bg-accent text-white shadow-glow'
                : 'border-line bg-surface text-ink hover:border-accent/50 hover:text-accent'
            )}
          >
            {time}
          </button>
        );
      })}
    </div>
  );
}
