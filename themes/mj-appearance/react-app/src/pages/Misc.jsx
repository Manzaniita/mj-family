import { useState } from 'react';
import { Link } from 'react-router-dom';
import { motion } from 'framer-motion';
import { Heart, Scale, X, MapPin, Mail, Phone, MessageCircle, ArrowRight, Send, Loader2, CheckCircle2, AlertCircle, Sparkles, Instagram, Navigation } from 'lucide-react';
import { Section } from '@/components/layout/Layout';
import { Button } from '@/components/ui/Button';
import { Input, Textarea } from '@/components/ui/Input';
import { Accordion } from '@/components/ui/Accordion';
import { Rating } from '@/components/ui/Rating';
import { Price } from '@/components/ui/Price';
import { ProductImage } from '@/components/ui/ProductImage';
import { ProductGrid } from '@/components/product/ProductGrid';
import { SectionHeader } from '@/components/ui/SectionHeader';
import { iconByName } from '@/components/ui/DynamicIcon';
import { Reveal, MaskReveal, Stagger, staggerItem, Counter } from '@/components/motion/Reveal';
import { useWishlist, useCompare, useRecentlyViewed, useCatalog } from '@/store';
import { faqs } from '@/data/catalog';
import { WP, IS_WP, CONTACT, SOCIAL, SITE, REVIEWS_ENABLED, ABOUT } from '@/lib/config';
import { t, BRAND_LABEL, LEGAL } from '@/lib/copy';
import { COLEGAS, projectPath, colegaPath, paragraphsOf } from '@/lib/colegas';
import { TURNOS, bookingPath, servicesPath } from '@/lib/turnos';
import { sendContact } from '@/lib/wc';
import { formatPrice, cn } from '@/lib/format';
import { effectivePrice } from '@/lib/api';
import { displayPrice } from '@/lib/payments';
import { usePageMeta } from '@/lib/seo';
import { PageHeader } from '@/components/ui/PageHeader';

/* ------------------------------- Favoritos ------------------------------- */
export function Wishlist() {
  usePageMeta(t('seo_title_wishlist'));
  const ids = useWishlist((s) => s.ids);
  const products = useCatalog((s) => s.products);
  const items = products.filter((p) => ids.includes(p.id));
  return (
    <Section className="md:py-16">
      <PageHeader eyebrow="Tu selección" title="Favoritos" subtitle={items.length ? `${items.length} producto${items.length > 1 ? 's' : ''} guardado${items.length > 1 ? 's' : ''}.` : undefined} />
      {items.length === 0 ? (
        <Reveal className="mt-12 mj-empty rounded-(--mj-radius-2xl) border border-line glass p-16 text-center">
          <Heart size={28} className="mx-auto text-ink-faint" />
          <p className="display mt-5 text-2xl">Nada por acá todavía</p>
          <p className="mt-2 text-ink-soft">Tocá el corazón en cualquier producto para guardarlo.</p>
          <Button className="mt-7" to="/tienda">Explorar la tienda</Button>
        </Reveal>
      ) : (
        <div className="mt-12"><ProductGrid products={items} /></div>
      )}
    </Section>
  );
}

