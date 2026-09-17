import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { useLocation, useNavigate } from 'react-router-dom';
import { Section } from '@/components/layout/Layout';
import { PageHeader } from '@/components/ui/PageHeader';
import { LoadError } from '@/components/ui/LoadError';
import { NotFound } from '@/pages/Misc';
import { BLOCKS } from '@/components/bloques/registry';
import { fetchPage, previewPageId } from '@/lib/pages';
import { usePageMeta } from '@/lib/seo';

/* Bloques que traen su propio título: la página no repite el suyo arriba. */
const OWN_TITLE = ['mj/hero', 'mj/encabezado', 'mj/nosotros'];

function parseProps(json) {
  try {
    const parsed = JSON.parse(json || '{}');
    return parsed && typeof parsed === 'object' ? parsed : {};
  } catch {
    return {};
  }
}

/* Mientras llega la página: un encabezado y unos párrafos grises. */
function PageSkeleton() {
  return (
    <div className="mx-auto max-w-7xl px-4 py-16 md:px-8 md:py-24" aria-busy="true" aria-label="Cargando">
      <div className="h-4 w-24 animate-pulse rounded bg-surface-2" />
      <div className="mt-4 h-12 w-2/3 max-w-md animate-pulse rounded bg-surface-2" />
      <div className="mt-10 max-w-3xl space-y-3">
        <div className="h-4 animate-pulse rounded bg-surface-2" />
        <div className="h-4 w-11/12 animate-pulse rounded bg-surface-2" />
        <div className="h-4 w-4/5 animate-pulse rounded bg-surface-2" />
      </div>
    </div>
  );
}

/**
 * Una página de WordPress dentro de la tienda.
 *
 * Pide el HTML ya renderizado a mj/v1/page, lo monta tal cual (párrafos,
 * títulos, imágenes, columnas… con los estilos de .mj-page__prose) y en cada
 * marcador de bloque MJ mete, con un portal, el componente de React que le
 * corresponde (components/bloques/registry.jsx). Los enlaces internos del
 * contenido navegan por la SPA, sin recargar.
 *
 * `home`: la portada elegida en Ajustes → Lectura. `?preview=true` (el botón
 * "Vista previa" de Gutenberg) muestra el último autoguardado a quien puede
 * editar la página.
 */
export default function PageView({ path = '', home = false }) {
  const location = useLocation();
  const navigate = useNavigate();
  const preview = new URLSearchParams(location.search).get('preview') === 'true';
  const previewId = previewPageId(location.search);

  const [state, setState] = useState({ status: 'loading', page: null });
  const [attempt, setAttempt] = useState(0);

  useEffect(() => {
    let live = true;
    setState({ status: 'loading', page: null });
    fetchPage({ path, id: previewId, preview })
      .then((page) => { if (live) setState({ status: 'ready', page }); })
      .catch((err) => {
        if (live) setState({ status: err?.status === 404 ? 'notfound' : 'error', page: null });
      });
    return () => { live = false; };
  }, [path, previewId, preview, attempt]);

  const page = state.page;
  usePageMeta(home ? '' : page?.title || '', page?.excerpt || '');

  /* El HTML se inyecta una vez por página; React solo administra los portales. */
  const ref = useRef(null);
  const [portals, setPortals] = useState([]);
  useLayoutEffect(() => {
    const el = ref.current;
    if (!el) return;
    el.innerHTML = page?.content || '';
    const nodes = page ? Array.from(el.querySelectorAll('[data-mj-block]')) : [];
    setPortals(nodes.map((node, i) => ({
      node,
      name: node.dataset.mjBlock,
      props: parseProps(node.dataset.mjProps),
      key: `${page.id}-${page.modified}-${i}`,
    })));
  }, [page]);

  /* Enlaces internos del contenido → navegación de la SPA. */
  const onClick = (e) => {
    const anchor = e.target.closest?.('a[href]');
    if (!anchor || anchor.target === '_blank' || anchor.hasAttribute('download')) return;
    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    let url;
    try {
      url = new URL(anchor.href, window.location.href);
    } catch {
      return;
    }
    if (url.origin !== window.location.origin) return;
    if (/^\/wp-(admin|login|content|json|includes)|\.[a-z0-9]{2,5}$/i.test(url.pathname)) return;
    e.preventDefault();
    navigate(url.pathname + url.search + url.hash);
  };

  if (state.status === 'notfound') return <NotFound />;
  if (state.status === 'error') {
    return (
      <Section>
        <LoadError title="No pudimos cargar la página" onRetry={() => setAttempt((n) => n + 1)} />
      </Section>
    );
  }

  const hideTitle = !page || home || page.hideTitle || OWN_TITLE.includes(page.firstBlock);

  return (
    <article className="mj-page">
      {!page && <PageSkeleton />}
      {page && !hideTitle && (
        <Section className="pb-0 md:py-16 md:pb-0">
          <PageHeader title={page.title} />
        </Section>
      )}
      {/* Sin hijos de React: el contenido lo pone innerHTML en el efecto de arriba. */}
      <div ref={ref} className="mj-page__content" onClick={onClick} />
      {portals.map(({ node, name, props, key }) => {
        const Block = BLOCKS[name];
        return Block ? createPortal(<Block {...props} page={page} />, node, key) : null;
      })}
    </article>
  );
}
