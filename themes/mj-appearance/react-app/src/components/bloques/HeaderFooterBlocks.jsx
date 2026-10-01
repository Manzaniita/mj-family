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
import { CONTACT, SOCIAL, SITE, LOGO } from '@/lib/config';
import { sendContact } from '@/lib/wc';

/* 1. Logo */
export function LogoBlock({
  customLogo,
  showSiteName = true,
  logoWidth = '130px',
  logoHeight = '40px',
  siteNameSize,
  siteNameColor
}) {
  const setMobileMenuOpen = useUI((s) => s.setMobileMenuOpen);
  const theme = useUI((s) => s.theme);
  const siteLogo = LOGO?.url ? (theme === 'dark' && LOGO.dark ? LOGO.dark : LOGO.url) : '';
  const logo = customLogo?.url || (typeof customLogo === 'string' && customLogo ? customLogo : '') || siteLogo;
  const siteName = SITE?.name || 'Tienda';
  const logoStyle = {};
  if (logoWidth && logoWidth !== 'auto') logoStyle.maxWidth = logoWidth;
  if (logoHeight && logoHeight !== 'none') logoStyle.maxHeight = logoHeight;

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
      <Link to="/" className="flex items-center gap-2.5 group min-w-0">
        {logo ? (
          <img
            src={logo}
            alt={siteName}
            style={logoStyle}
            className="h-auto w-auto object-contain transition-transform group-hover:scale-105"
          />
        ) : null}
        {(!logo || showSiteName) ? (
          <span
            className="font-bold tracking-tight text-ink group-hover:text-accent transition-colors truncate"
            style={{ fontSize: siteNameSize || undefined, color: siteNameColor || undefined }}
          >
            {siteName}
          </span>
        ) : null}
      </Link>
    </div>
  );
}

/* 2. Menú de navegación / MegaMenú */
export function NavBlock(props) {
  const { style = 'inline' } = props;
  if (style === 'megamenu') {
    return (
      <div className="mj-block-nav-megamenu w-full">
        <MegaMenu {...props} />
      </div>
    );
  }
  return (
    <nav className="mj-block-nav hidden lg:flex items-center w-full">
      <MegaMenu inline {...props} />
    </nav>
  );
}

/* 3. Buscador */
export function SearchBlock({
  maxWidth = '360px',
  height,
  placeholder,
  style = 'pill',
  bgColor,
  textColor,
  placeholderColor,
  borderColor,
  iconColor,
  fontSize
}) {
  const isFull = maxWidth === '100%' || maxWidth === 'full';
  const widthVal = maxWidth ? (String(maxWidth).match(/^\d+$/) ? `${maxWidth}px` : maxWidth) : '360px';

  return (
    <div
      className="mj-block-search hidden sm:flex items-center min-w-0"
      style={{
        width: isFull ? '100%' : widthVal,
        maxWidth: isFull ? '100%' : widthVal,
        flex: isFull ? '1 1 auto' : undefined,
        '--search-bg': bgColor || undefined,
        '--search-text': textColor || undefined,
        '--search-border': borderColor || undefined,
        '--search-placeholder': placeholderColor || undefined,
        '--search-icon': iconColor || undefined,
        '--search-height': height || undefined,
        '--search-radius': style === 'pill' ? '9999px' : style === 'rounded' ? '8px' : '0px',
        '--search-font-size': fontSize || undefined,
      }}
    >
      <SearchBar className="w-full max-w-none" />
    </div>
  );
}

/* 4. Carrito WooCommerce */
export function CartBlock({
  showCount = true,
  showLabel = false,
  label = 'Carrito',
  style = 'icon',
  iconSize,
  iconColor,
  hoverColor,
  bgColor,
  badgeBg,
  badgeColor
}) {
  const cartCount = useCart((s) => s.items.reduce((n, i) => n + i.qty, 0));
  const setCartOpen = useUI((s) => s.setCartOpen);
  return (
    <div
      className={`mj-block-cart flex items-center ${style === 'pill' ? 'rounded-full border border-line/60 px-2' : ''}`}
      style={{ backgroundColor: bgColor || undefined }}
    >
      <IconAction
        onClick={() => setCartOpen(true)}
        icon={ShoppingBag}
        label={label || t('nav_cart')}
        count={showCount ? cartCount : 0}
        showLabel={showLabel}
        customIconSize={iconSize}
        customColor={iconColor}
        customHoverColor={hoverColor}
        customBadgeBg={badgeBg}
        customBadgeColor={badgeColor}
      />
    </div>
  );
}

