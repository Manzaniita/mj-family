/**
 * Estado de una reserva por token (GET /bookings/{token}): resumen, estado,
 * tarjeta de seña mientras espera la transferencia, "Ya transferí" y
 * cancelación (si canCancel) con confirmación.
 *
 * Además (cada cosa solo si la vista del turno la trae):
 *  - la seña descuenta el saldo a favor usado (deposit.creditApplied);
 *  - cancelTerms.text antes de confirmar la cancelación;
 *  - reprogramar (canReschedule) con rescheduleTerms.text; si no se puede pero
 *    hay texto (plazo vencido), solo el texto;
 *  - la dueña canceló y ofrece opciones (offer.open): otro día, devolución o
 *    saldo a favor;
 *  - opinión del turno atendido (review), con ?opinar=1 lleva la vista ahí.
 *
 * En el shortcode se abre inline con el token guardado en sessionStorage
 * (la SPA del tema tiene su propia página /turnos/t/{token}).
 */
import { useCallback, useEffect, useRef, useState } from 'react';
import { ArrowLeft, CalendarClock, CalendarX2, HeartHandshake, MessageCircle, RefreshCw, Wallet, XCircle } from 'lucide-react';
import { publicApi } from '@/lib/api';
import { longDateLabel, timeRange, whatsappUrl } from '@/lib/format';
import { Button, ConfirmDialog, LoadingBlock, StatusBadge } from '@/ui';
import { ACTIVE, Alert, DepositCard, Summary, summaryItem, urlParam, useBooking } from './shared';
import RescheduleView from './RescheduleView';
import ReviewBlock from './ReviewBlock';

const OFFER_DONE = {
  refund_requested: 'booking_offer_refund_done',
  credited: 'booking_offer_credit_done',
  refunded: 'booking_offer_refunded',
};

const OFFER_CHOICES = {
  reschedule: { key: 'booking_offer_reschedule', icon: CalendarClock, variant: 'primary' },
  refund: { key: 'booking_offer_refund', icon: Wallet, variant: 'secondary' },
  credit: { key: 'booking_offer_credit', icon: HeartHandshake, variant: 'secondary' },
};

/**
 * @param {{ token: string, onBack: () => void, onForget?: () => void, onStatusChange?: (status: string) => void,
 *   onTokenChange?: (token: string) => void }} props
 */
