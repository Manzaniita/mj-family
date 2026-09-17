import { useEffect, useRef, useState } from 'react';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import { motion, AnimatePresence } from 'framer-motion';
import {
  AlertCircle, ArrowLeft, ArrowRight, CalendarDays, Check, CheckCircle2, Clock, ExternalLink,
  LayoutDashboard, Loader2, LockKeyhole, MessageCircle, RotateCcw, ShieldCheck, Sparkles, Wallet, XCircle,
} from 'lucide-react';
import { Section } from '@/components/layout/Layout';
import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import { Reveal, Stagger, staggerItem } from '@/components/motion/Reveal';
import { ServiceCard } from '@/components/turnos/ServiceCard';
import { Calendar, monthOf, shiftMonth, monthRange } from '@/components/turnos/Calendar';
import { SlotPicker } from '@/components/turnos/SlotPicker';
import { BookingForm, emptyBookingValues, bookingPayload } from '@/components/turnos/BookingForm';
import { PageHeader } from '@/pages/Servicios';
import { NotFound } from '@/pages/Misc';
import { useAuth } from '@/store/auth';
import { TRANSITIONS } from '@/lib/motion';
import { EASE, formatPrice, cn } from '@/lib/format';
import { usePageMeta } from '@/lib/seo';
import {
  TURNOS, tcopy, statusLabel, bookingPath, statusPath, bookingLoginPath, serviceBySlug, serviceDeposit,
  formatDuration, fillTemplate, todayISO, lastBookableISO, formatDateLong,
  getAvailability, createBooking, getBooking, cancelBooking, notifyDeposit, isSlotTakenError,
} from '@/lib/turnos';

/**
 * Reserva de turnos (plugin MJ Turnos Online):
 *  - BookingPage (/{slug}): wizard de 4 pasos — servicio → día y hora → datos → listo.
 *  - BookingStatusPage (/{slug}/t/:token): estado de una reserva, seña y cancelación.
 * Todos los textos vienen del plugin (tcopy); los precios, de formatPrice.
 * Para la dueña (TURNOS.canManage) /{slug} lo intercepta el plugin con el panel:
 * acá solo la ve con ?vista=publica, con un banner para volver al panel.
 */

const pad2 = (n) => String(n).padStart(2, '0');

/** 'HH:MM' + minutos → 'HH:MM' (fin del turno). */
function addMinutes(time, minutes) {
  const m = /^(\d{1,2}):(\d{2})$/.exec(String(time || ''));
  if (!m) return '';
  const total = (Number(m[1]) * 60 + Number(m[2]) + Math.round(Number(minutes) || 0)) % (24 * 60);
  return `${pad2(Math.floor(total / 60))}:${pad2(total % 60)}`;
}

/** Tono del badge según el estado de la reserva. */
const STATUS_TONES = {
  confirmed: 'success',
  completed: 'success',
  cancelled: 'danger',
  no_show: 'danger',
  pending: 'accent',
  awaiting_deposit: 'accent',
};

/** Mensaje de éxito: el que manda el servidor, o el texto del plugin según estado. */
function successText(status, message) {
  if (typeof message === 'string' && message.trim() !== '') return message;
  if (status === 'awaiting_deposit') return tcopy('booking_success_deposit');
  if (status === 'confirmed') return tcopy('booking_success_auto');
  return tcopy('booking_success_pending');
}

/* Caja de error (mismo look que en Contacto / Auth). */
function ErrorBox({ children, className }) {
  if (!children) return null;
  return (
    <div role="alert" className={cn('mj-booking__error flex items-start gap-3 rounded-xl2 border border-danger/30 bg-danger/10 p-4 text-sm text-danger', className)}>
      <AlertCircle size={17} className="mt-0.5 shrink-0" aria-hidden="true" />
      <p>{children}</p>
    </div>
  );
}

/* --------------------------- Dueña / login ------------------------------- */

/** Banner para la dueña: vuelve al panel con recarga completa (el plugin intercepta /{slug}). */
function OwnerBanner() {
  if (!TURNOS?.canManage) return null;
  return (
    <Reveal className="mj-booking__owner mb-8">
      <a
        href={bookingPath()}
        className="group flex items-center justify-between gap-4 rounded-xl2 border border-accent/30 bg-accent-soft px-5 py-4 text-sm font-medium text-ink transition-colors hover:border-accent"
      >
        <span className="inline-flex items-center gap-3">
          <LayoutDashboard size={18} className="shrink-0 text-accent" aria-hidden="true" />
          {tcopy('booking_panel_link')}
        </span>
        <ArrowRight size={16} className="shrink-0 text-accent transition-transform duration-300 group-hover:translate-x-1" aria-hidden="true" />
      </a>
    </Reveal>
  );
}

