import { Link, useNavigate } from 'react-router-dom';
import { motion } from 'framer-motion';
import { STILL } from '@/lib/motion';
import { Heart, Scale, Eye, ShoppingBag, Warehouse, HandHeart } from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import { Price } from '@/components/ui/Price';
import { Rating } from '@/components/ui/Rating';
import { ProductImage } from '@/components/ui/ProductImage';
import { Highlight } from '@/components/ui/Highlight';
import { useCart, useWishlist, useCompare, useUI, useCatalog } from '@/store';
import { discountPct } from '@/lib/api';
import { useSelectedMethod, badgeFor } from '@/lib/payments';
import { REVIEWS_ENABLED } from '@/lib/config';
import { COLEGAS, makerOf } from '@/lib/colegas';
import { staggerItem } from '@/components/motion/Reveal';
import { cn } from '@/lib/format';

function FloatAction({ icon: Icon, label, active, onClick }) {
  return (
    <motion.button
      whileHover={STILL ? undefined : { scale: 1.12 }}
      whileTap={STILL ? undefined : { scale: 0.9 }}
      onClick={(e) => { e.preventDefault(); e.stopPropagation(); onClick(); }}
      aria-label={label}
      title={label}
      className={cn(
        'mj-card__action grid size-9 place-items-center rounded-full backdrop-blur-xl border transition-colors',
        active
          ? 'bg-accent border-accent text-white'
          : 'bg-black/40 border-white/15 text-white/85 hover:text-white'
      )}
    >
      <Icon size={15} strokeWidth={2} className={active ? 'fill-current' : ''} />
    </motion.button>
  );
}

/**
 * Chip "quién lo hizo" (plugin MJ Colegas): se muestra en productos de
 * colegas y, si el plugin lo pide, también en los propios de la tienda.
 */
function makerChip(product) {
  if (!COLEGAS || !COLEGAS.showBadge) return null;
  if (!product.colega && !COLEGAS.showHouseBadge) return null;
  return makerOf(product);
}

/**
 * Tarjeta clásica: imagen arriba (badges + acciones flotantes) y debajo
 * categoría, título, precio y botón de compra ("Añadir al carrito" o
 * "Elegir opciones" en productos variables).
 */
export function ProductCard({ product, asStaggerItem = true, highlight }) {
  const add = useCart((s) => s.add);
  const { setCartOpen, setQuickView } = useUI();
  const navigate = useNavigate();
  const wish = useWishlist();
  const compare = useCompare();
  const categories = useCatalog((s) => s.categories);
  const isWished = wish.ids.includes(product.id);
  const isCompared = compare.ids.includes(product.id);
  const out = product.stock_status !== 'instock';
  const pct = discountPct(product);
  // "10% OFF con transferencia" (plugin MJ Medios de Pago), según el método elegido.
  const payBadge = badgeFor(product, useSelectedMethod());
  const maker = makerChip(product);

  // Nombre legible de la categoría (el producto guarda el slug).
  const categoryName =
    categories.find((c) => c.slug === product.category)?.name ??
    product.brandName ?? product.brand ?? product.category;

  const handleBuy = (e) => {
    e.preventDefault();
    // Los productos variables requieren elegir opciones en su página
    if (product.type === 'variable') {
      navigate(`/producto/${product.slug}`);
      return;
    }
    add(product);
    setCartOpen(true);
  };

  const Wrapper = asStaggerItem ? motion.div : 'div';

  return (
    <Wrapper variants={asStaggerItem ? staggerItem : undefined} className="mj-card group relative">
      <Link
        to={`/producto/${product.slug}`}
        className="mj-card__link block overflow-hidden rounded-xl3 border border-line bg-bg-soft transition-colors hover:border-line-strong"
      >
        <div className="mj-card__media relative aspect-square overflow-hidden">
          <ProductImage
            product={product}
            className="absolute inset-0"
            imgClassName="transition-transform duration-700 ease-out group-hover:scale-[1.06]"
          />

          {/* badges */}
          <div className="mj-card__badges absolute left-3 top-3 flex flex-col items-start gap-1.5">
            {product.is_new && <Badge tone="solid">Nuevo</Badge>}
            {pct > 0 && <Badge tone="danger">-{pct}%</Badge>}
            {payBadge && <Badge tone="success">{payBadge}</Badge>}
            {out && <Badge tone="neutral">Sin stock</Badge>}
          </div>

          {/* tiempo de entrega por SKU (plugin MJ Tiempos de Entrega) */}
          {product.delivery && (
            <span className="mj-card__delivery absolute bottom-3 left-3 inline-flex max-w-[85%] items-center gap-1.5 rounded-(--mj-radius-badge) border border-white/15 bg-black/60 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-wider text-white/90 backdrop-blur-md">
              <Warehouse size={11} className="shrink-0" />
              <span className="truncate">{product.delivery}</span>
            </span>
          )}

          {/* quién lo hizo (plugin MJ Colegas) */}
          {maker && (
            <span
              className={cn(
                'mj-card__maker absolute bottom-3 right-3 inline-flex max-w-[70%] items-center gap-1.5 rounded-(--mj-radius-badge) border border-white/15 bg-black/60 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-wider text-white/90 backdrop-blur-md',
                maker.house && 'mj-card__maker--house'
              )}
              title={`${COLEGAS.labels.madeBy} ${maker.name}`}
            >
              <HandHeart size={11} className="shrink-0" />
              <span className="truncate">{maker.name}</span>
            </span>
          )}

          {/* acciones flotantes */}
          <div className="mj-card__actions absolute right-3 top-3 flex translate-x-3 flex-col gap-2 opacity-0 transition-all duration-300 group-hover:translate-x-0 group-hover:opacity-100">
            <FloatAction icon={Heart} label="Agregar a favoritos" active={isWished} onClick={() => wish.toggle(product.id)} />
            <FloatAction icon={Eye} label="Vista rápida" onClick={() => setQuickView(product)} />
            <FloatAction icon={Scale} label="Comparar" active={isCompared} onClick={() => compare.toggle(product.id)} />
          </div>
        </div>

        {/* info debajo de la imagen */}
        <div className="mj-card__body p-4 md:p-5">
          <p className="mj-card__cat text-[11px] font-semibold uppercase tracking-[0.18em] text-ink-faint">
            {categoryName}
          </p>
          <h3 className="mj-card__title mt-1.5 line-clamp-2 min-h-10 font-display text-base font-semibold tracking-tight">
            <Highlight text={product.name} query={highlight} />
          </h3>
          {REVIEWS_ENABLED && product.rating > 0 && <Rating value={product.rating} className="mt-2" />}
          <Price product={product} className="mt-2" />

          <motion.button
            whileHover={STILL ? undefined : out ? undefined : { y: -1 }}
            whileTap={STILL ? undefined : out ? undefined : { scale: 0.97 }}
            onClick={handleBuy}
            disabled={out}
            className={cn(
              'mj-card__cta mt-4 inline-flex h-10 w-full items-center justify-center gap-2 rounded-(--mj-radius-btn) text-sm font-medium transition-all duration-300 select-none',
              out
                ? 'cursor-not-allowed bg-surface text-ink-faint'
                : 'bg-accent text-white shadow-glow'
            )}
          >
            <ShoppingBag size={15} />
            {out ? 'Sin stock' : product.type === 'variable' ? 'Elegir opciones' : 'Añadir al carrito'}
          </motion.button>
        </div>
      </Link>
    </Wrapper>
  );
}
