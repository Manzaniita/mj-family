import { Loader2 } from 'lucide-react';
import { cn } from '@/lib/format';

/** Indicador de carga girando. @param {{ size?: number, className? }} props */
export function Spinner({ size = 20, className, label = 'Cargando' }) {
  return (
    <span role="status" aria-label={label} className={cn('mjto-spinner inline-flex text-accent', className)}>
      <Loader2 size={size} className="animate-spin-soft" aria-hidden="true" />
    </span>
  );
}

/** Bloque centrado con spinner y texto opcional (para listas y vistas). */
export function LoadingBlock({ text, className, size = 24 }) {
  return (
    <div className={cn('mjto-loading flex flex-col items-center justify-center gap-3 py-12 text-sm text-ink-faint', className)}>
      <Spinner size={size} />
      {text && <span>{text}</span>}
    </div>
  );
}

/**
 * Placeholder animado. Con `lines` dibuja varias líneas de texto.
 * @param {{ className?, lines?: number, style? }} props
 */
export function Skeleton({ className, lines, style }) {
  if (lines) {
    return (
      <div className={cn('mjto-skeleton-lines flex flex-col gap-2', className)} aria-hidden="true">
        {Array.from({ length: lines }, (_, i) => (
          <span key={i} className="mjto-skeleton block h-3.5" style={{ width: `${i === lines - 1 ? 60 : 100 - (i % 3) * 10}%` }} />
        ))}
      </div>
    );
  }
  return <span className={cn('mjto-skeleton block', className)} style={style} aria-hidden="true" />;
}

/** Tarjeta esqueleto (misma silueta que una tarjeta de turno). */
export function SkeletonCard({ className }) {
  return (
    <div className={cn('mjto-card flex gap-4 p-4', className)} aria-hidden="true">
      <Skeleton className="h-12 w-14 shrink-0 rounded-xl" />
      <div className="flex-1">
        <Skeleton lines={3} />
      </div>
    </div>
  );
}
