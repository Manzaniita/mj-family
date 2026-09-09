import { useState } from 'react';
import { Link } from 'react-router-dom';
import { ArrowRight, Check, Clock } from 'lucide-react';
import { Button } from '@/components/ui/Button';
import { TURNOS, tcopy, serviceBookingPath, serviceDeposit, formatDuration } from '@/lib/turnos';
import { formatPrice, cn } from '@/lib/format';

/**
 * Tarjeta de servicio (plugin MJ Turnos Online). Tres modos:
 *  - por defecto: tarjeta completa con foto (o fondo con el color del
 *    servicio), nombre, descripción, duración, precio, seña y botón "Reservar"
 *    (página de servicios);
 *  - compact: toda la tarjeta es un link a la reserva con el servicio
 *    preseleccionado, sin descripción ni botón (portada);
 *  - onSelect: la tarjeta es un botón (paso 1 del wizard de reserva) y
 *    `selected` la marca como elegida.
 * Todos los textos salen del plugin (tcopy); los precios, de formatPrice.
 */

/* Tono de fondo estable por servicio cuando no hay foto ni color (misma idea que lib/wc.js). */
const hueOf = (text) => {
  let h = 0;
  for (const ch of String(text || '')) h = (h * 31 + ch.charCodeAt(0)) % 360;
  return h;
};

/** Primer párrafo de un texto plano con saltos de línea. */
const firstParagraph = (text) =>
  String(text || '')
    .split(/\n\s*\n/)
    .map((s) => s.trim())
    .find(Boolean) || '';

/* Foto del servicio, o un fondo con su color (o un degradado por hue) con la inicial. */
function Media({ service, compact }) {
  const [failed, setFailed] = useState(false);
  const hasImage = !!service.image && !failed;
  const initial = (service.name || '?').trim()[0] || '?';
  return (
    <div
      className={cn(
        'mj-service-card__media relative overflow-hidden',
        compact ? 'aspect-4/3' : 'aspect-4/5',
        !hasImage && !service.color && 'mj-img'
      )}
      style={{
        '--mj-hue': hueOf(service.slug),
        ...(!hasImage && service.color ? { background: service.color } : {}),
      }}
    >
      {hasImage ? (
        <img
          src={service.image}
          alt=""
          loading="lazy"
          onError={() => setFailed(true)}
          className="absolute inset-0 h-full w-full object-cover transition-transform duration-700 group-hover:scale-105"
        />
      ) : (
        <>
          <div className="absolute inset-0 bg-gradient-to-br from-white/25 via-transparent to-black/25" aria-hidden="true" />
          <span className="display absolute inset-0 grid place-items-center text-6xl text-white/60" aria-hidden="true">
            {initial}
          </span>
        </>
      )}
    </div>
  );
}

export function ServiceCard({ service, compact = false, selected = false, onSelect, className }) {
  if (!service) return null;

  const showPrice = !!TURNOS?.priceVisible && service.priceVisible !== false && Number(service.price) > 0;
  const deposit = serviceDeposit(service);
  const description = compact ? '' : service.excerpt || firstParagraph(service.description);
  const selectable = typeof onSelect === 'function';
  const bookable = service.bookable !== false;

  const body = (
    <div className={cn('mj-service-card__body', compact ? 'p-4' : 'p-5 md:p-6')}>
      <div className="flex items-start justify-between gap-3">
        <h3 className={cn('mj-service-card__name display', compact ? 'text-lg' : 'text-xl md:text-2xl')}>{service.name}</h3>
        {selectable ? (
          <span
            className={cn(
              'mj-service-card__check grid size-6 shrink-0 place-items-center rounded-full border transition-all duration-300',
              selected ? 'border-accent bg-accent text-white' : 'border-line-strong text-transparent'
            )}
            aria-hidden="true"
          >
            <Check size={13} />
          </span>
        ) : (
          service.color && (
            <span
              className="mj-service-card__color mt-2 size-2.5 shrink-0 rounded-full"
              style={{ background: service.color }}
              aria-hidden="true"
            />
          )
        )}
      </div>
      {description && (
        <p className="mj-service-card__desc mt-2 line-clamp-3 text-sm leading-relaxed text-ink-soft">{description}</p>
      )}
      <div className="mj-service-card__meta mt-4 flex flex-wrap items-center justify-between gap-x-4 gap-y-1.5">
        {service.duration > 0 && (
          <span className="mj-service-card__duration inline-flex items-center gap-1.5 text-sm text-ink-soft">
            <Clock size={14} className="shrink-0" />
            {formatDuration(service.duration)}
          </span>
        )}
        {showPrice && (
          <span className="mj-service-card__price font-display text-base font-semibold tracking-tight text-ink">
            {formatPrice(service.price)}
          </span>
        )}
      </div>
      {deposit > 0 && (
        <p className="mj-service-card__deposit mt-1.5 text-xs text-ink-faint">
          {tcopy('label_deposit')}: {formatPrice(deposit)}
        </p>
      )}
      {!compact && !selectable && bookable && (
        <div className="mj-service-card__cta mt-5">
          <Button size="sm" to={serviceBookingPath(service.slug)}>
            {tcopy('services_book_button')}
            <ArrowRight size={14} />
          </Button>
        </div>
      )}
      {compact && (
        <span className="mj-service-card__arrow mt-3 inline-flex size-8 items-center justify-center rounded-full bg-surface-2 text-ink transition-all duration-300 group-hover:bg-accent group-hover:text-white">
          <ArrowRight size={14} className="transition-transform duration-300 group-hover:-rotate-45" />
        </span>
      )}
    </div>
  );

  const base = cn(
    'mj-service-card group block overflow-hidden rounded-xl3 border border-line glass transition-all duration-300',
    compact && 'mj-service-card--compact',
    selectable && 'mj-service-card--selectable cursor-pointer text-left hover:border-accent/40',
    selected && 'mj-service-card--selected border-accent shadow-glow',
    !selectable && 'hover:border-accent/40 hover:shadow-glow',
    className
  );

  if (selectable) {
    const onKey = (e) => {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        onSelect(service);
      }
    };
    return (
      <div
        role="button"
        tabIndex={0}
        aria-pressed={selected}
        onClick={() => onSelect(service)}
        onKeyDown={onKey}
        className={base}
        style={service.color ? { '--mj-service-color': service.color } : undefined}
      >
        <Media service={service} compact={compact} />
        {body}
      </div>
    );
  }

  if (compact) {
    return (
      <Link
        to={serviceBookingPath(service.slug)}
        className={base}
        style={service.color ? { '--mj-service-color': service.color } : undefined}
      >
        <Media service={service} compact />
        {body}
      </Link>
    );
  }

  return (
    <article className={base} style={service.color ? { '--mj-service-color': service.color } : undefined}>
      <Media service={service} compact={false} />
      {body}
    </article>
  );
}
