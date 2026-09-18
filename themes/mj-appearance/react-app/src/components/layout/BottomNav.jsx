import { useEffect, useState } from 'react';
import { Link, useLocation } from 'react-router-dom';
import { AnimatePresence, motion } from 'framer-motion';
import { Home, Store, Search, ShoppingBag, User, X, CalendarDays } from 'lucide-react';
import { SearchBar } from './SearchBar';
import { useCart, useUI, useAuth } from '@/store';
import { EASE } from '@/lib/format';
import { t } from '@/lib/copy';
import { TURNOS, bookingPath } from '@/lib/turnos';

/**
 * Barra de navegación inferior fija — solo mobile (< lg). Etiquetas por
 * Textos → Menú; la pestaña Turnos la aporta el plugin MJ Turnos Online
 * (Turnos → Ajustes → "Mostrar en la barra inferior").
 */
export function BottomNav() {
  const [searchOpen, setSearchOpen] = useState(false);
  const { pathname } = useLocation();
  const cartCount = useCart((s) => s.items.reduce((n, i) => n + i.qty, 0));
  const user = useAuth((s) => s.user);
  const setCartOpen = useUI((s) => s.setCartOpen);

  // El buscador navega a /buscar o a un producto: cerrar el overlay al navegar.
  useEffect(() => setSearchOpen(false), [pathname]);

  useEffect(() => {
    if (!searchOpen) return undefined;
    const onKey = (e) => e.key === 'Escape' && setSearchOpen(false);
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [searchOpen]);

  const accountTo = user ? '/cuenta' : '/login';
  const accountActive = pathname.startsWith('/cuenta') || pathname.startsWith('/login');

  const showTurnos = !!TURNOS?.menu.showBottomNav;
  const turnosActive = showTurnos && (pathname === bookingPath() || pathname.startsWith(`${bookingPath()}/`));

  const itemClass = (active) =>
    `mj-bottomnav__item flex flex-1 flex-col items-center gap-1 py-2.5 text-[10px] font-medium transition-colors ${
      active ? 'mj-bottomnav__item--active text-accent' : 'text-ink-faint hover:text-ink'
    }`;

  return (
    <>
      <nav
        className="mj-bottomnav fixed inset-x-0 bottom-0 z-80 border-t border-line bg-bg/85 backdrop-blur-2xl lg:hidden"
        aria-label={t('nav_mobile_label')}
      >
        <div className="flex items-stretch pb-[env(safe-area-inset-bottom)]">
          <Link to="/" className={itemClass(pathname === '/')} aria-label={t('nav_home_mobile')}>
            <Home size={20} strokeWidth={1.8} />
            {t('nav_home_mobile')}
          </Link>
          {showTurnos && (
            <Link
              to={bookingPath()}
              className={`${itemClass(turnosActive)} mj-bottomnav__item--turnos`}
              aria-label={TURNOS.menu.label}
            >
              <CalendarDays size={20} strokeWidth={1.8} />
              {TURNOS.menu.label}
            </Link>
          )}
          <Link
            to="/tienda"
            className={itemClass(pathname.startsWith('/tienda') || pathname.startsWith('/categoria'))}
            aria-label={t('nav_shop')}
          >
            <Store size={20} strokeWidth={1.8} />
            {t('nav_shop')}
          </Link>
          <button onClick={() => setSearchOpen(true)} className={itemClass(searchOpen)} aria-label={t('nav_search')}>
            <Search size={20} strokeWidth={1.8} />
            {t('nav_search')}
          </button>
          <button onClick={() => setCartOpen(true)} className={itemClass(false)} aria-label={t('nav_cart')}>
            <span className="relative">
              <ShoppingBag size={20} strokeWidth={1.8} />
              {cartCount > 0 && (
                <motion.span
                  key={cartCount}
                  initial={{ scale: 0.5 }}
                  animate={{ scale: 1 }}
                  transition={{ type: 'spring', stiffness: 500, damping: 20 }}
                  className="absolute -right-2 -top-1.5 grid min-w-4.5 place-items-center rounded-full bg-accent px-1 text-[10px] font-bold text-white h-4.5"
                >
                  {cartCount}
                </motion.span>
              )}
            </span>
            {t('nav_cart')}
          </button>
          <Link to={accountTo} className={itemClass(accountActive)} aria-label={t('nav_account')}>
            <User size={20} strokeWidth={1.8} />
            {t('nav_account')}
          </Link>
        </div>
      </nav>

      <AnimatePresence>
        {searchOpen && (
          <motion.div
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            exit={{ opacity: 0 }}
            transition={{ duration: 0.25, ease: EASE }}
            className="fixed inset-0 z-90 bg-bg/90 backdrop-blur-2xl lg:hidden"
            onClick={(e) => e.target === e.currentTarget && setSearchOpen(false)}
          >
            <motion.div
              initial={{ y: 24, opacity: 0 }}
              animate={{ y: 0, opacity: 1 }}
              exit={{ y: 24, opacity: 0 }}
              transition={{ duration: 0.3, ease: EASE }}
              className="mx-auto max-w-xl px-4 pt-24"
            >
              <div className="mb-4 flex items-center justify-between">
                <p className="font-display text-lg font-semibold tracking-tight">{t('search_label')}</p>
                <button
                  onClick={() => setSearchOpen(false)}
                  className="grid size-10 place-items-center rounded-full text-ink-soft hover:bg-surface"
                  aria-label={t('search_close')}
                >
                  <X size={20} />
                </button>
              </div>
              <div ref={(el) => el?.querySelector('input')?.focus()}>
                <SearchBar />
              </div>
            </motion.div>
          </motion.div>
        )}
      </AnimatePresence>
    </>
  );
}
