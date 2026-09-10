/**
 * Estado de una reserva por token (GET /bookings/{token}): resumen, estado,
 * tarjeta de seña mientras espera la transferencia, "Ya transferí" y
 * cancelación (si canCancel) con confirmación.
 *
 * En el shortcode se abre inline con el token guardado en sessionStorage
 * (la SPA del tema tiene su propia página /turnos/t/{token}).
 */
import { useCallback, useEffect, useState } from 'react';
import { ArrowLeft, CalendarX2, MessageCircle, RefreshCw, XCircle } from 'lucide-react';
import { publicApi } from '@/lib/api';
import { longDateLabel, timeRange, whatsappUrl } from '@/lib/format';
import { Button, ConfirmDialog, LoadingBlock, StatusBadge } from '@/ui';
import { ACTIVE, Alert, DepositCard, Summary, summaryItem, useBooking } from './shared';

/**
 * @param {{ token: string, onBack: () => void, onForget?: () => void, onStatusChange?: (status: string) => void }} props
 */
export default function StatusView({ token, onBack, onForget, onStatusChange }) {
  const { t, cfg, statusLabel } = useBooking();
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [notFound, setNotFound] = useState(false);
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [cancelling, setCancelling] = useState(false);
  const [cancelError, setCancelError] = useState('');
  const [justCancelled, setJustCancelled] = useState(false);

  const load = useCallback(async () => {
    if (!token) return;
    setLoading(true);
    setError('');
    setNotFound(false);
    try {
      const res = await publicApi.getBooking(token);
      setData(res || null);
    } catch (e) {
      if (e && e.status === 404) setNotFound(true);
      else setError(e?.message || '');
    } finally {
      setLoading(false);
    }
  }, [token]);

  useEffect(() => {
    load();
  }, [load]);

  const cancel = async () => {
    setCancelling(true);
    setCancelError('');
    try {
      const res = await publicApi.cancelBooking(token);
      const next = res?.status || 'cancelled';
      setData((d) => ({ ...(d || {}), status: next, statusLabel: res?.statusLabel, canCancel: false }));
      setJustCancelled(true);
      setConfirmOpen(false);
      onStatusChange?.(next);
    } catch (e) {
      setCancelError(e?.message || '');
      setConfirmOpen(false);
    } finally {
      setCancelling(false);
    }
  };

  const status = data?.status || '';
  const isActive = ACTIVE.includes(status);
  const deposit = data?.deposit || null;
  const depositRequired = !!deposit && (deposit.required === true || deposit.required === 1) && Number(deposit.amount) > 0;
  const businessWa = cfg.business.whatsapp ? whatsappUrl(cfg.business.whatsapp) : '';
  const item = data ? summaryItem({ appointment: data.appointment, deposit: depositRequired ? deposit.amount : 0, cfg }) : null;

  return (
    <div className="mjto-booking__status animate-fade">
      <div className="mb-5 flex items-center justify-between gap-3">
        <h3 className="font-display text-xl font-bold tracking-tight text-ink">{t('booking_status_title')}</h3>
        {!loading && !notFound && (
          <Button variant="ghost" size="sm" onClick={load} icon={RefreshCw} aria-label={t('booking_retry')}>
            {t('booking_retry')}
          </Button>
        )}
      </div>

      {loading && <LoadingBlock />}

      {!loading && notFound && (
        <div className="flex flex-col items-center gap-4 py-6 text-center">
          <span className="grid size-14 place-items-center rounded-full bg-surface-2 text-ink-faint" aria-hidden="true">
            <CalendarX2 size={26} />
          </span>
          <p className="max-w-sm text-sm text-ink-soft">{t('booking_status_not_found')}</p>
          <Button
            variant="secondary"
            icon={ArrowLeft}
            onClick={() => {
              onForget?.();
              onBack();
            }}
          >
            {t('booking_back')}
          </Button>
        </div>
      )}

      {!loading && !notFound && error && (
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
      )}

      {!loading && data && (
        <>
          <div className="mb-4 flex flex-wrap items-center gap-3">
            <StatusBadge status={status} label={data.statusLabel || statusLabel(status)} size="lg" />
            {justCancelled && <span className="text-sm font-medium text-ink-soft">{t('booking_cancelled_text')}</span>}
          </div>

          <div className="grid grid-cols-1 gap-4 md:grid-cols-2 md:items-start">
            <Summary item={item} />
            {status === 'awaiting_deposit' && depositRequired && (
              <DepositCard
                deposit={deposit}
                receipt={data.receipt}
                token={token}
                whatsapp={data.whatsapp}
                notified={!!data.depositNotified}
                onNotified={() => setData((d) => ({ ...d, depositNotified: true }))}
              />
            )}
          </div>

          {cancelError && <Alert tone="danger" className="mt-4">{cancelError}</Alert>}

          {isActive && !data.canCancel && !justCancelled && (
            <Alert
              tone="info"
              className="mt-4"
              action={
                businessWa ? (
                  <Button size="xs" variant="outline" href={businessWa} target="_blank" rel="noopener noreferrer" icon={MessageCircle}>
                    WhatsApp
                  </Button>
                ) : null
              }
            >
              {t('booking_cannot_cancel')}
            </Alert>
          )}

          <div className="mt-6 flex flex-wrap items-center justify-between gap-3 mjto-hairline-t pt-5">
            <Button variant="ghost" onClick={onBack} icon={ArrowLeft}>
              {t('booking_back')}
            </Button>
            {isActive && data.canCancel && (
              <Button variant="danger" onClick={() => setConfirmOpen(true)} icon={XCircle}>
                {t('booking_cancel_button')}
              </Button>
            )}
          </div>

          <ConfirmDialog
            open={confirmOpen}
            onClose={() => !cancelling && setConfirmOpen(false)}
            onConfirm={cancel}
            title={t('booking_cancel_confirm')}
            text={
              item
                ? [item.serviceName, item.date ? longDateLabel(item.date) : '', item.time ? timeRange(item.time, item.endTime) : ''].filter(Boolean).join(' · ')
                : ''
            }
            confirmLabel={t('booking_cancel_button')}
            cancelLabel={t('booking_back')}
            tone="danger"
            loading={cancelling}
          />
        </>
      )}
    </div>
  );
}
