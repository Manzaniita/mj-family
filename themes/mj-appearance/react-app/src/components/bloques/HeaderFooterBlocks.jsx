import { useState } from 'react';
import { Link } from 'react-router-dom';
import {
  ShoppingBag, Heart, Scale, User, Menu, ArrowUpRight,
  MapPin, Phone, Mail, CreditCard, Loader2,
  Instagram, Facebook, Twitter, Youtube, Music2
} from 'lucide-react';
import { Logo } from '@/components/ui/Logo';
import { MegaMenu } from '@/components/layout/MegaMenu';
import { SearchBar } from '@/components/layout/SearchBar';
import { ExchangeRate } from '@/components/layout/ExchangeRate';
import { PaymentSelector } from '@/components/layout/PaymentSelector';
import { TopBar } from '@/components/layout/TopBar';
import { FreeShippingBar } from '@/components/layout/FreeShippingBar';
import { Button } from '@/components/ui/Button';
import { iconByName } from '@/components/ui/DynamicIcon';
import { IconAction, ThemeToggle, QuickLinks } from '@/components/layout/Header';
import { useCart, useWishlist, useCompare, useUI, useAuth } from '@/store';
import { t } from '@/lib/copy';
import { isExternalHref } from '@/lib/nav';
import { CONTACT, SOCIAL, SITE } from '@/lib/config';
import { sendContact } from '@/lib/wc';

/* 1. Logo */
export function LogoBlock({ showSiteName = true }) {
  const setMobileMenuOpen = useUI((s) => s.setMobileMenuOpen);
  return (
    <div className="mj-block-logo flex items-center gap-2 min-w-0">
      <button
        type="button"
        onClick={() => setMobileMenuOpen(true)}
        aria-label={t('nav_menu')}
        title={t('nav_menu')}
        className="mj-header__menu -ml-1 grid size-9 shrink-0 place-items-center rounded-(--mj-radius-pill) text-ink-soft transition-colors hover:bg-surface hover:text-ink lg:hidden"
      >
        <Menu size={20} strokeWidth={1.8} />
      </button>
      <Logo showSiteName={showSiteName} className="min-w-0 flex-1 lg:flex-none" />
    </div>
  );
}

/* 2. Menú de navegación / MegaMenú */
export function NavBlock({ style = 'inline' }) {
  if (style === 'megamenu') {
    return (
      <div className="mj-block-nav-megamenu w-full">
        <MegaMenu />
      </div>
    );
  }
  return (
    <nav className="mj-block-nav hidden lg:flex items-center">
      <MegaMenu inline />
    </nav>
  );
}

/* 3. Buscador */
export function SearchBlock({ maxWidth = '360px' }) {
  return (
    <div className="mj-block-search hidden sm:flex items-center w-full" style={{ maxWidth }}>
      <SearchBar />
    </div>
  );
}

/* 4. Carrito WooCommerce */
export function CartBlock({ showCount = true }) {
  const cartCount = useCart((s) => s.items.reduce((n, i) => n + i.qty, 0));
  const setCartOpen = useUI((s) => s.setCartOpen);
  return (
    <div className="mj-block-cart flex items-center">
      <IconAction
        onClick={() => setCartOpen(true)}
        icon={ShoppingBag}
        label={t('nav_cart')}
        count={showCount ? cartCount : 0}
      />
    </div>
  );
}

/* 5. Favoritos / Wishlist */
export function WishlistBlock({ showCount = true }) {
  const wishCount = useWishlist((s) => s.ids.length);
  return (
    <div className="mj-block-wishlist hidden sm:flex items-center">
      <IconAction to="/favoritos" icon={Heart} label={t('nav_favorites')} count={showCount ? wishCount : 0} />
    </div>
  );
}

