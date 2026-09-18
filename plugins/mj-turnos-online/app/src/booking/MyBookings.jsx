/**
 * "Mis turnos" en el widget del shortcode: se muestra con ?mis-turnos=1 en la
 * URL (el myBookingsUrl por defecto cuando no está el tema MJ Appearance).
 *
 *  - Sin sesión: my_bookings_login con el enlace al login (vuelve acá).
 *  - Con sesión: GET /me/bookings → próximos y anteriores, saldo a favor (si
 *    hay) y "Ver turno", que abre el estado de ese turno inline (onOpen).
 */
import { useCallback, useEffect, useState } from 'react';
import { CalendarPlus, ChevronRight, LogIn, RefreshCw, Wallet } from 'lucide-react';
import { publicApi } from '@/lib/api';
import { cn, longDateLabel, timeRange } from '@/lib/format';
import { Button, LoadingBlock, StatusBadge } from '@/ui';
import { Alert, accountLoginUrl, useBooking } from './shared';

function BookingRow({ item, onOpen }) {
  const { t, statusLabel } = useBooking();
  return (
    <li
      className={cn(
        'mjto-booking__mine-item flex flex-col gap-3 rounded-tile border bg-surface p-4 sm:flex-row sm:items-center',
        item.needsAction ? 'border-accent/40' : 'border-line'
      )}
    >
      <div className="min-w-0 flex-1">
        <p className="font-display text-[15px] font-semibold leading-tight tracking-tight text-ink">{item.serviceName}</p>
        <p className="mt-1 text-sm text-ink-soft">
          {item.date && <span className="capitalize">{longDateLabel(item.date, { year: true })}</span>}
          {item.time && (
            <>
              {item.date && <span aria-hidden="true"> · </span>}
              <span className="mjto-tabular font-semibold text-ink">{timeRange(item.time, item.endTime)}</span>
            </>
          )}
        </p>
        <div className="mt-2">
          <StatusBadge status={item.status} label={item.statusLabel || statusLabel(item.status)} />
        </div>
      </div>
      {item.token && (
        <Button variant={item.needsAction ? 'primary' : 'secondary'} size="sm" iconRight={ChevronRight} onClick={() => onOpen(item.token)} className="self-start sm:self-center">
          {t('my_bookings_view')}
        </Button>
      )}
    </li>
  );
}

/**
 * @param {{ onOpen: (token: string) => void, onBook: () => void }} props
 */
export default function MyBookings({ onOpen, onBook }) {
  const { t, cfg, priceOf } = useBooking();
  const loggedIn = cfg.account.loggedIn;
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(loggedIn);
  const [error, setError] = useState('');
  const [needsLogin, setNeedsLogin] = useState(!loggedIn);

  const load = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      const res = await publicApi.getMyBookings();
      setData(res || {});
      setNeedsLogin(false);
    } catch (e) {
      if (e && (e.status === 401 || e.status === 403)) setNeedsLogin(true);
      else setError(e?.message || '');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    if (loggedIn) load();
  }, [loggedIn, load]);

  const upcoming = Array.isArray(data?.upcoming) ? data.upcoming : [];
  const past = Array.isArray(data?.past) ? data.past : [];
  const credit = Number(data?.credit) || 0;

  const list = (title, items) =>
    items.length > 0 && (
      <section className="mjto-booking__mine-group" aria-label={title}>
        <h4 className="mb-2 text-[11px] font-bold uppercase tracking-[0.14em] text-ink-faint">{title}</h4>
        <ul className="flex flex-col gap-2">
          {items.map((item) => (
            <BookingRow key={item.token || `${item.date}-${item.time}`} item={item} onOpen={onOpen} />
          ))}
        </ul>
      </section>
    );

  return (
    <div className="mjto-booking__mine animate-fade">
      <div className="mb-5 flex items-center justify-between gap-3">
        <h3 className="font-display text-xl font-bold tracking-tight text-ink">{t('my_bookings_title')}</h3>
        {loggedIn && !needsLogin && !loading && (
          <Button variant="ghost" size="sm" onClick={load} icon={RefreshCw} aria-label={t('booking_retry')}>
            {t('booking_retry')}
          </Button>
        )}
      </div>

      {needsLogin ? (
        <div className="flex flex-col items-start gap-4">
          <p className="text-sm text-ink-soft">{t('my_bookings_login')}</p>
          <Button href={accountLoginUrl(cfg)} icon={LogIn}>
            {t('booking_account_login')}
          </Button>
        </div>
      ) : loading ? (
        <LoadingBlock />
      ) : error ? (
        <Alert
          tone="danger"
          action={
            <Button size="xs" variant="outline" onClick={load}>
              {t('booking_retry')}
            </Button>
          }
        >
          {error}
        </Alert>
      ) : (
        <div className="flex flex-col gap-6">
          {credit > 0 && (
            <div className="mjto-booking__credit flex items-center gap-3 rounded-tile border border-success/20 bg-success-soft px-4 py-3 text-success">
              <Wallet size={18} className="shrink-0" aria-hidden="true" />
              <span className="min-w-0 flex-1 text-sm font-medium">{t('label_credit')}</span>
              <span className="font-display text-lg font-bold mjto-tabular">{priceOf(credit)}</span>
            </div>
          )}
          {!upcoming.length && !past.length && <p className="text-sm text-ink-soft">{t('my_bookings_empty')}</p>}
          {list(t('my_bookings_upcoming'), upcoming)}
          {list(t('my_bookings_past'), past)}
        </div>
      )}

      <div className="mt-6 flex justify-end mjto-hairline-t pt-5">
        <Button onClick={onBook} icon={CalendarPlus} className="w-full sm:w-auto">
          {t('my_bookings_book')}
        </Button>
      </div>
    </div>
  );
}
