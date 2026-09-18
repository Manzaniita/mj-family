import { motion } from 'framer-motion';
import { CalendarDays, Sparkles } from 'lucide-react';
import { Section } from '@/components/layout/Layout';
import { Button } from '@/components/ui/Button';
import { Reveal, Stagger, staggerItem } from '@/components/motion/Reveal';
import { ServiceCard, ServiceGroupHeading, groupHasHeading } from '@/components/turnos/ServiceCard';
import { NotFound } from '@/pages/Misc';
import { TURNOS, tcopy, bookingPath, groupServicesByCategory } from '@/lib/turnos';
import { cn } from '@/lib/format';
import { usePageMeta } from '@/lib/seo';

/** Cabecera de página (misma forma que la de Misc.jsx) — la usan Servicios y Turnos. */
export function PageHeader({ eyebrow, title, subtitle, className }) {
  return (
    <Reveal className={cn('mj-page-head max-w-2xl', className)}>
      {eyebrow && <p className="mj-eyebrow text-xs font-semibold uppercase tracking-[0.2em] text-accent">{eyebrow}</p>}
      <h1 className="display mt-3 text-4xl md:text-6xl">{title}</h1>
      {subtitle && <p className="mt-4 text-lg text-ink-soft">{subtitle}</p>}
    </Reveal>
  );
}

/**
 * Página de servicios (/{servicesSlug}) del plugin MJ Turnos Online: grilla de
 * tarjetas con foto/color, nombre, descripción, duración, precio, seña y botón
 * de reserva; estado vacío y un cierre con el llamado a reservar. Con
 * categorías (y "agrupar por categoría" prendido) va un bloque por categoría,
 * en el orden del plugin, y los servicios sin categoría al final.
 * Textos: Turnos → Ajustes → Textos (services_*, booking_*, header_cta).
 */
export function ServicesPage() {
  usePageMeta(TURNOS ? TURNOS.menu.servicesLabel : '');
  if (!TURNOS) return <NotFound />;

  const services = TURNOS.services
    .filter((s) => s.active !== false)
    .sort((a, b) => a.order - b.order || a.name.localeCompare(b.name));
  const groups = groupServicesByCategory(services);

  const grid = (list, spacing = 'mt-12') => (
    <Stagger className={cn('mj-services__grid grid gap-5 sm:grid-cols-2 lg:grid-cols-3', spacing)}>
      {list.map((service) => (
        <motion.div key={service.id} variants={staggerItem}>
          <ServiceCard service={service} />
        </motion.div>
      ))}
    </Stagger>
  );

  return (
    <Section className="mj-services md:py-16">
      <PageHeader eyebrow={tcopy('services_eyebrow')} title={tcopy('services_title')} subtitle={tcopy('services_subtitle')} />

      {services.length === 0 ? (
        <Reveal className="mj-services__empty mj-empty mt-12 rounded-(--mj-radius-2xl) border border-line glass p-16 text-center">
          <Sparkles size={28} className="mx-auto text-ink-faint" aria-hidden="true" />
          <p className="mt-5 text-lg text-ink-soft">{tcopy('services_empty')}</p>
        </Reveal>
      ) : (
        <>
          {groups
            ? groups.map(({ category, services: list }) => (
                <section key={category ? category.id : 'sin-categoria'} className="mj-services__group mt-12 md:mt-16">
                  {groupHasHeading(category) && (
                    <Reveal>
                      <ServiceGroupHeading category={category} />
                    </Reveal>
                  )}
                  {grid(list, groupHasHeading(category) ? 'mt-6' : 'mt-0')}
                </section>
              ))
            : grid(services)}

          <Reveal className="mj-services__cta mt-16 flex flex-col items-center gap-5 rounded-(--mj-radius-2xl) border border-line glass p-10 text-center md:flex-row md:justify-between md:text-left">
            <div className="max-w-xl">
              <p className="mj-eyebrow text-xs font-semibold uppercase tracking-[0.2em] text-accent">{tcopy('booking_eyebrow')}</p>
              <p className="display mt-2 text-2xl md:text-3xl">{tcopy('booking_title')}</p>
              {tcopy('booking_subtitle') && <p className="mt-2 text-ink-soft">{tcopy('booking_subtitle')}</p>}
            </div>
            <Button size="lg" to={bookingPath()} className="shrink-0">
              <CalendarDays size={17} />
              {TURNOS.menu.headerCta}
            </Button>
          </Reveal>
        </>
      )}
    </Section>
  );
}
