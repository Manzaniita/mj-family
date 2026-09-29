/**
 * Tarjeta de un turno (Hoy, Turnos, Ficha → Turnos): hora inicio–fin, clienta
 * (link a la ficha) + teléfono, servicio, chip de estado, badges "Avisó seña"
 * (depositNotifiedAt), "Sin ficha" (confirmado sin visitId), seña pagada,
 * origen web, notas; y las acciones (AppointmentActions). Desde 1.3 también:
 * qué eligió la clienta con la seña cuando cancela la dueña (ownerOffer), la
 * parte de la seña que salió del saldo a favor y la seña perdida.
 *
 * @param {{ appointment: object, onChange?: (next, meta) => void, onOpenFicha?: (appointment) => void,
 *   showDate?: boolean (fecha además de la hora: listas de varios días), compact?: boolean, className? }} props
 */
import { useState } from 'react';
import { Link } from 'react-router-dom';
import { Ban, BellRing, ClipboardList, Coins, Globe, HandCoins, Hourglass, PiggyBank, Receipt, StickyNote } from 'lucide-react';
import { cn, dateLabel, formatTime, fullName, prettyPhone, timeAgo } from '@/lib/format';
import { Badge, StatusBadge } from '@/ui';
import { usePanel } from '../store';
import { AppointmentActions } from './AppointmentActions';
import { ReceiptDialog } from './ReceiptDialog';

const num = (v) => (Number.isFinite(Number(v)) ? Number(v) : 0);

/** Estado de la seña cuando cancela la dueña con opciones → etiqueta y tono (los textos son del panel). */
export const OWNER_OFFER_BADGES = {
  open: { label: 'Esperando que elija qué hacer con la seña', short: 'Seña: elige ella', tone: 'warning', icon: Hourglass },
  refund_requested: { label: 'Pidió la devolución de la seña', short: 'Pidió devolución', tone: 'danger', icon: HandCoins },
  refunded: { label: 'Seña devuelta', short: 'Seña devuelta', tone: 'neutral', icon: HandCoins },
  credited: { label: 'La seña quedó como saldo a favor', short: 'Seña a saldo', tone: 'info', icon: PiggyBank },
  rescheduled: { label: 'Eligió otro día con la misma seña', short: 'Pasó a otro día', tone: 'info', icon: Hourglass },
};

