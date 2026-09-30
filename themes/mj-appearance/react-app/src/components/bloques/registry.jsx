/**
 * Bloques MJ (inc/bloques.php) → componentes de React.
 *
 * PHP deja en la página un marcador por bloque (<div data-mj-block="…"
 * data-mj-props="{…}">) y PageView monta acá adentro el componente que
 * corresponde, con las props saneadas por el servidor. Son los MISMOS
 * componentes de la portada clásica: un bloque "Productos" es la grilla de
 * siempre, un "Hero" es el hero de siempre. Lo que el admin dejó vacío en el
 * bloque sale del panel o de la skin, como en la portada.
 *
 * Un bloque sin componente (plugin desactivado, versión vieja del build) no
 * dibuja nada.
 *
 * Bloques propios de las páginas (no están en la portada clásica):
 * encabezado, contacto, faq y galeria (Galería de trabajos: fotos con
 * leyenda, filtro por leyenda y foto grande al tocar — GalleryBlock.jsx).
 * "opiniones" elige su fuente con `source`: auto | woo | turnos.
 */
import { Hero } from '@/components/home/Hero';
import { BannerSlider } from '@/components/home/BannerSlider';
import { BrandsMarquee } from '@/components/home/BrandsMarquee';
import { CategoryShowcase, Testimonials, InstagramStrip } from '@/components/home/Sections';
import { AboutTeaser } from '@/components/home/AboutTeaser';
import { ColegasShowcase } from '@/components/home/Colegas';
import { ServicesShowcase, BookingCTA } from '@/components/home/Turnos';
import { Section } from '@/components/layout/Layout';
import { Header } from '@/components/layout/Header';
import { Footer } from '@/components/layout/Footer';
import { SectionHeader } from '@/components/ui/SectionHeader';
import { PageHeader } from '@/components/ui/PageHeader';
import { ProductsBlock } from '@/components/bloques/ProductsBlock';
import { GalleryBlock } from '@/components/bloques/GalleryBlock';
import { ContactPanel, FAQList, AboutBody } from '@/pages/Misc';
import { useCatalog } from '@/store/catalog';
import { SOCIAL, CONTACT } from '@/lib/config';
import { t } from '@/lib/copy';

/* Hero: sus campos pisan los del panel; las fotos, solo si eligió alguna. */
function HeroBlock(props) {
  return (
    <Hero
      override={{
        badge: props.badge,
        title: props.title,
        titleHighlight: props.titleHighlight,
        subtitle: props.subtitle,
        ctaPrimary: { text: props.ctaPrimaryText, url: props.ctaPrimaryUrl },
        ctaSecondary: { text: props.ctaSecondaryText, url: props.ctaSecondaryUrl },
        images: props.images,
        showStats: props.showStats !== false,
      }}
    />
  );
}

function HeaderBlock({ eyebrow, title, subtitle, page }) {
  return (
    <Section className="pb-0 md:py-16 md:pb-0">
      <PageHeader eyebrow={eyebrow} title={title || page?.title || ''} subtitle={subtitle} />
    </Section>
  );
}

function BannersBlock({ images, autoplay, interval, height }) {
  return (
    <Section className="pt-16 pb-0 md:pt-24 md:pb-0">
      <BannerSlider images={images} autoplay={!!autoplay} interval={interval} height={height} />
    </Section>
  );
}

function CategoriesBlock({ eyebrow, title, categories, limit, layout, linkTo, linkLabel }) {
  const all = useCatalog((s) => s.categories);
  const ready = useCatalog((s) => s.ready);
  if (ready && all.length === 0) return null;
  return (
    <Section>
      <SectionHeader
        eyebrow={eyebrow || t('home_categories_eyebrow')}
        title={title || t('home_categories_title')}
        linkTo={linkTo || '/tienda'}
        linkLabel={linkLabel || t('home_categories_link')}
      />
      <CategoryShowcase slugs={categories} limit={limit} layout={layout} />
    </Section>
  );
}

function InstagramBlock({ title, linkLabel, limit }) {
  const instagram = SOCIAL?.instagram;
  if (!instagram) return null;
  const handle = instagram.match(/instagram\.com\/([^/?#]+)/)?.[1];
  return (
    <Section>
      <SectionHeader
        eyebrow={handle ? `@${handle}` : 'Instagram'}
        title={title || t('home_instagram_title')}
        linkTo={instagram}
        linkLabel={linkLabel || t('home_instagram_link')}
      />
      <InstagramStrip limit={Number(limit) || 6} />
    </Section>
  );
}

function AboutBlock({ layout, eyebrow, title, text, image, linkLabel }) {
  const override = { eyebrow, title, text, image, linkLabel };
  return layout === 'full' ? <AboutBody override={override} /> : <AboutTeaser override={override} />;
}

/* El bloque puede apagar cada parte; lo que el panel apagó (Contacto → formulario) queda apagado igual. */
function ContactBlock({ showForm, showChannels, showMap }) {
  return (
    <Section className="pt-8 md:pt-12">
      <ContactPanel showForm={showForm !== false && CONTACT.showForm !== false} showChannels={showChannels !== false} showMap={showMap !== false} />
    </Section>
  );
}

function FAQBlock({ items, showContactCard }) {
  return (
    <Section className="pt-8 md:pt-12">
      <FAQList items={items} showContactCard={showContactCard !== false} />
    </Section>
  );
}

export const BLOCKS = {
  header: (props) => <Header override={props} />,
  footer: (props) => <Footer override={props} />,
  hero: HeroBlock,
  encabezado: HeaderBlock,
  banners: BannersBlock,
  productos: ProductsBlock,
  categorias: CategoriesBlock,
  marcas: ({ label }) => <BrandsMarquee label={label} />,
  opiniones: ({ eyebrow, title, limit, source }) => <Testimonials eyebrow={eyebrow} title={title} limit={limit} source={source} />,
  galeria: GalleryBlock,
  instagram: InstagramBlock,
  nosotros: AboutBlock,
  contacto: ContactBlock,
  faq: FAQBlock,
  servicios: (props) => <ServicesShowcase override={props} />,
  reserva: (props) => <BookingCTA override={props} />,
  colegas: (props) => <ColegasShowcase override={props} />,
};