/* 6. Comparador */
export function CompareBlock({ showCount = true }) {
  const compareCount = useCompare((s) => s.ids.length);
  return (
    <div className="mj-block-compare hidden md:flex items-center">
      <IconAction to="/comparar" icon={Scale} label={t('nav_compare')} count={showCount ? compareCount : 0} />
    </div>
  );
}

/* 7. Cuenta / Login */
export function AccountBlock() {
  const user = useAuth((s) => s.user);
  return (
    <div className="mj-block-account hidden sm:flex items-center">
      <IconAction to={user ? '/cuenta' : '/login'} icon={User} label={t('nav_account')} />
    </div>
  );
}

/* 8. Selector de tema (sol / luna) */
export function ThemeToggleBlock() {
  return (
    <div className="mj-block-theme flex items-center">
      <ThemeToggle />
    </div>
  );
}

/* 9. Cotización MJ Dólar */
export function DolarBlock() {
  return (
    <div className="mj-block-dolar flex items-center">
      <ExchangeRate className="mr-1.5" />
    </div>
  );
}

/* 10. Medios de pago */
export function MediosPagoBlock({ variant = 'selector' }) {
  if (variant === 'badges') {
    return (
      <div className="mj-block-medios-pago-badges flex items-center gap-2 text-xs text-ink-soft">
        <CreditCard size={18} />
        <span>Tarjetas, transferencia y efectivo</span>
      </div>
    );
  }
  return (
    <div className="mj-block-medios-pago flex items-center">
      <PaymentSelector className="mr-1.5 hidden sm:inline-flex" />
    </div>
  );
}

/* 11. Barra superior de anuncios (TopBar) */
export function AnunciosBlock() {
  return (
    <div className="mj-block-anuncios w-full">
      <TopBar />
    </div>
  );
}

/* 12. Barra de envío gratis */
export function EnvioGratisBlock() {
  return (
    <div className="mj-block-envio-gratis w-full">
      <FreeShippingBar />
    </div>
  );
}