/** Link discreto al login (con vuelta a /{slug}) para quien no está logueado. */
function LoginHint({ className }) {
  const user = useAuth((s) => s.user);
  if (TURNOS?.canManage || user) return null;
  return (
    <p className={cn('mj-booking__login-hint', className)}>
      <Link to={bookingLoginPath()} className="inline-flex items-center gap-1.5 text-xs text-ink-faint transition-colors hover:text-accent">
        <LockKeyhole size={13} aria-hidden="true" />
        {tcopy('booking_login_hint')}
      </Link>
    </p>
  );
}

/* ------------------------------ Piezas ----------------------------------- */

/** Pasos del wizard: los ya hechos se pueden tocar para volver. */
function Steps({ current, onGo }) {
  const items = [
    ['service', tcopy('booking_step_service')],
    ['date', tcopy('booking_step_date')],
    ['details', tcopy('booking_step_details')],
    ['done', tcopy('booking_step_done')],
  ];
  const idx = items.findIndex(([key]) => key === current);
  return (
    <ol className="mj-booking__steps flex flex-wrap gap-2">
      {items.map(([key, label], i) => {
        const state = i < idx ? 'done' : i === idx ? 'active' : 'todo';
        const clickable = state === 'done' && current !== 'done';
        return (
          <li key={key}>
            <button
              type="button"
              disabled={!clickable}
              onClick={() => onGo(key)}
              aria-current={state === 'active' ? 'step' : undefined}
              className={cn(
                'mj-booking__step inline-flex h-9 items-center gap-2 rounded-(--mj-radius-pill) border px-3.5 text-[13px] font-medium transition-all duration-300 disabled:pointer-events-none',
                state === 'active' && 'mj-booking__step--active border-accent bg-accent text-white shadow-glow',
                state === 'done' && 'mj-booking__step--done border-line glass text-ink hover:border-accent/50',
                state === 'todo' && 'mj-booking__step--todo border-line text-ink-faint'
              )}
            >
              <span
                className={cn(
                  'mj-booking__step-num grid size-5 place-items-center rounded-full text-[11px] font-semibold tabular-nums',
                  state === 'active' ? 'bg-white/20' : state === 'done' ? 'bg-accent text-white' : 'bg-surface-2'
                )}
                aria-hidden="true"
              >
                {state === 'done' ? <Check size={11} /> : i + 1}
              </span>
              {label}
            </button>
          </li>
        );
      })}
    </ol>
  );
}

/** Resumen lateral de lo elegido (servicio, día/hora, precio, seña). */
function Summary({ service, date, time, className }) {
  if (!service) return null;
  const deposit = serviceDeposit(service);
  const showPrice = TURNOS.priceVisible && service.priceVisible !== false && Number(service.price) > 0;
  const end = time && service.duration > 0 ? addMinutes(time, service.duration) : '';
  return (
    <aside className={cn('mj-booking__summary rounded-xl3 border border-line glass p-6', className)}>
      <p className="mj-eyebrow text-xs font-semibold uppercase tracking-[0.2em] text-accent">{tcopy('booking_summary_title')}</p>
      <p className="mj-booking__summary-service display mt-3 text-xl">{service.name}</p>
      <ul className="mj-booking__summary-list mt-4 space-y-2.5 text-sm text-ink-soft">
        {service.duration > 0 && (
          <li className="flex items-center gap-2.5">
            <Clock size={15} className="shrink-0 text-ink-faint" aria-hidden="true" />
            <span>{tcopy('label_duration')}: {formatDuration(service.duration)}</span>
          </li>
        )}
        {date && (
          <li className="flex items-center gap-2.5 text-ink">
            <CalendarDays size={15} className="shrink-0 text-accent" aria-hidden="true" />
            <span className="capitalize">
              {formatDateLong(date)}
              {time && <span className="tabular-nums"> · {time}{end && ` – ${end}`}</span>}
            </span>
          </li>
        )}
        {showPrice && (
          <li className="flex items-center gap-2.5">
            <Wallet size={15} className="shrink-0 text-ink-faint" aria-hidden="true" />
            <span>{tcopy('label_price')}: <strong className="font-display font-semibold text-ink">{formatPrice(service.price)}</strong></span>
          </li>
        )}
        {deposit > 0 && (
          <li className="flex items-center gap-2.5">
            <ShieldCheck size={15} className="shrink-0 text-ink-faint" aria-hidden="true" />
            <span>{tcopy('label_deposit')}: {formatPrice(deposit)}</span>
          </li>
        )}
      </ul>
    </aside>
  );
}

