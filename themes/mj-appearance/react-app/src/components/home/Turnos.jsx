import { motion } from 'framer-motion';
import { ArrowRight, CalendarDays } from 'lucide-react';
import { Reveal, Stagger, staggerItem } from '@/components/motion/Reveal';
import { Section } from '@/components/layout/Layout';
import { SectionHeader } from '@/components/ui/SectionHeader';
import { Button } from '@/components/ui/Button';
import { ServiceCard } from '@/components/turnos/ServiceCard';
import { TURNOS, bookingPath, servicesPath } from '@/lib/turnos';
import { cn } from '@/lib/format';

/**
 * Secciones de la portada que aporta el plugin MJ Turnos Online
 * (window.MJ.turnos → home.services / home.booking). Textos, cantidad de
 * servicios, foto del banner y visibilidad se configuran en Turnos → Ajustes;
 * el orden y el ocultado general, en Apariencia → MJ Appearance → Secciones.
 * Sin plugin (TURNOS = null) no renderizan nada.
 */

/* Columnas de la grilla según cuántos servicios se muestran (1 a 4). */
const GRID_COLS = ['sm:grid-cols-1', 'sm:grid-cols-2', 'sm:grid-cols-2 lg:grid-cols-3', 'sm:grid-cols-2 lg:grid-cols-4'];

/**
 * Servicios destacados (hasta home.services.count) con enlace a la página de servicios.
 * `override`: lo escrito en el bloque "Servicios" (textos y cantidad); vacío = Turnos → Ajustes.
 * Desde un bloque la sección se muestra aunque esté apagada en el plugin: el admin la puso a mano.
 */
export function ServicesShowcase({ override } = {}) {
  const o = override || {};
  if (!TURNOS || (!override && !TURNOS.home.services.enabled)) return null;
  const home = TURNOS.home.services;
  const count = Number(o.limit) > 0 ? Number(o.limit) : home.count;
  const services = TURNOS.services.slice(0, count);
  if (services.length === 0) return null;

  const cols = GRID_COLS[Math.min(services.length, 4) - 1];

  return (
    <Section className="mj-services-home">
      <SectionHeader
        eyebrow={o.eyebrow || home.eyebrow}
        title={o.title || home.title}
        subtitle={o.subtitle || home.subtitle}
        // El enlace "Ver todos" solo si la página de servicios está habilitada.
        linkTo={TURNOS.menu.servicesPageEnabled ? servicesPath() : undefined}
        linkLabel={o.linkLabel || home.link || TURNOS.menu.servicesLabel}
      />
      <Stagger className={cn('mj-services-home__grid grid gap-5', cols)}>
        {services.map((service) => (
          <motion.div key={service.id} variants={staggerItem}>
            <ServiceCard service={service} compact />
          </motion.div>
        ))}
      </Stagger>
    </Section>
  );
}

/**
 * Banner "Reservá tu turno": imagen opcional, eyebrow, título, subtítulo y botón a la reserva.
 * `override`: lo escrito en el bloque "Reservá tu turno"; vacío = Turnos → Ajustes.
 */
export function BookingCTA({ override } = {}) {
  const o = override || {};
  if (!TURNOS || (!override && !TURNOS.home.booking.enabled)) return null;
  const base = TURNOS.home.booking;
  const booking = {
    eyebrow: o.eyebrow || base.eyebrow,
    title: o.title || base.title,
    subtitle: o.subtitle || base.subtitle,
    button: o.button || base.button,
    image: o.image || base.image,
  };
  const hasImage = !!booking.image;

  return (
    <Section className="mj-booking-cta-section">
      <Reveal
        className={cn(
          'mj-booking-cta relative overflow-hidden rounded-(--mj-radius-2xl) border border-line glass',
          hasImage && 'mj-booking-cta--media'
        )}
      >
        {/* Halo de acento detrás del texto (las skins lo pueden pisar). */}
        <div
          className="mj-booking-cta__glow pointer-events-none absolute -left-24 -top-24 h-80 w-80 rounded-full opacity-25 blur-[100px]"
          style={{ background: 'var(--mj-hero-gradient)' }}
          aria-hidden="true"
        />
        <div className={cn('relative grid gap-8', hasImage ? 'lg:grid-cols-[minmax(0,7fr)_minmax(0,5fr)]' : '')}>
          <div className={cn('mj-booking-cta__body flex flex-col justify-center p-8 md:p-12 lg:p-14', !hasImage && 'items-start')}>
            {booking.eyebrow && (
              <p className="mj-eyebrow text-xs font-semibold uppercase tracking-[0.2em] text-accent">{booking.eyebrow}</p>
            )}
            <h2 className="mj-booking-cta__title display mt-3 text-3xl md:text-5xl">{booking.title}</h2>
            {booking.subtitle && (
              <p className="mj-booking-cta__subtitle mt-4 max-w-xl text-base text-ink-soft md:text-lg">{booking.subtitle}</p>
            )}
            <div className="mt-8">
              <Button size="lg" to={bookingPath()} className="mj-booking-cta__button">
                <CalendarDays size={17} />
                {booking.button}
                <ArrowRight size={17} />
              </Button>
            </div>
          </div>
          {hasImage && (
            <div className="mj-booking-cta__media relative min-h-64 lg:min-h-full">
              <img
                src={booking.image}
                alt=""
                loading="lazy"
                className="absolute inset-0 h-full w-full object-cover"
              />
              {/* Fundido hacia el texto para que el banner se lea como una sola pieza. */}
              <div className="mj-booking-cta__veil absolute inset-0 bg-gradient-to-t from-bg/60 via-transparent to-transparent lg:bg-gradient-to-r" aria-hidden="true" />
            </div>
          )}
        </div>
      </Reveal>
    </Section>
  );
}
