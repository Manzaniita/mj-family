import { useState } from 'react';
import { RefreshCw, WifiOff } from 'lucide-react';
import { Button } from './Button';
import { t } from '@/lib/copy';

/**
 * Cuando no se pudo cargar algo del servidor: dice qué pasó y deja reintentar.
 * `onRetry` devuelve una promesa (o no): mientras corre, el botón gira.
 */
export function LoadError({
  title = t('ui_load_error_title'),
  text = t('ui_load_error_text'),
  onRetry,
  compact = false,
  className = '',
}) {
  const [busy, setBusy] = useState(false);
  const retry = async () => {
    if (!onRetry) {
      window.location.reload();
      return;
    }
    setBusy(true);
    try {
      await onRetry();
    } finally {
      setBusy(false);
    }
  };
  return (
    <div
      role="alert"
      className={`mj-load-error rounded-(--mj-radius-2xl) border border-line glass text-center ${compact ? 'p-8' : 'p-12 md:p-16'} ${className}`}
    >
      <span className="mx-auto grid size-14 place-items-center rounded-full bg-warning/10 text-warning" aria-hidden="true">
        <WifiOff size={24} strokeWidth={1.6} />
      </span>
      <p className="display mt-5 text-2xl">{title}</p>
      <p className="mx-auto mt-2 max-w-md text-sm leading-relaxed text-ink-soft">{text}</p>
      <Button className="mt-6" variant="secondary" onClick={retry} disabled={busy}>
        <RefreshCw size={15} className={busy ? 'animate-spin' : ''} />
        {busy ? t('ui_retrying') : t('ui_retry')}
      </Button>
    </div>
  );
}