/** Ficha del turno tal como la devuelve el plugin (appointment: serviceName, date, time, endTime, durationMin, price). */
function AppointmentCard({ appointment, status, label, className }) {
  if (!appointment) return null;
  const tone = STATUS_TONES[status] || 'neutral';
  const showPrice = TURNOS.priceVisible && Number(appointment.price) > 0;
  return (
    <div className={cn('mj-booking__appointment rounded-xl3 border border-line glass p-6', className)}>
      <div className="flex flex-wrap items-start justify-between gap-3">
        <p className="display text-xl">{appointment.serviceName}</p>
        {status && <Badge tone={tone}>{label || statusLabel(status)}</Badge>}
      </div>
      <ul className="mt-4 space-y-2.5 text-sm text-ink-soft">
        {appointment.date && (
          <li className="flex items-center gap-2.5 text-ink">
            <CalendarDays size={15} className="shrink-0 text-accent" aria-hidden="true" />
            <span className="capitalize">
              {formatDateLong(appointment.date)}
              {appointment.time && (
                <span className="tabular-nums"> · {appointment.time}{appointment.endTime && ` – ${appointment.endTime}`}</span>
              )}
            </span>
          </li>
        )}
        {Number(appointment.durationMin) > 0 && (
          <li className="flex items-center gap-2.5">
            <Clock size={15} className="shrink-0 text-ink-faint" aria-hidden="true" />
            <span>{tcopy('label_duration')}: {formatDuration(appointment.durationMin)}</span>
          </li>
        )}
        {showPrice && (
          <li className="flex items-center gap-2.5">
            <Wallet size={15} className="shrink-0 text-ink-faint" aria-hidden="true" />
            <span>{tcopy('label_price')}: <strong className="font-display font-semibold text-ink">{formatPrice(appointment.price)}</strong></span>
          </li>
        )}
      </ul>
    </div>
  );
}

/**
 * Tarjeta de la seña: monto, instrucciones, vencimiento y acciones
 * ("Ya transferí", comprobante por WhatsApp, link de pago).
 */
function DepositCard({ token, deposit, whatsapp, notified, onNotified, className }) {
  const [sending, setSending] = useState(false);
  const [error, setError] = useState('');
  if (!deposit || !deposit.required) return null;

  const deadline = deposit.deadlineLabel || deposit.deadline || '';
  const notify = async () => {
    setSending(true);
    setError('');
    try {
      await notifyDeposit(token, '');
      onNotified?.();
    } catch (err) {
      setError(err?.message || '');
    } finally {
      setSending(false);
    }
  };

  return (
    <div className={cn('mj-booking__deposit rounded-xl3 border border-accent/25 bg-accent-soft p-6', className)}>
      <div className="flex items-center gap-2.5">
        <ShieldCheck size={18} className="shrink-0 text-accent" aria-hidden="true" />
        <p className="font-display text-lg font-semibold tracking-tight">{tcopy('booking_deposit_title')}</p>
      </div>
      {Number(deposit.amount) > 0 && (
        <p className="mj-booking__deposit-amount mt-4">
          <span className="block text-xs font-medium uppercase tracking-wider text-ink-faint">{tcopy('booking_deposit_amount_label')}</span>
          <span className="display mt-1 block text-3xl">{formatPrice(deposit.amount)}</span>
        </p>
      )}
      {deposit.instructions && (
        <p className="mj-booking__deposit-instructions mt-4 whitespace-pre-line text-sm leading-relaxed text-ink-soft">{deposit.instructions}</p>
      )}
      {deadline && (
        <p className="mj-booking__deposit-deadline mt-3 text-sm text-ink-soft">
          {fillTemplate(tcopy('booking_deposit_deadline'), { fecha_limite: deadline })}
        </p>
      )}
      <div className="mj-booking__deposit-actions mt-5 flex flex-wrap gap-3">
        {notified ? (
          <p className="inline-flex items-center gap-2 text-sm font-medium text-success">
            <CheckCircle2 size={17} aria-hidden="true" />
            {tcopy('booking_notified_done')}
          </p>
        ) : (
          <Button onClick={notify} disabled={sending}>
            {sending ? <Loader2 size={15} className="animate-spin" /> : <Check size={15} />}
            {tcopy('booking_notified_button')}
          </Button>
        )}
        {whatsapp?.url && (
          <Button variant="secondary" href={whatsapp.url} target="_blank" rel="noreferrer">
            <MessageCircle size={15} />
            {tcopy('booking_whatsapp_button')}
          </Button>
        )}
        {deposit.link && (
          <Button variant="secondary" href={deposit.link} target="_blank" rel="noreferrer">
            <ExternalLink size={15} />
            {tcopy('booking_pay_link_button')}
          </Button>
        )}
      </div>
      <ErrorBox className="mt-4">{error}</ErrorBox>
    </div>
  );
}