export function AppointmentCard({ appointment, onChange, onOpenFicha, showDate = false, compact = false, className }) {
  const store = usePanel();
  const [receiptOpen, setReceiptOpen] = useState(false);
  if (!appointment) return null;

  const a = appointment;
  const client = a.client || {};
  const name = fullName(client) || 'Sin nombre';
  const clientId = Number(a.clientId || client.id || 0);
  const service = store.serviceById(a.serviceId);
  const color = service?.color || '';
  const inactive = a.status === 'cancelled' || a.status === 'no_show';
  const notified = !!a.depositNotifiedAt && a.status === 'awaiting_deposit';
  const missingFicha = (a.status === 'confirmed' || a.status === 'completed') && !a.visitId;
  const paid = num(a.depositPaid);
  const required = num(a.depositRequired);
  /* null cuando este turno no pide comprobante o el plugin no está. */
  const receipt = a.receipt || null;
  const phone = prettyPhone(client.phoneRaw || client.phone);
  const offer = OWNER_OFFER_BADGES[a.ownerOffer] || null;
  const creditApplied = num(a.creditApplied);
  const forfeited = num(a.depositForfeited);

  return (
    <article
      className={cn('mjto-card mjto-appt relative flex gap-3 p-3.5 transition-opacity sm:gap-4 sm:p-4', inactive && 'mjto-appt--inactive opacity-60', className)}
      style={color ? { borderLeft: `4px solid ${color}` } : undefined}
      data-status={a.status}
      aria-label={`${formatTime(a.time)} ${name}`}
    >
      {/* Hora */}
      <div className="mjto-appt__time w-14 shrink-0 text-center sm:w-16">
        {showDate && <p className="mb-0.5 text-[11px] font-semibold uppercase tracking-wider text-ink-faint">{dateLabel(a.date)}</p>}
        <p className="font-display text-lg font-bold leading-none tracking-tight text-ink mjto-tabular">{formatTime(a.time)}</p>
        {a.endTime && <p className="mt-1 text-xs text-ink-faint mjto-tabular">– {formatTime(a.endTime)}</p>}
        {a.durationMin > 0 && !compact && <p className="mt-1 hidden text-[11px] text-ink-faint sm:block">{store.durationLabel(a.durationMin)}</p>}
      </div>

      {/* Cuerpo */}
      <div className="min-w-0 flex-1">
        <div className="flex items-start justify-between gap-2">
          <div className="min-w-0">
            {clientId ? (
              <Link to={`/clientas/${clientId}`} className="mjto-appt__name block truncate font-semibold text-ink hover:text-accent">
                {name}
              </Link>
            ) : (
              <p className="mjto-appt__name truncate font-semibold text-ink">{name}</p>
            )}
            <p className="truncate text-sm text-ink-soft">
              {phone || 'Sin teléfono'}
              {client.locality ? ` · ${client.locality}` : ''}
            </p>
          </div>
          <StatusBadge status={a.status} label={a.statusLabel || store.statusLabel(a.status)} />
        </div>

        <p className="mjto-appt__service mt-1.5 truncate text-sm text-ink">
          {a.serviceName || 'Turno'}
          {num(a.price) > 0 && <span className="text-ink-soft"> · {store.formatPrice(a.price)}</span>}
        </p>

        {(notified || missingFicha || paid > 0 || receipt || (required > 0 && paid <= 0 && a.status === 'awaiting_deposit') || a.source === 'web' || offer || creditApplied > 0 || forfeited > 0) && (
          <div className="mjto-appt__badges mt-2 flex flex-wrap items-center gap-1.5">
            {notified && (
              <Badge tone="info" icon={BellRing} title={`Avisó seña ${timeAgo(a.depositNotifiedAt)}`}>
                Avisó seña
              </Badge>
            )}
            {missingFicha && (
              <Badge tone="warning" icon={ClipboardList}>
                Sin ficha
              </Badge>
            )}
            {paid > 0 && (
              <Badge tone="success" icon={Coins}>
                Seña {store.formatPrice(paid)}
                {a.depositMethod ? ` · ${a.depositMethod}` : ''}
              </Badge>
            )}
            {required > 0 && paid <= 0 && a.status === 'awaiting_deposit' && (
              <Badge tone="violet" icon={Coins}>
                Seña {store.formatPrice(required)}
              </Badge>
            )}
            {creditApplied > 0 && (
              <Badge tone="info" icon={PiggyBank} title={`${store.formatPrice(creditApplied)} de la seña salieron del saldo a favor de la clienta`}>
                {store.formatPrice(creditApplied)} de saldo a favor
              </Badge>
            )}
            {forfeited > 0 && (
              <Badge tone="danger" icon={Ban} title="Seña que la clienta perdió (no vino o canceló tarde)">
                Seña perdida {store.formatPrice(forfeited)}
              </Badge>
            )}
            {offer && (
              <Badge tone={offer.tone} icon={offer.icon} title={offer.label}>
                {offer.short}
              </Badge>
            )}
            {/* Comprobante de la seña: se revisa y aprueba directamente en el modal */}
            {receipt && (
              <button
                type="button"
                onClick={() => setReceiptOpen(true)}
                className="cursor-pointer transition-transform hover:scale-105 active:scale-95 focus:outline-hidden"
                title="Ver y aprobar o rechazar comprobante"
              >
                <Badge
                  tone={receipt.state === 'aprobado' ? 'success' : receipt.state === 'rechazado' ? 'danger' : 'warning'}
                  icon={Receipt}
                >
                  {receipt.files?.length ? `${receipt.nounTitle}: ${receipt.stateLabel}` : `Sin ${receipt.noun}`}
                </Badge>
              </button>
            )}
            {a.source === 'web' && (
              <Badge tone="neutral" icon={Globe} title="Reservado desde la web">
                Web
              </Badge>
            )}
          </div>
        )}

        {!compact && (a.internalNotes || a.clientNotes) && (
          <div className="mjto-appt__notes mt-2 flex flex-col gap-1 text-[13px] leading-snug">
            {a.clientNotes && (
              <p className="mjto-clamp-2 text-ink-soft">
                <span className="font-semibold text-ink-faint">Clienta: </span>
                {a.clientNotes}
              </p>
            )}
            {a.internalNotes && (
              <p className="mjto-clamp-2 flex items-start gap-1 text-ink-soft">
                <StickyNote size={13} className="mt-0.5 shrink-0 text-ink-faint" aria-hidden="true" />
                <span>{a.internalNotes}</span>
              </p>
            )}
          </div>
        )}

        {a.status === 'cancelled' && (a.cancelReason || a.cancelledBy) && (
          <p className="mt-2 text-[13px] leading-snug text-ink-faint">
            Cancelado{a.cancelledBy === 'client' ? ' por la clienta' : a.cancelledBy === 'system' ? ' automáticamente' : ''}
            {a.cancelReason ? `: ${a.cancelReason}` : '.'}
          </p>
        )}

        <div className="mt-3 flex items-center justify-end">
          <AppointmentActions appointment={a} onChange={onChange} onOpenFicha={onOpenFicha} compact={compact} />
        </div>
      </div>

      {receipt && (
        <ReceiptDialog
          appointment={a}
          receipt={receipt}
          open={receiptOpen}
          onClose={() => setReceiptOpen(false)}
          onChanged={onChange}
        />
      )}
    </article>
  );
}

export default AppointmentCard;
