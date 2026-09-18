import { useEffect, useState } from 'react';
import { Banknote } from 'lucide-react';
import { EXCHANGE_RATE, IS_WP, SITE } from '@/lib/config';
import { getExchangeRate } from '@/lib/wc';
import { cn } from '@/lib/format';
import { t, tf } from '@/lib/copy';

/**
 * Tipo de cambio en el header (plugin MJ Dólar). Arranca con el valor
 * inyectado en window.MJ y lo refresca desde mjd/v1/rate al montar, así
 * sigue correcto aunque una caché de página sirva HTML viejo. Sin plugin,
 * sin TC cargado o con "mostrar en header" apagado no renderiza nada.
 */
let pending = null; // una sola consulta por carga aunque haya varias instancias
const refresh = () => {
  if (!pending) pending = getExchangeRate().catch(() => null);
  return pending;
};

export function ExchangeRate({ className }) {
  const [rate, setRate] = useState(EXCHANGE_RATE);

  useEffect(() => {
    if (!EXCHANGE_RATE || !IS_WP) return undefined;
    let alive = true;
    refresh().then((fresh) => {
      if (alive && fresh && Number(fresh.rate) > 0) setRate((prev) => ({ ...prev, ...fresh }));
    });
    return () => { alive = false; };
  }, []);

  if (!rate || rate.showHeader === false || !(Number(rate.rate) > 0)) return null;

  const updated = rate.updatedAt ? new Date(rate.updatedAt) : null;
  const title = updated && !Number.isNaN(updated.getTime())
    ? tf('rate_updated', { fecha: updated.toLocaleDateString(SITE.locale, { day: 'numeric', month: 'short' }), hora: updated.toLocaleTimeString(SITE.locale, { hour: '2-digit', minute: '2-digit' }) })
    : undefined;
  const label = rate.label || t('rate_label');

  return (
    <span
      className={cn(
        'mj-rate inline-flex h-8 shrink-0 items-center gap-1.5 rounded-(--mj-radius-pill) glass px-2.5 text-xs font-semibold tabular-nums text-ink-soft',
        className
      )}
      title={title}
      aria-label={`${label} ${rate.formatted}`}
    >
      <Banknote size={13} className="shrink-0 text-accent" aria-hidden="true" />
      <span className="mj-rate__label hidden text-[10px] font-semibold uppercase tracking-[0.14em] text-ink-faint md:inline">
        {label}
      </span>
      <span className="mj-rate__value text-ink">{rate.formatted}</span>
    </span>
  );
}