/* 5. Favoritos / Wishlist */
export function WishlistBlock({
  showCount = true,
  showLabel = false,
  label = 'Favoritos',
  iconSize,
  iconColor,
  hoverColor,
  badgeBg,
  badgeColor
}) {
  const wishCount = useWishlist((s) => s.ids.length);
  return (
    <div className="mj-block-wishlist hidden sm:flex items-center">
      <IconAction
        to="/favoritos"
        icon={Heart}
        label={label || t('nav_favorites')}
        count={showCount ? wishCount : 0}
        showLabel={showLabel}
        customIconSize={iconSize}
        customColor={iconColor}
        customHoverColor={hoverColor}
        customBadgeBg={badgeBg}
        customBadgeColor={badgeColor}
      />
    </div>
  );
}

/* 6. Comparador */
export function CompareBlock({
  showCount = true,
  showLabel = false,
  label = 'Comparar',
  iconSize,
  iconColor,
  hoverColor,
  badgeBg,
  badgeColor
}) {
  const compareCount = useCompare((s) => s.ids.length);
  return (
    <div className="mj-block-compare hidden md:flex items-center">
      <IconAction
        to="/comparar"
        icon={Scale}
        label={label || t('nav_compare')}
        count={showCount ? compareCount : 0}
        showLabel={showLabel}
        customIconSize={iconSize}
        customColor={iconColor}
        customHoverColor={hoverColor}
        customBadgeBg={badgeBg}
        customBadgeColor={badgeColor}
      />
    </div>
  );
}

/* 7. Cuenta / Login */
export function AccountBlock({
  showIcon = true,
  showLabel = false,
  label = '',
  iconSize,
  iconColor,
  textColor,
  hoverColor
}) {
  const user = useAuth((s) => s.user);
  const text = label || t('nav_account');
  return (
    <div className="mj-block-account hidden sm:flex items-center gap-1.5">
      <IconAction
        to={user ? '/cuenta' : '/login'}
        icon={showIcon ? User : null}
        label={text}
        showLabel={showLabel}
        customIconSize={iconSize}
        customColor={iconColor || textColor}
        customHoverColor={hoverColor}
      />
    </div>
  );
}

/* 8. Selector de tema (sol / luna) */
export function ThemeToggleBlock({
  showIcon = true,
  showLabel = false,
  label = 'Modo',
  iconSize,
  iconColor,
  hoverColor
}) {
  return (
    <div className="mj-block-theme flex items-center gap-1.5" style={{ color: iconColor || undefined }}>
      <ThemeToggle />
      {showLabel && <span className="text-sm font-medium" style={{ color: iconColor || undefined }}>{label || 'Modo'}</span>}
    </div>
  );
}

/* 9. Cotización MJ Dólar */
export function DolarBlock({
  compact = true,
  bgColor,
  textColor,
  borderColor,
  fontSize
}) {
  return (
    <div
      className="mj-block-dolar flex items-center"
      style={{
        backgroundColor: bgColor || undefined,
        color: textColor || undefined,
        borderColor: borderColor || undefined,
        fontSize: fontSize || undefined
      }}
    >
      <ExchangeRate className="mr-1.5" />
    </div>
  );
}

/* 10. Medios de pago */
export function MediosPagoBlock({
  variant = 'selector',
  bgColor,
  textColor,
  borderColor,
  fontSize
}) {
  const customStyle = {
    backgroundColor: bgColor || undefined,
    color: textColor || undefined,
    borderColor: borderColor || undefined,
    fontSize: fontSize || undefined
  };
  if (variant === 'badges') {
    return (
      <div className="mj-block-medios-pago-badges flex items-center gap-2 text-xs text-ink-soft" style={customStyle}>
        <CreditCard size={18} />
        <span>Tarjetas, transferencia y efectivo</span>
      </div>
    );
  }
  return (
    <div className="mj-block-medios-pago flex items-center" style={customStyle}>
      <PaymentSelector className="mr-1.5 hidden sm:inline-flex" />
    </div>
  );
}

