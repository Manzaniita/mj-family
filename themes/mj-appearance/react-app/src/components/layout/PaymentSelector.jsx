import { CreditCard, ChevronDown } from 'lucide-react';
import { PAYMENT_PRICING } from '@/lib/config';
import { t, tf } from '@/lib/copy';
import { payMethods, usePaymentMethod, selectedMethodId } from '@/lib/payments';
import { cn } from '@/lib/format';

/**
 * "Ver precios con: Transferencia" — el visitante elige con qué medio de pago
 * ver los precios en toda la tienda (plugin MJ Medios de Pago). Se recuerda en
 * el navegador y el carrito del servidor se sincroniza solo (store/index.js).
 * Sin plugin, apagado en el admin o con un solo método, no renderiza nada.
 *
 * variant "pill" (header) | "block" (carrito, menú mobile).
 */
export function PaymentSelector({ className, variant = 'pill' }) {
  const id = usePaymentMethod((s) => s.id);
  const setId = usePaymentMethod((s) => s.set);
  const methods = payMethods();
  if (!PAYMENT_PRICING || PAYMENT_PRICING.showSelector === false || methods.length < 2) return null;

  const current = selectedMethodId(id);
  const label = PAYMENT_PRICING.selectorLabel || t('price_selector_label');
  const optionText = (m) =>
    `${m.short || m.title}${m.kind === 'discount' ? ` · ${tf('price_off', { n: m.value })}` : m.kind === 'surcharge' ? ` · +${m.value}%` : ''}`;

  const select = (
    <span className="relative flex items-center">
      <select
        value={current ?? ''}
        onChange={(e) => setId(e.target.value)}
        aria-label={label}
        className={cn(
          'mj-paysel__select cursor-pointer appearance-none bg-transparent pr-4 font-semibold text-ink outline-none',
          variant === 'pill' ? 'text-xs' : 'text-sm'
        )}
      >
        {methods.map((m) => (
          <option key={m.id} value={m.id}>{optionText(m)}</option>
        ))}
      </select>
      <ChevronDown size={variant === 'pill' ? 12 : 14} className="pointer-events-none absolute right-0 text-ink-faint" />
    </span>
  );

  if (variant === 'block') {
    return (
      <label className={cn('mj-paysel mj-paysel--block flex items-center gap-2.5 rounded-xl2 border border-line px-3 py-2.5', className)}>
        <CreditCard size={15} className="shrink-0 text-accent" />
        <span className="text-[11px] font-semibold uppercase tracking-[0.14em] text-ink-faint">{label}</span>
        <span className="ml-auto">{select}</span>
      </label>
    );
  }

  return (
    <label
      className={cn('mj-paysel inline-flex h-8 shrink-0 items-center gap-1.5 rounded-(--mj-radius-pill) glass px-2.5 text-xs text-ink-soft', className)}
      title={label}
    >
      <CreditCard size={13} className="shrink-0 text-accent" />
      <span className="hidden text-[10px] font-semibold uppercase tracking-[0.14em] text-ink-faint lg:inline">{label}</span>
      {select}
    </label>
  );
}