/* 13. Grupo de acciones rápidas de cabecera */
export function AccionesHeaderBlock(props = {}) {
  const cartCount = useCart((s) => s.items.reduce((n, i) => n + i.qty, 0));
  const wishCount = useWishlist((s) => s.ids.length);
  const compareCount = useCompare((s) => s.ids.length);
  const user = useAuth((s) => s.user);
  const setCartOpen = useUI((s) => s.setCartOpen);

  return (
    <div className="mj-header__actions flex shrink-0 items-center gap-0.5">
      {props.showDollarRate !== false && <ExchangeRate className="mr-1.5" />}
      {props.showPaymentSelect !== false && <PaymentSelector className="mr-1.5 hidden sm:inline-flex" />}
      {props.showThemeToggle !== false && <ThemeToggle />}
      {props.showAccount !== false && (
        <span className="hidden lg:contents">
          <IconAction to={user ? '/cuenta' : '/login'} icon={User} label={t('nav_account')} />
        </span>
      )}
      {props.showWishlist !== false && (
        <span className="hidden lg:contents">
          <IconAction to="/favoritos" icon={Heart} label={t('nav_favorites')} count={wishCount} />
        </span>
      )}
      {props.showCompare !== false && (
        <span className="hidden lg:contents">
          <IconAction to="/comparar" icon={Scale} label={t('nav_compare')} count={compareCount} />
        </span>
      )}
      {props.showCart !== false && (
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
    </div>
  );
}

/* 14. Botón CTA */
export function CtaButtonBlock({ label = 'Ver más', url = '/tienda', variant = 'secondary', icon = 'arrow-up-right', target = '_self' }) {
  const Icon = icon ? iconByName(icon, ArrowUpRight) : null;
  const isExt = isExternalHref(url) || target === '_blank';
  const content = (
    <>
      {Icon && <Icon size={15} strokeWidth={1.8} className="mr-1" />}
      <span>{label}</span>
    </>
  );
  const btnProps = {
    size: 'sm',
    variant,
    className: 'px-3',
    title: label,
    'aria-label': label,
  };
  return (
    <div className="mj-block-cta flex items-center">
      {isExt ? (
        <Button {...btnProps} href={url} target={target === '_blank' ? '_blank' : undefined} rel={target === '_blank' ? 'noreferrer' : undefined}>
          {content}
        </Button>
      ) : (
        <Button {...btnProps} to={url}>
          {content}
        </Button>
      )}
    </div>
  );
}

/* 15. Newsletter */
export function NewsletterBlock({ title, subtitle, buttonText }) {
  const [email, setEmail] = useState('');
  const [status, setStatus] = useState('idle');

  const handleSubmit = async (e) => {
    e.preventDefault();
    if (!email || status === 'sending') return;
    setStatus('sending');
    try {
      const res = await sendContact({ email, subject: 'Suscripción Newsletter', message: 'Suscripción desde footer' });
      if (res.ok) {
        setStatus('ok');
        setEmail('');
      } else {
        setStatus('error');
      }
    } catch {
      setStatus('error');
    }
  };

  return (
    <div className="mj-block-newsletter hairline-b py-8 w-full">
      <div className="mx-auto flex max-w-7xl flex-col items-start justify-between gap-6 px-4 md:flex-row md:items-center md:px-8">
        <div>
          <h3 className="font-display text-xl font-bold tracking-tight text-ink md:text-2xl">
            {title || t('footer_news_title') || 'Suscribite a novedades'}
          </h3>
          <p className="mt-1 text-sm text-ink-soft">
            {subtitle || t('footer_news_subtitle') || 'Lanzamientos, ofertas exclusivas y novedades.'}
          </p>
        </div>
        <form onSubmit={handleSubmit} className="flex w-full max-w-md gap-2">
          <input
            type="email"
            required
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            placeholder={t('footer_news_placeholder') || 'Tu correo electrónico'}
            className="flex-1 rounded-(--mj-radius-input) border border-line bg-surface px-4 py-2 text-sm text-ink placeholder:text-ink-faint focus:border-accent focus:outline-none"
          />
          <Button type="submit" variant="primary" size="md" disabled={status === 'sending'}>
            {status === 'sending' ? <Loader2 className="animate-spin" size={16} /> : (buttonText || t('footer_news_button') || 'Suscribirme')}
          </Button>
        </form>
      </div>
    </div>
  );
}

/* 16. Redes sociales */
export function SocialsBlock() {
  const socials = [
    { key: 'instagram', icon: Instagram, href: SOCIAL?.instagram },
    { key: 'facebook', icon: Facebook, href: SOCIAL?.facebook },
    { key: 'twitter', icon: Twitter, href: SOCIAL?.twitter },
    { key: 'youtube', icon: Youtube, href: SOCIAL?.youtube },
    { key: 'tiktok', icon: Music2, href: SOCIAL?.tiktok },
  ].filter((s) => s.href);

  if (!socials.length) return null;

  return (
    <div className="mj-block-socials flex items-center gap-3">
      {socials.map(({ key, icon: Icon, href }) => (
        <a
          key={key}
          href={href}
          target="_blank"
          rel="noreferrer"
          className="grid size-9 place-items-center rounded-(--mj-radius-pill) bg-surface text-ink-soft transition-colors hover:bg-surface-2 hover:text-ink"
        >
          <Icon size={16} />
        </a>
      ))}
    </div>
  );
}

/* 17. Marca del pie de página */
export function FooterBrandBlock({ tagline, showSocials = true }) {
  return (
    <div className="mj-block-footer-brand flex flex-col gap-4">
      <Logo showSiteName />
      <p className="text-sm text-ink-soft leading-relaxed max-w-sm">
        {tagline || t('footer_tagline') || SITE?.tagline}
      </p>
      {showSocials && <SocialsBlock />}
    </div>
  );
}

/* 18. Columna de enlaces del footer */
export function FooterLinksBlock({ title, links }) {
  const parsedLinks = typeof links === 'string'
    ? links.split('\n').map((line) => {
        const parts = line.split('|').map((s) => s.trim());
        return { label: parts[0] || '', href: parts[1] || '#', target: parts[2] || '_self' };
      }).filter((l) => l.label)
    : (Array.isArray(links) ? links : []);

  return (
    <div className="mj-block-footer-links flex flex-col gap-3">
      {title && <h4 className="font-display text-sm font-semibold text-ink">{title}</h4>}
      <ul className="flex flex-col gap-2">
        {parsedLinks.map((l, i) => (
          <li key={i}>
            {isExternalHref(l.href) || l.target === '_blank' ? (
              <a
                href={l.href}
                target={l.target}
                rel={l.target === '_blank' ? 'noreferrer' : undefined}
                className="text-sm text-ink-soft transition-colors hover:text-ink"
              >
                {l.label}
              </a>
            ) : (
              <Link to={l.href} className="text-sm text-ink-soft transition-colors hover:text-ink">
                {l.label}
              </Link>
            )}
          </li>
        ))}
      </ul>
    </div>
  );
}

/* 19. Contacto del pie de página */
export function ContactoBlock() {
  return (
    <div className="mj-block-contacto flex flex-col gap-3">
      <h4 className="font-display text-sm font-semibold text-ink">{t('footer_contact_title') || 'Contacto'}</h4>
      <div className="flex flex-col gap-2 text-sm text-ink-soft">
        {CONTACT?.address && (
          <div className="flex items-start gap-2">
            <MapPin size={16} className="mt-0.5 shrink-0 text-accent" />
            <span>{CONTACT.address}</span>
          </div>
        )}
        {CONTACT?.phone && (
          <div className="flex items-center gap-2">
            <Phone size={16} className="shrink-0 text-accent" />
            <a href={`tel:${CONTACT.phone}`} className="hover:text-ink">{CONTACT.phone}</a>
          </div>
        )}
        {CONTACT?.email && (
          <div className="flex items-center gap-2">
            <Mail size={16} className="shrink-0 text-accent" />
            <a href={`mailto:${CONTACT.email}`} className="hover:text-ink">{CONTACT.email}</a>
          </div>
        )}
      </div>
    </div>
  );
}

/* 20. Barra legal y copyright */
export function FooterLegalBlock({ copyright, showPayments = true }) {
  const copyText = copyright || `© ${new Date().getFullYear()} ${SITE?.name || 'MJ Appearance'}. ${t('footer_rights')}`;
  return (
    <div className="mj-block-footer-legal hairline-t mt-12 pt-8 flex flex-col items-center justify-between gap-4 md:flex-row text-xs text-ink-faint w-full">
      <p>{copyText}</p>
      {showPayments && (
        <div className="flex items-center gap-3">
          <CreditCard size={18} />
          <span>{t('footer_payments_accepted') || 'Medios de pago seguros'}</span>
        </div>
      )}
    </div>
  );
}

export const HEADER_FOOTER_BLOCKS = {
  logo: LogoBlock,
  nav: NavBlock,
  search: SearchBlock,
  carrito: CartBlock,
  favoritos: WishlistBlock,
  comparador: CompareBlock,
  cuenta: AccountBlock,
  'tema-toggle': ThemeToggleBlock,
  dolar: DolarBlock,
  'medios-pago': MediosPagoBlock,
  anuncios: AnunciosBlock,
  'envio-gratis': EnvioGratisBlock,
  'acciones-header': AccionesHeaderBlock,
  'cta-button': CtaButtonBlock,
  newsletter: NewsletterBlock,
  'footer-brand': FooterBrandBlock,
  'footer-links': FooterLinksBlock,
  redes: SocialsBlock,
  contacto: ContactoBlock,
  'footer-legal': FooterLegalBlock,
};
