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
import { HEADER, HEADER_LAYOUT } from '@/lib/config';
import { DynamicHeader } from './DynamicHeader';

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
  const label = dark ? t('nav_theme_light') : t('nav_theme_dark');
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

export function Header(props = {}) {
  const override = props.override || (props.sticky !== undefined || props.headerStyle !== undefined ? props : null);
  const isBlock = !!override;

  if (!isBlock && HEADER_LAYOUT) {
    return <DynamicHeader layout={HEADER_LAYOUT} />;
  }

  const conf = {
    sticky: override?.sticky || HEADER.sticky || 'sticky',
    headerStyle: override?.headerStyle || HEADER.headerStyle || 'glass',
    border: override?.border || HEADER.border || 'subtle',
    logoPosition: override?.logoPosition || HEADER.logoPosition || 'left',
    searchPosition: override?.searchPosition || HEADER.searchPosition || 'center',
    menuPosition: override?.menuPosition || HEADER.menuPosition || 'below',
    showWishlist: override?.showWishlist !== undefined ? !!override.showWishlist : HEADER.showWishlist,
    showCompare: override?.showCompare !== undefined ? !!override.showCompare : HEADER.showCompare,
    showCart: override?.showCart !== undefined ? !!override.showCart : HEADER.showCart,
    showAccount: override?.showAccount !== undefined ? !!override.showAccount : HEADER.showAccount,
    showThemeToggle: override?.showThemeToggle !== undefined ? !!override.showThemeToggle : HEADER.showThemeToggle,
    showDollarRate: override?.showDollarRate !== undefined ? !!override.showDollarRate : HEADER.showDollarRate,
    showPaymentSelect: override?.showPaymentSelect !== undefined ? !!override.showPaymentSelect : HEADER.showPaymentSelect,
    showTurnosCta: override?.showTurnosCta !== undefined ? !!override.showTurnosCta : (HEADER.showTurnosCta || !!TURNOS?.menu.headerCtaEnabled),
    turnosCtaText: override?.turnosCtaText || HEADER.turnosCtaText || TURNOS?.menu.headerCta || 'Reservar',
    customButtonText: override?.customButtonText ?? HEADER.customButtonText,
    customButtonUrl: override?.customButtonUrl ?? HEADER.customButtonUrl,
    customButtonIcon: override?.customButtonIcon ?? HEADER.customButtonIcon,
    customButtonTarget: override?.customButtonTarget ?? HEADER.customButtonTarget,
    bgColor: override?.bgColor || HEADER.bgColor || '',
    inkColor: override?.inkColor || HEADER.inkColor || '',
    accentColor: override?.accentColor || HEADER.accentColor || '',
  };

  const cartCount = useCart((s) => s.items.reduce((n, i) => n + i.qty, 0));
  const wishCount = useWishlist((s) => s.ids.length);
  const compareCount = useCompare((s) => s.ids.length);
  const user = useAuth((s) => s.user);
  const setCartOpen = useUI((s) => s.setCartOpen);
  const setMobileMenuOpen = useUI((s) => s.setMobileMenuOpen);

  const stickyClass = {
    sticky: 'sticky top-0 z-80',
    static: 'relative z-80',
    'sticky-desktop': 'relative lg:sticky lg:top-0 z-80',
    'sticky-mobile': 'sticky top-0 lg:relative z-80',
  }[conf.sticky] || 'sticky top-0 z-80';

  const styleClass = {
    glass: 'bg-bg/80 backdrop-blur-2xl',
    solid: 'bg-bg',
    transparent: 'bg-transparent',
  }[conf.headerStyle] || 'bg-bg/80 backdrop-blur-2xl';

  const borderClass = {
    subtle: 'hairline-b',
    strong: 'border-b border-line-strong',
    none: 'border-b-0',
  }[conf.border] || 'hairline-b';

  const inlineStyles = {};
  if (conf.bgColor) inlineStyles.backgroundColor = conf.bgColor;
  if (conf.inkColor) inlineStyles.color = conf.inkColor;
  if (conf.accentColor) inlineStyles['--mj-accent'] = conf.accentColor;

  return (
    <>
      {!isBlock && (
        <div className="lg:hidden">
          <TopBar />
          <FreeShippingBar />
        </div>
      )}

      <header className={`mj-header ${stickyClass}`} style={inlineStyles}>
        {!isBlock && (
          <div className="hidden lg:block">
            <TopBar />
            <FreeShippingBar />
          </div>
        )}
        <div className={`mj-header__glass ${styleClass}`}>
          <div className={`mj-header__bar ${borderClass}`}>
            <div className="mx-auto flex h-16 max-w-7xl items-center gap-3 px-4 md:gap-8 md:px-8">
              {/* Menú del celular / drawer */}
              <button
                type="button"
                onClick={() => setMobileMenuOpen(true)}
                aria-label={t('nav_menu')}
                title={t('nav_menu')}
                className={`mj-header__menu -ml-1 grid size-9 shrink-0 place-items-center rounded-(--mj-radius-pill) text-ink-soft transition-colors hover:bg-surface hover:text-ink ${conf.menuPosition === 'drawer' ? '' : 'lg:hidden'}`}
              >
                <Menu size={20} strokeWidth={1.8} />
              </button>

              {/* Logo a la izquierda */}
              {conf.logoPosition === 'left' && (
                <Logo className="min-w-0 flex-1 lg:flex-none" />
              )}

              {/* Centro: buscador, navegación inline o logo centrado */}
              {conf.logoPosition === 'center' ? (
                <>
                  <div className="hidden flex-1 items-center justify-start lg:flex">
                    {conf.menuPosition === 'inline' ? (
                      <MegaMenu inline />
                    ) : conf.searchPosition === 'center' ? (
                      <SearchBar />
                    ) : null}
                  </div>
                  <Logo className="min-w-0 flex-1 text-center lg:flex-none" />
                </>
              ) : (
                <div className="hidden flex-1 justify-center lg:flex">
                  {conf.menuPosition === 'inline' ? (
                    <MegaMenu inline />
                  ) : conf.searchPosition === 'center' ? (
                    <SearchBar />
                  ) : null}
                </div>
              )}

              {/* Buscador a la derecha si está configurado */}
              {conf.searchPosition === 'right' && (
                <div className="hidden lg:flex shrink-0">
                  <SearchBar />
                </div>
              )}

              <div className="mj-header__actions ml-auto flex shrink-0 items-center gap-0.5 lg:ml-0">
                {/* Tipo de cambio (plugin MJ Dólar) */}
                {conf.showDollarRate && <ExchangeRate className="mr-1.5" />}
                {/* Selector de precios por medio de pago */}
                {conf.showPaymentSelect && <PaymentSelector className="mr-1.5 hidden sm:inline-flex" />}
                {conf.showThemeToggle && <ThemeToggle />}
                {conf.showAccount && (
                  <span className="hidden lg:contents">
                    <IconAction to={user ? '/cuenta' : '/login'} icon={User} label={t('nav_account')} />
                  </span>
                )}
                {conf.showWishlist && (
                  <span className="hidden lg:contents">
                    <IconAction to="/favoritos" icon={Heart} label={t('nav_favorites')} count={wishCount} />
                  </span>
                )}
                {conf.showCompare && (
                  <span className="hidden lg:contents">
                    <IconAction to="/comparar" icon={Scale} label={t('nav_compare')} count={compareCount} />
                  </span>
                )}
                {conf.showCart && (
                  <span className="hidden lg:contents">
                    <IconAction
                      onClick={() => setCartOpen(true)}
                      icon={ShoppingBag}
                      label={t('nav_cart')}
                      count={cartCount}
                    />
                  </span>
                )}
                <QuickLinks />
                {/* Botón personalizado */}
                {conf.customButtonText && (
                  <span className="hidden lg:contents">
                    <Button
                      size="sm"
                      variant="secondary"
                      href={conf.customButtonUrl || '#'}
                      target={conf.customButtonTarget === '_blank' ? '_blank' : undefined}
                      rel={conf.customButtonTarget === '_blank' ? 'noreferrer' : undefined}
                      className="mj-header__custom-btn ml-2"
                    >
                      {conf.customButtonIcon && <span className="mr-1">{conf.customButtonIcon}</span>}
                      {conf.customButtonText}
                    </Button>
                  </span>
                )}
                {/* CTA Turnos */}
                {conf.showTurnosCta && (
                  <span className="hidden lg:contents">
                    <Button size="sm" to={bookingPath()} className="mj-header__cta ml-2">
                      <CalendarDays size={15} />
                      {conf.turnosCtaText}
                    </Button>
                  </span>
                )}
              </div>
            </div>
          </div>
          {conf.menuPosition === 'below' && <MegaMenu />}
        </div>
      </header>
    </>
  );
}
