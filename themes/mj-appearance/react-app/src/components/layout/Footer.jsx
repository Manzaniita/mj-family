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
import { WP, IS_WP, CONTACT, SOCIAL, SITE } from '@/lib/config';
import { t } from '@/lib/copy';
import { COLEGAS, projectPath } from '@/lib/colegas';
import { TURNOS, bookingPath, servicesPath } from '@/lib/turnos';
import { sendContact } from '@/lib/wc';

/**
 * Footer de tres niveles: newsletter → enlaces + contacto → legal + pagos.
 * Títulos de grupo y etiquetas de enlaces: Apariencia → MJ Appearance →
 * Textos → Pie de página / Menú. Servicios y Turnos los aporta el plugin
 * MJ Turnos Online; el proyecto de colegas, MJ Colegas.
 */
export function Footer() {
  const [email, setEmail] = useState('');
  const [status, setStatus] = useState('idle'); // idle | sending | ok | error
  const categories = useCatalog((s) => s.categories);

  // "Nosotros" en Ayuda solo si el admin (o la skin) le puso texto en el menú.
  const aboutLabel = t('nav_about').trim();

  const linkGroups = [
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

  return (
    <footer className="mj-footer mt-28 hairline-t bg-bg-soft/50">
      {/* Nivel 1 — Newsletter */}
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
                  placeholder="tu@email.com"
                  className="w-full bg-transparent text-sm outline-none placeholder:text-ink-faint"
                  aria-label="Email para newsletter"
                />
              </div>
              <motion.button
                whileHover={STILL ? undefined : { scale: 1.04 }}
                whileTap={STILL ? undefined : { scale: 0.95 }}
                disabled={status === 'sending'}
                className={`mj-footer__submit grid size-12 shrink-0 place-items-center rounded-(--mj-radius-pill) text-white transition-colors ${status === 'ok' ? 'bg-success' : 'bg-accent shadow-glow'}`}
                aria-label="Suscribirme"
              >
                {status === 'ok'
                  ? <Check size={18} />
                  : status === 'sending'
                    ? <Loader2 size={18} className="animate-spin" />
                    : <ArrowRight size={18} />}
              </motion.button>
            </form>
            {status === 'ok' && (
              <p className="mt-2 text-xs text-success">¡Listo! Te avisamos de las novedades.</p>
            )}
            {status === 'error' && (
              <p className="mt-2 text-xs text-danger">No pudimos registrar tu email. Intentá de nuevo.</p>
            )}
          </div>
        </Reveal>
      </div>

      {/* Nivel 2 — Enlaces + contacto */}
      <div className="hairline-t">
        <div className="mx-auto grid max-w-7xl gap-10 px-4 py-14 md:grid-cols-5 md:px-8">
          <div className="md:col-span-2">
            <Logo />
            {(t('footer_tagline') || SITE?.tagline) && (
              <p className="mt-4 max-w-xs text-sm leading-relaxed text-ink-soft">
                {t('footer_tagline') || SITE?.tagline}
              </p>
            )}
            {contactLines.length > 0 && (
              <div className="mt-6 space-y-2 text-sm text-ink-soft">
                {contactLines.map(({ Icon, value }) => (
                  <p key={value} className="flex items-center gap-2.5">
                    <Icon size={14} className="shrink-0 text-accent" /> {value}
                  </p>
                ))}
              </div>
            )}
            {socials.length > 0 && (
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

          {linkGroups.map((g, i) => (
            <div key={`${i}-${g.title}`}>
              <p className="mj-footer__heading mb-4 text-xs font-semibold uppercase tracking-[0.18em] text-ink-faint">{g.title}</p>
              <ul className="space-y-2.5">
                {g.links.map(([label, href]) => (
                  <li key={href + label}>
                    <Link to={href} className="mj-footer__link text-sm text-ink-soft transition-colors hover:text-ink">
                      {label}
                    </Link>
                  </li>
                ))}
              </ul>
            </div>
          ))}
        </div>
      </div>

      {/* Nivel 3 — Legal + medios de pago */}
      <div className="hairline-t">
        <div className="mj-footer__legal mx-auto flex max-w-7xl flex-col items-center justify-between gap-4 px-4 py-6 text-xs text-ink-faint md:flex-row md:px-8">
          <p>© {new Date().getFullYear()} {SITE?.name || 'MJ Appearance'}. {t('footer_rights')}</p>
          {paymentMethods.length > 0 && (
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
    </footer>
  );
}
