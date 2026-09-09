import { useEffect, useRef, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { motion, AnimatePresence } from 'framer-motion';
import {
  Heart, Scale, ShoppingBag, Truck, ShieldCheck, RotateCcw, ChevronRight, ZoomIn, Copy, Check, ArrowRight,
} from 'lucide-react';
import { Section } from '@/components/layout/Layout';
import { Badge } from '@/components/ui/Badge';
import { Price } from '@/components/ui/Price';
import { Rating } from '@/components/ui/Rating';
import { Button } from '@/components/ui/Button';
import { Tabs } from '@/components/ui/Tabs';
import { QuantityStepper } from '@/components/ui/QuantityStepper';
import { ProductImage } from '@/components/ui/ProductImage';
import { SectionHeader } from '@/components/ui/SectionHeader';
import { ProductGrid } from '@/components/product/ProductGrid';
import { MobileBuyBar } from '@/components/product/MobileBuyBar';
import { PaymentPanel } from '@/components/product/PaymentPanel';
import { MaskReveal, Reveal } from '@/components/motion/Reveal';
import { getProduct, getRelated, discountPct } from '@/lib/api';
import { getProductStock } from '@/lib/wc';
import { reviews } from '@/data/catalog';
import { useCart, useWishlist, useCompare, useUI, useRecentlyViewed, useCatalog } from '@/store';
import { WP, REVIEWS_ENABLED, IS_WP } from '@/lib/config';
import { t } from '@/lib/copy';
import { COLEGAS, colegaOf, colegaPath } from '@/lib/colegas';
import { iconByName } from '@/components/ui/DynamicIcon';
import { useDialog } from '@/lib/a11y';
import { cn, EASE } from '@/lib/format';

/**
 * Foto principal con lupa: una capa con la misma imagen ampliada sigue al
 * puntero (solo hover con puntero fino; ver .mj-zoom en globals.css).
 * La posición se escribe directo en el DOM: sin re-render por cada movimiento.
 */
function ZoomImage({ product, src, className }) {
  const lens = useRef(null);
  const onMove = (e) => {
    const el = lens.current;
    if (!el) return;
    const r = e.currentTarget.getBoundingClientRect();
    const x = ((e.clientX - r.left) / r.width) * 100;
    const y = ((e.clientY - r.top) / r.height) * 100;
    el.style.backgroundPosition = `${x}% ${y}%`;
  };
  return (
    <div className={cn('mj-zoom', className)} onPointerMove={onMove}>
      <ProductImage product={product} src={src} className="aspect-square" />
      {src && (
        <div
          ref={lens}
          className="mj-zoom__lens"
          style={{ backgroundImage: `url("${src}")` }}
          aria-hidden="true"
        />
      )}
    </div>
  );
}

function Gallery({ product }) {
  const images = product.gallery?.length ? product.gallery : [product.image];
  const [active, setActive] = useState(0);
  const [zoom, setZoom] = useState(false);
  const lightbox = useRef(null);
  useDialog(lightbox, zoom, () => setZoom(false));

  useEffect(() => { setActive(0); }, [product.id]);

  const openZoom = () => setZoom(true);

  return (
    <div className="mj-gallery">
      <MaskReveal className="rounded-(--mj-radius-2xl)">
        <div
          className="mj-gallery__main group relative cursor-zoom-in overflow-hidden rounded-(--mj-radius-2xl) border border-line"
          onClick={openZoom}
          onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openZoom(); } }}
          role="button"
          tabIndex={0}
          aria-label="Ampliar imagen"
        >
          <AnimatePresence mode="wait">
            <motion.div
              key={active}
              initial={{ opacity: 0, scale: 1.02 }}
              animate={{ opacity: 1, scale: 1 }}
              exit={{ opacity: 0 }}
              transition={{ duration: 0.4, ease: EASE }}
            >
              <ZoomImage product={product} src={images[active]} />
            </motion.div>
          </AnimatePresence>
          <span className="mj-gallery__zoom absolute bottom-4 right-4 grid size-10 place-items-center rounded-full bg-black/40 text-white opacity-0 backdrop-blur-md transition-opacity duration-300 group-hover:opacity-100">
            <ZoomIn size={17} />
          </span>
        </div>
      </MaskReveal>

      {images.length > 1 && (
        <div className="mj-gallery__thumbs mt-4 flex gap-3" role="list">
          {images.map((src, i) => (
            <button
              key={i}
              onClick={() => setActive(i)}
              aria-pressed={i === active}
              className={cn(
                'mj-gallery__thumb overflow-hidden rounded-xl2 border transition-all duration-300',
                i === active ? 'border-accent shadow-glow' : 'border-line opacity-60 hover:opacity-100'
              )}
              aria-label={`Imagen ${i + 1}`}
            >
              <ProductImage product={product} src={src} className="size-20" />
            </button>
          ))}
        </div>
      )}

      {/* Zoom lightbox */}
      <AnimatePresence>
        {zoom && (
          <motion.div
            ref={lightbox}
            tabIndex={-1}
            role="dialog"
            aria-modal="true"
            aria-label={product.name}
            className="mj-lightbox fixed inset-0 z-95 grid cursor-zoom-out place-items-center bg-black/90 p-6 outline-none backdrop-blur-lg"
            initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }}
            onClick={() => setZoom(false)}
          >
            <motion.div
              initial={{ scale: 0.9 }} animate={{ scale: 1 }} exit={{ scale: 0.94 }}
              transition={{ duration: 0.35, ease: EASE }}
              className="w-full max-w-3xl"
            >
              <ProductImage product={product} src={images[active]} className="aspect-square rounded-(--mj-radius-2xl)" />
            </motion.div>
          </motion.div>
        )}
      </AnimatePresence>
    </div>
  );
}