/* 11. Barra superior de anuncios (TopBar) */
export function AnunciosBlock({
  autoplay = true,
  customMessages,
  bgColor,
  textColor,
  fontSize,
  textAlign
}) {
  const customStyle = {
    backgroundColor: bgColor || undefined,
    color: textColor || undefined,
    fontSize: fontSize || undefined,
    textAlign: textAlign || undefined
  };
  return (
    <div className="mj-block-anuncios w-full" style={customStyle}>
      <TopBar />
    </div>
  );
}

/* 12. Barra de envío gratis */
export function EnvioGratisBlock({
  showProgress = true,
  message,
  bgColor,
  textColor,
  barColor,
  fontSize
}) {
  const customStyle = {
    backgroundColor: bgColor || undefined,
    color: textColor || undefined,
    fontSize: fontSize || undefined
  };
  return (
    <div className="mj-block-envio-gratis w-full" style={customStyle}>
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
export function CtaButtonBlock({
  label = 'Ver más',
  url = '/tienda',
  variant = 'secondary',
  bgColor,
  textColor,
  hoverBg,
  hoverColor,
  fontSize,
  borderRadius,
  icon = 'arrow-up-right',
  target = '_self'
}) {
  const Icon = icon ? iconByName(icon, ArrowUpRight) : null;
  const isExt = isExternalHref(url) || target === '_blank';
  const isCustom = variant === 'custom' || !!bgColor || !!textColor;
  const [hovered, setHovered] = useState(false);

  const customStyle = {
    backgroundColor: isCustom ? (hovered && hoverBg ? hoverBg : (bgColor || undefined)) : undefined,
    color: isCustom ? (hovered && hoverColor ? hoverColor : (textColor || undefined)) : undefined,
    fontSize: fontSize || undefined,
    borderRadius: borderRadius || undefined,
  };

  const content = (
    <>
      {Icon && <Icon size={15} strokeWidth={1.8} className="mr-1" />}
      <span>{label}</span>
    </>
  );
  const btnProps = {
    size: 'sm',
    variant: isCustom ? undefined : variant,
    className: 'px-3',
    style: customStyle,
    title: label,
    'aria-label': label,
    onMouseEnter: () => setHovered(true),
    onMouseLeave: () => setHovered(false),
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
export function NewsletterBlock({ title, subtitle, buttonText, bgColor, textColor, buttonBg, buttonTextColor }) {
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

  const containerStyle = {
    backgroundColor: bgColor || undefined,
    color: textColor || undefined,
  };

  return (
    <div className="mj-block-newsletter hairline-b py-8 w-full" style={containerStyle}>
      <div className="mx-auto flex max-w-7xl flex-col items-start justify-between gap-6 px-4 md:flex-row md:items-center md:px-8">
        <div>
          <h3 className="font-display text-xl font-bold tracking-tight text-ink md:text-2xl" style={{ color: textColor || undefined }}>
            {title || t('footer_news_title') || 'Suscribite a novedades'}
          </h3>
          <p className="mt-1 text-sm text-ink-soft" style={{ color: textColor ? textColor : undefined, opacity: textColor ? 0.8 : undefined }}>
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
          <Button
            type="submit"
            variant="primary"
            size="md"
            disabled={status === 'sending'}
            style={{
              backgroundColor: buttonBg || undefined,
              color: buttonTextColor || undefined
            }}
          >
            {status === 'sending' ? <Loader2 className="animate-spin" size={16} /> : (buttonText || t('footer_news_button') || 'Suscribirme')}
          </Button>
        </form>
      </div>
    </div>
  );
}

/* 16. Redes sociales */
export function SocialsBlock({
  showInstagram = true,
  showFacebook = true,
  showWhatsapp = true,
  showTiktok = true,
  iconSize = '20px',
  iconColor,
  hoverColor,
  gap = '12px'
}) {
  const [hoveredKey, setHoveredKey] = useState(null);
  const numSize = parseInt(iconSize, 10) || 18;

  const socials = [
    { key: 'instagram', icon: Instagram, href: SOCIAL?.instagram, show: showInstagram },
    { key: 'facebook', icon: Facebook, href: SOCIAL?.facebook, show: showFacebook },
    { key: 'whatsapp', icon: Phone, href: CONTACT?.phone ? `https://wa.me/${CONTACT.phone.replace(/[^0-9]/g, '')}` : null, show: showWhatsapp },
    { key: 'tiktok', icon: Music2, href: SOCIAL?.tiktok, show: showTiktok },
    { key: 'twitter', icon: Twitter, href: SOCIAL?.twitter, show: true },
    { key: 'youtube', icon: Youtube, href: SOCIAL?.youtube, show: true },
  ].filter((s) => s.href && s.show !== false);

  if (!socials.length) return null;

  return (
    <div className="mj-block-socials flex items-center flex-wrap" style={{ gap }}>
      {socials.map(({ key, icon: Icon, href }) => {
        const isHovered = hoveredKey === key;
        const color = isHovered && hoverColor ? hoverColor : (iconColor || undefined);
        return (
          <a
            key={key}
            href={href}
            target="_blank"
            rel="noreferrer"
            onMouseEnter={() => setHoveredKey(key)}
            onMouseLeave={() => setHoveredKey(null)}
            className="grid size-9 place-items-center rounded-(--mj-radius-pill) bg-surface text-ink-soft transition-colors hover:bg-surface-2 hover:text-ink"
            style={{ color }}
          >
            <Icon size={numSize} style={{ color }} />
          </a>
        );
      })}
    </div>
  );
}

/* 17. Marca del pie de página */
export function FooterBrandBlock({ tagline, showSocials = true, textColor, taglineColor }) {
  return (
    <div className="mj-block-footer-brand flex flex-col gap-4">
      <Logo showSiteName />
      <p className="text-sm text-ink-soft leading-relaxed max-w-sm" style={{ color: taglineColor || textColor || undefined }}>
        {tagline || t('footer_tagline') || SITE?.tagline}
      </p>
      {showSocials && <SocialsBlock />}
    </div>
  );
}

/* 18. Columna de enlaces del footer */
export function FooterLinksBlock({
  title,
  links,
  titleColor,
  linksColor,
  hoverColor,
  fontSize
}) {
  const [hoveredIdx, setHoveredIdx] = useState(null);
  const parsedLinks = typeof links === 'string'
    ? links.split('\n').map((line) => {
        const parts = line.split('|').map((s) => s.trim());
        return { label: parts[0] || '', href: parts[1] || '#', target: parts[2] || '_self' };
      }).filter((l) => l.label)
    : (Array.isArray(links) ? links : []);

  return (
    <div className="mj-block-footer-links flex flex-col gap-3">
      {title && (
        <h4 className="font-display text-sm font-semibold text-ink" style={{ color: titleColor || undefined }}>
          {title}
        </h4>
      )}
      <ul className="flex flex-col gap-2">
        {parsedLinks.map((l, i) => {
          const isHovered = hoveredIdx === i;
          const color = isHovered && hoverColor ? hoverColor : (linksColor || undefined);
          const linkStyle = {
            color,
            fontSize: fontSize || undefined
          };
          return (
            <li key={i}>
              {isExternalHref(l.href) || l.target === '_blank' ? (
                <a
                  href={l.href}
                  target={l.target}
                  rel={l.target === '_blank' ? 'noreferrer' : undefined}
                  onMouseEnter={() => setHoveredIdx(i)}
                  onMouseLeave={() => setHoveredIdx(null)}
                  className="text-sm text-ink-soft transition-colors hover:text-ink"
                  style={linkStyle}
                >
                  {l.label}
                </a>
              ) : (
                <Link
                  to={l.href}
                  onMouseEnter={() => setHoveredIdx(i)}
                  onMouseLeave={() => setHoveredIdx(null)}
                  className="text-sm text-ink-soft transition-colors hover:text-ink"
                  style={linkStyle}
                >
                  {l.label}
                </Link>
              )}
            </li>
          );
        })}
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
export function FooterLegalBlock({ copyright, showPayments = true, textColor, fontSize }) {
  const copyText = copyright || `© ${new Date().getFullYear()} ${SITE?.name || 'MJ Appearance'}. ${t('footer_rights')}`;
  return (
    <div
      className="mj-block-footer-legal hairline-t mt-12 pt-8 flex flex-col items-center justify-between gap-4 md:flex-row text-xs text-ink-faint w-full"
      style={{
        color: textColor || undefined,
        fontSize: fontSize || undefined
      }}
    >
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
