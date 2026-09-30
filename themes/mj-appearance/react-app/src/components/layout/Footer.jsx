import { Link } from 'react-router-dom';
import { useState } from 'react';
import { motion } from 'framer-motion';
import { STILL } from '@/lib/motion';
import {
  ArrowRight, Instagram, Facebook, Twitter, Youtube, Music2,
  Mail, MapPin, Phone, Check, CreditCard, Loader2,
} from 'lucide-react';
import { Reveal } from '@/components/motion/Reveal';
import { Logo } from '@/components/ui/Logo';
import { useCatalog } from '@/store/catalog';
import { WP, IS_WP, CONTACT, SOCIAL, SITE, FOOTER } from '@/lib/config';
import { t } from '@/lib/copy';
import { COLEGAS, projectPath } from '@/lib/colegas';
import { TURNOS, bookingPath, servicesPath } from '@/lib/turnos';
import { sendContact } from '@/lib/wc';
import { isExternalHref } from '@/lib/nav';

function normalizeLink(l) {
  if (Array.isArray(l)) {
    return { label: l[0] || '', href: l[1] || '#', target: l[2] || '_self' };
  }
  if (l && typeof l === 'object') {
    return { label: l.label || '', href: l.href || '#', target: l.target || '_self' };
  }
  if (typeof l === 'string' && l.includes('|')) {
    const parts = l.split('|').map((p) => p.trim());
    return { label: parts[0] || '', href: parts[1] || '#', target: parts[2] || '_self' };
  }
  return null;
}

/**
 * Footer configurable de tres niveles: newsletter → enlaces + contacto → legal + pagos.
 */