/** Fila SKU con botón de copiar al portapapeles (confirmación de 1,6 s). */
function SkuRow({ sku }) {
  const [copied, setCopied] = useState(false);
  const copy = async () => {
    try {
      await navigator.clipboard.writeText(sku);
      setCopied(true);
      setTimeout(() => setCopied(false), 1600);
    } catch {
      /* sin permiso de portapapeles: el SKU sigue visible para copiarlo a mano */
    }
  };
  return (
    <div className="mj-sku flex items-baseline justify-between gap-4">
      <dt className="font-semibold uppercase tracking-[0.15em] text-ink-faint">SKU</dt>
      <dd className="flex items-center gap-1.5">
        <span className="mj-sku__value font-mono text-[13px] font-medium tabular-nums">{sku}</span>
        <button
          type="button"
          onClick={copy}
          className="mj-sku__copy grid size-6 place-items-center rounded-(--mj-radius-sm) text-ink-faint transition-colors hover:bg-surface hover:text-ink"
          aria-label={copied ? 'SKU copiado' : 'Copiar SKU'}
          title="Copiar SKU"
        >
          {copied ? <Check size={12} className="text-success" /> : <Copy size={12} />}
        </button>
      </dd>
    </div>
  );
}

/** Encuentra la variación que coincide con la selección de atributos. */
function matchVariation(product, selection) {
  const attrs = product.variation_attributes ?? [];
  if (attrs.some((a) => !selection[a.name])) return null;
  return (
    (product.variations ?? []).find((v) =>
      v.attributes.every((a) => !a.value || a.value === selection[a.name])
    ) ?? null
  );
}

/** Íconos por defecto para avisos sin ícono elegido (WP.productBadges). */
const TRUST_ICONS = [Truck, ShieldCheck, RotateCcw];

