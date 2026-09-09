/**
 * Resumen de una visita para la línea de tiempo de la ficha (spec A.11 — Ficha):
 * fecha, servicio, producto (campo role product), total / seña / método,
 * chips de advertencias, fechas de control y retoque, botón editar y
 * detalle completo en lectura al expandir.
 *
 *   <VisitSummary visit={v} blocks={fichaBlocks} expanded={open === v.id}
 *                 onToggle={() => …} onEdit={() => …} onDelete={() => …} appointment={appt} />
 */
import { AlertTriangle, CalendarClock, ChevronDown, Pencil, RefreshCw, Sparkles, Trash2 } from 'lucide-react';
import { cn, dateLabel, formatDateTime, weekdayLabel } from '@/lib/format';
import { isDateStr } from '@/lib/dates';
import { Badge, Button, Chip, ChipGroup, StatusBadge } from '@/ui';
import { usePanel } from '../store';
import VisitFields, { computeWarnings, isEmptyValue, roleValue } from './VisitFields';

const num = (v) => {
  const n = Number(v);
  return Number.isFinite(n) ? n : 0;
};

/** Chip de fecha de seguimiento (control / retoque) con tono según si ya pasó. */
function FollowUp({ icon: Icon, label, date, today }) {
  if (!isDateStr(date)) return null;
  const past = today && date < today;
  return (
    <Chip size="sm" tone={past ? 'neutral' : 'info'} icon={Icon} title={`${label}: ${dateLabel(date, { year: true })}`}>
      {label}: {dateLabel(date)}
    </Chip>
  );
}

/**
 * @param {{ visit: object, blocks?: Array, paymentMethods?: string[], expanded?: boolean, onToggle?: () => void,
 *   onEdit?: () => void, onDelete?: () => void, appointment?: object|null, isLast?: boolean, className? }} props
 */
