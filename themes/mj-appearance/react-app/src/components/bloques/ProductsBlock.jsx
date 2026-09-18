import { useEffect, useState } from 'react';
import { Section } from '@/components/layout/Layout';
import { SectionHeader } from '@/components/ui/SectionHeader';
import { Carousel } from '@/components/ui/Carousel';
import { ProductGrid } from '@/components/product/ProductGrid';
import { ProductCard } from '@/components/product/ProductCard';
import { Reveal } from '@/components/motion/Reveal';
import { useCatalog, whenCatalogReady } from '@/store/catalog';
import { getProducts } from '@/lib/api';
import { t } from '@/lib/copy';

/*
 * Textos por defecto según la fuente: los mismos que usa la portada clásica
 * para Destacados, Últimos ingresos y Recomendados. Para el resto no hay
 * título salvo que el admin lo escriba en el bloque.
 */
const COPY_BY_SOURCE = { featured: 'home_featured', new: 'home_latest', rating: 'home_recommended' };

/**
 * Bloque "Productos" (mj/productos): una selección del catálogo en grilla o
 * carrusel. Qué productos, cuántos, en qué orden y con qué títulos lo decide
 * el admin en el editor; la consulta corre sobre el catálogo ya cargado
 * (lib/api.js), así que no cuesta pedidos extra.
 *
 * Sin productos que mostrar (categoría vacía, sin ofertas…), el bloque no se
 * dibuja: igual que las secciones de la portada sin datos.
 */
export function ProductsBlock({
  eyebrow = '', title = '', subtitle = '',
  source = 'featured', category = '', brand = '', products = [],
  orderby = 'relevance', limit = 4, inStock = false,
  layout = 'grid', cols = '4', linkTo = '', linkLabel = '',
}) {
  const ready = useCatalog((s) => s.ready);
  const [list, setList] = useState([]);
  const manualKey = Array.isArray(products) ? products.join(',') : '';

  useEffect(() => {
    let live = true;
    const max = Math.max(1, Number(limit) || 4);

    async function load() {
      const query = { limit: max, sort: orderby, inStock: !!inStock };
      switch (source) {
        case 'featured': query.featured = true; break;
        case 'new': query.onlyNew = true; break;
        case 'rating': if (orderby === 'relevance') query.sort = 'rating'; break;
        case 'sale': query.onlySale = true; break;
        case 'category':
          if (!category) return [];
          query.category = category;
          break;
        case 'brand':
          if (!brand) return [];
          query.brand = brand;
          break;
        case 'manual': {
          const ids = (Array.isArray(products) ? products : []).map(Number);
          if (!ids.length) return [];
          const { products: all } = await whenCatalogReady();
          return ids.map((id) => all.find((p) => p.id === id)).filter(Boolean).slice(0, max);
        }
        default: break;
      }
      return getProducts(query);
    }

    load().then((items) => { if (live) setList(items); });
    return () => { live = false; };
  }, [ready, source, category, brand, manualKey, orderby, limit, inStock]);

  const copyKey = COPY_BY_SOURCE[source];
  const head = {
    eyebrow: eyebrow || (copyKey ? t(`${copyKey}_eyebrow`) : ''),
    title: title || (copyKey ? t(`${copyKey}_title`) : ''),
    subtitle: subtitle || (copyKey && source !== 'new' ? t(`${copyKey}_subtitle`) : ''),
  };

  // Catálogo listo y nada que mostrar: la sección no existe.
  if (ready && list.length === 0) return null;

  const n = [2, 3, 4].includes(Number(cols)) ? Number(cols) : 4;
  const max = Math.max(1, Number(limit) || 4);

  return (
    <Section className="mj-products-block">
      {head.title && (
        <SectionHeader
          eyebrow={head.eyebrow}
          title={head.title}
          subtitle={head.subtitle}
          linkTo={linkTo || undefined}
          linkLabel={linkLabel || t('ui_view_all')}
        />
      )}
      {layout === 'carousel' && ready ? (
        <Reveal>
          <Carousel cols={n} ariaLabel={head.title || t('ui_products')}>
            {list.map((p) => (
              <ProductCard key={p.id} product={p} asStaggerItem={false} />
            ))}
          </Carousel>
        </Reveal>
      ) : (
        <ProductGrid products={list} cols={n} loading={!ready} skeleton={Math.min(max, n * 2)} />
      )}
    </Section>
  );
}