export default function Product() {
  const { slug } = useParams();
  const [product, setProduct] = useState(null);
  const [related, setRelated] = useState([]);
  const [stockData, setStockData] = useState(null);
  const [qty, setQty] = useState(1);
  const [selection, setSelection] = useState({});
  const [barVisible, setBarVisible] = useState(false);
  const panelRef = useRef(null); // panel de compra (destino del scroll desde la barra mobile)
  const buyRef = useRef(null); // fila del CTA principal (dispara la barra mobile al salir de pantalla)
  const add = useCart((s) => s.add);
  const { setCartOpen, setCurrentProduct } = useUI();
  const wish = useWishlist();
  const compare = useCompare();
  const recent = useRecentlyViewed();

  useEffect(() => {
    setQty(1);
    setSelection({});
    setStockData(null);
    getProduct(slug).then((p) => {
      setProduct(p);
      if (p) {
        recent.push(p.id);
        setCurrentProduct(p);
        getRelated(p).then(setRelated);
        // Stock y precios por variación (endpoint propio: la Store API no los expone).
        if (IS_WP) {
          getProductStock(p.id).then(setStockData).catch(() => setStockData(null));
        }
      }
    });
    return () => setCurrentProduct(null);
  }, [slug]);

  // Barra de compra mobile: visible cuando el CTA principal ya pasó por arriba.
  useEffect(() => {
    const el = buyRef.current;
    if (!el || typeof IntersectionObserver === 'undefined') return undefined;
    const io = new IntersectionObserver(([entry]) => {
      setBarVisible(!entry.isIntersecting && entry.boundingClientRect.top < 0);
    });
    io.observe(el);
    return () => io.disconnect();
  }, [product?.id]);

  const allProducts = useCatalog((s) => s.products);
  const catalogCategories = useCatalog((s) => s.categories);
  const isDemo = useCatalog((s) => s.demo || !window.MJ);
  const recentProducts = allProducts.filter(
    (p) => recent.ids.includes(p.id) && p.slug !== slug
  ).slice(0, 4);

  // Si la variación elegida tiene menos stock que la cantidad pedida, bajarla.
  // (Va antes del early-return: product puede ser null; se recalcula localmente.)
  useEffect(() => {
    if (!product) return;
    const isVar = product.type === 'variable' && (product.variation_attributes?.length ?? 0) > 0;
    const v = isVar ? matchVariation(product, selection) : null;
    const ex = isVar
      ? (stockData?.variations ?? []).find((x) => x.id === v?.id)
      : stockData;
    const max = ex?.manage_stock && ex.stock_quantity != null ? ex.stock_quantity : null;
    if (max != null && qty > max) setQty(Math.max(1, max));
  }, [product, selection, stockData]);

  if (!product) {
    return (
      <Section>
        <div className="grid gap-12 lg:grid-cols-2">
          <div className="aspect-square animate-pulse rounded-(--mj-radius-2xl) bg-surface-2" />
          <div className="space-y-4 pt-8">
            <div className="h-4 w-24 animate-pulse rounded bg-surface-2" />
            <div className="h-12 w-3/4 animate-pulse rounded bg-surface-2" />
            <div className="h-8 w-40 animate-pulse rounded bg-surface-2" />
          </div>
        </div>
      </Section>
    );
  }

  const isWished = wish.ids.includes(product.id);
  const isCompared = compare.ids.includes(product.id);

  const isVariable = product.type === 'variable' && (product.variation_attributes?.length ?? 0) > 0;
  const variation = isVariable ? matchVariation(product, selection) : null;
  // Atributo aún sin elegir: bloquea la compra y guía al usuario.
  const missingAttr = isVariable
    ? product.variation_attributes.find((a) => !selection[a.name])
    : null;

  /* Extras por variación (precio/stock reales) desde mj/v1/product-stock.
   * El match es por id de variación: el endpoint devuelve labels, no slugs. */
  const extrasByVariation = new Map((stockData?.variations ?? []).map((v) => [v.id, v]));
  const variationExtras = variation ? extrasByVariation.get(variation.id) ?? null : null;

  // Precio mostrado: el de la variación elegida; si no, el del producto padre.
  // El USD (plugin MJ Dólar) también es el de la variación elegida.
  const variationUsd = variation ? product.variationUsd?.[variation.id] ?? null : null;
  const displayProduct = variationExtras?.price != null
    ? {
        ...product,
        price: variationExtras.price,
        regular_price: variationExtras.regular_price ?? product.regular_price,
        sale_price: variationExtras.sale_price ?? null,
        usd: variationUsd?.usd ?? null,
        usd_sale: variationUsd?.usd_sale ?? null,
      }
    : product;

  // Stock mostrado: variación elegida (variable) o producto (simple).
  const stockInfo = isVariable ? variationExtras : stockData;
  const out = stockInfo
    ? stockInfo.stock_status !== 'instock'
    : product.stock_status !== 'instock';
  const canBackorder = !!stockInfo?.backorders;
  const canBuy = (!out || canBackorder) && !missingAttr && (!isVariable || !!variation);
  const maxQty = stockInfo?.manage_stock && stockInfo.stock_quantity != null
    ? stockInfo.stock_quantity
    : undefined;
  const lowStock = stockInfo?.manage_stock && stockInfo.stock_quantity != null && stockInfo.stock_quantity <= 3;

  // Tiempo de entrega según SKU (plugin MJ Tiempos de Entrega): el de la
  // variación elegida; sin selección (o simple), el del producto padre.
  const deliveryText = (isVariable && variation
    ? product.variationDelivery?.[variation.id] || product.delivery
    : product.delivery) || '';

  // SKU mostrado: el de la variación elegida si el endpoint lo expone; si no, el del padre.
  const displaySku = variationExtras?.sku || product.sku || '';

  // Descripción corta completa (tagline es la versión truncada usada en listados).
  const shortDesc = product.shortDesc || product.tagline || '';

  // Categorías del producto con su nombre (el catálogo resuelve slug → nombre).
  const metaCats = (product.categories?.length ? product.categories : [product.category])
    .filter(Boolean)
    .map((slug) => ({ slug, name: catalogCategories.find((c) => c.slug === slug)?.name ?? slug }));

  /** ¿Hay al menos una variación con stock para esta opción (con la selección actual)? */
  const termAvailable = (attrName, slug) => {
    if (!stockData || !isVariable) return true;
    const sel = { ...selection, [attrName]: slug };
    return (product.variations ?? []).some((v) => {
      const matches = v.attributes.every((a) => !a.value || !sel[a.name] || a.value === sel[a.name]);
      if (!matches) return false;
      const ex = extrasByVariation.get(v.id);
      return ex ? ex.stock_status === 'instock' || ex.backorders : true;
    });
  };

  // Avisos del producto (envío, garantía, devoluciones) — distintos de la barra
  // de anuncios: se configuran aparte en PHP (window.MJ.productBadges).
  // Cada aviso puede tener ícono propio ({ text, icon }); strings = formato viejo.
  // Vacío → no renderizar nada.
  const trustItems = (Array.isArray(WP?.productBadges) ? WP.productBadges : [])
    .map((b) => (typeof b === 'string' ? { text: b, icon: null } : b))
    .slice(0, 3);

  // Quién lo hizo (plugin MJ Colegas): bloque con foto, nombre y link a su página.
  // Productos propios de la tienda: solo si el plugin pide mostrar también la casa.
  const maker = COLEGAS?.showProductBlock
    ? (colegaOf(product) ?? (COLEGAS.showHouseBadge ? { ...COLEGAS.house, house: true } : null))
    : null;

  const handleAdd = () => {
    if (isVariable && !variation) {
      // Atributos completos pero sin variación que coincida → combinación inexistente.
      useCart.getState().setError('Esa combinación no está disponible');
      return;
    }
    add(product, qty, variation ? { id: variation.id, ...(variationExtras ?? {}) } : null);
    setCartOpen(true);
  };

  // Barra mobile: mismas reglas que el CTA; si faltan opciones, lleva al panel.
  const needsOptions = isVariable && (!!missingAttr || !variation);
  const soldOut = out && !canBackorder;
  const barLabel = soldOut ? 'Sin stock' : needsOptions ? 'Elegir opciones' : 'Agregar';
  const handleBar = () => {
    if (needsOptions) {
      panelRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
      return;
    }
    handleAdd();
  };

  return (
    <>
      <Section className="mj-product md:py-14">
        {/* Breadcrumb */}
        <Reveal y={10} className="mj-breadcrumb mb-8 flex items-center gap-1.5 text-xs text-ink-faint">
          <Link to="/" className="hover:text-ink">Inicio</Link>
          <ChevronRight size={12} />
          <Link to={`/categoria/${product.category}`} className="capitalize hover:text-ink">{product.category}</Link>
          <ChevronRight size={12} />
          <span className="text-ink-soft">{product.name}</span>
        </Reveal>

        <div className="grid gap-12 lg:grid-cols-2 lg:gap-16">
          <Gallery product={product} />

          {/* Panel de compra sticky */}
          <div className="lg:sticky lg:top-44 lg:self-start">
            <Reveal delay={0.1}>
              <div className="mj-product__badges flex flex-wrap gap-2">
                {product.is_new && <Badge tone="solid">Nuevo</Badge>}
                {discountPct(product) > 0 && <Badge tone="danger">-{discountPct(product)}% OFF</Badge>}
                <Badge tone={out ? 'neutral' : 'success'}>{out ? 'Sin stock' : 'En stock'}</Badge>
              </div>

              <p className="mj-eyebrow mt-6 text-xs font-semibold uppercase tracking-[0.25em] text-ink-faint">
                {product.brandName ?? product.brand ?? product.category}
              </p>
              <h1 className="mj-product__title display mt-2 text-4xl md:text-5xl">{product.name}</h1>

              {REVIEWS_ENABLED && (
                <div className="mt-4">
                  <Rating value={product.rating} count={product.rating_count} />
                </div>
              )}

              <div ref={panelRef} className="mj-product__panel mt-8 scroll-mt-24 rounded-xl3 border border-line glass p-6">
                <Price product={displayProduct} size="lg" />

                {/* Precio con cada medio de pago (plugin MJ Medios de Pago) */}
                <PaymentPanel product={displayProduct} />

                {/* Disponible en… (plugin MJ Tiempos de Entrega): variación elegida o producto */}
                {deliveryText && (
                  <p className="mj-product__delivery mt-3 flex items-center gap-2 text-sm font-semibold text-accent">
                    <span className="size-2 shrink-0 rounded-full bg-accent" />
                    {deliveryText}
                  </p>
                )}

                {/* Stock real (WooCommerce): variación elegida o producto simple */}
                {stockInfo && (
                  <p
                    className={cn(
                      'mj-product__stock mt-2 text-sm font-medium',
                      soldOut ? 'text-danger' : lowStock ? 'text-warning' : 'text-success'
                    )}
                    aria-live="polite"
                  >
                    {out
                      ? canBackorder
                        ? 'Disponible bajo pedido'
                        : 'Sin stock'
                      : stockInfo.manage_stock && stockInfo.stock_quantity != null
                        ? `${stockInfo.stock_quantity} ${stockInfo.stock_quantity === 1 ? 'unidad disponible' : 'unidades disponibles'}`
                        : 'En stock'}
                  </p>
                )}

                {/* Meta bajo el precio: descripción corta, SKU y categorías */}
                {(shortDesc || displaySku || metaCats.length > 0) && (
                  <div className="mj-product__meta mt-5 border-t border-line pt-4">
                    {shortDesc && (
                      <p className="text-sm leading-relaxed text-ink-soft">{shortDesc}</p>
                    )}
                    {(displaySku || metaCats.length > 0) && (
                      <dl className={cn('space-y-1.5 text-xs', shortDesc && 'mt-3')}>
                        {displaySku && <SkuRow key={displaySku} sku={displaySku} />}
                        {metaCats.length > 0 && (
                          <div className="flex items-baseline justify-between gap-4">
                            <dt className="font-semibold uppercase tracking-[0.15em] text-ink-faint">
                              {metaCats.length === 1 ? 'Categoría' : 'Categorías'}
                            </dt>
                            <dd className="text-right font-medium">
                              {metaCats.map((c, i) => (
                                <span key={c.slug}>
                                  {i > 0 && ', '}
                                  <Link to={`/categoria/${c.slug}`} className="capitalize transition-colors hover:text-accent">
                                    {c.name}
                                  </Link>
                                </span>
                              ))}
                            </dd>
                          </div>
                        )}
                      </dl>
                    )}
                  </div>
                )}

                {/* Selector de variaciones (productos variables de WooCommerce) */}
                {isVariable && (
                  <div className="mj-product__options mt-6 space-y-4">
                    {product.variation_attributes.map((attr) => (
                      <div key={attr.name} role="group" aria-label={attr.name}>
                        <p className="mb-2 text-xs font-semibold uppercase tracking-[0.15em] text-ink-faint">
                          {attr.name}
                        </p>
                        <div className="flex flex-wrap gap-2">
                          {attr.terms.map((t) => {
                            const active = selection[attr.name] === t.slug;
                            const available = termAvailable(attr.name, t.slug);
                            return (
                              <button
                                key={t.slug}
                                disabled={!available}
                                aria-pressed={active}
                                onClick={() =>
                                  setSelection((s) => ({ ...s, [attr.name]: active ? undefined : t.slug }))
                                }
                                className={cn(
                                  'mj-chip rounded-(--mj-radius-chip) border px-4 py-2 text-sm font-medium transition-all duration-200',
                                  active
                                    ? 'mj-chip--active border-accent bg-accent text-white'
                                    : 'border-line text-ink-soft hover:border-line-strong hover:text-ink',
                                  !available && 'cursor-not-allowed opacity-40 line-through hover:border-line hover:text-ink-soft'
                                )}
                              >
                                {t.name}
                              </button>
                            );
                          })}
                        </div>
                      </div>
                    ))}
                    {missingAttr ? (
                      <p className="text-xs text-ink-faint">
                        Elegí {missingAttr.name.toLowerCase()} para continuar.
                      </p>
                    ) : !variation ? (
                      <p className="text-xs text-danger">
                        Esa combinación no está disponible.
                      </p>
                    ) : null}
                  </div>
                )}

                <div ref={buyRef} className="mj-product__buy mt-6 flex flex-wrap items-center gap-3">
                  <QuantityStepper
                    value={qty}
                    onChange={(v) => setQty(Math.max(1, maxQty ? Math.min(maxQty, v) : v))}
                    max={maxQty}
                  />
                  <Button
                    className="flex-1 min-w-44"
                    disabled={!canBuy}
                    onClick={handleAdd}
                  >
                    <ShoppingBag size={16} />
                    {soldOut ? 'Sin stock' : missingAttr ? `Elegí ${missingAttr.name.toLowerCase()}` : 'Agregar al carrito'}
                  </Button>
                </div>

                <div className="mj-product__secondary mt-4 flex gap-3">
                  <button
                    onClick={() => wish.toggle(product.id)}
                    aria-pressed={isWished}
                    className={cn(
                      'mj-chip flex flex-1 items-center justify-center gap-2 rounded-(--mj-radius-btn) border py-2.5 text-sm font-medium transition-colors',
                      isWished ? 'border-accent bg-accent-soft text-accent' : 'border-line text-ink-soft hover:text-ink'
                    )}
                  >
                    <Heart size={15} className={isWished ? 'fill-current' : ''} />
                    {isWished ? 'En favoritos' : 'Favorito'}
                  </button>
                  <button
                    onClick={() => compare.toggle(product.id)}
                    aria-pressed={isCompared}
                    className={cn(
                      'mj-chip flex flex-1 items-center justify-center gap-2 rounded-(--mj-radius-btn) border py-2.5 text-sm font-medium transition-colors',
                      isCompared ? 'border-accent bg-accent-soft text-accent' : 'border-line text-ink-soft hover:text-ink'
                    )}
                  >
                    <Scale size={15} /> Comparar
                  </button>
                </div>
              </div>

              {maker && (
                <Link
                  to={colegaPath(maker.slug)}
                  className={cn(
                    'mj-maker mt-6 flex items-center gap-4 rounded-xl3 border border-line glass p-4 transition-colors hover:border-line-strong',
                    maker.house && 'mj-maker--house'
                  )}
                >
                  <span className="mj-maker__avatar grid size-12 shrink-0 place-items-center overflow-hidden rounded-full bg-accent-soft font-display text-lg font-semibold text-accent">
                    {maker.image ? <img src={maker.image} alt="" className="h-full w-full object-cover" /> : maker.name.trim()[0]}
                  </span>
                  <span className="min-w-0 flex-1">
                    <span className="mj-eyebrow block text-[10px] font-semibold uppercase tracking-[0.2em] text-ink-faint">
                      {COLEGAS.labels.madeBy}
                    </span>
                    <span className="mj-maker__name block font-display text-base font-semibold tracking-tight">{maker.name}</span>
                    {maker.description && (
                      <span className="mj-maker__desc mt-0.5 line-clamp-2 block text-xs leading-relaxed text-ink-soft">{maker.description}</span>
                    )}
                  </span>
                  <ArrowRight size={16} className="shrink-0 text-accent" />
                </Link>
              )}

              {trustItems.length > 0 && (
                <div
                  className="mj-trust mt-6 grid gap-3 text-center"
                  style={{ gridTemplateColumns: `repeat(${trustItems.length}, minmax(0, 1fr))` }}
                >
                  {trustItems.map((badge, i) => {
                    const Icon = iconByName(badge.icon, TRUST_ICONS[i % TRUST_ICONS.length]);
                    return (
                      <div key={badge.text} className="mj-trust__item rounded-xl2 border border-line py-4">
                        <Icon size={17} className="mx-auto text-accent" />
                        <p className="mt-2 text-[11px] font-medium text-ink-soft">{badge.text}</p>
                      </div>
                    );
                  })}
                </div>
              )}
            </Reveal>
          </div>
        </div>

        {/* Tabs de información */}
        <Reveal className="mj-product__tabs mt-20">
          <Tabs
            tabs={[
              {
                label: 'Descripción',
                content: (
                  <div className="max-w-2xl space-y-4 text-ink-soft leading-relaxed">
                    <p>{product.description || product.tagline || ''}</p>
                  </div>
                ),
              },
              ...(product.specs?.length
                ? [{
                    label: 'Especificaciones',
                    content: (
                      <dl className="mj-specs max-w-2xl divide-y divide-line rounded-xl3 border border-line overflow-hidden">
                        {product.specs.map(([k, v]) => (
                          <div key={k} className="grid grid-cols-2 gap-4 px-6 py-4 text-sm">
                            <dt className="text-ink-faint">{k}</dt>
                            <dd className="font-medium">{v}</dd>
                          </div>
                        ))}
                      </dl>
                    ),
                  }]
                : []),
              // Tab de opiniones demo (standalone) — se apaga si WP desactivó las valoraciones.
              ...(isDemo && REVIEWS_ENABLED
                ? [{
                    label: `Opiniones (${product.rating_count})`,
                    content: (
                      <div className="grid max-w-4xl gap-4 md:grid-cols-2">
                        {reviews.slice(0, 2).map((r) => (
                          <div key={r.name} className="mj-testimonial rounded-xl3 border border-line glass p-6">
                            <Rating value={r.rating} />
                            <p className="mt-3 text-sm leading-relaxed text-ink-soft">“{r.text}”</p>
                            <p className="mt-4 text-sm font-medium">{r.name}</p>
                          </div>
                        ))}
                      </div>
                    ),
                  }]
                : []),
            ]}
          />
        </Reveal>
      </Section>

      {related.length > 0 && (
        <Section>
          <SectionHeader eyebrow={t('product_related_eyebrow')} title={t('product_related_title')} />
          <ProductGrid products={related} />
        </Section>
      )}

      {recentProducts.length > 0 && (
        <Section className="pt-0 md:pt-0">
          <SectionHeader eyebrow={t('product_recent_eyebrow')} title={t('product_recent_title')} linkTo="/vistos-recientemente" />
          <ProductGrid products={recentProducts} />
        </Section>
      )}

      <MobileBuyBar
        product={product}
        priceProduct={displayProduct}
        visible={barVisible}
        label={barLabel}
        disabled={soldOut}
        onClick={handleBar}
      />
    </>
  );
}