export default function VisitSummary({ visit, blocks, paymentMethods, expanded = false, onToggle, onEdit, onDelete, appointment = null, isLast = false, className }) {
  const store = usePanel();
  const fichaBlocks = Array.isArray(blocks) ? blocks : store.fichaBlocks;
  const methods = Array.isArray(paymentMethods) ? paymentMethods : store.paymentMethods;
  const data = visit?.data && typeof visit.data === 'object' ? visit.data : {};

  const warnings = Array.isArray(visit?.warnings) && visit.warnings.length ? visit.warnings : computeWarnings(fichaBlocks, data, methods);
  const product = roleValue(fichaBlocks, data, 'product');
  const total = num(visit?.total);
  const deposit = num(visit?.deposit);
  const method = visit?.paymentMethod || '';
  const service = visit?.serviceName || store.serviceName(visit?.serviceId, '');
  const date = visit?.visitedAt || '';
  const day = date.slice(8, 10);
  const monthShort = isDateStr(date) ? dateLabel(date).split(' ').slice(-1)[0] : '';
  const hasDetail = Object.keys(data).some((k) => !isEmptyValue(data[k]));

  return (
    <li className={cn('mjto-visit-item relative flex gap-3 sm:gap-4', !isLast && 'pb-5', className)}>
      {/* Línea de tiempo */}
      <div className="flex w-12 shrink-0 flex-col items-center sm:w-14" aria-hidden="true">
        <div className={cn('mjto-visit-date grid h-14 w-12 place-items-center rounded-2xl sm:w-14', warnings.length ? 'bg-warning-soft text-warning' : 'bg-accent-soft text-accent')}>
          <span className="font-display text-xl font-bold leading-none mjto-tabular">{day || '—'}</span>
          <span className="mt-0.5 text-[10px] font-bold uppercase tracking-wider">{monthShort}</span>
        </div>
        {!isLast && <span className="mt-2 w-px flex-1 bg-line" />}
      </div>

      <article className={cn('mjto-card min-w-0 flex-1 overflow-hidden transition-shadow', expanded && 'shadow-float')}>
        <button
          type="button"
          onClick={onToggle}
          aria-expanded={expanded}
          className="flex w-full items-start gap-3 px-4 py-3.5 text-left transition-colors hover:bg-surface-2/60 sm:px-5"
        >
          <div className="min-w-0 flex-1">
            <p className="text-xs capitalize text-ink-faint">
              {isDateStr(date) ? `${weekdayLabel(date)} ${dateLabel(date, { year: true }).replace(/^\S+\s/, '')}` : 'Sin fecha'}
            </p>
            <p className="mt-0.5 truncate font-display text-[15px] font-semibold tracking-tight text-ink">{service || 'Visita'}</p>
            {!isEmptyValue(product) && (
              <p className="mt-0.5 flex items-center gap-1.5 truncate text-sm text-ink-soft">
                <Sparkles size={14} className="shrink-0 text-accent" aria-hidden="true" />
                <span className="truncate">{String(product)}</span>
              </p>
            )}
            <div className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
              <span className="font-semibold text-ink mjto-tabular">{total > 0 ? store.formatPrice(total) : <span className="font-normal text-ink-faint">Sin total</span>}</span>
              {deposit > 0 && (
                <span className="text-ink-soft mjto-tabular">
                  Seña {store.formatPrice(deposit)}
                </span>
              )}
              {method && <Badge tone="neutral" size="sm">{method}</Badge>}
            </div>
            {(warnings.length > 0 || isDateStr(visit?.controlDate) || isDateStr(visit?.retouchDate)) && (
              <ChipGroup className="mt-2.5">
                {warnings.map((w, i) => (
                  <Chip key={`${w.field}-${i}`} size="sm" tone="warning" icon={AlertTriangle} title={w.text}>
                    {w.option ? `${w.label}: ${w.option}` : w.label || w.text}
                  </Chip>
                ))}
                <FollowUp icon={CalendarClock} label="Control" date={visit?.controlDate} today={store.today} />
                <FollowUp icon={RefreshCw} label="Retoque" date={visit?.retouchDate} today={store.today} />
              </ChipGroup>
            )}
          </div>
          <ChevronDown size={18} className={cn('mt-1 shrink-0 text-ink-faint transition-transform duration-200', expanded && 'rotate-180')} aria-hidden="true" />
        </button>

        {expanded && (
          <div className="mjto-hairline-t animate-fade px-4 py-4 sm:px-5">
            {appointment && (
              <div className="mb-3 flex flex-wrap items-center gap-2 text-sm text-ink-soft">
                <CalendarClock size={15} className="shrink-0 text-ink-faint" aria-hidden="true" />
                <span>Turno del {formatDateTime(appointment.startsAt)}</span>
                <StatusBadge status={appointment.status} label={appointment.statusLabel || store.statusLabel(appointment.status)} size="sm" />
              </div>
            )}
            {warnings.length > 0 && (
              <div className="mb-3 flex flex-col gap-2">
                {warnings.map((w, i) => (
                  <div key={i} role="note" className="flex items-start gap-2 rounded-tile border border-warning/30 bg-warning-soft px-3 py-2 text-[13px] leading-snug text-warning">
                    <AlertTriangle size={16} className="mt-0.5 shrink-0" aria-hidden="true" />
                    <span>{w.text}</span>
                  </div>
                ))}
              </div>
            )}
            {hasDetail ? (
              <VisitFields blocks={fichaBlocks} values={data} readOnly paymentMethods={methods} warnings={[]} />
            ) : (
              <p className="rounded-tile bg-surface-2 px-4 py-4 text-center text-sm text-ink-soft">Esta visita todavía no tiene datos cargados.</p>
            )}
            <div className="mt-4 flex flex-wrap items-center justify-end gap-2">
              {onDelete && (
                <Button variant="ghost" size="sm" icon={Trash2} onClick={onDelete} className="text-danger hover:bg-danger-soft hover:text-danger">
                  Eliminar
                </Button>
              )}
              {onEdit && (
                <Button variant="soft" size="sm" icon={Pencil} onClick={onEdit}>
                  Editar visita
                </Button>
              )}
            </div>
          </div>
        )}

        {!expanded && onEdit && (
          <div className="mjto-hairline-t flex items-center justify-end gap-1 px-2 py-1.5">
            <Button variant="ghost" size="xs" icon={Pencil} onClick={onEdit}>
              Editar
            </Button>
          </div>
        )}
      </article>
    </li>
  );
}

export { VisitSummary };
