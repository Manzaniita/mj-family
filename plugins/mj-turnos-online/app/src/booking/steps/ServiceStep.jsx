/**
 * Paso 1 — Servicio: tarjetas con foto (o color), nombre, duración, precio
 * (si priceVisible) y seña (si aplica). Tocar una tarjeta la selecciona;
 * "Continuar" pasa al día y hora.
 */
import { ArrowRight, Check, Clock, Scissors } from 'lucide-react';
import { cn, initials } from '@/lib/format';
import { Button, EmptyState } from '@/ui';
import { StepFooter, useBooking } from '../shared';

function ServiceCard({ service, selected, onSelect }) {
  const { t, durationOf, priceOf, depositOf, showPrice } = useBooking();
  const deposit = depositOf(service);
  const text = service.excerpt || service.description;
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
      </div>
    </button>
  );
}

/**
 * @param {{ services: object[], value: object|null, onChange: (service) => void, onNext: () => void }} props
 */
export default function ServiceStep({ services, value, onChange, onNext }) {
  const { t } = useBooking();

  if (!services.length) {
    return <EmptyState icon={Scissors} title={t('services_empty')} />;
  }

  return (
    <div className="mjto-booking__services animate-fade">
      <div role="radiogroup" aria-label={t('booking_step_service')} className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
        {services.map((s) => (
          <ServiceCard key={s.id} service={s} selected={!!value && value.id === s.id} onSelect={() => onChange(s)} />
        ))}
      </div>
      <StepFooter className="justify-end">
        <Button onClick={onNext} disabled={!value} iconRight={ArrowRight} size="lg">
          {t('booking_next')}
        </Button>
      </StepFooter>
    </div>
  );
}
