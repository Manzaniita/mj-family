import { Minus, Plus } from 'lucide-react';

export function QuantityStepper({ value, onChange, size = 'md', max, disabled = false }) {
  const h = size === 'sm' ? 'h-8' : 'h-10';
  const atMax = typeof max === 'number' && value >= max;
  return (
    <div className={`mj-stepper inline-flex items-center rounded-(--mj-radius-pill) glass ${h} ${disabled ? 'opacity-50' : ''}`}>
      <button
        onClick={() => onChange(value - 1)}
        disabled={disabled}
        className="grid h-full w-9 place-items-center text-ink-soft transition-colors hover:text-ink disabled:pointer-events-none"
        aria-label="Restar"
      >
        <Minus size={14} />
      </button>
      <span className="mj-stepper__value min-w-7 text-center text-sm font-semibold tabular-nums" aria-live="polite">{value}</span>
      <button
        onClick={() => onChange(value + 1)}
        disabled={disabled || atMax}
        className="grid h-full w-9 place-items-center text-ink-soft transition-colors hover:text-ink disabled:pointer-events-none disabled:opacity-40"
        aria-label="Sumar"
      >
        <Plus size={14} />
      </button>
    </div>
  );
}
