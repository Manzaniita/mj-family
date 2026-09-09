import { Check } from 'lucide-react';
import { PAYMENT_PRICING } from '@/lib/config';
import { payMethods, usePaymentMethod, selectedMethodId, priceFor, usdTotalFor } from '@/lib/payments';
import { formatPrice, formatUsd, cn } from '@/lib/format';

/**
 * Ficha: precio del producto con cada medio de pago (plugin MJ Medios de
 * Pago), del más barato al más caro. Clic en uno = elegirlo para toda la
 * tienda (mismo estado que el selector del header).
 */
export function PaymentPanel({ product }) {
  const id = usePaymentMethod((s) => s.id);
  const setId = usePaymentMethod((s) => s.set);
  const methods = payMethods();
  if (!PAYMENT_PRICING || PAYMENT_PRICING.showPanel === false || methods.length < 2 || !product) return null;

  const current = selectedMethodId(id);
  const rows = methods
    .map((m) => ({ m, ...priceFor(product, m) }))
    .sort((a, b) => a.price - b.price);

  return (
    <div className="mj-paypanel mt-5 border-t border-line pt-4">
      <p className="mb-2 text-xs font-semibold uppercase tracking-[0.15em] text-ink-faint">Precio según medio de pago</p>
      <ul className="space-y-1.5" role="list">
        {rows.map(({ m, price, applied }) => {
          const active = m.id === current;
          const usd = usdTotalFor(price, m);
          return (
            <li key={m.id}>
              <button
                type="button"
                onClick={() => setId(m.id)}
                aria-pressed={active}
                title={m.description || undefined}
                className={cn(
                  'mj-paypanel__row flex w-full items-center justify-between gap-3 rounded-xl2 border px-3 py-2 text-left text-sm transition-colors',
                  active ? 'border-accent bg-accent-soft' : 'border-line hover:border-line-strong'
                )}
              >
                <span className="flex min-w-0 items-center gap-2">
                  <span className={cn('grid size-4 shrink-0 place-items-center rounded-full border-2 transition-colors', active ? 'border-accent bg-accent' : 'border-line-strong')}>
                    {active && <Check size={9} className="text-white" strokeWidth={3} />}
                  </span>
                  <span className="truncate font-medium">{m.short || m.title}</span>
                  {applied && m.kind === 'discount' && (
                    <span className="shrink-0 rounded-(--mj-radius-badge) bg-success/15 px-1.5 py-0.5 text-[10px] font-bold text-success">{m.value}% OFF</span>
                  )}
                  {applied && m.kind === 'surcharge' && (
                    <span className="shrink-0 rounded-(--mj-radius-badge) bg-surface-2 px-1.5 py-0.5 text-[10px] font-bold text-ink-faint">+{m.value}%</span>
                  )}
                </span>
                <span className="shrink-0 text-right">
                  <span className="font-display font-semibold tabular-nums">{formatPrice(price)}</span>
                  {usd != null && <span className="block text-[11px] text-ink-faint">{formatUsd(usd)}</span>}
                </span>
              </button>
            </li>
          );
        })}
      </ul>
    </div>
  );
}
