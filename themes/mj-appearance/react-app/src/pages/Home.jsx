import { Fragment, useEffect, useState } from 'react';
import { Hero } from '@/components/home/Hero';
import { BrandsMarquee } from '@/components/home/BrandsMarquee';
import { BannerSlider } from '@/components/home/BannerSlider';
import {
  CategoryShowcase, Testimonials, InstagramStrip,
} from '@/components/home/Sections';
import { ColegasShowcase } from '@/components/home/Colegas';
import { ServicesShowcase, BookingCTA } from '@/components/home/Turnos';
import { AboutTeaser } from '@/components/home/AboutTeaser';
import { SectionHeader } from '@/components/ui/SectionHeader';
import { ProductGrid } from '@/components/product/ProductGrid';
import { LoadError } from '@/components/ui/LoadError';
import { Section } from '@/components/layout/Layout';
import { useCatalog } from '@/store/catalog';
import { getProducts } from '@/lib/api';
import { usePageMeta } from '@/lib/seo';
import { SOCIAL, HOME_ORDER } from '@/lib/config';
import { t, homeSectionVisible as visible } from '@/lib/copy';

/**
 * Portada. Las secciones se ordenan y se ocultan desde Apariencia → MJ
 * Appearance → Secciones de la portada (HOME_ORDER + homeHidden) y cada
 * texto se cambia en Textos de la tienda. Las secciones sin datos (sin
 * banners, marcas, opiniones, Instagram, servicios…) se ocultan solas, y
 * las keys sin elemento (p. ej. las del plugin de turnos cuando no está)
 * se saltean.
 */
export default function Home() {
  usePageMeta('');
  const [featured, setFeatured] = useState([]);
  const [latest, setLatest] = useState([]);
  const [recommended, setRecommended] = useState([]);
  const ready = useCatalog((s) => s.ready);
  const catalogError = useCatalog((s) => s.error);

  useEffect(() => {
    getProducts({ featured: true, limit: 4 }).then(setFeatured);
    getProducts({ onlyNew: true, limit: 4 }).then(setLatest);
    getProducts({ sort: 'rating', limit: 4 }).then(setRecommended);
  }, [ready]);

  // Mientras no llegó el catálogo, las grillas muestran su esqueleto.
  const loading = !ready;

  const instagram = SOCIAL?.instagram;
  const igHandle = instagram?.match(/instagram\.com\/([^/?#]+)/)?.[1];

  /* Mapa key → elemento. Las keys son las de mj_home_sections() en PHP. */
  const sections = {
    banners: (
      <Section className="pt-16 md:pt-24 pb-0 md:pb-0">
        <BannerSlider />
      </Section>
    ),
    featured: (
      <Section>
        <SectionHeader
          eyebrow={t('home_featured_eyebrow')}
          title={t('home_featured_title')}
          subtitle={t('home_featured_subtitle')}
          linkTo="/tienda"
        />
        <ProductGrid products={featured} loading={loading} />
      </Section>
    ),
    categories: (
      <Section>
        <SectionHeader
          eyebrow={t('home_categories_eyebrow')}
          title={t('home_categories_title')}
          linkTo="/tienda"
          linkLabel={t('home_categories_link')}
        />
        <CategoryShowcase />
      </Section>
    ),
    latest: (
      <Section>
        <SectionHeader
          eyebrow={t('home_latest_eyebrow')}
          title={t('home_latest_title')}
          linkTo="/tienda?filtro=novedades"
        />
        <ProductGrid products={latest} loading={loading} />
      </Section>
    ),
    // Plugin MJ Colegas: se oculta solo si el plugin no está o no hay colegas con productos.
    colegas: <ColegasShowcase />,
    recommended: (
      <Section>
        <SectionHeader
          eyebrow={t('home_recommended_eyebrow')}
          title={t('home_recommended_title')}
          subtitle={t('home_recommended_subtitle')}
        />
        <ProductGrid products={recommended} loading={loading} />
      </Section>
    ),
    brands: <BrandsMarquee />,
    testimonials: <Testimonials />,
    instagram: instagram ? (
      <Section>
        <SectionHeader
          eyebrow={igHandle ? `@${igHandle}` : 'Instagram'}
          title={t('home_instagram_title')}
          linkTo={instagram}
          linkLabel={t('home_instagram_link')}
        />
        <InstagramStrip />
      </Section>
    ) : null,
    // Plugin MJ Turnos Online: null sin plugin o con la sección apagada en Turnos → Ajustes.
    services: <ServicesShowcase />,
    booking: <BookingCTA />,
    // Foto + texto de Nosotros (Apariencia → MJ Appearance → Contenido → Nosotros).
    about: <AboutTeaser />,
  };

  return (
    <>
      <Hero />
      {/* El catálogo no respondió: se dice una vez, arriba, con reintento; las secciones vacías no se dibujan. */}
      {catalogError && (
        <Section className="pb-0 md:pb-0">
          <LoadError
            title="No pudimos cargar los productos"
            onRetry={() => useCatalog.getState().init(true)}
          />
        </Section>
      )}
      {HOME_ORDER.map((key) =>
        visible(key) && sections[key] ? <Fragment key={key}>{sections[key]}</Fragment> : null
      )}
    </>
  );
}
