import { Star } from 'lucide-react';
import { cn } from '@/lib/format';
import { useUid } from '@/lib/a11y';
import { errorCls, labelCls } from './styles';

/**
 * Puntaje de 1 a 5 estrellas. Con `onChange` es un grupo de radios nativos
 * (teclado y lector de pantalla funcionan solos); sin `onChange` solo muestra.
 * Cada estrella se anuncia con su número: no hay texto fijo en el componente.
 * @param {{ value: number, onChange?: (n: number) => void, label?, error?, required?, max?, size?: number,
 *   disabled?, className?, id? }} props
 */
export function StarRating({ value = 0, onChange, label, error, required, max = 5, size = 28, disabled = false, className, id }) {
  const uid = useUid('mjto-stars');
  const groupId = id || uid;
  const current = Math.max(0, Math.min(max, Number(value) || 0));
  const stars = Array.from({ length: max }, (_, i) => i + 1);

  if (typeof onChange !== 'function') {
    return (
      <span className={cn('mjto-stars inline-flex items-center gap-0.5 text-accent', className)} role="img" aria-label={`${current}/${max}`}>
        {stars.map((n) => (
          <Star key={n} size={size} aria-hidden="true" className={n <= current ? 'fill-current' : 'text-line-strong'} />
        ))}
      </span>
    );
  }

  return (
    <fieldset id={groupId} className={cn('mjto-field mjto-stars min-w-0 border-0 p-0', className)} disabled={disabled} aria-invalid={error ? 'true' : undefined}>
      {label && (
        <legend className={cn(labelCls, 'p-0')}>
          <span>{label}</span>
          {required && <span className="text-accent" aria-hidden="true">*</span>}
        </legend>
      )}
      <div className="flex items-center gap-1">
        {stars.map((n) => (
          <label
            key={n}
            className={cn(
              'relative grid size-11 cursor-pointer place-items-center rounded-full transition-colors hover:bg-accent-soft',
              'has-[:focus-visible]:ring-4 has-[:focus-visible]:ring-accent/20',
              disabled && 'cursor-not-allowed opacity-50'
            )}
          >
            <input
              type="radio"
              name={groupId}
              value={n}
              checked={current === n}
              onChange={() => onChange(n)}
              aria-label={`${n}/${max}`}
              className="absolute inset-0 size-full cursor-pointer appearance-none rounded-full opacity-0"
            />
            <Star size={size} aria-hidden="true" className={n <= current ? 'fill-current text-accent' : 'text-line-strong'} />
          </label>
        ))}
      </div>
      {error && <p className={errorCls} role="alert">{error}</p>}
    </fieldset>
  );
}

export default StarRating;