export default function StatusView({ token, onBack, onForget, onStatusChange, onTokenChange }) {
  const { t, cfg, statusLabel } = useBooking();
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [notFound, setNotFound] = useState(false);
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [cancelling, setCancelling] = useState(false);
  const [cancelError, setCancelError] = useState('');
  const [justCancelled, setJustCancelled] = useState(false);
  const [mode, setMode] = useState('view'); // 'view' | 'reschedule'
  const [done, setDone] = useState('');
  const [choosing, setChoosing] = useState('');
  const [choiceError, setChoiceError] = useState('');
  const reviewRef = useRef(null);
  const wantsReview = useRef(urlParam('opinar') === '1');

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

  // ?opinar=1 (link del mail): llevar la vista al formulario de opinión, una sola vez.
  useEffect(() => {
    if (!wantsReview.current || loading || !data?.review?.can) return;
    wantsReview.current = false;
    const el = reviewRef.current;
    if (!el) return;
    el.scrollIntoView?.({ block: 'start', behavior: 'smooth' });
    el.focus?.({ preventScroll: true });
  }, [loading, data]);

  // Las acciones van con el token de la vista (después de reprogramar con mode 'new' es otro).
  const activeToken = data?.token || token;

  const cancel = async () => {
    setCancelling(true);
    setCancelError('');
    try {
      const res = await publicApi.cancelBooking(activeToken);
      const next = res?.status || 'cancelled';
      if (res?.booking && typeof res.booking === 'object') setData(res.booking);
      else setData((d) => ({ ...(d || {}), status: next, statusLabel: res?.statusLabel, canCancel: false, canReschedule: false }));
      setJustCancelled(true);
      setDone('');
      setConfirmOpen(false);
      onStatusChange?.(next);
    } catch (e) {
      setCancelError(e?.message || '');
      setConfirmOpen(false);
    } finally {
      setCancelling(false);
    }
  };

  const rescheduled = (res, picked) => {
    const nextToken = typeof res.token === 'string' && res.token ? res.token : '';
    setData(res && res.status ? res : data);
    setMode('view');
    setJustCancelled(false);
    setChoiceError('');
    setDone(t('booking_rescheduled_done', { fecha: longDateLabel(picked.date), hora: picked.time }));
    if (res?.status) onStatusChange?.(res.status);
    if (nextToken && nextToken !== token) onTokenChange?.(nextToken);
  };

  const choose = async (choice) => {
    if (choice === 'reschedule') {
      setMode('reschedule');
      return;
    }
    setChoosing(choice);
    setChoiceError('');
    try {
      const res = await publicApi.offerChoice(activeToken, choice);
      if (res && typeof res === 'object' && res.status) setData(res);
    } catch (e) {
      setChoiceError(e?.message || '');
    } finally {
      setChoosing('');
    }
  };

  const status = data?.status || '';
  const isActive = ACTIVE.includes(status);
  const deposit = data?.deposit || null;
  const depositRequired = !!deposit && (deposit.required === true || deposit.required === 1) && Number(deposit.amount) > 0;
  const businessWa = cfg.business.whatsapp ? whatsappUrl(cfg.business.whatsapp) : '';
  const item = data ? summaryItem({ appointment: data.appointment, deposit: depositRequired ? deposit.amount : 0, cfg }) : null;
  const cancelText = String(data?.cancelTerms?.text || '');
  const rescheduleTerms = data?.rescheduleTerms || null;
  const rescheduleText = String(rescheduleTerms?.text || '');
  const canReschedule = isActive && !!data?.canReschedule;
  const offer = data?.offer || null;
  const offerOpen = !!offer?.open && Array.isArray(offer.choices) && offer.choices.length > 0;
  const offerDoneKey = offer && !offer.open ? OFFER_DONE[offer.state] : '';

  if (mode === 'reschedule' && data) {
    return (
      <div className="mjto-booking__status animate-fade">
        <RescheduleView token={activeToken} terms={rescheduleTerms} onCancel={() => setMode('view')} onDone={rescheduled} />
      </div>
    );
  }

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

          {done && <Alert tone="success" className="mb-4">{done}</Alert>}

          {offerOpen && (
            <section className="mjto-booking__offer mjto-card mjto-card--accent mb-4 p-5" aria-label={t('booking_offer_title')}>
              <p className="font-display text-lg font-semibold tracking-tight text-ink">{t('booking_offer_title')}</p>
              {t('booking_offer_text') && <p className="mt-1 text-sm leading-relaxed text-ink-soft">{t('booking_offer_text')}</p>}
              <div className="mt-4 flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                {offer.choices.map((choice) => {
                  const def = OFFER_CHOICES[choice];
                  if (!def) return null;
                  return (
                    <Button
                      key={choice}
                      variant={def.variant}
                      icon={def.icon}
                      onClick={() => choose(choice)}
                      loading={choosing === choice}
                      disabled={!!choosing && choosing !== choice}
                      className="sm:flex-1"
                    >
                      {t(def.key)}
                    </Button>
                  );
                })}
              </div>
              {choiceError && <Alert tone="danger" className="mt-3">{choiceError}</Alert>}
            </section>
          )}

          {offerDoneKey && <Alert tone="success" className="mb-4">{t(offerDoneKey)}</Alert>}

          <div className="grid grid-cols-1 gap-4 md:grid-cols-2 md:items-start">
            <Summary item={item} />
            {status === 'awaiting_deposit' && depositRequired && (
              <DepositCard
                deposit={deposit}
                receipt={data.receipt}
                token={activeToken}
                whatsapp={data.whatsapp}
                notified={!!data.depositNotified}
                onNotified={() => setData((d) => ({ ...d, depositNotified: true }))}
              />
            )}
          </div>

          {data.review && (data.review.can || data.review.done) && (
            <ReviewBlock
              ref={reviewRef}
              token={activeToken}
              review={data.review}
              className="mt-4"
              onSubmitted={(res) =>
                setData((d) => ({
                  ...d,
                  review: {
                    ...(d?.review || {}),
                    can: false,
                    done: true,
                    status: res?.status || '',
                    rating: Number(res?.review?.rating) || 0,
                    body: String(res?.review?.body || ''),
                  },
                }))
              }
            />
          )}

          {cancelError && <Alert tone="danger" className="mt-4">{cancelError}</Alert>}

          {isActive && !canReschedule && rescheduleText && !justCancelled && (
            <Alert tone="info" className="mt-4">
              {rescheduleText}
            </Alert>
          )}

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
            {isActive && (data.canCancel || canReschedule) && (
              <div className="flex flex-wrap items-center gap-2">
                {canReschedule && (
                  <Button variant="secondary" onClick={() => setMode('reschedule')} icon={CalendarClock}>
                    {t('booking_reschedule_button')}
                  </Button>
                )}
                {data.canCancel && (
                  <Button variant="danger" onClick={() => setConfirmOpen(true)} icon={XCircle}>
                    {t('booking_cancel_button')}
                  </Button>
                )}
              </div>
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
          >
            {cancelText ? (
              <p className={`rounded-tile px-3 py-2 text-sm leading-relaxed ${data.cancelTerms?.loses ? 'bg-warning-soft text-warning' : 'bg-surface-2 text-ink-soft'}`}>{cancelText}</p>
            ) : null}
          </ConfirmDialog>
        </>
      )}
    </div>
  );
}