/* ------------------------------- Comparador ------------------------------ */
export function Compare() {
  usePageMeta(t('seo_title_compare'));
  const { ids, toggle } = useCompare();
  const products = useCatalog((s) => s.products);
  const items = products.filter((p) => ids.includes(p.id));
  const rows = [
    ['Precio', (p) => formatPrice(displayPrice(p))],
    [BRAND_LABEL.singular, (p) => p.brandName ?? p.brand ?? '—'],
    // Quién lo hizo (plugin MJ Colegas).
    ...(COLEGAS ? [[COLEGAS.labels.singular, (p) => p.colegaName ?? COLEGAS.house.name]] : []),
    ['Categoría', (p) => p.category],
    // Valoraciones desactivadas en WooCommerce → sin fila de rating.
    ...(REVIEWS_ENABLED ? [['Valoración', (p) => `${p.rating} ★ (${p.rating_count})`]] : []),
    ['Stock', (p) => (p.stock_status === 'instock' ? 'Disponible' : 'Sin stock')],
  ];
  return (
    <Section className="md:py-16">
      <PageHeader eyebrow="Decisiones fáciles" title="Comparador" subtitle="Hasta 4 productos, lado a lado." />
      {items.length === 0 ? (
        <Reveal className="mt-12 mj-empty rounded-(--mj-radius-2xl) border border-line glass p-16 text-center">
          <Scale size={28} className="mx-auto text-ink-faint" />
          <p className="display mt-5 text-2xl">Todavía no elegiste productos</p>
          <p className="mt-2 text-ink-soft">Usá el ícono de balanza en las tarjetas para comparar.</p>
          <Button className="mt-7" to="/tienda">Explorar la tienda</Button>
        </Reveal>
      ) : (
        <Reveal className="mt-12 overflow-x-auto rounded-xl3 border border-line glass">
          <table className="w-full min-w-160 text-sm">
            <thead>
              <tr>
                <th className="w-36 p-5" />
                {items.map((p) => (
                  <th key={p.id} className="p-5 text-left align-top">
                    <div className="relative">
                      <button
                        onClick={() => toggle(p.id)}
                        className="absolute -right-2 -top-2 z-10 grid size-7 place-items-center rounded-full glass text-ink-soft hover:text-danger"
                        aria-label="Quitar de comparación"
                      >
                        <X size={13} />
                      </button>
                      <Link to={`/producto/${p.slug}`}>
                        <ProductImage product={p} className="aspect-square w-40 rounded-xl2" />
                        <p className="mt-3 font-display font-semibold">{p.name}</p>
                      </Link>
                    </div>
                  </th>
                ))}
              </tr>
            </thead>
            <tbody className="divide-y divide-line">
              {rows.map(([label, fn]) => (
                <tr key={label}>
                  <td className="p-5 text-xs font-semibold uppercase tracking-wider text-ink-faint">{label}</td>
                  {items.map((p) => (
                    <td key={p.id} className="p-5 capitalize text-ink-soft">{fn(p)}</td>
                  ))}
                </tr>
              ))}
              <tr>
                <td className="p-5" />
                {items.map((p) => (
                  <td key={p.id} className="p-5">
                    <Button size="sm" to={`/producto/${p.slug}`}>Ver producto</Button>
                  </td>
                ))}
              </tr>
            </tbody>
          </table>
        </Reveal>
      )}
    </Section>
  );
}

/* --------------------------- Vistos recientemente ------------------------ */
export function RecentlyViewed() {
  usePageMeta(t('seo_title_recent'));
  const ids = useRecentlyViewed((s) => s.ids);
  const products = useCatalog((s) => s.products);
  const items = ids.map((id) => products.find((p) => p.id === id)).filter(Boolean);
  return (
    <Section className="md:py-16">
      <PageHeader eyebrow="Tu historial" title="Vistos recientemente" />
      {items.length === 0 ? (
        <Reveal className="mt-12 mj-empty rounded-(--mj-radius-2xl) border border-line glass p-16 text-center">
          <p className="display text-2xl">Tu historial está vacío</p>
          <Button className="mt-7" to="/tienda">Empezar a explorar</Button>
        </Reveal>
      ) : (
        <div className="mt-12"><ProductGrid products={items} /></div>
      )}
    </Section>
  );
}

/* --------------------------------- Marcas -------------------------------- */
export function Brands() {
  usePageMeta(t('seo_title_brands') || BRAND_LABEL.plural, t('seo_desc_brands'));
  const { brands, products } = useCatalog();
  return (
    <Section className="md:py-16">
      <PageHeader eyebrow={t('brands_eyebrow')} title={BRAND_LABEL.plural} subtitle={t('brands_subtitle')} />
      <Stagger className="mt-12 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
        {brands.map((b) => {
          const count = products.filter((p) => p.brand === b.slug).length;
          return (
            <motion.div key={b.slug} variants={staggerItem}>
              <Link
                to={`/marca/${b.slug}`}
                className="mj-brand-tile group flex aspect-4/3 flex-col items-center justify-center gap-2 rounded-xl3 border border-line glass transition-all duration-300 hover:border-accent/40 hover:shadow-glow"
              >
                <span className="font-display text-2xl font-semibold tracking-tight">{b.name}</span>
                <span className="text-xs text-ink-faint">{count} producto{count !== 1 && 's'}</span>
                <ArrowRight size={16} className="mt-1 text-accent opacity-0 transition-all duration-300 group-hover:opacity-100 group-hover:translate-x-1" />
              </Link>
            </motion.div>
          );
        })}
      </Stagger>
    </Section>
  );
}

