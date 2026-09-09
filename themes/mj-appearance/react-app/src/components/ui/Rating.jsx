import { Star } from 'lucide-react';
import { cn } from '@/lib/format';

export function Rating({ value = 0, count, className }) {
  return (
    <div className={cn('mj-rating flex items-center gap-1.5', className)} aria-label={`${value} de 5`}>
      <div className="flex gap-0.5">
        {[1, 2, 3, 4, 5].map((i) => (
          <Star
            key={i}
            size={13}
            className={i <= Math.round(value) ? 'fill-(--mj-star) text-(--mj-star)' : 'text-line-strong'}
          />
        ))}
      </div>
      {count != null && <span className="text-xs text-ink-faint">({count})</span>}
    </div>
  );
}
