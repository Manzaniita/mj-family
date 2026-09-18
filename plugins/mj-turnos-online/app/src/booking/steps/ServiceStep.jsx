/**
 * Paso 1 — Servicio: tarjetas con foto (o color), nombre, duración, precio
 * (si priceVisible) y seña (si aplica). Tocar una tarjeta la selecciona;
 * "Continuar" pasa al día y hora.
 *
 * Opcional (solo si viene en la config):
 *  - cfg.groupByCategory: servicios agrupados por categoría, en el orden de
 *    cfg.categories; los que no tienen categoría, al final.
 *  - service.maintenance: "Mantenimiento recomendado: cada 4 semanas".
 *  - service.sections: textos del servicio (cuidados, qué incluye…) plegados
 *    debajo de la tarjeta.
 */
import { useMemo } from 'react';
import { ArrowRight, Check, ChevronDown, Clock, RefreshCcw, Scissors } from 'lucide-react';
import { cn, initials } from '@/lib/format';
import { Button, EmptyState } from '@/ui';
import { StepFooter, useBooking } from '../shared';

/** Contenido de la tarjeta (foto + textos): igual con o sin secciones. */
function ServiceCardBody({ service, selected }) {
  const { t, durationOf, priceOf, depositOf, showPrice } = useBooking();
  const deposit = depositOf(service);
  const text = service.excerpt || service.description;
  return (
    <>
      <div
        className="mjto-booking__service-media relative aspect-[4/3] w-full overflow-hidden bg-accent-soft"
        style={!service.image && service.color ? { background: service.color } : undefined}
      >
        {service.image ? (
          <img src={service.image} alt="" loading="lazy" className="size-full object-cover transition-transform duration-500 group-hover:scale-[1.03]" />
        ) : (
          <span
            className={cn('grid size-full place-items-center font-display text-4xl font-bold tracking-tight', service.color ? 'text-white/90' : 'text-accent/60')}
            aria-hidden="true"
          >
            {initials(service.name)}
          </span>
        )}
        <span
          className={cn(
            'absolute right-3 top-3 grid size-8 place-items-center rounded-full border-2 transition-all duration-200',
            selected ? 'border-accent bg-accent text-on-accent shadow-glow' : 'border-white/80 bg-black/15 text-transparent backdrop-blur-sm'
          )}
          aria-hidden="true"
        >
          <Check size={16} strokeWidth={3} />
        </span>
      </div>
      <div className="flex flex-1 flex-col gap-2 p-4">
        <p className="font-display text-[17px] font-semibold leading-tight tracking-tight text-ink">{service.name}</p>
        {text && <p className="mjto-clamp-2 text-sm leading-snug text-ink-soft">{text}</p>}
        <div className="mt-auto flex flex-wrap items-center gap-x-3 gap-y-1 pt-1 text-[13px]">
          {service.duration > 0 && (
            <span className="inline-flex items-center gap-1 text-ink-soft">
              <Clock size={14} aria-hidden="true" />
              {durationOf(service.duration)}
            </span>
          )}
          {showPrice(service) && <span className="font-semibold text-ink mjto-tabular">{priceOf(service.price)}</span>}
          {deposit > 0 && (
            <span className="text-ink-faint mjto-tabular">
              {t('label_deposit')} {priceOf(deposit)}
            </span>
          )}
        </div>
        {service.maintenance && service.maintenance.label && t('label_maintenance') && (
          <p className="mjto-booking__service-maintenance inline-flex items-start gap-1.5 text-xs leading-snug text-ink-faint">
            <RefreshCcw size={13} className="mt-px shrink-0" aria-hidden="true" />
            <span>{t('label_maintenance', { periodo: service.maintenance.label })}</span>
          </p>
        )}
      </div>
    </>
  );
}

/** Secciones del servicio, plegadas (details nativo: accesible y sin JS). */
function ServiceSections({ sections }) {
  return (
    <div className="mjto-booking__service-sections mjto-hairline-t divide-y divide-line">
      {sections.map((sec) => (
        <details key={sec.key || sec.label} className="group/sec">
          <summary className="flex min-h-11 cursor-pointer list-none items-center justify-between gap-3 px-4 py-2.5 text-[13px] font-semibold text-ink-soft hover:text-ink [&::-webkit-details-marker]:hidden">
            <span className="min-w-0">{sec.label}</span>
            <ChevronDown size={15} className="shrink-0 group-open/sec:rotate-180" aria-hidden="true" />
          </summary>
          <p className="whitespace-pre-wrap px-4 pb-3 text-[13px] leading-relaxed text-ink-soft">{sec.text}</p>
        </details>
      ))}
    </div>
  );
}

