import { useLocation, useOutlet } from 'react-router-dom';
import { useEffect, useRef } from 'react';
import { AnimatePresence } from 'framer-motion';
import { Header } from './Header';
import { Footer } from './Footer';
import { BottomNav } from './BottomNav';
import { CartDrawer } from './CartDrawer';
import { MobileMenu } from './MobileMenu';
import { QuickView } from './QuickView';
import { CartToast } from '@/components/ui/CartToast';
import { FloatingContact } from './FloatingContact';
import { Intro } from '@/components/motion/Intro';
import { PageTransition } from '@/components/motion/PageTransition';
import { useCart, useUI } from '@/store';
import { useCatalog } from '@/store/catalog';
import { TRANSITIONS } from '@/lib/motion';
import { watchSystemTheme, syncThemeColor } from '@/lib/theme';

/**
 * Clave de página para las transiciones: cada ruta es una página, salvo la
 * cuenta, que se navega por pestañas (sus subrutas no re-animan el marco).
 */
const pageKey = (pathname) => (pathname.startsWith('/cuenta') ? '/cuenta' : pathname);

const scrollTop = () => window.scrollTo({ top: 0, behavior: 'instant' });

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

  return (
    <>
      <a href="#main" className="mj-skip-link">Saltar al contenido</a>
      <Intro />
      <div className="mj-ambient" aria-hidden="true" />
      <Header />
      <main id="main" className="min-h-screen" tabIndex={-1}>
        {TRANSITIONS ? (
          <AnimatePresence mode="wait" initial={false} onExitComplete={scrollTop}>
            <PageTransition key={key}>{outlet}</PageTransition>
          </AnimatePresence>
        ) : (
          outlet
        )}
      </main>
      <Footer />
      {/* Espacio para que la barra inferior fija no tape el final del footer. */}
      <div className="h-20 pb-[env(safe-area-inset-bottom)] lg:hidden" aria-hidden="true" />
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
