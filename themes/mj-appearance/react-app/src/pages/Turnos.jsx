import { useCallback, useEffect, useRef, useState } from 'react';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { motion, AnimatePresence } from 'framer-motion';
import {
  AlertCircle, ArrowLeft, ArrowRight, CalendarClock, CalendarDays, Check, CheckCircle2, Clock, ExternalLink, Info,
  LayoutDashboard, ListChecks, Loader2, LockKeyhole, MessageCircle, RotateCcw, Send, ShieldCheck, Sparkles, Star,
  Wallet, XCircle,
} from 'lucide-react';
import { Section } from '@/components/layout/Layout';
import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import { Input, Textarea } from '@/components/ui/Input';
import { ReceiptUploader } from '@/components/ui/ReceiptUploader';
import { Reveal, Stagger, staggerItem } from '@/components/motion/Reveal';
import { ServiceCard, ServiceGroupHeading, groupHasHeading } from '@/components/turnos/ServiceCard';
import { Calendar, monthOf } from '@/components/turnos/Calendar';
import { SlotPicker } from '@/components/turnos/SlotPicker';
import { useMonthAvailability } from '@/components/turnos/useMonthAvailability';
import {
  BookingForm, emptyBookingValues, bookingPayload, bookingAccountState, prefillBookingValues,
} from '@/components/turnos/BookingForm';
import { PageHeader } from '@/pages/Servicios';
import { NotFound } from '@/pages/Misc';
import { useAuth } from '@/store/auth';
import { TRANSITIONS } from '@/lib/motion';
import { EASE, formatPrice, cn } from '@/lib/format';
import { usePageMeta } from '@/lib/seo';
import {
  TURNOS, tcopy, statusLabel, bookingPath, statusPath, bookingLoginPath, serviceBySlug, serviceBookingPath, serviceDeposit,
  formatDuration, fillTemplate, todayISO, lastBookableISO, formatDateLong, groupServicesByCategory,
  getAvailability, createBooking, getBooking, cancelBooking, notifyDeposit, isSlotTakenError,
  getMe, rescheduleBooking, chooseOffer, submitReview,
} from '@/lib/turnos';

/**
 * Reserva de turnos (plugin MJ Turnos Online):
 *  - BookingPage (/{slug}): wizard de 4 pasos — servicio → día y hora → datos → listo.
 *  - BookingStatusPage (/{slug}/t/:token): estado de una reserva, seña (con el
 *    comprobante de MJ Medios de Pago), cancelación, reprogramado, las opciones
 *    cuando cancela la dueña y la opinión de un turno atendido.
 * Todos los textos vienen del plugin (tcopy); los precios, de formatPrice.
 * Lo que agregó el plugin 1.3 (cuentas, reprogramar, saldo a favor, opiniones,
 * categorías) aparece solo si el dato lo habilita: con los ajustes de siempre
 * la reserva se ve igual que antes.
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
 * Tarjeta de la seña: monto (lo que falta transferir), saldo a favor usado,
 * instrucciones, vencimiento, acciones ("Ya transferí", comprobante por
 * WhatsApp, link de pago) y el comprobante de MJ Medios de Pago si el turno lo pide.
 */
function DepositCard({ token, deposit, whatsapp, notified, onNotified, receipt, className }) {
  const [sending, setSending] = useState(false);
  const [error, setError] = useState('');
  if (!deposit || !deposit.required) return null;

  const deadline = deposit.deadlineLabel || deposit.deadline || '';
  const creditApplied = Number(deposit.creditApplied) || 0;
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
          <span className="block text-xs font-medium uppercase tracking-wider text-ink-faint">
            {tcopy(creditApplied > 0 ? 'booking_deposit_to_pay' : 'booking_deposit_amount_label')}
          </span>
          <span className="display mt-1 block text-3xl">{formatPrice(deposit.amount)}</span>
        </p>
      )}
      {creditApplied > 0 && (
        <p className="mj-booking__deposit-credit mt-2 inline-flex items-center gap-2 text-sm text-success">
          <Wallet size={15} className="shrink-0" aria-hidden="true" />
          {fillTemplate(tcopy('booking_credit_applied'), { monto: formatPrice(creditApplied) })}
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
      {/* Comprobante de la transferencia: el widget es de MJ Medios de Pago (sin ese plugin no se dibuja). */}
      {receipt?.objectId && (
        <ReceiptUploader
          className="mj-booking__receipt mt-5"
          context={receipt.context || 'turno'}
          objectId={receipt.objectId}
          token={receipt.token || ''}
          title={tcopy('booking_receipt_title')}
        />
      )}
    </div>
  );
}

