/**
 * Paso 4 — Listo: título + mensaje según el estado (booking_success_*),
 * resumen del turno, tarjeta de seña (si el turno quedó esperando seña) y
 * el enlace al estado de la reserva (en el shortcode se muestra inline).
 *
 * `result` = respuesta de POST /bookings:
 * { token, status, statusLabel, message, appointment:{…}, deposit:{required, amount, instructions, link, deadline, deadlineLabel},
 *   whatsapp:{number, url}, canCancel }
 */
import { useState } from 'react';
import { CalendarCheck, CalendarDays, Clock, Hourglass, PartyPopper, Plus } from 'lucide-react';
import { Button, StatusBadge } from '@/ui';
import { DepositCard, Summary, summaryItem, useBooking } from '../shared';

const ICONS = { confirmed: PartyPopper, awaiting_deposit: Hourglass, pending: Clock };

/**
 * @param {{ result: object, service?: object, onViewStatus: () => void, onRestart: () => void }} props
 */
export default function ResultStep({ result, service, onViewStatus, onRestart }) {
  const { t, cfg, statusLabel } = useBooking();
  const [notified, setNotified] = useState(false);
  if (!result) return null;

  const status = result.status || (cfg.confirmMode === 'auto' ? 'confirmed' : 'pending');
  const deposit = result.deposit || null;
  const depositRequired = !!deposit && (deposit.required === true || deposit.required === 1) && Number(deposit.amount) > 0;
  const message =
    result.message ||
    (status === 'confirmed' ? t('booking_success_auto') : status === 'awaiting_deposit' ? t('booking_success_deposit') : t('booking_success_pending'));
  const Icon = ICONS[status] || CalendarCheck;
  const item = summaryItem({ service, appointment: result.appointment, deposit: depositRequired ? deposit.amount : 0, cfg });
  // Con cuenta (la tenía o se creó recién): enlace a "Mis turnos". Es un enlace: la página se recarga y toma la sesión.
  const withAccount = cfg.account.mode !== 'off' && !cfg.canManage && (cfg.account.loggedIn || !!result.accountCreated);
  const myBookingsHref = withAccount ? result.myBookingsUrl || cfg.account.myBookingsUrl : '';

  return (
    <div className="mjto-booking__result animate-up">
      <div className="flex flex-col items-center text-center">
        <span className="grid size-16 place-items-center rounded-full bg-accent-soft text-accent animate-pop" aria-hidden="true">
          <Icon size={30} strokeWidth={1.75} />
        </span>
        <h3 className="mjto-balance mt-4 font-display text-2xl font-bold tracking-tight text-ink sm:text-3xl">{t('booking_success_title')}</h3>
        <p className="mjto-balance mt-2 max-w-md text-[15px] leading-relaxed text-ink-soft">{message}</p>
        <div className="mt-3">
          <StatusBadge status={status} label={result.statusLabel || statusLabel(status)} size="lg" />
        </div>
      </div>

      <div className="mx-auto mt-8 grid max-w-3xl grid-cols-1 gap-4 md:grid-cols-2 md:items-start">
        <Summary item={item} />
        {depositRequired && status === 'awaiting_deposit' && (
          <DepositCard deposit={deposit} receipt={result.receipt} token={result.token} whatsapp={result.whatsapp} notified={notified} onNotified={() => setNotified(true)} />
        )}
      </div>

      <div className="mt-8 flex flex-col items-center justify-center gap-2 sm:flex-row">
        {result.token && (
          <Button variant="secondary" onClick={onViewStatus} icon={CalendarCheck}>
            {t('booking_status_link')}
          </Button>
        )}
        {myBookingsHref && (
          <Button variant="ghost" href={myBookingsHref} icon={CalendarDays}>
            {t('my_bookings_title')}
          </Button>
        )}
        <Button variant="ghost" onClick={onRestart} icon={Plus}>
          {t('booking_new_booking')}
        </Button>
      </div>
    </div>
  );
}