/* --------------------------- Reserva (wizard) ---------------------------- */

const availKeyOf = (service, month) => (service ? `${service.id}:${month}` : null);

function BookingWizard() {
  const [searchParams, setSearchParams] = useSearchParams();
  const services = TURNOS.services.filter((s) => s.active !== false && s.bookable !== false);
  const fields = TURNOS.form.fields;

  // Preselección por ?servicio=slug → arranca en el paso del día.
  const [service, setService] = useState(() => {
    const pre = serviceBySlug(searchParams.get('servicio'));
    return pre && pre.active !== false && pre.bookable !== false ? pre : null;
  });
  const [step, setStep] = useState(service ? 'date' : 'service');
  const [month, setMonth] = useState(() => monthOf(todayISO()));
  const [date, setDate] = useState(null);
  const [time, setTime] = useState(null);
  const [values, setValues] = useState(() => emptyBookingValues(fields));
  const [avail, setAvail] = useState({}); // { 'serviceId:YYYY-MM': { 'YYYY-MM-DD': [slots] } }
  const [loadingAvail, setLoadingAvail] = useState(false);
  const [availError, setAvailError] = useState('');
  const [availTick, setAvailTick] = useState(0); // "Reintentar" tras un error al cargar el mes
  const [notice, setNotice] = useState(''); // 409: el horario se ocupó
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState('');
  const [fieldErrors, setFieldErrors] = useState({});
  const [result, setResult] = useState(null);
  const [notified, setNotified] = useState(false);
  const autoHops = useRef(0);
  const wrapRef = useRef(null);

  const minDate = todayISO();
  const maxDate = lastBookableISO();
  const availKey = availKeyOf(service, month);
  const days = availKey ? avail[availKey] ?? null : null;
  const slots = date && days ? days[date] ?? [] : [];

  /** Cambia de paso y lleva la vista al inicio del wizard. */
  const goTo = (next) => {
    setStep(next);
    if (typeof window === 'undefined') return;
    window.requestAnimationFrame(() => {
      wrapRef.current?.scrollIntoView({ behavior: TRANSITIONS ? 'smooth' : 'auto', block: 'start' });
    });
  };

  // Disponibilidad del mes visible (cacheada por servicio + mes).
  useEffect(() => {
    if (step !== 'date' || !service || !availKey || avail[availKey]) return undefined;
    const { from, to } = monthRange(month);
    const f = from < minDate ? minDate : from;
    const t = to > maxDate ? maxDate : to;
    if (f > t) {
      setAvail((prev) => ({ ...prev, [availKey]: {} }));
      return undefined;
    }
    let active = true;
    setLoadingAvail(true);
    setAvailError('');
    getAvailability(service.id, f, t).then(
      (res) => {
        if (!active) return;
        const map = res?.days && typeof res.days === 'object' && !Array.isArray(res.days) ? res.days : {};
        setLoadingAvail(false);
        setAvail((prev) => ({ ...prev, [availKey]: map }));
      },
      (err) => {
        if (!active) return;
        setLoadingAvail(false);
        setAvailError(err?.message || '');
      }
    );
    return () => {
      active = false;
    };
  }, [step, service, month, availKey, avail, minDate, maxDate, availTick]);

  // Mes sin días libres y sin elección todavía → avanza solo (hasta 3 meses) para no mostrar una grilla vacía.
  useEffect(() => {
    if (step !== 'date' || !days || date || loadingAvail) return;
    const hasDays = Object.values(days).some((list) => Array.isArray(list) && list.length > 0);
    if (hasDays) return;
    const next = shiftMonth(month, 1);
    if (autoHops.current < 3 && next <= monthOf(maxDate)) {
      autoHops.current += 1;
      setMonth(next);
    }
  }, [step, days, date, loadingAvail, month, maxDate]);

  const chooseService = (svc) => {
    setService(svc);
    setDate(null);
    setTime(null);
    setNotice('');
    setMonth(monthOf(todayISO()));
    autoHops.current = 0;
    // Conserva ?vista=publica (la dueña mirando la reserva como clienta).
    const next = svc ? { servicio: svc.slug } : {};
    if (searchParams.get('vista')) next.vista = searchParams.get('vista');
    setSearchParams(next, { replace: true });
    goTo('date');
  };

  const chooseDate = (iso) => {
    setDate(iso);
    setTime(null);
  };

  const chooseTime = (t) => {
    setTime(t);
    setNotice('');
  };

  const setValue = (key, value) => setValues((prev) => ({ ...prev, [key]: value }));

  /** Vuelve a pedir la disponibilidad del mes visible después de un error de red. */
  const retryAvail = () => {
    setAvailError('');
    setAvailTick((n) => n + 1);
  };

  /**
   * "Hacer otra reserva": vuelve al paso 1 con la elección y el resultado en
   * blanco (los datos de la clienta se conservan) y sin la disponibilidad
   * cacheada, que ya cambió con el turno recién tomado.
   */
  const restart = () => {
    setService(null);
    setDate(null);
    setTime(null);
    setAvail({});
    setAvailError('');
    setNotice('');
    setError('');
    setFieldErrors({});
    setResult(null);
    setNotified(false);
    setMonth(monthOf(todayISO()));
    autoHops.current = 0;
    const next = {};
    if (searchParams.get('vista')) next.vista = searchParams.get('vista');
    setSearchParams(next, { replace: true });
    goTo('service');
  };

  const onSubmit = async (e) => {
    e.preventDefault();
    if (!service || !date || !time || submitting) return;
    setSubmitting(true);
    setError('');
    setFieldErrors({});
    try {
      const res = await createBooking(bookingPayload({ service, date, time, values, fields }));
      setResult(res);
      setNotified(false);
      goTo('done');
    } catch (err) {
      if (isSlotTakenError(err)) {
        // El horario se ocupó mientras cargaba los datos: volver al paso 2 y recargar el mes.
        setNotice(err?.message || '');
        setTime(null);
        setAvail((prev) => {
          const next = { ...prev };
          delete next[availKey];
          return next;
        });
        goTo('date');
      } else {
        // El plugin manda los errores por campo en data.errors (y params, por compatibilidad con WP).
        const params = err?.data?.data?.errors ?? err?.data?.data?.params;
        if (params && typeof params === 'object') setFieldErrors(params);
        setError(err?.message || '');
      }
    } finally {
      setSubmitting(false);
    }
  };

  const monthHasDays = !!days && Object.values(days).some((list) => Array.isArray(list) && list.length > 0);
  const canContinue = !!date && !!time;

  const panel = {
    initial: TRANSITIONS ? { opacity: 0, y: 14 } : false,
    animate: { opacity: 1, y: 0 },
    exit: TRANSITIONS ? { opacity: 0, y: -10 } : undefined,
    transition: { duration: 0.3, ease: EASE },
  };

  return (
    <div ref={wrapRef} className="mj-booking mt-10 scroll-mt-28">
      <Steps current={step} onGo={goTo} />

      <AnimatePresence mode="wait" initial={false}>
        {/* ------------------------------ 1. Servicio ------------------------------ */}
        {step === 'service' && (
          <motion.div key="service" {...panel} className="mj-booking__panel mj-booking__panel--service mt-8">
            <h2 className="display text-2xl md:text-3xl">{tcopy('booking_step_service')}</h2>
            {services.length === 0 ? (
              <Reveal className="mj-empty mt-8 rounded-(--mj-radius-2xl) border border-line glass p-16 text-center">
                <Sparkles size={28} className="mx-auto text-ink-faint" aria-hidden="true" />
                <p className="mt-5 text-lg text-ink-soft">{tcopy('services_empty')}</p>
              </Reveal>
            ) : (
              <Stagger className="mt-8 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                {services.map((svc) => (
                  <motion.div key={svc.id} variants={staggerItem}>
                    <ServiceCard
                      service={svc}
                      className="mj-booking__service h-full"
                      selected={service?.id === svc.id}
                      onSelect={chooseService}
                    />
                  </motion.div>
                ))}
              </Stagger>
            )}
          </motion.div>
        )}

        {/* ---------------------------- 2. Día y hora ------------------------------ */}
        {step === 'date' && service && (
          <motion.div key="date" {...panel} className="mj-booking__panel mj-booking__panel--date mt-8 grid gap-8 lg:grid-cols-[1fr_320px]">
            <div>
              <ErrorBox className="mb-6">{notice}</ErrorBox>
              {availError && (
                <div className="mj-booking__avail-error mb-6">
                  <ErrorBox>{availError}</ErrorBox>
                  <Button size="sm" variant="secondary" className="mt-3" onClick={retryAvail}>
                    <RotateCcw size={14} />
                    {tcopy('booking_retry')}
                  </Button>
                </div>
              )}
              <div className="grid gap-8 md:grid-cols-2">
                <div>
                  <h2 className="display text-2xl">{tcopy('booking_pick_day')}</h2>
                  <div className="mt-5 rounded-xl3 border border-line glass p-5">
                    <Calendar
                      month={month}
                      onMonthChange={(m) => {
                        autoHops.current = 3;
                        setMonth(m);
                      }}
                      days={days || {}}
                      selected={date}
                      onSelect={chooseDate}
                      weekStart={TURNOS.schedule.weekStart}
                      minDate={minDate}
                      maxDate={maxDate}
                      loading={loadingAvail}
                    />
                  </div>
                  {!loadingAvail && days && !monthHasDays && (
                    <p className="mj-booking__no-days mt-3 text-sm text-ink-soft">{tcopy('booking_no_days')}</p>
                  )}
                </div>
                <div>
                  <h2 className="display text-2xl">{tcopy('booking_pick_time')}</h2>
                  {date ? (
                    <>
                      <p className="mt-2 text-sm capitalize text-ink-soft">{formatDateLong(date)}</p>
                      <SlotPicker
                        className="mt-5"
                        slots={slots}
                        value={time}
                        onChange={chooseTime}
                        disabled={loadingAvail}
                        emptyText={loadingAvail ? '' : tcopy('booking_no_slots')}
                      />
                    </>
                  ) : (
                    <div className="mj-booking__slots-placeholder mt-5 grid min-h-40 place-items-center rounded-xl2 border border-dashed border-line-strong p-6 text-center text-sm text-ink-faint">
                      <span className="inline-flex items-center gap-2">
                        <CalendarDays size={16} aria-hidden="true" />
                        {tcopy('booking_pick_day')}
                      </span>
                    </div>
                  )}
                </div>
              </div>
              <div className="mj-booking__actions mt-8 flex flex-wrap items-center gap-3">
                <Button variant="secondary" onClick={() => goTo('service')}>
                  <ArrowLeft size={15} />
                  {tcopy('booking_back')}
                </Button>
                <Button disabled={!canContinue} onClick={() => goTo('details')}>
                  {tcopy('booking_next')}
                  <ArrowRight size={15} />
                </Button>
              </div>
            </div>
            <Summary service={service} date={date} time={time} className="order-first lg:order-none lg:sticky lg:top-28 lg:self-start" />
          </motion.div>
        )}

        {/* ----------------------------- 3. Tus datos ------------------------------ */}
        {step === 'details' && service && (
          <motion.div key="details" {...panel} className="mj-booking__panel mj-booking__panel--details mt-8">
            <form onSubmit={onSubmit} className="grid gap-8 lg:grid-cols-[1fr_320px]">
              <div>
                <h2 className="display text-2xl md:text-3xl">{tcopy('booking_step_details')}</h2>
                <BookingForm
                  className="mt-6"
                  fields={fields}
                  values={values}
                  onChange={setValue}
                  errors={fieldErrors}
                  policy={TURNOS.form.policy}
                  disabled={submitting}
                />
                <ErrorBox className="mt-6">{error}</ErrorBox>
                <div className="mj-booking__actions mt-8 flex flex-wrap items-center gap-3">
                  <Button type="button" variant="secondary" onClick={() => goTo('date')} disabled={submitting}>
                    <ArrowLeft size={15} />
                    {tcopy('booking_back')}
                  </Button>
                  <Button type="submit" disabled={submitting}>
                    {submitting ? <Loader2 size={15} className="animate-spin" /> : <Check size={15} />}
                    {tcopy('booking_submit')}
                  </Button>
                </div>
              </div>
              <Summary service={service} date={date} time={time} className="order-first lg:order-none lg:sticky lg:top-28 lg:self-start" />
            </form>
          </motion.div>
        )}

        {/* -------------------------------- 4. Listo ------------------------------- */}
        {step === 'done' && result && (
          <motion.div key="done" {...panel} className="mj-booking__panel mj-booking__panel--done mt-8">
            <div className="mj-booking__result mx-auto max-w-2xl">
              <div className="text-center">
                <CheckCircle2 size={40} className="mx-auto text-success" aria-hidden="true" />
                <h2 className="display mt-5 text-3xl md:text-4xl">{tcopy('booking_success_title')}</h2>
                <p className="mx-auto mt-3 max-w-lg text-lg text-ink-soft">{successText(result.status, result.message)}</p>
              </div>
              <AppointmentCard className="mt-8" appointment={result.appointment} status={result.status} label={result.statusLabel} />
              {result.status === 'awaiting_deposit' && (
                <DepositCard
                  className="mt-5"
                  token={result.token}
                  deposit={result.deposit}
                  whatsapp={result.whatsapp}
                  notified={notified}
                  onNotified={() => setNotified(true)}
                />
              )}
              <div className="mj-booking__result-actions mt-8 flex flex-wrap justify-center gap-3">
                {result.token && (
                  <Button variant="secondary" to={statusPath(result.token)}>
                    {tcopy('booking_status_link')}
                    <ArrowRight size={15} />
                  </Button>
                )}
                <Button variant="ghost" onClick={restart}>
                  <RotateCcw size={15} />
                  {tcopy('booking_new_booking')}
                </Button>
              </div>
            </div>
          </motion.div>
        )}
      </AnimatePresence>
    </div>
  );
}

