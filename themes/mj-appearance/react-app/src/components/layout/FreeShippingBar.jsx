import { motion } from 'framer-motion';
import { Truck, PartyPopper } from 'lucide-react';
import { useCart } from '@/store';
import { formatPrice, FREE_SHIPPING_THRESHOLD, EASE } from '@/lib/format';

/** Nivel 2 — progreso hacia envío gratis, se anima con cada cambio del carrito. */
export function FreeShippingBar({ compact = false }) {
  const subtotal = useCart((s) => s.items.reduce(
    (n, i) => n + (i.product.sale_price ?? i.product.price) * i.qty, 0
  ));

  // Umbral 0 (o sin configurar): la tienda no ofrece envío gratis → ocultar.
  if (FREE_SHIPPING_THRESHOLD <= 0) return null;

  const pct = Math.min(100, (subtotal / FREE_SHIPPING_THRESHOLD) * 100);
  const remaining = Math.max(0, FREE_SHIPPING_THRESHOLD - subtotal);
  const done = remaining === 0;

  return (
    /*
     * Fondo sólido: la barra vive fuera del bloque con desenfoque del header, así que el suyo tiene
     * que tapar por sí solo o se le vería el contenido de la página por detrás al scrollear.
     */
    <div className={compact ? '' : 'mj-shipbar hairline-b bg-bg-soft'}>
      <div className={compact ? '' : 'mx-auto max-w-7xl px-4 py-1.5 md:px-8 md:py-2'}>
        <div className="flex items-center gap-3">
          <span className={`grid size-6 shrink-0 place-items-center rounded-full ${done ? 'bg-success/15 text-success' : 'bg-accent-soft text-accent'}`}>
            {done ? <PartyPopper size={12} /> : <Truck size={12} />}
          </span>
          <p className="min-w-0 truncate text-xs text-ink-soft">
            {done ? (
              <span className="font-semibold text-success">¡Tenés envío GRATIS! 🎉</span>
            ) : (
              <>Te faltan <span className="font-semibold text-ink">{formatPrice(remaining)}</span> para envío <span className="font-semibold text-accent">GRATIS</span></>
            )}
          </p>
          <div className="relative h-1.5 flex-1 overflow-hidden rounded-full bg-surface-2 min-w-16">
            <motion.div
              className={`absolute inset-y-0 left-0 rounded-full ${done ? 'bg-success' : 'bg-accent'}`}
              animate={{ width: `${pct}%` }}
              transition={{ duration: 0.8, ease: EASE }}
            />
          </div>
          {/* El contador exacto solo donde entra: en el celular ya lo dice la frase y la barra. */}
          <span className="hidden shrink-0 text-[11px] font-medium tabular-nums text-ink-faint whitespace-nowrap sm:inline">
            {formatPrice(subtotal)} / {formatPrice(FREE_SHIPPING_THRESHOLD)}
          </span>
        </div>
      </div>
    </div>
  );
}
