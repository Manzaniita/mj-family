import { Link } from 'react-router-dom';
import { AnimatePresence, motion } from 'framer-motion';
import { STILL } from '@/lib/motion';
import { Heart, Scale, ShoppingBag, User, SunMedium, MoonStar, CalendarDays, ArrowUpRight, Menu } from 'lucide-react';
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
import { NAV_HEADER, isExternalHref } from '@/lib/nav';
import { iconByName } from '@/components/ui/DynamicIcon';

function IconAction({ to, onClick, icon: Icon, label, count }) {
  const content = (
    <motion.span
      whileHover={STILL ? undefined : { scale: 1.08 }}
      whileTap={STILL ? undefined : { scale: 0.92 }}
      className="mj-icon-action relative grid size-9 place-items-center rounded-(--mj-radius-pill) text-ink-soft transition-colors hover:text-ink hover:bg-surface lg:size-10"
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
      whileHover={STILL ? undefined : { scale: 1.08 }}
      whileTap={STILL ? undefined : { scale: 0.92 }}
      onClick={(e) => toggleTheme({ x: e.clientX || undefined, y: e.clientY || undefined })}
      className="mj-theme-toggle mj-icon-action relative grid size-9 place-items-center overflow-hidden rounded-(--mj-radius-pill) text-ink-soft transition-colors hover:text-ink hover:bg-surface lg:size-10"
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

/**
 * Accesos rápidos del header (Apariencia → MJ Appearance → Barra de navegación,
 * "Botón en el header").
 *
 * Es el lugar del acceso al ERP: quien puede entrar lo tiene a un clic desde
 * cualquier pantalla de la tienda, y quien no, ni siquiera recibe el enlace —
 * PHP filtra los ítems por audiencia antes de mandarlos al navegador.
 */
function QuickLinks() {
  if (!NAV_HEADER.length) return null;

  return NAV_HEADER.map((item) => {
    const Icon = item.icon ? iconByName(item.icon, ArrowUpRight) : ArrowUpRight;
    const content = (
      <>
        <Icon size={15} strokeWidth={1.8} />
        <span className="hidden xl:inline">{item.label}</span>
      </>
    );
    /*
     * También en el celular: es el atajo del dueño de la tienda y esconderlo en
     * el menú lateral era esconderlo del todo. En pantalla chica queda el ícono
     * solo, del mismo tamaño que el resto de los botones de la barra.
     */
    const props = {
      size: 'sm',
      variant: 'secondary',
      className: 'mj-header__quick ml-1.5 px-2.5 xl:px-4',
      title: item.label,
      'aria-label': item.label,
    };

    return isExternalHref(item.href) || item.target === '_blank' ? (
      <Button
        key={item.key}
        {...props}
        href={item.href}
        target={item.target === '_blank' ? '_blank' : undefined}
        rel={item.target === '_blank' ? 'noreferrer' : undefined}
      >
        {content}
      </Button>
    ) : (
      <Button key={item.key} {...props} to={item.href}>{content}</Button>
    );
  });
}

export function Header() {
  const cartCount = useCart((s) => s.items.reduce((n, i) => n + i.qty, 0));
  const wishCount = useWishlist((s) => s.ids.length);
  const compareCount = useCompare((s) => s.ids.length);
  const user = useAuth((s) => s.user);
  const setCartOpen = useUI((s) => s.setCartOpen);
  const setMobileMenuOpen = useUI((s) => s.setMobileMenuOpen);

  return (
    <>
      {/*
        Qué queda pegado arriba y qué no.
        ---------------------------------
        En la compu se pega el header entero: hay pantalla de sobra. En el celular no — tres barras
        fijas se comen un tercio de la vista mientras se recorre el catálogo. Así que en el celular
        los anuncios y el envío gratis se ven al entrar y se van con el scroll, y queda pegada solo
        la barra con el logo y los botones.

        Por eso están acá afuera y no adentro del header: un elemento sticky no puede quedarse fijo
        más allá de su contenedor, así que si vivieran dentro del header arrastrarían con ellos a la
        barra principal al scrollear. Afuera, el header sigue siendo sticky de punta a punta.
      */}
      <div className="lg:hidden">
        <TopBar />
        <FreeShippingBar />
      </div>

      <header className="mj-header sticky top-0 z-80">
        <div className="hidden lg:block">
          <TopBar />
          <FreeShippingBar />
        </div>
        <div className="mj-header__glass bg-bg/80 backdrop-blur-2xl">
          <div className="mj-header__bar hairline-b">
            {/*
              La fila no puede crecer más que la pantalla: el logo cede ancho (min-w-0 + truncate) y
              las acciones no se achican (shrink-0). Sin eso, en un celular angosto el contenido
              empuja la fila, se sale del fondo del header y la página termina scrolleando de costado.
            */}
            <div className="mx-auto flex h-16 max-w-7xl items-center gap-3 px-4 md:gap-8 md:px-8">
              {/* Menú del celular: es la única puerta al menú configurable y a los favoritos. */}
              <button
                type="button"
                onClick={() => setMobileMenuOpen(true)}
                aria-label={t('nav_menu')}
                title={t('nav_menu')}
                className="mj-header__menu -ml-1 grid size-9 shrink-0 place-items-center rounded-(--mj-radius-pill) text-ink-soft transition-colors hover:bg-surface hover:text-ink lg:hidden"
              >
                <Menu size={20} strokeWidth={1.8} />
              </button>

              {/* Logo configurable (Apariencia → MJ Appearance → Logo). */}
              <Logo className="min-w-0 flex-1 lg:flex-none" />

              <div className="hidden flex-1 justify-center lg:flex">
                <SearchBar />
              </div>

              <div className="mj-header__actions ml-auto flex shrink-0 items-center gap-0.5 lg:ml-0">
                {/* Tipo de cambio (plugin MJ Dólar): no renderiza nada si el plugin no está. */}
                <ExchangeRate className="mr-1.5" />
                {/* Selector "Ver precios con" (plugin MJ Medios de Pago); en mobile vive en el menú. */}
                <PaymentSelector className="mr-1.5 hidden sm:inline-flex" />
                <ThemeToggle />
                <span className="hidden lg:contents">
                  <IconAction to={user ? '/cuenta' : '/login'} icon={User} label={t('nav_account')} />
                </span>
                {/* En el celular estos dos viven en el menú lateral: la barra no da para más. */}
                <span className="hidden lg:contents">
                  <IconAction to="/favoritos" icon={Heart} label="Favoritos" count={wishCount} />
                  <IconAction to="/comparar" icon={Scale} label="Comparar" count={compareCount} />
                </span>
                <span className="hidden lg:contents">
                  <IconAction
                    onClick={() => setCartOpen(true)}
                    icon={ShoppingBag}
                    label={t('nav_cart')}
                    count={cartCount}
                  />
                </span>
                {/* Accesos rápidos configurables (ERP, paneles propios): también en el celular. */}
                <QuickLinks />
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
    </>
  );
}
