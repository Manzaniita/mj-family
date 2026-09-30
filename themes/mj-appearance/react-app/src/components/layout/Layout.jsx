import { useLocation, useOutlet } from 'react-router-dom';
import { Suspense, useEffect, useRef } from 'react';
import { AnimatePresence } from 'framer-motion';
import { Header } from './Header';
import { Footer } from './Footer';
import { BottomNav } from './BottomNav';
import { CartDrawer } from './CartDrawer';
import { MobileMenu } from './MobileMenu';
import { QuickView } from './QuickView';
import { CartToast } from '@/components/ui/CartToast';
import { ErrorBoundary } from '@/components/ui/ErrorBoundary';
import { FloatingContact } from './FloatingContact';
import { StickyCta } from './StickyCta';
import { Intro } from '@/components/motion/Intro';
import { PageTransition } from '@/components/motion/PageTransition';
import { useCart, useUI } from '@/store';
import { useCatalog } from '@/store/catalog';
import { TRANSITIONS } from '@/lib/motion';
import { watchSystemTheme, syncThemeColor } from '@/lib/theme';
import { t } from '@/lib/copy';

/**
 * Clave de página para las transiciones: cada ruta es una página, salvo la
 * cuenta, que se navega por pestañas (sus subrutas no re-animan el marco).
 */
const pageKey = (pathname) => (pathname.startsWith('/cuenta') ? '/cuenta' : pathname);

const scrollTop = () => window.scrollTo({ top: 0, behavior: 'instant' });

/*
 * Mientras baja el código de una pantalla que se carga bajo demanda (cuenta,
 * checkout, turnos): un bloque gris con la forma de un encabezado. Dura lo
 * que tarda el archivo, normalmente menos de un segundo.
 */
function PageFallback() {
  return (
    <div className="mx-auto max-w-7xl px-4 py-16 md:px-8 md:py-24" aria-busy="true" aria-label={t('ui_loading')}>
      <div className="h-4 w-24 animate-pulse rounded bg-surface-2" />
      <div className="mt-4 h-12 w-2/3 max-w-md animate-pulse rounded bg-surface-2" />
      <div className="mt-10 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
        {Array.from({ length: 3 }).map((_, i) => (
          <div key={i} className="h-40 animate-pulse rounded-xl3 bg-surface-2" />
        ))}
      </div>
    </div>
  );
}

export function Layout() {
  const { pathname } = useLocation();
  const outlet = useOutlet();
  const key = pageKey(pathname);
  const prevKey = useRef(key);

  // Carga el catálogo (WooCommerce o demo) y sincroniza el carrito del servidor.
  useEffect(() => {
    useCatalog.getState().init().then(() => useCart.getState().init?.());
  }, []);

  // Tema: refleja el color del navegador y sigue al sistema si aplica.
  useEffect(() => {
    syncThemeColor();
    return watchSystemTheme((theme) => useUI.getState().setTheme(theme, { persist: false }));
  }, []);

  // Sin transición (mismo marco o nivel sutil): arriba de inmediato.
  // Con transición, el scroll se resetea al terminar la salida (onExitComplete).
  useEffect(() => {
    if (!TRANSITIONS || prevKey.current === key) scrollTop();
    prevKey.current = key;
  }, [pathname, key]);

  const pageLayout = useUI((s) => s.pageLayout);

  return (
    <>
      <a href="#main" className="mj-skip-link">{t('ui_skip_to_content')}</a>
      <Intro />
      <div className="mj-ambient" aria-hidden="true" />
      {!pageLayout?.hideHeader && <Header />}
      <main id="main" className="min-h-screen" tabIndex={-1}>
        {/* Si una página revienta, cae solo ella: header, carrito y menú siguen. Se reinicia al navegar. */}
        <ErrorBoundary scope="page" resetKey={pathname}>
          <Suspense fallback={<PageFallback />}>
            {TRANSITIONS ? (
              <AnimatePresence mode="wait" initial={false} onExitComplete={scrollTop}>
                <PageTransition key={key}>{outlet}</PageTransition>
              </AnimatePresence>
            ) : (
              outlet
            )}
          </Suspense>
        </ErrorBoundary>
      </main>
      {!pageLayout?.hideFooter && <Footer />}
      {/* Botón fijo abajo (apagado por defecto): deja su propio espacio al final de la página. */}
      <StickyCta />
      {/* Espacio para que la barra inferior fija no tape el final del footer. */}
      {!pageLayout?.hideFooter && (
        <div className="h-20 pb-[env(safe-area-inset-bottom)] lg:hidden" aria-hidden="true" />
      )}
      <BottomNav />
      <CartDrawer />
      <MobileMenu />
      <QuickView />
      <CartToast />
      <FloatingContact />
    </>
  );
}

/** Contenedor estándar de secciones. */
export function Section({ children, className = '' }) {
  return (
    <section className={`mx-auto max-w-7xl px-4 py-16 md:px-8 md:py-24 ${className}`}>
      {children}
    </section>
  );
}