/* --------------------------- Reserva (wizard) ---------------------------- */

const availKeyOf = (service, month) => (service ? `${service.id}:${month}` : null);

/** Grilla de tarjetas de servicio (paso 1), agrupada por categoría si corresponde. */
function ServicePicker({ services, selected, onSelect }) {
  const groups = groupServicesByCategory(services);
  const grid = (list, spacing = 'mt-8') => (
    <Stagger className={cn(spacing, 'grid gap-5 sm:grid-cols-2 lg:grid-cols-3')}>
      {list.map((svc) => (
        <motion.div key={svc.id} variants={staggerItem}>
          <ServiceCard service={svc} className="mj-booking__service h-full" selected={selected?.id === svc.id} onSelect={onSelect} />
        </motion.div>
      ))}
    </Stagger>
  );
  if (!groups) return grid(services);
  return groups.map(({ category, services: list }) => (
    <section key={category ? category.id : 'sin-categoria'} className="mj-booking__service-group mt-8">
      <ServiceGroupHeading category={category} as="h3" small />
      {grid(list, groupHasHeading(category) ? 'mt-5' : 'mt-0')}
    </section>
  ));
}

/**
 * Calendario + horarios del día elegido (paso 2 del wizard y reprogramado).
 * La disponibilidad la maneja quien lo usa (useMonthAvailability).
 */
