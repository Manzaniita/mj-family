import { Link, useNavigate } from 'react-router-dom';
import { AnimatePresence, motion } from 'framer-motion';
import { ShoppingBag, Trash2, ArrowRight, Plus } from 'lucide-react';
import { Drawer } from '@/components/ui/Drawer';
import { Button } from '@/components/ui/Button';
import { QuantityStepper } from '@/components/ui/QuantityStepper';
import { ProductImage } from '@/components/ui/ProductImage';
import { FreeShippingBar } from './FreeShippingBar';
import { useCart, useUI, useCatalog } from '@/store';
import { effectivePrice } from '@/lib/api';
import { formatPrice } from '@/lib/format';
import { IS_WP } from '@/lib/config';
import { t, tf } from '@/lib/copy';
import { useSelectedMethod, resolveAdjustment, displayPrice } from '@/lib/payments';

/** Carrito flotante — drawer lateral, cross-sell incluido, sin cambiar de página. */
export function CartDrawer() {
  const { cartOpen, setCartOpen } = useUI();
  const { items, setQty, remove, add, syncing, server } = useCart();
  const products = useCatalog((s) => s.products);
  const subtotal = items.reduce((n, i) => n + effectivePrice(i.product) * i.qty, 0);
  // Ajuste por medio de pago (plugin MJ Medios de Pago): confirmado por el servidor o estimado.
  const payMethod = useSelectedMethod();
  const adjustment = resolveAdjustment({ items, method: payMethod, server, syncing, live: IS_WP });
  const total = subtotal + (adjustment?.amount ?? 0);
  const navigate = useNavigate();

  const inCartIds = new Set(items.map((i) => i.product.id));
  // Solo productos simples: los variables requieren elegir opciones
  const crossSell = products
    .filter((p) => !inCartIds.has(p.id) && p.stock_status === 'instock' && p.type !== 'variable')
    .sort((a, b) => effectivePrice(a) - effectivePrice(b))
    .slice(0, 2);

  return (
    <Drawer
      open={cartOpen}
      onClose={() => setCartOpen(false)}
      title={`${t('cart_drawer_title')}${items.length ? ` · ${items.length}` : ''}`}
      footer={
        items.length > 0 && (
          <div className="space-y-4">
            <div className="space-y-1.5">
              <div className="flex items-center justify-between">
                <span className="text-sm text-ink-soft">{t('cart_subtotal')}</span>
                <span className={adjustment ? 'text-sm font-medium' : 'font-display text-xl font-semibold'}>{formatPrice(subtotal)}</span>
              </div>
              {adjustment && (
                <>
                  <div className="flex items-center justify-between gap-3 text-sm">
                    <span className="text-ink-soft">{adjustment.label}</span>
                    <span className={adjustment.amount < 0 ? 'font-semibold text-success' : 'font-medium'}>
                      {adjustment.amount < 0 ? '- ' : '+ '}{formatPrice(Math.abs(adjustment.amount))}
                    </span>
                  </div>
                  <div className="flex items-center justify-between">
                    <span className="text-sm font-medium">{t('cart_total')}</span>
                    <span className="font-display text-xl font-semibold">{formatPrice(total)}</span>
                  </div>
                </>
              )}
            </div>
            <div className="grid grid-cols-2 gap-3">
              <Button variant="secondary" onClick={() => { setCartOpen(false); navigate('/carrito'); }}>
                {t('cart_drawer_view')}
              </Button>
              <Button onClick={() => { setCartOpen(false); navigate('/checkout'); }}>
                {t('cart_drawer_checkout')} <ArrowRight size={15} />
              </Button>
            </div>
          </div>
        )
      }
    >
      <div className="mb-5 rounded-xl2 glass px-4 py-3">
        <FreeShippingBar compact />
      </div>

      {items.length === 0 ? (
        <div className="flex flex-col items-center gap-4 py-16 text-center">
          <span className="grid size-16 place-items-center rounded-full bg-surface-2 text-ink-faint">
            <ShoppingBag size={26} strokeWidth={1.5} />
          </span>
          <p className="text-sm text-ink-soft">{t('cart_drawer_empty')}</p>
          <Button size="sm" onClick={() => setCartOpen(false)} to="/tienda">
            {t('ui_explore_shop')}
          </Button>
        </div>
      ) : (
        <ul className="space-y-4">
          <AnimatePresence initial={false}>
            {items.map(({ product, qty }) => (
              <motion.li
                key={product.id}
                layout
                initial={{ opacity: 0, y: 12 }}
                animate={{ opacity: 1, y: 0 }}
                exit={{ opacity: 0, x: 60 }}
                className="mj-cart-item flex gap-4 rounded-xl2 glass p-3"
              >
                <Link to={`/producto/${product.slug}`} onClick={() => setCartOpen(false)}>
                  <ProductImage product={product} className="size-20 shrink-0 rounded-xl" />
                </Link>
                <div className="flex min-w-0 flex-1 flex-col justify-between">
                  <div className="flex items-start justify-between gap-2">
                    <p className="text-sm font-medium leading-snug">{product.name}</p>
                    <button
                      onClick={() => remove(product.id)}
                      className="text-ink-faint transition-colors hover:text-danger"
                      aria-label={t('cart_drawer_remove')}
                    >
                      <Trash2 size={15} />
                    </button>
                  </div>
                  <div className="flex items-center justify-between">
                    <QuantityStepper size="sm" value={qty} onChange={(v) => setQty(product.id, v)} />
                    <span className="text-sm font-semibold">{formatPrice(effectivePrice(product) * qty)}</span>
                  </div>
                </div>
              </motion.li>
            ))}
          </AnimatePresence>
        </ul>
      )}

      {items.length > 0 && crossSell.length > 0 && (
        <div className="mt-8">
          <p className="mb-3 text-xs font-semibold uppercase tracking-[0.18em] text-ink-faint">
            {t('cart_cross_sell')}
          </p>
          <div className="space-y-3">
            {crossSell.map((p) => (
              <div key={p.id} className="flex items-center gap-3 rounded-xl2 border border-line p-3">
                <ProductImage product={p} className="size-14 shrink-0 rounded-lg" />
                <div className="min-w-0 flex-1">
                  <p className="truncate text-sm font-medium">{p.name}</p>
                  <p className="text-xs text-ink-soft">{formatPrice(displayPrice(p))}</p>
                </div>
                <button
                  onClick={() => add(p)}
                  className="grid size-8 shrink-0 place-items-center rounded-(--mj-radius-pill) bg-accent-soft text-accent transition-transform hover:scale-110"
                  aria-label={tf('cart_add_aria', { nombre: p.name })}
                >
                  <Plus size={15} />
                </button>
              </div>
            ))}
          </div>
        </div>
      )}
    </Drawer>
  );
}
