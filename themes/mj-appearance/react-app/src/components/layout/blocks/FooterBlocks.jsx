import { useState } from 'react';
import { Link } from 'react-router-dom';
import { Mail, Check, Loader2, MapPin, Phone, Instagram, Facebook, Twitter, Youtube, Music2, CreditCard } from 'lucide-react';
import { Reveal } from '@/components/motion/Reveal';
import { Logo } from '@/components/ui/Logo';
import { useCatalog } from '@/store/catalog';
import { WP, IS_WP, CONTACT, SOCIAL, SITE } from '@/lib/config';
import { t } from '@/lib/copy';
import { COLEGAS, projectPath } from '@/lib/colegas';
import { TURNOS, bookingPath, servicesPath } from '@/lib/turnos';
import { sendContact } from '@/lib/wc';

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

export function FooterNewsletterBlock({ title, subtitle, buttonText }) {
  const [email, setEmail] = useState('');
  const [status, setStatus] = useState('idle');

  const subscribe = async (e) => {
    e.preventDefault();
    if (!email || status === 'sending') return;
    setStatus('sending');
    try {
      if (IS_WP) await sendContact({ kind: 'newsletter', email });
      setStatus('ok');
    } catch {
      setStatus('error');
    }
  };

  return (
    <div className="mj-block-footer-newsletter w-full">
      <Reveal className="flex flex-col items-start justify-between gap-8 md:flex-row md:items-center">
        <div className="max-w-lg">
          <h3 className="mj-footer__title display text-3xl md:text-4xl">
            {title || t('footer_newsletter_title')}
          </h3>
          {(subtitle || t('footer_newsletter_text')) && (
            <p className="mt-3 text-ink-soft">
              {subtitle || t('footer_newsletter_text')}
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
                placeholder={t('footer_newsletter_placeholder')}
                className="w-full bg-transparent text-sm placeholder:text-ink-faint focus:outline-hidden"
              />
            </div>
            <button
              type="submit"
              disabled={status === 'sending' || status === 'ok'}
              className="mj-footer__btn btn-accent flex h-12 items-center gap-2 rounded-(--mj-radius-pill) px-6 text-sm font-semibold transition-transform hover:scale-[1.02] active:scale-[0.98] disabled:opacity-60"
            >
              {status === 'sending' ? (
                <Loader2 size={16} className="animate-spin" />
              ) : status === 'ok' ? (
                <>
                  <Check size={16} />
                  <span>{t('footer_newsletter_subscribed')}</span>
                </>
              ) : (
                <span>{buttonText || t('footer_newsletter_submit')}</span>
              )}
            </button>
          </form>
          {status === 'error' && (
            <p className="mt-2 text-xs text-rose-500">{t('footer_newsletter_error')}</p>
          )}
        </div>
      </Reveal>
    </div>
  );
}

export function FooterBrandBlock({ showLogo = true, tagline, maxWidth }) {
  return (
    <div className="mj-block-footer-brand flex flex-col gap-4" style={maxWidth ? { maxWidth } : undefined}>
      {showLogo && <Logo size="sm" />}
      {(tagline || SITE?.tagline) && (
        <p className="text-sm text-ink-soft max-w-sm leading-relaxed">
          {tagline || SITE?.tagline}
        </p>
      )}
    </div>
  );
}

export function FooterContactBlock({ showAddress = true, showPhone = true, showEmail = true }) {
  const lines = [
    showAddress && CONTACT?.address ? { Icon: MapPin, value: CONTACT.address } : null,
    showPhone && CONTACT?.phone ? { Icon: Phone, value: CONTACT.phone } : null,
    showEmail && CONTACT?.email ? { Icon: Mail, value: CONTACT.email } : null,
  ].filter(Boolean);

  if (!lines.length) return null;

  return (
    <ul className="mj-block-footer-contact mt-3 space-y-2.5 text-sm text-ink-soft">
      {lines.map(({ Icon, value }, i) => (
        <li key={i} className="flex items-center gap-2.5">
          <Icon size={15} className="shrink-0 text-ink-faint" />
          <span>{value}</span>
        </li>
      ))}
    </ul>
  );
}

export function FooterSocialsBlock() {
  const socials = [
    { name: 'Instagram', url: SOCIAL?.instagram, Icon: Instagram },
    { name: 'Facebook', url: SOCIAL?.facebook, Icon: Facebook },
    { name: 'Twitter', url: SOCIAL?.twitter, Icon: Twitter },
    { name: 'YouTube', url: SOCIAL?.youtube, Icon: Youtube },
    { name: 'TikTok', url: SOCIAL?.tiktok, Icon: Music2 },
  ].filter((s) => s.url);

  if (!socials.length) return null;

  return (
    <div className="mj-block-footer-socials flex items-center gap-2 mt-4">
      {socials.map(({ name, url, Icon }) => (
        <a
          key={name}
          href={url}
          target="_blank"
          rel="noreferrer"
          aria-label={name}
          title={name}
          className="grid size-9 place-items-center rounded-(--mj-radius-pill) border border-line-subtle text-ink-soft transition-colors hover:border-line-strong hover:text-ink hover:bg-surface"
        >
          <Icon size={16} />
        </a>
      ))}
    </div>
  );
}

export function FooterColumnsBlock({ columnsCount = 4, customColumns }) {
  const categories = useCatalog((s) => s.categories);
  const aboutLabel = t('nav_about').trim();

  let cols = null;
  if (customColumns) {
    const raw = typeof customColumns === 'string' ? customColumns : '';
    const lines = raw.split(/\r?\n/).map((l) => l.trim()).filter(Boolean);
    const parsed = [];
    let cur = null;
    for (const line of lines) {
      if (line.startsWith('#')) {
        if (cur) parsed.push(cur);
        cur = { title: line.replace(/^#\s*/, ''), links: [] };
      } else if (cur) {
        const link = normalizeLink(line);
        if (link) cur.links.push(link);
      }
    }
    if (cur) parsed.push(cur);
    if (parsed.length) cols = parsed;
  }

  if (!cols) {
    cols = [
      {
        title: t('footer_group_shop'),
        links: [
          ...categories.slice(0, 5).map((c) => ({ label: c.name, href: `/categoria/${c.slug}` })),
          { label: t('shop_sale_title'), href: '/tienda?filtro=ofertas' },
          { label: t('shop_new_title'), href: '/tienda?filtro=novedades' },
          ...(TURNOS?.menu.servicesPageEnabled ? [{ label: TURNOS.menu.servicesLabel, href: servicesPath() }] : []),
          ...(TURNOS ? [{ label: TURNOS.menu.label, href: bookingPath() }] : []),
          ...(COLEGAS ? [{ label: COLEGAS.project.menuLabel, href: projectPath() }] : []),
        ],
      },
      {
        title: t('footer_group_help'),
        links: [
          ...(aboutLabel ? [{ label: aboutLabel, href: '/nosotros' }] : []),
          { label: t('footer_link_contact'), href: '/contacto' },
          ...(WP?.faqs?.length ? [{ label: t('footer_link_faq'), href: '/faq' }] : []),
          { label: t('footer_link_terms'), href: '/terminos' },
          { label: t('footer_link_privacy'), href: '/privacidad' },
        ],
      },
      {
        title: t('footer_group_account'),
        links: [
          { label: t('footer_link_account'), href: '/cuenta' },
          { label: t('footer_link_orders'), href: '/cuenta/pedidos' },
          { label: t('footer_link_wishlist'), href: '/favoritos' },
        ],
      },
    ];
  }

  const gridColsClass = {
    2: 'grid-cols-1 sm:grid-cols-2',
    3: 'grid-cols-1 sm:grid-cols-2 md:grid-cols-3',
    4: 'grid-cols-2 sm:grid-cols-3 md:grid-cols-4',
    5: 'grid-cols-2 sm:grid-cols-3 md:grid-cols-5',
  }[columnsCount] || 'grid-cols-2 sm:grid-cols-3 md:grid-cols-4';

  return (
    <div className={`mj-block-footer-columns grid gap-8 w-full ${gridColsClass}`}>
      {cols.map((col, idx) => (
        <div key={idx} className="flex flex-col gap-3">
          <h4 className="text-xs font-semibold uppercase tracking-wider text-ink">
            {col.title}
          </h4>
          <ul className="space-y-2 text-sm text-ink-soft">
            {col.links.map((l, i) => (
              <li key={i}>
                <Link
                  to={l.href}
                  target={l.target || '_self'}
                  rel={l.target === '_blank' ? 'noreferrer' : undefined}
                  className="transition-colors hover:text-ink"
                >
                  {l.label}
                </Link>
              </li>
            ))}
          </ul>
        </div>
      ))}
    </div>
  );
}

export function FooterLegalBlock({ copyright, showLinks = true }) {
  const currentYear = new Date().getFullYear();
  const siteName = SITE?.name || 'MJ Appearance';
  const copyText = copyright || `© ${currentYear} ${siteName}. ${t('footer_rights')}`;

  return (
    <div className="mj-block-footer-legal flex flex-wrap items-center justify-between gap-4 text-xs text-ink-faint w-full">
      <p>{copyText}</p>
      {showLinks && (
        <div className="flex items-center gap-4">
          <Link to="/terminos" className="hover:text-ink-soft transition-colors">{t('footer_link_terms')}</Link>
          <span>•</span>
          <Link to="/privacidad" className="hover:text-ink-soft transition-colors">{t('footer_link_privacy')}</Link>
        </div>
      )}
    </div>
  );
}

export function FooterPaymentsBlock({ alignment = 'right' }) {
  const paymentMethods = Array.isArray(WP?.paymentMethods) ? WP.paymentMethods : [];
  if (!paymentMethods.length) return null;

  return (
    <div className={`mj-block-footer-payments flex flex-wrap items-center gap-2 ${alignment === 'right' ? 'justify-end' : 'justify-start'}`}>
      {paymentMethods.map((pm) => (
        <span
          key={pm.id}
          className="inline-flex items-center gap-1.5 rounded-sm border border-line-subtle px-2 py-1 text-[11px] text-ink-soft bg-surface"
          title={pm.title}
        >
          {pm.icon ? (
            <img src={pm.icon} alt={pm.title} className="h-3.5 w-auto object-contain" />
          ) : (
            <CreditCard size={12} className="text-ink-faint" />
          )}
          <span>{pm.title}</span>
        </span>
      ))}
    </div>
  );
}