function DateTimePicker({ month, onMonthChange, days, date, onDate, time, onTime, slots, loading, monthHasDays, minDate, maxDate }) {
  return (
    <div className="mj-booking__datetime grid gap-8 md:grid-cols-2">
      <div>
        <h2 className="display text-2xl">{tcopy('booking_pick_day')}</h2>
        <div className="mt-5 rounded-xl3 border border-line glass p-5">
          <Calendar
            month={month}
            onMonthChange={onMonthChange}
            days={days || {}}
            selected={date}
            onSelect={onDate}
            weekStart={TURNOS.schedule.weekStart}
            minDate={minDate}
            maxDate={maxDate}
            loading={loading}
          />
        </div>
        {!loading && days && !monthHasDays && (
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
              onChange={onTime}
              disabled={loading}
              emptyText={loading ? '' : tcopy('booking_no_slots')}
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
  );
}

function BookingWizard() {
  const [searchParams, setSearchParams] = useSearchParams();
  const services = TURNOS.services.filter((s) => s.active !== false && s.bookable !== false);
  const fields = TURNOS.form.fields;
  const authUser = useAuth((s) => s.user);
  const account = bookingAccountState();

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
  const [accountName, setAccountName] = useState(() => (account === 'logged' ? authUser?.name || '' : ''));
  const [notice, setNotice] = useState(''); // 409: el horario se ocupó
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState('');
  const [loginOffer, setLoginOffer] = useState(false); // 409 mjto_account_exists: ofrecer el login
  const [fieldErrors, setFieldErrors] = useState({});
  const [result, setResult] = useState(null);
  const [notified, setNotified] = useState(false);
  const wrapRef = useRef(null);

  const minDate = todayISO();
  const maxDate = lastBookableISO();
  const availKey = availKeyOf(service, month);
  const loadMonth = useCallback((from, to) => getAvailability(service?.id, from, to), [service]);
  const {
    days, loading: loadingAvail, error: availError, retry: retryAvail, invalidate, clear: clearAvail,
    resetHops, stopHops, monthHasDays,
  } = useMonthAvailability({
    active: step === 'date' && !!service,
    cacheKey: availKey,
    month,
    setMonth,
    load: loadMonth,
    minDate,
    maxDate,
    hasSelection: !!date,
  });
  const slots = date && days ? days[date] ?? [] : [];

  // Con sesión (y la precarga prendida): el formulario arranca con sus datos guardados (GET /me).
  useEffect(() => {
    if (account !== 'logged' || !TURNOS.account.prefill) return undefined;
    let live = true;
    getMe()
      .then((res) => {
        if (!live || !res) return;
        if (res.user?.name) setAccountName(res.user.name);
        if (res.client) setValues((prev) => prefillBookingValues(prev, res.client, fields));
      })
      .catch(() => {
        /* Sin precarga no pasa nada: carga sus datos a mano como siempre. */
      });
    return () => {
      live = false;
    };
  }, [account, fields]);

  /** Cambia de paso y lleva la vista al inicio del wizard. */
  const goTo = (next) => {
    setStep(next);
    if (typeof window === 'undefined') return;
    window.requestAnimationFrame(() => {
      wrapRef.current?.scrollIntoView({ behavior: TRANSITIONS ? 'smooth' : 'auto', block: 'start' });
    });
  };

  const chooseService = (svc) => {
    setService(svc);
    setDate(null);
    setTime(null);
    setNotice('');
    setMonth(monthOf(todayISO()));
    resetHops();
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

  /**
   * "Hacer otra reserva": vuelve al paso 1 con la elección y el resultado en
   * blanco (los datos de la clienta se conservan) y sin la disponibilidad
   * cacheada, que ya cambió con el turno recién tomado.
   */
  const restart = () => {
    setService(null);
    setDate(null);
    setTime(null);
    clearAvail();
    setNotice('');
    setError('');
    setLoginOffer(false);
    setFieldErrors({});
    setResult(null);
    setNotified(false);
    setMonth(monthOf(todayISO()));
    resetHops();
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
    setLoginOffer(false);
    setFieldErrors({});
    try {
      const res = await createBooking(bookingPayload({ service, date, time, values, fields }));
      setResult(res);
      setNotified(false);
      goTo('done');
    } catch (err) {
      if (isSlotTakenError(err) && err?.code !== 'mjto_account_exists') {
        // El horario se ocupó mientras cargaba los datos: volver al paso 2 y recargar el mes.
        setNotice(err?.message || '');
        setTime(null);
        invalidate(availKey);
        goTo('date');
      } else {
        // El plugin manda los errores por campo en data.errors (y params, por compatibilidad con WP).
        const params = err?.data?.data?.errors ?? err?.data?.data?.params;
        if (params && typeof params === 'object') setFieldErrors(params);
        setError(err?.message || '');
        // Ya hay una cuenta con ese email: ofrecer el login con vuelta a la reserva.
        if (err?.code === 'mjto_account_exists' || err?.data?.data?.login) setLoginOffer(true);
      }
    } finally {
      setSubmitting(false);
    }
  };

  const canContinue = !!date && !!time;
  const loginBack = service ? bookingLoginPath(serviceBookingPath(service.slug)) : bookingLoginPath();

  const panel = {
    initial: TRANSITIONS ? { opacity: 0, y: 14 } : false,
    animate: { opacity: 1, y: 0 },
    exit: TRANSITIONS ? { opacity: 0, y: -10 } : undefined,
    transition: { duration: 0.3, ease: EASE },
  };

  /*
   * Si la reserva acaba de crear la cuenta, la sesión nueva recién está en la
   * cookie: el estado del turno y "Mis turnos" se abren con carga completa
   * para que la página tenga el nonce de esa sesión.
   */
  const fresh = !!result?.accountCreated;
  const myBookings = result?.myBookingsUrl || '';

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
              <ServicePicker services={services} selected={service} onSelect={chooseService} />
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
              <DateTimePicker
                month={month}
                onMonthChange={(m) => {
                  stopHops();
                  setMonth(m);
                }}
                days={days}
                date={date}
                onDate={chooseDate}
                time={time}
                onTime={chooseTime}
                slots={slots}
                loading={loadingAvail}
                monthHasDays={monthHasDays}
                minDate={minDate}
                maxDate={maxDate}
              />
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
                  accountName={accountName}
                  loginPath={loginBack}
                />
                {error && (
                  <div className="mt-6">
                    <ErrorBox>{error}</ErrorBox>
                    {loginOffer && (
                      <p className="mt-3">
                        <Link to={loginBack} className="inline-flex items-center gap-1.5 text-sm font-medium text-accent hover:underline">
                          <LockKeyhole size={14} aria-hidden="true" />
                          {tcopy('booking_account_login')}
                        </Link>
                      </p>
                    )}
                  </div>
                )}
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
                  receipt={result.receipt}
                />
              )}
              <div className="mj-booking__result-actions mt-8 flex flex-wrap justify-center gap-3">
                {result.token &&
                  (fresh ? (
                    <Button variant="secondary" href={statusPath(result.token)}>
                      {tcopy('booking_status_link')}
                      <ArrowRight size={15} />
                    </Button>
                  ) : (
                    <Button variant="secondary" to={statusPath(result.token)}>
                      {tcopy('booking_status_link')}
                      <ArrowRight size={15} />
                    </Button>
                  ))}
                {myBookings && (
                  <Button variant="secondary" href={myBookings}>
                    <ListChecks size={15} />
                    {tcopy('my_bookings_title')}
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

/* Aviso informativo (reglas de cancelación / reprogramado, resultado de una acción). */
function InfoBox({ children, tone = 'info', className }) {
  if (!children) return null;
  const ok = tone === 'success';
  return (
    <div
      className={cn(
        'mj-booking__info flex items-start gap-3 rounded-xl2 border p-4 text-sm',
        ok ? 'border-success/25 bg-success/10 text-success' : 'border-accent/20 bg-accent-soft text-ink-soft',
        className
      )}
    >
      {ok ? (
        <CheckCircle2 size={17} className="mt-0.5 shrink-0" aria-hidden="true" />
      ) : (
        <Info size={17} className="mt-0.5 shrink-0 text-accent" aria-hidden="true" />
      )}
      <p className="whitespace-pre-line leading-relaxed">{children}</p>
    </div>
  );
}

/**
 * Reprogramar un turno: calendario + horarios con la disponibilidad para ese
 * turno (GET /availability?token=…, usa su duración y no cuenta su propio
 * horario) y POST /bookings/{token}/reschedule. Antes de confirmar muestra
 * rescheduleTerms.text (qué pasa con la seña).
 * onDone(vista) con la vista que devuelve el servidor (puede traer OTRO token).
 */
function RescheduleBox({ token, terms, onDone, onClose }) {
  const [month, setMonth] = useState(() => monthOf(todayISO()));
  const [date, setDate] = useState(null);
  const [time, setTime] = useState(null);
  const [notice, setNotice] = useState('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const boxRef = useRef(null);

  const minDate = todayISO();
  const maxDate = lastBookableISO();
  const cacheKey = `${token}:${month}`;
  const loadMonth = useCallback((from, to) => getAvailability(0, from, to, token), [token]);
  const { days, loading, error: availError, retry, invalidate, stopHops, monthHasDays } = useMonthAvailability({
    active: true,
    cacheKey,
    month,
    setMonth,
    load: loadMonth,
    minDate,
    maxDate,
    hasSelection: !!date,
  });
  const slots = date && days ? days[date] ?? [] : [];

  // Al abrirlo, la vista baja hasta el calendario (en el celular queda lejos del botón).
  useEffect(() => {
    if (typeof window === 'undefined') return;
    window.requestAnimationFrame(() => {
      boxRef.current?.scrollIntoView({ behavior: TRANSITIONS ? 'smooth' : 'auto', block: 'start' });
    });
  }, []);

  const confirm = async () => {
    if (!date || !time || saving) return;
    setSaving(true);
    setError('');
    setNotice('');
    try {
      const res = await rescheduleBooking(token, date, time);
      onDone?.(res);
    } catch (err) {
      if (err?.code === 'mjto_slot_taken' || err?.status === 409) {
        // El horario se ocupó: recargar el mes y elegir otro.
        setNotice(err?.message || '');
        setTime(null);
        invalidate(cacheKey);
      } else {
        setError(err?.message || '');
      }
    } finally {
      setSaving(false);
    }
  };

  return (
    <div ref={boxRef} className="mj-booking__reschedule scroll-mt-28 rounded-xl3 border border-line glass p-5 md:p-6">
      <p className="flex items-center gap-2.5 font-display text-lg font-semibold tracking-tight">
        <CalendarClock size={18} className="shrink-0 text-accent" aria-hidden="true" />
        {tcopy('booking_reschedule_title')}
      </p>
      <InfoBox className="mt-4">{terms?.text}</InfoBox>
      <ErrorBox className="mt-4">{notice}</ErrorBox>
      {availError && (
        <div className="mt-4">
          <ErrorBox>{availError}</ErrorBox>
          <Button size="sm" variant="secondary" className="mt-3" onClick={retry}>
            <RotateCcw size={14} />
            {tcopy('booking_retry')}
          </Button>
        </div>
      )}
      <div className="mt-6">
        <DateTimePicker
          month={month}
          onMonthChange={(m) => {
            stopHops();
            setMonth(m);
          }}
          days={days}
          date={date}
          onDate={(iso) => {
            setDate(iso);
            setTime(null);
          }}
          time={time}
          onTime={(t) => {
            setTime(t);
            setNotice('');
          }}
          slots={slots}
          loading={loading}
          monthHasDays={monthHasDays}
          minDate={minDate}
          maxDate={maxDate}
        />
      </div>
      <ErrorBox className="mt-6">{error}</ErrorBox>
      <div className="mj-booking__actions mt-6 flex flex-wrap items-center gap-3">
        <Button variant="secondary" onClick={onClose} disabled={saving}>
          <ArrowLeft size={15} />
          {tcopy('booking_back')}
        </Button>
        <Button onClick={confirm} disabled={!date || !time || saving}>
          {saving ? <Loader2 size={15} className="animate-spin" /> : <Check size={15} />}
          {tcopy('booking_reschedule_confirm')}
        </Button>
      </div>
    </div>
  );
}

/** Qué pasó con la seña después de la cancelación de la dueña (offer.state). */
const OFFER_DONE_TEXT = {
  refund_requested: 'booking_offer_refund_done',
  credited: 'booking_offer_credit_done',
  refunded: 'booking_offer_refunded',
};

/**
 * La dueña canceló y la clienta elige qué hacer con la seña: otro día (abre el
 * reprogramado), devolución o saldo a favor (POST /choice).
 */
function OfferBox({ token, offer, onChosen, onReschedule }) {
  const [busy, setBusy] = useState('');
  const [error, setError] = useState('');
  const labels = { reschedule: 'booking_offer_reschedule', refund: 'booking_offer_refund', credit: 'booking_offer_credit' };

  const choose = async (choice) => {
    if (choice === 'reschedule') {
      onReschedule?.();
      return;
    }
    setBusy(choice);
    setError('');
    try {
      const res = await chooseOffer(token, choice);
      onChosen?.(res);
    } catch (err) {
      setError(err?.message || '');
    } finally {
      setBusy('');
    }
  };

  return (
    <div className="mj-booking__offer rounded-xl3 border border-accent/25 bg-accent-soft p-5 md:p-6">
      <p className="font-display text-lg font-semibold tracking-tight">{tcopy('booking_offer_title')}</p>
      <p className="mt-2 text-sm leading-relaxed text-ink-soft">{tcopy('booking_offer_text')}</p>
      {Number(offer.amount) > 0 && (
        <p className="mt-3 text-sm text-ink">
          {tcopy('label_deposit')}: <strong className="font-display font-semibold">{formatPrice(offer.amount)}</strong>
        </p>
      )}
      <div className="mt-5 flex flex-col gap-3 sm:flex-row sm:flex-wrap">
        {offer.choices.map((choice, i) => (
          <Button
            key={choice}
            variant={i === 0 ? 'primary' : 'secondary'}
            onClick={() => choose(choice)}
            disabled={!!busy}
            className="w-full sm:w-auto"
          >
            {busy === choice && <Loader2 size={15} className="animate-spin" />}
            {tcopy(labels[choice])}
          </Button>
        ))}
      </div>
      <ErrorBox className="mt-4">{error}</ErrorBox>
    </div>
  );
}

/* Estrellas de solo lectura (lo que dejó la clienta). */
function Stars({ value, size = 16 }) {
  return (
    <span className="mj-booking__stars inline-flex gap-0.5" aria-label={`${value}/5`}>
      {[1, 2, 3, 4, 5].map((n) => (
        <Star key={n} size={size} aria-hidden="true" className={n <= value ? 'fill-(--mj-star) text-(--mj-star)' : 'text-line-strong'} />
      ))}
    </span>
  );
}

/**
 * Opinión de un turno atendido (review.can): estrellas 1–5, texto y el nombre
 * como quiere que aparezca. Con review.done muestra lo que dejó.
 */
function ReviewBox({ token, review, onSent }) {
  const [rating, setRating] = useState(0);
  const [body, setBody] = useState('');
  const [name, setName] = useState(review?.name || '');
  const [sending, setSending] = useState(false);
  const [error, setError] = useState('');
  const [message, setMessage] = useState('');

  if (review?.done) {
    return (
      <div className="mj-booking__review rounded-xl3 border border-line glass p-5 md:p-6">
        <p className="font-display text-lg font-semibold tracking-tight">{tcopy('review_title')}</p>
        {review.rating > 0 && <Stars value={review.rating} size={18} />}
        {review.body && <p className="mt-3 whitespace-pre-line text-sm leading-relaxed text-ink-soft">{review.body}</p>}
        <InfoBox tone="success" className="mt-4">
          {message || tcopy(review.status === 'approved' ? 'review_thanks' : 'review_thanks_pending')}
        </InfoBox>
      </div>
    );
  }

  const submit = async (e) => {
    e.preventDefault();
    if (!rating || sending) return;
    setSending(true);
    setError('');
    try {
      const res = await submitReview(token, { rating, body: body.trim(), name: name.trim() });
      setMessage(res?.message || '');
      onSent?.({ ...review, can: false, done: true, status: res?.status || 'pending', rating, body: body.trim() });
    } catch (err) {
      setError(err?.message || '');
    } finally {
      setSending(false);
    }
  };

  return (
    <form onSubmit={submit} className="mj-booking__review rounded-xl3 border border-line glass p-5 md:p-6">
      <p className="font-display text-lg font-semibold tracking-tight">{tcopy('review_title')}</p>
      {tcopy('review_text') && <p className="mt-2 text-sm leading-relaxed text-ink-soft">{tcopy('review_text')}</p>}
      <fieldset className="mt-5">
        <legend className="mj-field__label mb-1.5 block text-xs font-medium uppercase tracking-wider text-ink-faint">
          {tcopy('review_rating_label')} *
        </legend>
        <div className="flex gap-1" role="radiogroup">
          {[1, 2, 3, 4, 5].map((n) => (
            <button
              key={n}
              type="button"
              role="radio"
              aria-checked={rating === n}
              aria-label={`${n}/5`}
              disabled={sending}
              onClick={() => setRating(n)}
              className="mj-booking__star grid size-11 place-items-center rounded-full transition-colors hover:bg-surface-2"
            >
              <Star size={26} aria-hidden="true" className={n <= rating ? 'fill-(--mj-star) text-(--mj-star)' : 'text-line-strong'} />
            </button>
          ))}
        </div>
      </fieldset>
      <div className="mt-5 grid gap-4">
        <Textarea
          id="mjto-review-body"
          name="body"
          label={tcopy('review_body_label')}
          value={body}
          maxLength={2000}
          disabled={sending}
          onChange={(e) => setBody(e.target.value)}
          className="min-h-28"
        />
        <Input
          id="mjto-review-name"
          name="name"
          label={tcopy('review_name_label')}
          value={name}
          maxLength={120}
          autoComplete="name"
          disabled={sending}
          onChange={(e) => setName(e.target.value)}
        />
      </div>
      <ErrorBox className="mt-4">{error}</ErrorBox>
      <Button type="submit" className="mt-5" disabled={!rating || sending}>
        {sending ? <Loader2 size={15} className="animate-spin" /> : <Send size={15} />}
        {tcopy('review_submit')}
      </Button>
    </form>
  );
}

function StatusView({ token }) {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const [data, setData] = useState(null);
  const [error, setError] = useState(null);
  const [loading, setLoading] = useState(true);
  const [confirming, setConfirming] = useState(false);
  const [cancelling, setCancelling] = useState(false);
  const [cancelError, setCancelError] = useState('');
  const [rescheduling, setRescheduling] = useState(false);
  const [done, setDone] = useState(''); // "¡Listo! Tu turno quedó para…"
  const [tick, setTick] = useState(0); // "Reintentar" tras un error de carga
  const scrolled = useRef(false);

  useEffect(() => {
    let active = true;
    setLoading(true);
    setError(null);
    setData(null);
    setConfirming(false);
    setCancelError('');
    setRescheduling(false);
    setDone('');
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

  // Link del mail de opinión (?opinar=1#opinion): bajar hasta el formulario cuando ya está en pantalla.
  const wantsReview = searchParams.get('opinar') === '1';
  const hasReview = !!(data?.review?.can || data?.review?.done);
  useEffect(() => {
    if (!wantsReview || !hasReview || scrolled.current || typeof window === 'undefined') return;
    scrolled.current = true;
    window.requestAnimationFrame(() => {
      document.getElementById('opinion')?.scrollIntoView({ behavior: TRANSITIONS ? 'smooth' : 'auto', block: 'start' });
    });
  }, [wantsReview, hasReview]);

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
  const offer = data.offer && typeof data.offer === 'object' ? data.offer : null;
  const offerOpen = !!offer?.open && Array.isArray(offer.choices) && offer.choices.length > 0;
  const offerDone = offer && !offer.open ? OFFER_DONE_TEXT[offer.state] : '';
  const moveTerms = data.rescheduleTerms && typeof data.rescheduleTerms === 'object' ? data.rescheduleTerms : null;
  const cancelTermsText = String(data.cancelTerms?.text || '').trim();
  const myBookings = String(data.myBookingsUrl || '');

  const doCancel = async () => {
    setCancelling(true);
    setCancelError('');
    try {
      const res = await cancelBooking(token);
      if (res?.booking && typeof res.booking === 'object') {
        // El plugin 1.3 devuelve la vista completa (con lo que pasó con la seña).
        setData(res.booking);
      } else {
        setData((prev) => ({
          ...prev,
          status: res?.status || 'cancelled',
          statusLabel: res?.statusLabel || statusLabel(res?.status || 'cancelled'),
          canCancel: false,
        }));
      }
      setConfirming(false);
    } catch (err) {
      setCancelError(err?.message || tcopy('booking_cannot_cancel'));
    } finally {
      setCancelling(false);
    }
  };

  /** El servidor reprogramó: mismo token → se actualiza la vista; otro token (seña nueva) → su página. */
  const onRescheduled = (res) => {
    setRescheduling(false);
    if (res?.token && res.token !== token) {
      navigate(statusPath(res.token));
      return;
    }
    if (res && typeof res === 'object') setData(res);
    const appt = res?.appointment || {};
    setDone(fillTemplate(tcopy('booking_rescheduled_done'), { fecha: formatDateLong(appt.date), hora: appt.time || '' }));
  };

  return (
    <div className="mj-booking__status mt-10 max-w-2xl">
      <Reveal>
        <AppointmentCard appointment={data.appointment} status={status} label={label} />
      </Reveal>

      {done && <InfoBox tone="success" className="mt-5">{done}</InfoBox>}

      {status === 'cancelled' && !offerOpen && (
        <Reveal className="mt-5 flex items-start gap-3 rounded-xl2 border border-line glass p-5 text-sm text-ink-soft">
          <XCircle size={18} className="mt-0.5 shrink-0 text-danger" aria-hidden="true" />
          <p>{tcopy('booking_cancelled_text')}</p>
        </Reveal>
      )}

      {/* La dueña canceló: la clienta elige qué hacer con la seña. */}
      {offerOpen && !rescheduling && (
        <Reveal className="mt-5">
          <OfferBox token={token} offer={offer} onChosen={(res) => res && setData(res)} onReschedule={() => setRescheduling(true)} />
        </Reveal>
      )}
      {offerDone && <InfoBox tone="success" className="mt-5">{tcopy(offerDone)}</InfoBox>}

      {status === 'awaiting_deposit' && (
        <Reveal className="mt-5">
          <DepositCard
            token={token}
            deposit={data.deposit}
            whatsapp={data.whatsapp}
            notified={!!data.depositNotified}
            onNotified={() => setData((prev) => ({ ...prev, depositNotified: true }))}
            receipt={data.receipt}
          />
        </Reveal>
      )}

      {/* Reprogramar (desde el botón o desde "Elegir otro día" de la dueña). */}
      {rescheduling && data.canReschedule && (
        <div className="mt-8">
          <RescheduleBox token={token} terms={moveTerms} onDone={onRescheduled} onClose={() => setRescheduling(false)} />
        </div>
      )}
      {!rescheduling && !offerOpen && data.canReschedule && (
        <Reveal className="mj-booking__reschedule-open mt-8">
          <Button variant="secondary" onClick={() => setRescheduling(true)}>
            <CalendarClock size={15} />
            {tcopy('booking_reschedule_button')}
          </Button>
        </Reveal>
      )}
      {/* No se puede reprogramar pero hay algo que decir (ya es tarde): solo el texto. */}
      {!data.canReschedule && !offerOpen && moveTerms?.text && <InfoBox className="mt-8">{moveTerms.text}</InfoBox>}

      {cancellable && !rescheduling && (
        <Reveal className="mj-booking__cancel mt-8">
          {data.canCancel ? (
            confirming ? (
              <div className="rounded-xl2 border border-danger/30 bg-danger/10 p-5">
                <p className="text-sm font-medium text-ink">{tcopy('booking_cancel_confirm')}</p>
                {cancelTermsText && <p className="mt-2 whitespace-pre-line text-sm leading-relaxed text-ink-soft">{cancelTermsText}</p>}
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

      {/* Opinión de un turno atendido (opiniones prendidas). */}
      {hasReview && (
        <Reveal id="opinion" className="mt-8 scroll-mt-28">
          <ReviewBox token={token} review={data.review} onSent={(review) => setData((prev) => ({ ...prev, review }))} />
        </Reveal>
      )}

      {/* "Mis turnos" (solo con cuentas prendidas; carga completa por si la sesión es nueva). */}
      {myBookings && (
        <p className="mj-booking__my-bookings mt-8">
          <a href={myBookings} className="inline-flex items-center gap-1.5 text-sm font-medium text-accent hover:underline">
            <ListChecks size={15} aria-hidden="true" />
            {tcopy('my_bookings_title')}
          </a>
        </p>
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