export function Footer(props = {}) {
  const override = props.override || (props.showNewsletter !== undefined || props.columnsCount !== undefined || props.columns !== undefined ? props : null);

  const conf = {
    showNewsletter: override?.showNewsletter !== undefined ? !!override.showNewsletter : FOOTER.showNewsletter,
    columnsCount: Number(override?.columnsCount || FOOTER.columnsCount) || 4,
    showLogo: override?.showLogo !== undefined ? !!override.showLogo : FOOTER.showLogo,
    tagline: override?.tagline !== undefined ? override.tagline : (FOOTER.tagline || t('footer_tagline') || SITE?.tagline),
    showContact: override?.showContact !== undefined ? !!override.showContact : FOOTER.showContact,
    showSocials: override?.showSocials !== undefined ? !!override.showSocials : FOOTER.showSocials,
    columns: (Array.isArray(override?.columns) && override.columns.length > 0)
      ? override.columns
      : (Array.isArray(FOOTER.columns) && FOOTER.columns.length > 0 ? FOOTER.columns : null),
    showLegalBar: override?.showLegalBar !== undefined ? !!override.showLegalBar : FOOTER.showLegalBar,
    copyright: override?.copyright !== undefined && override.copyright.trim() !== ''
      ? override.copyright
      : (FOOTER.copyright || `© ${new Date().getFullYear()} ${SITE?.name || 'MJ Appearance'}. ${t('footer_rights')}`),
    showPayments: override?.showPayments !== undefined ? !!override.showPayments : FOOTER.showPayments,
    border: override?.border || FOOTER.border || 'subtle',
    bgColor: override?.bgColor || FOOTER.bgColor || '',
    inkColor: override?.inkColor || FOOTER.inkColor || '',
    accentColor: override?.accentColor || FOOTER.accentColor || '',
  };

  const [email, setEmail] = useState('');
  const [status, setStatus] = useState('idle'); // idle | sending | ok | error
  const categories = useCatalog((s) => s.categories);

  // "Nosotros" en Ayuda solo si el admin (o la skin) le puso texto en el menú.
  const aboutLabel = t('nav_about').trim();

  const defaultLinkGroups = [
    {
      title: t('footer_group_shop'),
      links: [
        ...categories.slice(0, 5).map((c) => [c.name, `/categoria/${c.slug}`]),
        [t('shop_sale_title'), '/tienda?filtro=ofertas'],
        [t('shop_new_title'), '/tienda?filtro=novedades'],
        // Servicios y turnos (plugin MJ Turnos Online).
        ...(TURNOS?.menu.servicesPageEnabled ? [[TURNOS.menu.servicesLabel, servicesPath()]] : []),
        ...(TURNOS ? [[TURNOS.menu.label, bookingPath()]] : []),
        // Proyecto de colegas (plugin MJ Colegas).
        ...(COLEGAS ? [[COLEGAS.project.menuLabel, projectPath()]] : []),
      ],
    },
    {
      title: t('footer_group_help'),
      links: [
        ...(aboutLabel ? [[aboutLabel, '/nosotros']] : []),
        [t('footer_link_contact'), '/contacto'],
        ...(WP?.faqs?.length ? [[t('footer_link_faq'), '/faq']] : []),
        [t('footer_link_terms'), '/terminos'],
        [t('footer_link_privacy'), '/privacidad'],
      ],
    },
    {
      title: t('footer_group_account'),
      links: [
        [t('footer_link_account'), '/cuenta'],
        [t('footer_link_orders'), '/cuenta/pedidos'],
        [t('footer_link_wishlist'), '/favoritos'],
      ],
    },
  ];

  const paymentMethods = Array.isArray(WP?.paymentMethods) ? WP.paymentMethods : [];

  const contactLines = [
    { Icon: MapPin, value: CONTACT?.address },
    { Icon: Phone, value: CONTACT?.phone },
    { Icon: Mail, value: CONTACT?.email },
  ].filter((l) => l.value);

  const socials = [
    { name: 'Instagram', url: SOCIAL?.instagram, Icon: Instagram },
    { name: 'Facebook', url: SOCIAL?.facebook, Icon: Facebook },
    { name: 'Twitter', url: SOCIAL?.twitter, Icon: Twitter },
    { name: 'YouTube', url: SOCIAL?.youtube, Icon: Youtube },
    { name: 'TikTok', url: SOCIAL?.tiktok, Icon: Music2 },
  ].filter((s) => s.url);

  const subscribe = async (e) => {
    e.preventDefault();
    if (!email || status === 'sending') return;
    setStatus('sending');
    try {
      // Standalone no tiene servidor: se simula la suscripción solo en dev.
      if (IS_WP) await sendContact({ kind: 'newsletter', email });
      setStatus('ok');
    } catch {
      setStatus('error');
    }
  };

  const borderClass = {
    subtle: 'hairline-t',
    strong: 'border-t border-line-strong',
    none: 'border-t-0',
  }[conf.border] || 'hairline-t';

  const inlineStyles = {};
  if (conf.bgColor) inlineStyles.backgroundColor = conf.bgColor;
  if (conf.inkColor) inlineStyles.color = conf.inkColor;
  if (conf.accentColor) inlineStyles['--mj-accent'] = conf.accentColor;

  const colsCount = Math.max(2, Math.min(5, conf.columnsCount));
  const hasBrandCol = conf.showLogo || conf.tagline || (conf.showContact && contactLines.length > 0) || (conf.showSocials && socials.length > 0);

  const renderColumns = conf.columns ? conf.columns.map((col) => {
    let rawLinks = col.links;
    if (typeof rawLinks === 'string') {
      rawLinks = rawLinks.split('\n').map((l) => l.trim()).filter(Boolean);
    }
    const links = (Array.isArray(rawLinks) ? rawLinks : []).map(normalizeLink).filter(Boolean);
    return { title: col.title || '', links };
  }) : defaultLinkGroups.map((g) => ({
    title: g.title,
    links: g.links.map(normalizeLink).filter(Boolean),
  }));

  return (
    <footer className={`mj-footer mt-28 ${borderClass} bg-bg-soft/50`} style={inlineStyles}>
      {/* Nivel 1 — Newsletter */}
      {conf.showNewsletter && (
        <div className="mj-footer__newsletter mx-auto max-w-7xl px-4 py-16 md:px-8 md:py-20">
          <Reveal className="flex flex-col items-start justify-between gap-8 md:flex-row md:items-center">
            <div className="max-w-lg">
              <h3 className="mj-footer__title display text-3xl md:text-4xl">
                {t('footer_newsletter_title')}
              </h3>
              {t('footer_newsletter_text') && (
                <p className="mt-3 text-ink-soft">
                  {t('footer_newsletter_text')}
                </p>
              )}
            </div>
            <div className="w-full max-w-md">
              <form className="flex w-full gap-2" onSubmit={subscribe}>
                <div className="mj-footer__field flex h-12 flex-1 items-center gap-2 rounded-(--mj-radius-pill) glass px-4 focus-within:border-accent/50">
                  <Mail size={16} className="text-ink-faint" />
                  <input
                    type="email"
                    required
                    value={email}
                    onChange={(e) => { setEmail(e.target.value); if (status === 'error') setStatus('idle'); }}
                    placeholder={t('field_email_placeholder')}
                    className="w-full bg-transparent text-sm outline-none placeholder:text-ink-faint"
                    aria-label={t('footer_newsletter_email')}
                  />
                </div>
                <motion.button
                  whileHover={STILL ? undefined : { scale: 1.04 }}
                  whileTap={STILL ? undefined : { scale: 0.95 }}
                  disabled={status === 'sending'}
                  className={`mj-footer__submit grid size-12 shrink-0 place-items-center rounded-(--mj-radius-pill) text-white transition-colors ${status === 'ok' ? 'bg-success' : 'bg-accent shadow-glow'}`}
                  aria-label={t('footer_newsletter_submit')}
                >
                  {status === 'ok'
                    ? <Check size={18} />
                    : status === 'sending'
                      ? <Loader2 size={18} className="animate-spin" />
                      : <ArrowRight size={18} />}
                </motion.button>
              </form>
              {status === 'ok' && (
                <p className="mt-2 text-xs text-success">{t('footer_newsletter_ok')}</p>
              )}
              {status === 'error' && (
                <p className="mt-2 text-xs text-danger">{t('footer_newsletter_error')}</p>
              )}
            </div>
          </Reveal>
        </div>
      )}

      {/* Nivel 2 — Enlaces + contacto */}
      <div className={conf.showNewsletter ? 'hairline-t' : ''}>
        <div
          className="mx-auto grid max-w-7xl grid-cols-1 gap-10 px-4 py-14 sm:grid-cols-2 md:px-8"
          style={{ '--footer-cols': colsCount, gridTemplateColumns: `repeat(${colsCount}, minmax(0, 1fr))` }}
        >
          {hasBrandCol && (
            <div className={colsCount >= 4 ? 'md:col-span-2' : 'md:col-span-1'}>
              {conf.showLogo && <Logo />}
              {conf.tagline && (
                <p className="mt-4 max-w-xs text-sm leading-relaxed text-ink-soft">
                  {conf.tagline}
                </p>
              )}
              {conf.showContact && contactLines.length > 0 && (
                <div className="mt-6 space-y-2 text-sm text-ink-soft">
                  {contactLines.map(({ Icon, value }) => (
                    <p key={value} className="flex items-center gap-2.5">
                      <Icon size={14} className="shrink-0 text-accent" /> {value}
                    </p>
                  ))}
                </div>
              )}
              {conf.showSocials && socials.length > 0 && (
                <div className="mt-6 flex gap-2">
                  {socials.map(({ name, url, Icon }) => (
                    <motion.a
                      key={name}
                      href={url}
                      target="_blank"
                      rel="noreferrer"
                      whileHover={STILL ? undefined : { scale: 1.1, y: -2 }}
                      className="grid size-10 place-items-center rounded-full glass text-ink-soft transition-colors hover:text-ink"
                      aria-label={name}
                    >
                      <Icon size={16} />
                    </motion.a>
                  ))}
                </div>
              )}
            </div>
          )}

          {renderColumns.map((g, i) => (
            <div key={`${i}-${g.title}`}>
              {g.title && (
                <p className="mj-footer__heading mb-4 text-xs font-semibold uppercase tracking-[0.18em] text-ink-faint">
                  {g.title}
                </p>
              )}
              <ul className="space-y-2.5">
                {g.links.map((link, j) => {
                  const isExt = isExternalHref(link.href) || link.target === '_blank';
                  return (
                    <li key={`${j}-${link.href}`}>
                      {isExt ? (
                        <a
                          href={link.href}
                          target={link.target === '_blank' ? '_blank' : undefined}
                          rel={link.target === '_blank' ? 'noreferrer' : undefined}
                          className="mj-footer__link text-sm text-ink-soft transition-colors hover:text-ink"
                        >
                          {link.label}
                        </a>
                      ) : (
                        <Link
                          to={link.href}
                          className="mj-footer__link text-sm text-ink-soft transition-colors hover:text-ink"
                        >
                          {link.label}
                        </Link>
                      )}
                    </li>
                  );
                })}
              </ul>
            </div>
          ))}
        </div>
      </div>

      {/* Nivel 3 — Legal + medios de pago */}
      {conf.showLegalBar && (
        <div className="hairline-t">
          <div className="mj-footer__legal mx-auto flex max-w-7xl flex-col items-center justify-between gap-4 px-4 py-6 text-xs text-ink-faint md:flex-row md:px-8">
            <p>{conf.copyright}</p>
            {conf.showPayments && paymentMethods.length > 0 && (
              <div className="flex flex-wrap items-center gap-2">
                {paymentMethods.map((m) => (
                  <span key={m.id ?? m.title ?? m} className="flex items-center gap-1.5 rounded-lg border border-line px-2.5 py-1.5">
                    <CreditCard size={12} /> {m.title ?? m}
                  </span>
                ))}
              </div>
            )}
          </div>
        </div>
      )}
    </footer>
  );
}