function ServiceCard({ service, selected, onSelect }) {
  const sections = Array.isArray(service.sections) ? service.sections : [];

  // Sin secciones: la tarjeta de siempre (toda es el botón).
  if (!sections.length) {
    return (
      <button
        type="button"
        role="radio"
        aria-checked={selected}
        onClick={onSelect}
        className={cn(
          'mjto-booking__service group relative flex w-full flex-col overflow-hidden rounded-card border bg-surface text-left shadow-card transition-[border-color,box-shadow,transform] duration-200 active:scale-[0.99]',
          selected ? 'mjto-booking__service--selected border-accent ring-4 ring-accent/15' : 'border-line hover:border-accent/40 hover:shadow-float'
        )}
      >
        <ServiceCardBody service={service} selected={selected} />
      </button>
    );
  }

  // Con secciones: el botón es la parte de arriba y los textos plegables quedan
  // debajo, dentro de la misma tarjeta (un <details> no puede ir dentro de un botón).
  return (
    <div
      className={cn(
        'mjto-booking__service group relative flex w-full flex-col overflow-hidden rounded-card border bg-surface text-left shadow-card transition-[border-color,box-shadow] duration-200',
        selected ? 'mjto-booking__service--selected border-accent ring-4 ring-accent/15' : 'border-line hover:border-accent/40 hover:shadow-float'
      )}
    >
      <button type="button" role="radio" aria-checked={selected} onClick={onSelect} className="flex w-full flex-1 flex-col text-left transition-transform duration-200 active:scale-[0.99]">
        <ServiceCardBody service={service} selected={selected} />
      </button>
      <ServiceSections sections={sections} />
    </div>
  );
}

/** Agrupa por categoría (orden de cfg.categories); cada servicio va en la primera que tenga. */
function groupServices(services, categories) {
  const groups = categories.map((cat) => ({ key: `cat-${cat.id}`, name: cat.name, description: cat.description, items: [] }));
  const index = new Map(categories.map((cat, i) => [cat.id, i]));
  const rest = { key: 'sin-categoria', name: '', description: '', items: [] };
  services.forEach((s) => {
    const positions = (s.categories || []).map((c) => index.get(c.id)).filter((i) => i !== undefined);
    if (positions.length) groups[Math.min(...positions)].items.push(s);
    else rest.items.push(s);
  });
  return [...groups, rest].filter((g) => g.items.length > 0);
}

/**
 * @param {{ services: object[], value: object|null, onChange: (service) => void, onNext: () => void }} props
 */
export default function ServiceStep({ services, value, onChange, onNext }) {
  const { t, cfg } = useBooking();
  const groups = useMemo(
    () => (cfg.groupByCategory && cfg.categories.length ? groupServices(services, cfg.categories) : null),
    [services, cfg.groupByCategory, cfg.categories]
  );

  if (!services.length) {
    return <EmptyState icon={Scissors} title={t('services_empty')} />;
  }

  const grid = (list) =>
    list.map((s) => <ServiceCard key={s.id} service={s} selected={!!value && value.id === s.id} onSelect={() => onChange(s)} />);

  return (
    <div className="mjto-booking__services animate-fade">
      {groups ? (
        <div role="radiogroup" aria-label={t('booking_step_service')} className="flex flex-col gap-7">
          {groups.map((g) => (
            <section key={g.key} className="mjto-booking__service-group" aria-label={g.name || undefined}>
              {g.name && (
                <div className="mb-3">
                  <h3 className="font-display text-[17px] font-semibold tracking-tight text-ink">{g.name}</h3>
                  {g.description && <p className="mt-0.5 text-sm leading-snug text-ink-soft">{g.description}</p>}
                </div>
              )}
              <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">{grid(g.items)}</div>
            </section>
          ))}
        </div>
      ) : (
        <div role="radiogroup" aria-label={t('booking_step_service')} className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {grid(services)}
        </div>
      )}
      <StepFooter className="justify-end">
        <Button onClick={onNext} disabled={!value} iconRight={ArrowRight} size="lg">
          {t('booking_next')}
        </Button>
      </StepFooter>
    </div>
  );
}