/** Reserva pública (/{slug}). */
export function BookingPage() {
  usePageMeta(TURNOS ? tcopy('booking_title') : '');
  if (!TURNOS) return <NotFound />;
  return (
    <Section className="mj-booking-page md:py-16">
      <OwnerBanner />
      <PageHeader eyebrow={tcopy('booking_eyebrow')} title={tcopy('booking_title')} subtitle={tcopy('booking_subtitle')} />
      <BookingWizard />
      <LoginHint className="mt-12" />
    </Section>
  );
}

/* ----------------------------- Estado del turno -------------------------- */

const CANCELLABLE = ['pending', 'awaiting_deposit', 'confirmed'];

/** El token no existe (404): aviso amable con salida a una reserva nueva y al WhatsApp del negocio. */
function StatusNotFound() {
  const whatsappNumber = TURNOS.business.whatsapp;
  return (
    <div className="mj-booking__not-found mt-10 max-w-2xl rounded-xl3 border border-line glass p-8 text-center">
      <AlertCircle size={32} className="mx-auto text-ink-faint" aria-hidden="true" />
      <p className="mt-4 text-lg text-ink-soft">{tcopy('booking_status_not_found')}</p>
      <div className="mt-6 flex flex-wrap justify-center gap-3">
        <Button to={bookingPath()}>
          {tcopy('booking_new_booking')}
          <ArrowRight size={15} />
        </Button>
        {whatsappNumber && (
          <Button variant="secondary" href={`https://wa.me/${whatsappNumber}`} target="_blank" rel="noreferrer">
            <MessageCircle size={15} />
            {tcopy('booking_whatsapp_label')}
          </Button>
        )}
      </div>
    </div>
  );
}