/* -------------------------------- Contacto ------------------------------- */
export function Contact() {
  usePageMeta(t('seo_title_contact') || t('contact_title'), t('seo_desc_contact'));
  return (
    <Section className="md:py-16">
      <PageHeader eyebrow={t('contact_eyebrow')} title={t('contact_title')} subtitle={t('contact_subtitle')} />
      <ContactPanel showForm={CONTACT.showForm !== false} />
    </Section>
  );
}

/**
 * Formulario + canales de contacto (dirección, teléfono, WhatsApp, mail y
 * mapa). Es el cuerpo de la página Contacto y también el bloque "Contacto"
 * de una página de WordPress, que puede apagar cada parte.
 */
export function ContactPanel({ showForm = true, showChannels = true, showMap = true }) {
  // `website` es el honeypot: un campo oculto que una persona nunca completa. Si llega con algo, el servidor lo descarta.
  const [form, setForm] = useState({ name: '', email: '', message: '', website: '' });
  const [sending, setSending] = useState(false);
  const [sent, setSent] = useState(false);
  const [error, setError] = useState('');
  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));

  const onSubmit = async (e) => {
    e.preventDefault();
    setSending(true);
    setError('');
    try {
      await sendContact({
        kind: 'contact',
        name: form.name.trim(),
        email: form.email.trim(),
        message: form.message.trim(),
        website: form.website,
      });
      setForm({ name: '', email: '', message: '', website: '' });
      setSent(true);
    } catch (err) {
      setError(err?.data?.message || 'No se pudo enviar el mensaje. Probá de nuevo en unos minutos.');
    } finally {
      setSending(false);
    }
  };

  // Solo canales con datos reales — las líneas vacías se omiten. Instagram, solo si el panel lo pide.
  const instagram = CONTACT.showInstagram ? String(SOCIAL?.instagram || '').trim() : '';
  const igHandle = instagram.match(/instagram\.com\/([^/?#]+)/i)?.[1];
  const channels = [
    CONTACT.address && [MapPin, t('contact_label_address'), CONTACT.address],
    CONTACT.area && [Navigation, t('contact_label_area'), CONTACT.area],
    CONTACT.phone && [Phone, t('contact_label_phone'), CONTACT.phone],
    CONTACT.whatsapp && [MessageCircle, t('contact_label_whatsapp'), CONTACT.whatsapp],
    CONTACT.email && [Mail, t('contact_label_email'), CONTACT.email],
    instagram && [Instagram, t('contact_label_instagram'), igHandle ? `@${igHandle}` : instagram, instagram],
  ].filter(Boolean);

  const map = showMap ? CONTACT.map : '';
  const aside = showChannels && (channels.length > 0 || map);
  if (!showForm && !aside) return null;

  return (
    <div className={cn('mt-12 grid gap-8', showForm && aside && 'lg:grid-cols-[1fr_380px]', !showForm && 'lg:grid-cols-3')}>
        {showForm && (
        <Reveal className="mj-contact-form rounded-(--mj-radius-2xl) border border-line glass p-8">
          {sent ? (
            <div className="grid min-h-64 place-items-center text-center">
              <div>
                <CheckCircle2 size={28} className="mx-auto text-success" />
                <p className="display mt-5 text-2xl">Mensaje enviado</p>
                <p className="mt-2 text-ink-soft">Te respondemos a la brevedad.</p>
                <Button className="mt-7" variant="secondary" onClick={() => setSent(false)}>
                  Enviar otro mensaje
                </Button>
              </div>
            </div>
          ) : (
            <form className="relative grid gap-4 sm:grid-cols-2" onSubmit={onSubmit}>
              <Input label="Nombre" placeholder="Tu nombre" value={form.name} onChange={set('name')} required />
              <Input label="Email" type="email" placeholder="tu@email.com" value={form.email} onChange={set('email')} required />
              <div className="sm:col-span-2">
                <Textarea label="Mensaje" placeholder="Contanos…" value={form.message} onChange={set('message')} required />
              </div>
              {/* Honeypot anti-spam: fuera de la vista y del tab; los robots lo completan igual. */}
              <div className="absolute -left-[9999px] top-0 h-px w-px overflow-hidden" aria-hidden="true">
                <label htmlFor="mj-contact-website">Sitio web</label>
                <input
                  id="mj-contact-website"
                  type="text"
                  name="website"
                  tabIndex={-1}
                  autoComplete="off"
                  value={form.website}
                  onChange={set('website')}
                />
              </div>
              {error && (
                <div className="flex items-start gap-3 rounded-xl2 border border-danger/30 bg-danger/10 p-4 text-sm text-danger sm:col-span-2">
                  <AlertCircle size={17} className="mt-0.5 shrink-0" />
                  <p>{error}</p>
                </div>
              )}
              <div>
                <Button type="submit" disabled={sending}>
                  {sending ? <Loader2 size={15} className="animate-spin" /> : <Send size={15} />}
                  Enviar mensaje
                </Button>
              </div>
            </form>
          )}
        </Reveal>
        )}
        {aside && (
          <Reveal delay={0.15} className={cn('space-y-4', !showForm && 'lg:col-span-3 lg:grid lg:grid-cols-3 lg:gap-4 lg:space-y-0')}>
            {channels.map(([Icon, label, value, href]) => (
              <div key={label} className="mj-contact-channel relative flex gap-4 rounded-xl3 border border-line glass p-6">
                <span className="grid size-11 shrink-0 place-items-center rounded-xl2 bg-accent-soft text-accent">
                  <Icon size={18} />
                </span>
                <div className="min-w-0">
                  <p className="font-medium">{label}</p>
                  {href ? (
                    <a href={href} target="_blank" rel="noreferrer" className="mt-0.5 block break-words text-sm text-ink-soft transition-colors after:absolute after:inset-0 hover:text-accent">
                      {value}
                    </a>
                  ) : (
                    <p className="mt-0.5 break-words text-sm text-ink-soft">{value}</p>
                  )}
                </div>
              </div>
            ))}
            {map ? (
              <div className={cn('overflow-hidden rounded-xl3 border border-line', !showForm && 'lg:col-span-3')}>
                <iframe
                  title="Ubicación en Google Maps"
                  src={map}
                  loading="lazy"
                  allowFullScreen
                  referrerPolicy="no-referrer-when-downgrade"
                  className="aspect-video w-full border-0"
                />
              </div>
            ) : (
              showMap && CONTACT.address && (
                <div className="flex aspect-video flex-col items-center justify-center gap-2 rounded-xl3 border border-line glass p-6 text-center">
                  <MapPin size={20} className="text-accent" />
                  <p className="text-sm text-ink-soft">{CONTACT.address}</p>
                </div>
              )
            )}
          </Reveal>
        )}
    </div>
  );
}

/* -------------------------------- Nosotros ------------------------------- */
/* Columnas de una grilla de 1 a 4 tarjetas (cifras, puntos destacados). */
const gridCols = (count) =>
  ['sm:grid-cols-1', 'sm:grid-cols-2', 'sm:grid-cols-3', 'sm:grid-cols-2 lg:grid-cols-4'][Math.min(count, 4) - 1]
  ?? 'sm:grid-cols-2 lg:grid-cols-4';

/**
 * Página Nosotros. Todo sale de Apariencia → MJ Appearance: la foto y los
 * puntos destacados (Contenido → Nosotros), el texto (about_text), los
 * títulos y el botón final (Textos → Páginas). Con foto, va a la izquierda
 * en desktop; sin foto, el texto queda centrado como siempre. Las cifras son
 * las que escribió el admin (about_stats) o, si no cargó ninguna, las
 * automáticas del catálogo.
 */
export function About() {
  usePageMeta(t('seo_title_about'), t('seo_desc_about'));
  return <AboutBody />;
}

/**
 * Cuerpo de la página Nosotros. También es el bloque "Nosotros" en modo
 * página completa: `override` trae antetítulo, título, texto y foto escritos
 * en el bloque; vacío = lo del panel.
 */
export function AboutBody({ override } = {}) {
  const o = override || {};
  const { products, categories } = useCatalog();
  const paragraphs = paragraphsOf((o.text || '').trim() || WP?.aboutText);
  const image = o.image || ABOUT.image;
  const highlights = ABOUT.highlights;
  const highlightsTitle = t('about_highlights_title').trim();
  const ctaText = t('about_cta_text').trim();
  const ctaUrl = t('about_cta_url').trim();
  const ctaExternal = /^https?:\/\//i.test(ctaUrl);

  // Cifras propias del admin; si no hay, únicamente datos reales del catálogo.
  const rated = products.filter((p) => p.rating > 0);
  const avg = rated.length ? rated.reduce((n, p) => n + p.rating, 0) / rated.length : 0;
  const autoStats = [
    products.length > 0 && [String(products.length), 'Productos en catálogo'],
    categories.length > 0 && [String(categories.length), 'Categorías'],
    REVIEWS_ENABLED && avg > 0 && [`${avg.toFixed(1)} ★`, 'Valoración promedio'],
  ].filter(Boolean);
  const stats = ABOUT.stats.length ? ABOUT.stats.map((s) => [s.n, s.label]) : autoStats;

  return (
    <>
      <Section className={cn('mj-about md:py-24', image && 'mj-about--photo')}>
        <div className={cn('grid items-center gap-10 lg:gap-16', image && 'lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)]')}>
          {image && (
            <MaskReveal className="mj-about__photo overflow-hidden rounded-(--mj-radius-2xl) border border-line">
              <img src={image} alt="" className="aspect-4/5 h-full w-full object-cover" />
            </MaskReveal>
          )}
          <Reveal className={cn('mj-about__body', image ? 'max-w-2xl' : 'mx-auto max-w-3xl text-center')} delay={image ? 0.1 : 0}>
            <p className="mj-eyebrow text-xs font-semibold uppercase tracking-[0.25em] text-accent">{o.eyebrow || t('about_eyebrow')}</p>
            <h1 className="mj-about__title display mt-5 text-4xl md:text-6xl">
              {o.title || t('about_title')}
            </h1>
            {paragraphs.length > 0 ? (
              paragraphs.map((p, i) => (
                <p key={i} className="mj-about__text mt-6 text-lg leading-relaxed text-ink-soft">{p}</p>
              ))
            ) : (
              <p className="mj-about__text mt-6 text-lg leading-relaxed text-ink-soft">
                {SITE?.name || 'MJ Appearance'}{SITE?.tagline ? ` — ${SITE.tagline}` : ''}.
                Cuidamos cada detalle para que comprar sea simple, transparente y seguro.
              </p>
            )}
          </Reveal>
        </div>
      </Section>

      {highlights.length > 0 && (
        <Section className="mj-about__highlights pt-0 md:pt-0">
          {highlightsTitle && (
            <Reveal className="mb-8 md:mb-10">
              <h2 className="mj-about__highlights-title display text-2xl md:text-3xl">{highlightsTitle}</h2>
            </Reveal>
          )}
          <Stagger className={cn('grid gap-5', gridCols(highlights.length))}>
            {highlights.map(({ text, icon }, i) => {
              const Icon = iconByName(icon, Sparkles);
              return (
                <motion.div
                  key={`${i}-${text}`}
                  variants={staggerItem}
                  className="mj-about__highlight flex items-center gap-4 rounded-xl3 border border-line glass p-6"
                >
                  <span className="mj-about__highlight-icon grid size-11 shrink-0 place-items-center rounded-xl2 bg-accent-soft text-accent">
                    <Icon size={18} />
                  </span>
                  <p className="text-sm leading-relaxed text-ink-soft">{text}</p>
                </motion.div>
              );
            })}
          </Stagger>
        </Section>
      )}

      {stats.length > 0 && (
        <Section className="mj-about__stats pt-0 md:pt-0">
          <Stagger className={cn('mx-auto grid max-w-4xl gap-5', gridCols(stats.length))}>
            {stats.map(([n, l], i) => (
              <motion.div key={`${i}-${l}`} variants={staggerItem} className="mj-about__stat rounded-xl3 border border-line glass p-8 text-center">
                <p className="display text-4xl tabular-nums"><Counter value={n} /></p>
                <p className="mt-2 text-sm text-ink-soft">{l}</p>
              </motion.div>
            ))}
          </Stagger>
        </Section>
      )}

      {ctaText && ctaUrl && (
        <Section className="mj-about__cta pt-0 text-center md:pt-0">
          <Reveal>
            {ctaExternal ? (
              <Button size="lg" href={ctaUrl} target="_blank" rel="noreferrer">
                {ctaText} <ArrowRight size={17} />
              </Button>
            ) : (
              <Button size="lg" to={ctaUrl}>
                {ctaText} <ArrowRight size={17} />
              </Button>
            )}
          </Reveal>
        </Section>
      )}
    </>
  );
}

/* ---------------------------------- FAQ ---------------------------------- */
export function FAQ() {
  usePageMeta(t('seo_title_faq'), t('seo_desc_faq'));
  return (
    <Section className="md:py-16">
      <PageHeader eyebrow="Ayuda" title="Preguntas frecuentes" subtitle={t('faq_subtitle')} />
      <FAQList />
    </Section>
  );
}

/**
 * Acordeón de preguntas. `items` [{ q, a }] viene del bloque "Preguntas
 * frecuentes"; vacío = las del panel (en standalone, las demo).
 */
export function FAQList({ items, showContactCard = true } = {}) {
  const own = (Array.isArray(items) ? items : []).filter((i) => i && i.q && i.a);
  const list = own.length ? own : (IS_WP ? (WP?.faqs ?? []) : faqs);
  return (
    <>
      <Reveal className="mt-12 max-w-3xl">
        {list.length > 0 ? (
          <Accordion items={list} />
        ) : (
          <div className="rounded-xl3 border border-line glass p-12 text-center text-ink-soft">
            Próximamente publicaremos las preguntas frecuentes.
          </div>
        )}
      </Reveal>
      {showContactCard && (
        <Reveal className="mt-10 max-w-3xl rounded-xl3 border border-line glass p-8 text-center">
          <p className="font-display text-lg font-semibold">¿No encontraste tu respuesta?</p>
          <Button className="mt-4" variant="secondary" to="/contacto">Escribinos</Button>
        </Reveal>
      )}
    </>
  );
}

/* --------------------------------- Legales ------------------------------- */
/**
 * Los textos se escriben en Apariencia → MJ Appearance → Legales
 * (window.MJ.legal: [{ h, body }]). Sin texto propio, se muestra un genérico
 * pensado para una tienda argentina.
 */
function LegalPage({ title, sections }) {
  return (
    <Section className="md:py-16">
      <PageHeader eyebrow="Legales" title={title} />
      <Reveal className="prose-custom mt-12 max-w-3xl space-y-8">
        {sections.map(({ h, body }, i) => (
          <div key={`${i}-${h}`}>
            {h && <h2 className="font-display text-xl font-semibold tracking-tight">{h}</h2>}
            <p className={cn('whitespace-pre-line text-sm leading-relaxed text-ink-soft', h && 'mt-3')}>{body}</p>
          </div>
        ))}
      </Reveal>
    </Section>
  );
}

export function Privacy() {
  usePageMeta(t('seo_title_privacy'));
  const contact = CONTACT.email ? `escribiendo a ${CONTACT.email}` : 'desde nuestra página de contacto';
  const fallback = [
    { h: '1. Datos que recopilamos', body: 'Recopilamos únicamente los datos necesarios para procesar tu compra: nombre, email, teléfono y dirección de envío. Nunca almacenamos datos de tarjetas — el pago se procesa a través de pasarelas de pago seguras.' },
    { h: '2. Uso de la información', body: 'Usamos tus datos para gestionar pedidos, envíos y soporte. Solo te enviamos comunicaciones comerciales si te suscribiste explícitamente.' },
    { h: '3. Cookies', body: 'Usamos cookies funcionales (carrito, sesión, preferencias de tema) y analíticas anónimas. Podés gestionarlas desde tu navegador.' },
    { h: '4. Tus derechos', body: `Podés solicitar acceso, rectificación o eliminación de tus datos ${contact}, conforme a la Ley 25.326 de Protección de Datos Personales.` },
  ];
  return <LegalPage title="Política de privacidad" sections={LEGAL.privacy ?? fallback} />;
}

export function Terms() {
  usePageMeta(t('seo_title_terms'));
  const fallback = [
    { h: '1. Productos y precios', body: 'Los precios están expresados en pesos argentinos e incluyen IVA. Pueden modificarse sin previo aviso.' },
    { h: '2. Compra y pago', body: 'La compra queda confirmada al acreditarse el pago. Aceptamos los medios de pago habilitados al momento de la compra.' },
    { h: '3. Envíos', body: 'Realizamos envíos a todo el país con seguimiento. Los plazos son estimados y comienzan a correr desde la acreditación del pago.' },
    { h: '4. Devoluciones', body: 'Podés arrepentirte de la compra dentro del plazo legal aplicable (Ley 24.240), con el producto sin uso y en su embalaje original.' },
  ];
  return <LegalPage title="Términos y condiciones" sections={LEGAL.terms ?? fallback} />;
}

/* ------------------------------ Mapa del sitio --------------------------- */
export function Sitemap() {
  usePageMeta(t('seo_title_sitemap'));
  const { categories, products } = useCatalog();
  const colegas = COLEGAS ? COLEGAS.list.filter((c) => products.some((p) => p.colega === c.slug)) : [];
  // "Nosotros" siempre figura en el mapa: con el texto del menú si lo hay, si no con el eyebrow de la página.
  const aboutLabel = t('nav_about').trim() || t('about_eyebrow');
  const groups = [
    [t('footer_group_shop'), [
      [t('nav_home'), '/'],
      [t('shop_title'), '/tienda'],
      [t('shop_sale_title'), '/tienda?filtro=ofertas'],
      [t('shop_new_title'), '/tienda?filtro=novedades'],
      // Servicios y turnos (plugin MJ Turnos Online).
      ...(TURNOS?.menu.servicesPageEnabled ? [[TURNOS.menu.servicesLabel, servicesPath()]] : []),
      ...(TURNOS ? [[TURNOS.menu.label, bookingPath()]] : []),
      [BRAND_LABEL.plural, '/marcas'],
    ]],
    ['Categorías', categories.map((c) => [c.name, `/categoria/${c.slug}`])],
    // Proyecto de colegas (plugin MJ Colegas): la página del proyecto y una por colega.
    ...(COLEGAS ? [[COLEGAS.project.name, [
      [COLEGAS.project.menuLabel, projectPath()],
      ...colegas.map((c) => [c.name, colegaPath(c.slug)]),
    ]]] : []),
    [t('footer_group_account'), [
      [t('footer_link_account'), '/cuenta'],
      ['Ingresar', '/login'],
      ['Registro', '/registro'],
      [t('footer_link_orders'), '/cuenta/pedidos'],
      [t('footer_link_wishlist'), '/favoritos'],
      ['Comparador', '/comparar'],
      ['Vistos recientemente', '/vistos-recientemente'],
      [t('nav_cart'), '/carrito'],
    ]],
    [t('footer_group_help'), [
      [aboutLabel, '/nosotros'],
      [t('footer_link_contact'), '/contacto'],
      [t('footer_link_faq'), '/faq'],
      [t('footer_link_privacy'), '/privacidad'],
      [t('footer_link_terms'), '/terminos'],
    ]],
  ];
  return (
    <Section className="md:py-16">
      <PageHeader eyebrow="Navegación" title="Mapa del sitio" />
      <div className="mt-12 grid gap-10 sm:grid-cols-2 lg:grid-cols-4">
        {groups.map(([title, links]) => (
          <Reveal key={title}>
            <p className="mb-4 text-xs font-semibold uppercase tracking-[0.18em] text-ink-faint">{title}</p>
            <ul className="space-y-2.5">
              {links.map(([label, href]) => (
                <li key={href + label}>
                  <Link to={href} className="text-sm text-ink-soft transition-colors hover:text-accent">{label}</Link>
                </li>
              ))}
            </ul>
          </Reveal>
        ))}
      </div>
    </Section>
  );
}

/* ---------------------------------- 404 ---------------------------------- */
export function NotFound() {
  usePageMeta(t('seo_title_notfound'));
  return (
    <Section className="grid min-h-[65vh] place-items-center text-center">
      <div>
        <motion.p
          initial={{ opacity: 0, scale: 0.8 }}
          animate={{ opacity: 1, scale: 1 }}
          transition={{ duration: 0.6 }}
          className="display bg-gradient-to-b from-ink to-ink/10 bg-clip-text text-[8rem] leading-none text-transparent md:text-[12rem]"
        >
          404
        </motion.p>
        <h1 className="display mt-2 text-2xl md:text-3xl">Esta página no existe</h1>
        <p className="mt-3 text-ink-soft">Pero lo que buscás probablemente sí.</p>
        <div className="mt-8 flex justify-center gap-3">
          <Button to="/">Ir al inicio</Button>
          <Button variant="secondary" to="/tienda">Ver la tienda</Button>
        </div>
      </div>
    </Section>
  );
}
