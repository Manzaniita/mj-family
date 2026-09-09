import { Link, useNavigate } from 'react-router-dom';
import { useState } from 'react';
import { AnimatePresence, motion } from 'framer-motion';
import { Trash2, ArrowRight, ShoppingBag, Plus, FileText } from 'lucide-react';
import { Section } from '@/components/layout/Layout';
import { FreeShippingBar } from '@/components/layout/FreeShippingBar';
import { QuoteModal } from '@/components/cart/QuoteModal';
import { PaymentSelector } from '@/components/layout/PaymentSelector';
import { Button } from '@/components/ui/Button';
import { QuantityStepper } from '@/components/ui/QuantityStepper';
import { ProductImage } from '@/components/ui/ProductImage';
import { SectionHeader } from '@/components/ui/SectionHeader';
import { ProductGrid } from '@/components/product/ProductGrid';
import { Reveal } from '@/components/motion/Reveal';
import { useCart, useCatalog } from '@/store';
import { effectivePrice } from '@/lib/api';
import { WP } from '@/lib/config';
import { formatPrice, FREE_SHIPPING_THRESHOLD } from '@/lib/format';
import { useSelectedMethod, resolveAdjustment } from '@/lib/payments';

export default function CartPage() {
  const { items, setQty, remove, syncing, server } = useCart();
  const cartShipping = useCart((s) => s.shipping);
  const products = useCatalog((s) => s.products);
  const [quoteOpen, setQuoteOpen] = useState(false);
  const navigate = useNavigate();
  const subtotal = items.reduce((n, i) => n + effectivePrice(i.product) * i.qty, 0);
  // Envío: gratis sobre el umbral; si no, costo real del servidor (null = a calcular).
  const freeShip = FREE_SHIPPING_THRESHOLD > 0 && subtotal >= FREE_SHIPPING_THRESHOLD;
  const shippingCost = freeShip ? 0 : cartShipping?.cost ?? null;
  // Ajuste por medio de pago (plugin MJ Medios de Pago): línea aparte, en vivo al cambiar el método.
  const payMethod = useSelectedMethod();
  const adjustment = resolveAdjustment({ items, method: payMethod, server, syncing, live: !!WP });
  const total = subtotal + (shippingCost ?? 0) + (adjustment?.amount ?? 0);
  const paymentTitles = (Array.isArray(WP?.paymentMethods) ? WP.paymentMethods : [])
    .map((m) => m.title ?? m)
    .filter(Boolean);

  const inCart = new Set(items.map((i) => i.product.id));
  const upsell = products
    .filter((p) => !inCart.has(p.id) && p.stock_status === 'instock' && (p.featured || p.sale_price))
    .slice(0, 4);

  return (
    <>
      <Section className="mj-cart md:py-16">
        <Reveal className="mj-page-head">
          <h1 className="display text-4xl md:text-6xl">Tu carrito</h1>
        </Reveal>

        {items.length === 0 ? (
          <Reveal className="mj-empty mt-12 rounded-(--mj-radius-2xl) border border-line glass p-16 text-center">
            <span className="mx-auto grid size-16 place-items-center rounded-full bg-surface-2 text-ink-faint">
              <ShoppingBag size={26} strokeWidth={1.5} />
            </span>
            <p className="display mt-6 text-2xl">Todavía no agregaste nada</p>
            <p className="mt-2 text-ink-soft">Tus productos te están esperando.</p>
            <Button className="mt-8" to="/tienda">Explorar la tienda <ArrowRight size={15} /></Button>
          </Reveal>
        ) : (
          <div className="mt-10 grid gap-10 lg:grid-cols-[1fr_380px]">
            <div>
              <div className="mb-6 rounded-xl2 glass px-5 py-4">
                <FreeShippingBar compact />
              </div>
              <ul className="space-y-4">
                <AnimatePresence initial={false}>
                  {items.map(({ product, qty, qtyLimits }) => (
                    <motion.li
                      key={product.id}
                      layout
                      initial={{ opacity: 0, y: 16 }}
                      animate={{ opacity: 1, y: 0 }}
                      exit={{ opacity: 0, x: 80 }}
                      className="mj-cart-item flex gap-5 rounded-xl3 border border-line glass p-4 md:p-5"
                    >
                      <Link to={`/producto/${product.slug}`}>
                        <ProductImage product={product} className="size-24 shrink-0 rounded-xl2 md:size-32" />
                      </Link>
                      <div className="flex min-w-0 flex-1 flex-col justify-between py-1">
                        <div className="flex items-start justify-between gap-3">
                          <div>
                            <p className="text-[11px] font-semibold uppercase tracking-[0.15em] text-ink-faint">{product.brandName ?? product.brand ?? product.category}</p>
                            <Link to={`/producto/${product.slug}`} className="mt-0.5 block font-display font-semibold tracking-tight md:text-lg hover:text-accent transition-colors">
                              {product.name}
                            </Link>
                          </div>
                          <button onClick={() => remove(product.id)} className="text-ink-faint transition-colors hover:text-danger" aria-label="Quitar">
                            <Trash2 size={16} />
                          </button>
                        </div>
                        <div className="flex flex-wrap items-center justify-between gap-3">
                          <QuantityStepper
                            value={qty}
                            onChange={(v) => setQty(product.id, v)}
                            max={qtyLimits?.max}
                            disabled={syncing}
                          />
                          <p className="font-display text-lg font-semibold">{formatPrice(effectivePrice(product) * qty)}</p>
                        </div>
                      </div>
                    </motion.li>
                  ))}
                </AnimatePresence>
              </ul>
            </div>

            {/* Resumen */}
            <div className="lg:sticky lg:top-44 lg:self-start">
              <div className="mj-summary rounded-xl3 border border-line glass p-7">
                <h2 className="font-display text-xl font-semibold tracking-tight">Resumen</h2>
                <PaymentSelector variant="block" className="mt-4" />
                <dl className="mt-6 space-y-3 text-sm">
                  <div className="flex justify-between">
                    <dt className="text-ink-soft">Subtotal</dt>
                    <dd className="font-medium">{formatPrice(subtotal)}</dd>
                  </div>
                  <div className="flex justify-between">
                    <dt className="text-ink-soft">Envío</dt>
                    <dd className={shippingCost === 0 ? 'font-semibold text-success' : 'font-medium'}>
                      {shippingCost == null
                        ? 'Se calcula al finalizar'
                        : shippingCost === 0
                          ? 'GRATIS'
                          : formatPrice(shippingCost)}
                    </dd>
                  </div>
                  {adjustment && (
                    <div className="flex justify-between gap-3">
                      <dt className="text-ink-soft">{adjustment.label}</dt>
                      <dd className={adjustment.amount < 0 ? 'font-semibold text-success' : 'font-medium'}>
                        {adjustment.amount < 0 ? '- ' : '+ '}{formatPrice(Math.abs(adjustment.amount))}
                      </dd>
                    </div>
                  )}
                  <div className="flex justify-between border-t border-line pt-4 text-base">
                    <dt className="font-medium">Total</dt>
                    <dd className="font-display text-2xl font-semibold">{formatPrice(total)}</dd>
                  </div>
                </dl>
                <Button className="mt-7 w-full" size="lg" onClick={() => navigate('/checkout')}>
                  Finalizar compra <ArrowRight size={16} />
                </Button>
                <Button className="mt-3 w-full" variant="secondary" onClick={() => setQuoteOpen(true)}>
                  <FileText size={16} /> Solicitar presupuesto
                </Button>
                <p className="mt-4 text-center text-xs text-ink-faint">
                  {['Pago seguro', ...paymentTitles].join(' · ')}
                </p>
              </div>
            </div>
          </div>
        )}
      </Section>

      {items.length > 0 && upsell.length > 0 && (
        <Section className="pt-0 md:pt-0">
          <SectionHeader eyebrow="Antes de irte" title="Sumá a tu pedido" />
          <ProductGrid products={upsell} />
        </Section>
      )}

      <QuoteModal open={quoteOpen} onClose={() => setQuoteOpen(false)} items={items} total={total} adjustment={adjustment} />
    </>
  );
}