function StatusView({ token }) {
  const [data, setData] = useState(null);
  const [error, setError] = useState(null);
  const [loading, setLoading] = useState(true);
  const [confirming, setConfirming] = useState(false);
  const [cancelling, setCancelling] = useState(false);
  const [cancelError, setCancelError] = useState('');
  const [tick, setTick] = useState(0); // "Reintentar" tras un error de carga

  useEffect(() => {
    let active = true;
    setLoading(true);
    setError(null);
    setData(null);
    setConfirming(false);
    setCancelError('');
    getBooking(token)
      .then((res) => {
        if (active) setData(res);
      })
      .catch((err) => {
        if (active) setError(err);
      })
      .finally(() => {
        if (active) setLoading(false);
      });
    return () => {
      active = false;
    };
  }, [token, tick]);

  if (loading) {
    return (
      <div className="grid min-h-40 place-items-center" aria-busy="true">
        <Loader2 size={24} className="animate-spin text-ink-faint" aria-hidden="true" />
      </div>
    );
  }
  if (error?.status === 404) return <StatusNotFound />;
  if (error || !data) {
    return (
      <div className="mt-10 max-w-2xl">
        <ErrorBox>{error?.message || String(error?.code || '')}</ErrorBox>
        <Button variant="secondary" className="mt-4" onClick={() => setTick((n) => n + 1)}>
          <RotateCcw size={15} />
          {tcopy('booking_retry')}
        </Button>
      </div>
    );
  }

  const { status } = data;
  const label = data.statusLabel || statusLabel(status);
  const cancellable = CANCELLABLE.includes(status);
  const whatsappNumber = TURNOS.business.whatsapp;

  const doCancel = async () => {
    setCancelling(true);
    setCancelError('');
    try {
      const res = await cancelBooking(token);
      setData((prev) => ({
        ...prev,
        status: res?.status || 'cancelled',
        statusLabel: res?.statusLabel || statusLabel(res?.status || 'cancelled'),
        canCancel: false,
      }));
      setConfirming(false);
    } catch (err) {
      setCancelError(err?.message || tcopy('booking_cannot_cancel'));
    } finally {
      setCancelling(false);
    }
  };

  return (
    <div className="mj-booking__status mt-10 max-w-2xl">
      <Reveal>
        <AppointmentCard appointment={data.appointment} status={status} label={label} />
      </Reveal>

      {status === 'cancelled' && (
        <Reveal className="mt-5 flex items-start gap-3 rounded-xl2 border border-line glass p-5 text-sm text-ink-soft">
          <XCircle size={18} className="mt-0.5 shrink-0 text-danger" aria-hidden="true" />
          <p>{tcopy('booking_cancelled_text')}</p>
        </Reveal>
      )}

      {status === 'awaiting_deposit' && (
        <Reveal className="mt-5">
          <DepositCard
            token={token}
            deposit={data.deposit}
            whatsapp={data.whatsapp}
            notified={!!data.depositNotified}
            onNotified={() => setData((prev) => ({ ...prev, depositNotified: true }))}
          />
        </Reveal>
      )}

      {cancellable && (
        <Reveal className="mj-booking__cancel mt-8">
          {data.canCancel ? (
            confirming ? (
              <div className="rounded-xl2 border border-danger/30 bg-danger/10 p-5">
                <p className="text-sm font-medium text-ink">{tcopy('booking_cancel_confirm')}</p>
                <div className="mt-4 flex flex-wrap gap-3">
                  <Button variant="secondary" onClick={() => setConfirming(false)} disabled={cancelling}>
                    <ArrowLeft size={15} />
                    {tcopy('booking_back')}
                  </Button>
                  <Button variant="inverse" onClick={doCancel} disabled={cancelling}>
                    {cancelling ? <Loader2 size={15} className="animate-spin" /> : <XCircle size={15} />}
                    {tcopy('booking_cancel_button')}
                  </Button>
                </div>
                <ErrorBox className="mt-4">{cancelError}</ErrorBox>
              </div>
            ) : (
              <Button variant="ghost" onClick={() => setConfirming(true)}>
                <XCircle size={15} />
                {tcopy('booking_cancel_button')}
              </Button>
            )
          ) : (
            <div className="flex flex-wrap items-center justify-between gap-4 rounded-xl2 border border-line glass p-5 text-sm text-ink-soft">
              <p>{tcopy('booking_cannot_cancel')}</p>
              {whatsappNumber && (
                <Button size="sm" variant="secondary" href={`https://wa.me/${whatsappNumber}`} target="_blank" rel="noreferrer">
                  <MessageCircle size={14} />
                  {tcopy('booking_whatsapp_label')}
                </Button>
              )}
            </div>
          )}
        </Reveal>
      )}
    </div>
  );
}

/** Estado de una reserva (/{slug}/t/:token) — siempre pública. */
export function BookingStatusPage() {
  const { token } = useParams();
  usePageMeta(TURNOS ? tcopy('booking_status_title') : '');
  if (!TURNOS || !token) return <NotFound />;
  return (
    <Section className="mj-booking-page mj-booking-page--status md:py-16">
      <OwnerBanner />
      <PageHeader eyebrow={tcopy('booking_eyebrow')} title={tcopy('booking_status_title')} />
      <StatusView token={token} />
      <LoginHint className="mt-12" />
    </Section>
  );
}
