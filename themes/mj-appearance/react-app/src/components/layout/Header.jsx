import { Link } from 'react-router-dom';
import { AnimatePresence, motion } from 'framer-motion';
import { Heart, Scale, ShoppingBag, User, SunMedium, MoonStar, CalendarDays } from 'lucide-react';
import { TopBar } from './TopBar';
import { FreeShippingBar } from './FreeShippingBar';
import { SearchBar } from './SearchBar';
import { MegaMenu } from './MegaMenu';
import { ExchangeRate } from './ExchangeRate';
import { PaymentSelector } from './PaymentSelector';
import { Logo } from '@/components/ui/Logo';
import { Button } from '@/components/ui/Button';
import { useCart, useWishlist, useCompare, useUI, useAuth } from '@/store';
import { EASE } from '@/lib/format';
import { t } from '@/lib/copy';
import { TURNOS, bookingPath } from '@/lib/turnos';

function IconAction({ to, onClick, icon: Icon, label, count }) {
  const content = (
    <motion.span
      whileHover={{ scale: 1.08 }}
      whileTap={{ scale: 0.92 }}
      className="mj-icon-action relative grid size-10 place-items-center rounded-(--mj-radius-pill) text-ink-soft transition-colors hover:text-ink hover:bg-surface"
    >
      <Icon size={19} strokeWidth={1.8} />
      {count > 0 && (
        <motion.span
          key={count}
          initial={{ scale: 0.5 }}
          animate={{ scale: 1 }}
          transition={{ type: 'spring', stiffness: 500, damping: 20 }}
          className="mj-icon-action__count absolute -right-0.5 -top-0.5 grid min-w-4.5 place-items-center rounded-full bg-accent px-1 text-[10px] font-bold text-white h-4.5"
        >
          {count}
        </motion.span>
      )}
    </motion.span>
  );
  return to ? (
    <Link to={to} aria-label={label} title={label}>{content}</Link>
  ) : (
    <button onClick={onClick} aria-label={label} title={label}>{content}</button>
  );
}

/**
 * Botón día / noche. Es un switch (role="switch" + aria-checked = modo
 * oscuro activo); el ícono rota y se intercambia, y el click dispara la
 * transición circular desde el punto exacto donde se tocó.
 */
function ThemeToggle() {
  const theme = useUI((s) => s.theme);
  const toggleTheme = useUI((s) => s.toggleTheme);
  const dark = theme === 'dark';
  const label = dark ? 'Activar modo claro' : 'Activar modo oscuro';
  const Icon = dark ? SunMedium : MoonStar;

  return (
    <motion.button
      type="button"
      role="switch"
      aria-checked={dark}
      aria-label={label}
      title={label}
      whileHover={{ scale: 1.08 }}
      whileTap={{ scale: 0.92 }}
      onClick={(e) => toggleTheme({ x: e.clientX || undefined, y: e.clientY || undefined })}
      className="mj-theme-toggle mj-icon-action relative grid size-10 place-items-center overflow-hidden rounded-(--mj-radius-pill) text-ink-soft transition-colors hover:text-ink hover:bg-surface"
    >
      <AnimatePresence mode="popLayout" initial={false}>
        <motion.span
          key={theme}
          className="grid place-items-center"
          initial={{ rotate: -90, scale: 0.4, opacity: 0 }}
          animate={{ rotate: 0, scale: 1, opacity: 1 }}
          exit={{ rotate: 90, scale: 0.4, opacity: 0 }}
          transition={{ duration: 0.4, ease: EASE }}
        >
          <Icon size={19} strokeWidth={1.8} />
        </motion.span>
      </AnimatePresence>
    </motion.button>
  );
}

export function Header() {
  const cartCount = useCart((s) => s.items.reduce((n, i) => n + i.qty, 0));
  const wishCount = useWishlist((s) => s.ids.length);
  const compareCount = useCompare((s) => s.ids.length);
  const user = useAuth((s) => s.user);
  const setCartOpen = useUI((s) => s.setCartOpen);

  return (
    <header className="mj-header sticky top-0 z-80">
      <div className="hidden lg:block">
        <TopBar />
      </div>
      <div className="mj-header__glass bg-bg/80 backdrop-blur-2xl">
        <div className="hidden lg:block">
          <FreeShippingBar />
        </div>
        <div className="mj-header__bar hairline-b">
          <div className="mx-auto flex h-16 max-w-7xl items-center gap-4 px-4 md:gap-8 md:px-8">
            {/* Logo configurable (Apariencia → MJ Appearance → Logo). */}
            <Logo />

            <div className="hidden flex-1 justify-center lg:flex">
              <SearchBar />
            </div>

            <div className="mj-header__actions ml-auto flex items-center gap-0.5 lg:ml-0">
              {/* Tipo de cambio (plugin MJ Dólar): no renderiza nada si el plugin no está. */}
              <ExchangeRate className="mr-1.5" />
              {/* Selector "Ver precios con" (plugin MJ Medios de Pago); en mobile vive en el menú. */}
              <PaymentSelector className="mr-1.5 hidden sm:inline-flex" />
              <ThemeToggle />
              <span className="hidden lg:contents">
                <IconAction to={user ? '/cuenta' : '/login'} icon={User} label={t('nav_account')} />
              </span>
              <IconAction to="/favoritos" icon={Heart} label="Favoritos" count={wishCount} />
              <IconAction to="/comparar" icon={Scale} label="Comparar" count={compareCount} />
              <span className="hidden lg:contents">
                <IconAction
                  onClick={() => setCartOpen(true)}
                  icon={ShoppingBag}
                  label={t('nav_cart')}
                  count={cartCount}
                />
              </span>
              {/* CTA "Reservar" (plugin MJ Turnos Online): solo desktop; texto y visibilidad en Turnos → Ajustes. */}
              {TURNOS?.menu.headerCtaEnabled && (
                <span className="hidden lg:contents">
                  <Button size="sm" to={bookingPath()} className="mj-header__cta ml-2">
                    <CalendarDays size={15} />
                    {TURNOS.menu.headerCta}
                  </Button>
                </span>
              )}
            </div>
          </div>
        </div>
        <MegaMenu />
      </div>
    </header>
  );
}
