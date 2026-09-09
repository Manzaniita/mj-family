/**
 * Piezas compartidas del widget de reserva (shortcode [mj_turnos]):
 *  - COPY_DEFAULTS: espejo de los textos booking_* / label_* de inc/options.php
 *    (se usan sólo si window.MJTO.config.copy no trae la clave).
 *  - normalizeConfig(): mjto_public_config() con defaults en cada rama.
 *  - BookingProvider / useBooking(): copy, config y helpers de precio/seña/duración.
 *  - Stepper, Summary, DepositCard, Alert, StepFooter: UI que comparten los pasos
 *    y la vista de estado.
 *  - Token en sessionStorage (para "Ver el estado de mi turno" en el shortcode).
 */
import { createContext, useContext, useMemo, useState } from 'react';
import { Check, CheckCircle2, CreditCard, MessageCircle, AlertTriangle, Info, XCircle } from 'lucide-react';
import { BOOT, CUR, HOME_URL, REST_URL } from '@/lib/config';
import { publicApi } from '@/lib/api';
import { cn, fillTemplate, formatDateTime, formatPrice, longDateLabel, minutesToLabel, roundMoney, timeRange } from '@/lib/format';
import { addMinutesToTime } from '@/lib/dates';
import { Button } from '@/ui';

/* ---------------------------------------------------------------------------
 * Textos (espejo de los defaults de PHP). Los que no están en la spec y se
 * necesitan igual (reintentar, validaciones, franjas del día) también leen
 * copy.* para que PHP pueda hacerlos configurables cuando quiera.
 * ------------------------------------------------------------------------- */
export const COPY_DEFAULTS = {
  booking_eyebrow: 'Turnos',
  booking_title: 'Reservá tu turno',
  booking_subtitle: 'Elegí el servicio, el día y el horario que te quede cómodo.',
  booking_step_service: 'Servicio',
  booking_step_date: 'Día y hora',
  booking_step_details: 'Tus datos',
  booking_step_done: 'Listo',
  booking_pick_day: 'Elegí un día',
  booking_pick_time: 'Elegí un horario',
  booking_no_slots: 'No quedan horarios libres ese día.',
  booking_no_days: 'No hay días disponibles en este mes.',
  booking_summary_title: 'Tu reserva',
  booking_submit: 'Confirmar reserva',
  booking_back: 'Volver',
  booking_next: 'Continuar',
  booking_success_title: '¡Reserva recibida!',
  booking_success_pending: 'Te confirmamos el turno a la brevedad por WhatsApp o email.',
  booking_success_deposit: 'Para confirmar el turno, transferí la seña y avisanos.',
  booking_success_auto: 'Tu turno quedó confirmado. ¡Te esperamos!',
  booking_deposit_title: 'Seña',
  booking_deposit_amount_label: 'Monto de la seña',
  booking_deposit_deadline: 'Tenés tiempo hasta {fecha_limite} para enviar la seña.',
  booking_notified_button: 'Ya transferí la seña',
  booking_notified_done: '¡Gracias! Te confirmamos apenas veamos la transferencia.',
  booking_whatsapp_button: 'Enviar comprobante por WhatsApp',
  booking_pay_link_button: 'Pagar la seña online',
  booking_status_link: 'Ver el estado de mi turno',
  booking_cancel_button: 'Cancelar turno',
  booking_cancel_confirm: '¿Seguro que querés cancelar el turno?',
  booking_cancelled_text: 'Tu turno fue cancelado.',
  booking_cannot_cancel: 'Este turno ya no se puede cancelar desde la web. Escribinos por WhatsApp.',
  booking_login_hint: '¿Sos la dueña? Ingresá para abrir el panel',
  booking_panel_link: 'Abrir el panel de turnos',
  booking_policy_title: 'Antes de reservar',
  services_book_button: 'Reservar',
  services_empty: 'Pronto vas a poder ver los servicios acá.',
  label_duration: 'Duración',
  label_price: 'Precio',
  label_deposit: 'Seña',
  label_from: 'desde',
  label_minutes: 'min',
  label_hours: 'h',
  /* No están en la spec: defaults acá, configurables si PHP los agrega al copy. */
  booking_retry: 'Reintentar',
  booking_error_required: 'Completá este campo.',
  booking_error_email: 'Revisá el email: no parece válido.',
  booking_error_phone: 'Revisá el teléfono: tiene que tener al menos 6 números.',
  booking_error_number: 'Ingresá sólo números.',
  booking_slots_morning: 'Mañana',
  booking_slots_afternoon: 'Tarde',
  booking_slots_evening: 'Noche',
  booking_status_title: 'Estado de tu turno',
  booking_status_not_found: 'No encontramos esa reserva. Puede que haya sido eliminada.',
  booking_new_booking: 'Hacer otra reserva',
};

export const STATUS_DEFAULTS = {
  pending: 'Pendiente',
  awaiting_deposit: 'Esperando seña',
  confirmed: 'Confirmado',
  completed: 'Atendida',
  cancelled: 'Cancelado',
  no_show: 'No vino',
};

export const ACTIVE = ['pending', 'awaiting_deposit', 'confirmed'];

const num = (v, d = 0) => (v !== '' && v !== null && v !== undefined && Number.isFinite(Number(v)) ? Number(v) : d);
const bool = (v, d = false) => (v === undefined || v === null ? d : v === true || v === 1 || v === '1' || v === 'true');

/** mjto_public_config() → shape estable con defaults (ver spec A.10). */
export function normalizeConfig(raw) {
  const c = raw && typeof raw === 'object' ? raw : {};
  const copy = { ...COPY_DEFAULTS, ...(c.copy || {}) };
  Object.keys(copy).forEach((k) => {
    if (copy[k] === null || copy[k] === undefined) copy[k] = COPY_DEFAULTS[k] ?? '';
  });
  const statuses = { ...STATUS_DEFAULTS, ...((c.copy && c.copy.statuses) || c.statuses || {}) };
  const schedule = {
    slotStep: num(c.schedule?.slotStep, 30),
    minNoticeHours: num(c.schedule?.minNoticeHours, 12),
    maxDaysAhead: Math.max(1, num(c.schedule?.maxDaysAhead, 60)),
    weekStart: num(c.schedule?.weekStart, 1) === 7 ? 7 : 1,
    openDays: Array.isArray(c.schedule?.openDays) ? c.schedule.openDays.map((d) => num(d, 0)).filter(Boolean) : [],
  };
  const deposit = {
    type: c.deposit?.type === 'percent' ? 'percent' : 'fixed',
    amount: num(c.deposit?.amount, 0),
    percent: num(c.deposit?.percent, 30),
    instructions: String(c.deposit?.instructions || ''),
    link: String(c.deposit?.link || ''),
    hours: num(c.deposit?.hours, 24),
  };
  const services = (Array.isArray(c.services) ? c.services : [])
    .filter((s) => s && typeof s === 'object' && s.active !== false && s.bookable !== false)
    .map((s) => ({
      ...s,
      id: num(s.id, 0),
      slug: String(s.slug || ''),
      name: String(s.name || ''),
      description: String(s.description || ''),
      excerpt: String(s.excerpt || ''),
      duration: num(s.duration, 0),
      price: num(s.price, 0),
      priceVisible: bool(s.priceVisible, true),
      deposit: num(s.deposit, 0),
      color: String(s.color || ''),
      image: s.image || null,
    }));
  const fields = (Array.isArray(c.form?.fields) ? c.form.fields : [])
    .filter((f) => f && f.key && f.enabled !== false)
    .map((f) => ({
      key: String(f.key),
      label: String(f.label || f.key),
      placeholder: String(f.placeholder || ''),
      type: ['text', 'tel', 'email', 'textarea', 'select', 'number'].includes(f.type) ? f.type : 'text',
      required: bool(f.required, false),
      options: Array.isArray(f.options) ? f.options.map((o) => (typeof o === 'string' ? { value: o, label: o } : { value: String(o.value ?? ''), label: String(o.label ?? o.value ?? '') })) : [],
      system: bool(f.system, false),
    }));
  return {
    enabled: c.enabled !== false,
    slug: String(c.slug || 'turnos'),
    servicesSlug: String(c.servicesSlug || 'servicios'),
    canManage: bool(c.canManage, false),
    business: { name: '', address: '', whatsapp: '', instagram: '', ...(c.business || {}) },
    copy,
    statuses,
    form: { fields, policy: String(c.form?.policy || '') },
    services,
    schedule,
    confirmMode: ['deposit', 'manual', 'auto'].includes(c.confirmMode) ? c.confirmMode : 'deposit',
    deposit,
    cancel: { allowed: bool(c.cancel?.allowed, true), hoursBefore: num(c.cancel?.hoursBefore, 24) },
    priceVisible: bool(c.priceVisible, true),
    currency: { ...CUR, ...(c.currency || {}) },
  };
}

/** Seña efectiva de un servicio (propia > global fija / porcentaje). 0 si no se pide seña. */
export function depositFor(service, cfg) {
  if (!service || !cfg) return 0;
  if (cfg.confirmMode !== 'deposit') return 0;
  if (num(service.deposit, 0) > 0) return roundMoney(service.deposit, cfg.currency);
  if (cfg.deposit.type === 'percent') return roundMoney((num(service.price, 0) * cfg.deposit.percent) / 100, cfg.currency);
  return roundMoney(cfg.deposit.amount, cfg.currency);
}

/* ---------------------------------------------------------------------------
 * URLs del sitio (en modo booking PHP no manda homeUrl: se deduce de restUrl).
 * ------------------------------------------------------------------------- */
export function siteHomeUrl() {
  if (BOOT.homeUrl) return BOOT.homeUrl.endsWith('/') ? BOOT.homeUrl : `${BOOT.homeUrl}/`;
  const rest = String(REST_URL);
  const cut = rest.indexOf('/wp-json/') !== -1 ? rest.indexOf('/wp-json/') : rest.indexOf('/?rest_route=');
  if (cut > 0) return `${rest.slice(0, cut)}/`;
  return HOME_URL.endsWith('/') ? HOME_URL : `${HOME_URL}/`;
}

/** URL del panel de la dueña: /{slug} (el plugin intercepta si tiene la capability). */
export function panelUrl(cfg) {
  return `${siteHomeUrl()}${String(cfg?.slug || 'turnos').replace(/^\/+/, '')}`;
}

/** Login de WordPress con vuelta al panel (sirve con cualquier tema). */
export function loginUrl(cfg) {
  const back = panelUrl(cfg);
  const custom = BOOT.loginUrl || (BOOT.urls && BOOT.urls.login);
  if (custom) return custom.includes('redirect') ? custom : `${custom}${custom.includes('?') ? '&' : '?'}redirect_to=${encodeURIComponent(back)}`;
  return `${siteHomeUrl()}wp-login.php?redirect_to=${encodeURIComponent(back)}`;
}

/* ---------------------------------------------------------------------------
 * Token de la última reserva (sessionStorage): "Ver el estado de mi turno".
 * ------------------------------------------------------------------------- */
const tokenKey = (slug) => `mjto_booking_${slug || 'turnos'}`;

export function loadToken(slug) {
  try {
    const raw = window.sessionStorage.getItem(tokenKey(slug));
    if (!raw) return '';
    const data = JSON.parse(raw);
    return data && typeof data.token === 'string' && /^[a-f0-9]{16,64}$/i.test(data.token) ? data.token : '';
  } catch (e) {
    return '';
  }
}

export function saveToken(slug, token) {
  try {
    window.sessionStorage.setItem(tokenKey(slug), JSON.stringify({ token, at: Date.now() }));
  } catch (e) {
    /* modo privado / storage bloqueado: no pasa nada */
  }
}

export function clearToken(slug) {
  try {
    window.sessionStorage.removeItem(tokenKey(slug));
  } catch (e) {
    /* idem */
  }
}

/* ---------------------------------------------------------------------------
 * Contexto
 * ------------------------------------------------------------------------- */
const BookingContext = createContext(null);

export function BookingProvider({ cfg, children }) {
  const value = useMemo(() => {
    const copy = cfg.copy;
    const t = (key, vars) => fillTemplate(copy[key] ?? COPY_DEFAULTS[key] ?? '', vars || {});
    return {
      cfg,
      copy,
      t,
      statusLabel: (status) => cfg.statuses[status] || STATUS_DEFAULTS[status] || status || '',
      durationOf: (min) => minutesToLabel(min, { hours: copy.label_hours, minutes: copy.label_minutes }),
      priceOf: (n) => formatPrice(n, cfg.currency),
      depositOf: (service) => depositFor(service, cfg),
      showPrice: (service) => cfg.priceVisible && (!service || service.priceVisible !== false) && num(service?.price, 0) > 0,
    };
  }, [cfg]);
  return <BookingContext.Provider value={value}>{children}</BookingContext.Provider>;
}

export function useBooking() {
  const ctx = useContext(BookingContext);
  if (!ctx) throw new Error('useBooking() fuera de <BookingProvider>');
  return ctx;
}

/* ---------------------------------------------------------------------------
 * UI compartida
 * ------------------------------------------------------------------------- */

/** Aviso inline (error de red, horario tomado, éxito). */
export function Alert({ tone = 'info', children, className, action }) {
  const Icon = tone === 'danger' ? XCircle : tone === 'warning' ? AlertTriangle : tone === 'success' ? CheckCircle2 : Info;
  const tones = {
    info: 'bg-info-soft text-info border-info/20',
    success: 'bg-success-soft text-success border-success/20',
    warning: 'bg-warning-soft text-warning border-warning/25',
    danger: 'bg-danger-soft text-danger border-danger/20',
  };
  return (
    <div role={tone === 'danger' ? 'alert' : 'status'} className={cn('mjto-alert flex items-start gap-3 rounded-tile border px-4 py-3 text-sm leading-snug animate-fade', `mjto-alert--${tone}`, tones[tone] || tones.info, className)}>
      <Icon size={18} className="mt-0.5 shrink-0" aria-hidden="true" />
      <div className="min-w-0 flex-1 font-medium">{children}</div>
      {action && <div className="shrink-0">{action}</div>}
    </div>
  );
}

/** Indicador de pasos (pastillas): los anteriores son clicables para volver. */
export function Stepper({ steps, current, locked = false, onSelect }) {
  return (
    <ol className="mjto-booking__steps flex flex-wrap items-center gap-2">
      {steps.map((label, i) => {
        const n = i + 1;
        const state = n < current ? 'done' : n === current ? 'current' : 'todo';
        const clickable = state === 'done' && !locked && typeof onSelect === 'function';
        return (
          <li key={label + n} className="flex items-center gap-2">
            <button
              type="button"
              disabled={!clickable}
              onClick={() => clickable && onSelect(n)}
              aria-current={state === 'current' ? 'step' : undefined}
              data-state={state}
              className={cn(
                'mjto-booking__step inline-flex h-9 items-center gap-2 rounded-chip border pl-1.5 pr-3.5 text-[13px] font-semibold transition-[background-color,color,border-color] duration-200',
                state === 'current' && 'mjto-booking__step--current border-accent bg-accent text-on-accent shadow-glow',
                state === 'done' && 'mjto-booking__step--done border-accent/25 bg-accent-soft text-accent',
                state === 'todo' && 'mjto-booking__step--todo border-line bg-surface text-ink-faint',
                clickable && 'cursor-pointer hover:bg-accent/15',
                !clickable && 'cursor-default'
              )}
            >
              <span
                className={cn(
                  'grid size-6 shrink-0 place-items-center rounded-full text-[11px] font-bold mjto-tabular',
                  state === 'current' ? 'bg-on-accent/20' : state === 'done' ? 'bg-accent text-on-accent' : 'bg-surface-2'
                )}
                aria-hidden="true"
              >
                {state === 'done' ? <Check size={13} strokeWidth={3} /> : n}
              </span>
              <span className={cn(state !== 'current' && 'hidden sm:inline')}>{label}</span>
            </button>
            {i < steps.length - 1 && <span className="hidden h-px w-4 bg-line sm:block" aria-hidden="true" />}
          </li>
        );
      })}
    </ol>
  );
}

/** Pie de un paso: volver a la izquierda, continuar/confirmar a la derecha. */
export function StepFooter({ children, className }) {
  return <div className={cn('mjto-booking__footer mt-6 flex flex-wrap items-center justify-between gap-3 mjto-hairline-t pt-5', className)}>{children}</div>;
}

/**
 * Normaliza un turno para el resumen. Acepta el shape de la selección del
 * wizard ({service, date, time}) o el `appointment` que devuelve la REST.
 */
export function summaryItem({ service, date, time, appointment, deposit, cfg }) {
  const a = appointment || {};
  // Sin servicio (vista de estado) lo buscamos por nombre para respetar priceVisible y el color.
  if (!service && a.serviceName && cfg?.services) {
    service = cfg.services.find((s) => s.name === a.serviceName) || null;
  }
  const duration = num(a.durationMin, service?.duration || 0);
  const t = a.time || time || '';
  return {
    serviceName: a.serviceName || service?.name || '',
    date: a.date || date || '',
    time: t,
    endTime: a.endTime || (t && duration ? addMinutesToTime(t, duration) : ''),
    durationMin: duration,
    price: a.price !== undefined ? num(a.price, 0) : num(service?.price, 0),
    priceVisible: service ? service.priceVisible !== false : true,
    deposit: deposit !== undefined ? num(deposit, 0) : depositFor(service, cfg),
    color: service?.color || '',
  };
}

/** Tarjeta "Tu reserva": servicio, día y hora, duración, precio y seña. */
export function Summary({ item, title, className, compact = false }) {
  const { t, cfg, durationOf, priceOf } = useBooking();
  if (!item) return null;
  const showPrice = cfg.priceVisible && item.priceVisible !== false && item.price > 0;
  const rows = [
    item.durationMin > 0 && [t('label_duration'), durationOf(item.durationMin)],
    showPrice && [t('label_price'), priceOf(item.price)],
    item.deposit > 0 && [t('label_deposit'), priceOf(item.deposit)],
  ].filter(Boolean);
  return (
    <section className={cn('mjto-booking__summary mjto-card overflow-hidden', className)} aria-label={title || t('booking_summary_title')}>
      <div className="h-1.5 w-full bg-accent" style={item.color ? { background: item.color } : undefined} aria-hidden="true" />
      <div className={cn('flex flex-col gap-3', compact ? 'p-4' : 'p-5')}>
        <p className="text-[11px] font-bold uppercase tracking-[0.14em] text-accent">{title || t('booking_summary_title')}</p>
        {item.serviceName && <p className="font-display text-lg font-semibold leading-tight tracking-tight text-ink">{item.serviceName}</p>}
        {(item.date || item.time) && (
          <p className="text-sm text-ink-soft">
            {item.date && <span className="capitalize">{longDateLabel(item.date, { year: true })}</span>}
            {item.time && (
              <>
                {item.date && <span aria-hidden="true"> · </span>}
                <span className="mjto-tabular font-semibold text-ink">{timeRange(item.time, item.endTime)}</span>
              </>
            )}
          </p>
        )}
        {rows.length > 0 && (
          <dl className="mt-1 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5 text-sm">
            {rows.map(([k, v]) => (
              <div key={k} className="contents">
                <dt className="text-ink-faint">{k}</dt>
                <dd className="text-right font-semibold text-ink mjto-tabular">{v}</dd>
              </div>
            ))}
          </dl>
        )}
      </div>
    </section>
  );
}

/**
 * Tarjeta de seña: monto, instrucciones, vencimiento y acciones
 * (pagar online, mandar comprobante por WhatsApp, "ya transferí").
 * @param {{ deposit: { amount, instructions, link, deadline, deadlineLabel }, token, whatsapp?: { url },
 *   notified?: boolean, onNotified?: () => void, className? }} props
 */
export function DepositCard({ deposit, token, whatsapp, notified = false, onNotified, className }) {
  const { t, priceOf } = useBooking();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  if (!deposit) return null;
  const deadlineLabel = deposit.deadlineLabel || (deposit.deadline ? formatDateTime(deposit.deadline, { year: false }) : '');

  const notify = async () => {
    if (!token) return;
    setBusy(true);
    setError('');
    try {
      await publicApi.notifyDeposit(token, '');
      onNotified?.();
    } catch (e) {
      setError(e?.message || '');
    } finally {
      setBusy(false);
    }
  };

  return (
    <section className={cn('mjto-booking__deposit mjto-card mjto-card--accent p-5', className)} aria-label={t('booking_deposit_title')}>
      <div className="flex items-start justify-between gap-4">
        <div className="min-w-0">
          <p className="text-[11px] font-bold uppercase tracking-[0.14em] text-accent">{t('booking_deposit_title')}</p>
          <p className="mt-1 text-sm text-ink-soft">{t('booking_deposit_amount_label')}</p>
        </div>
        <p className="font-display text-2xl font-bold tracking-tight text-ink mjto-tabular">{priceOf(deposit.amount)}</p>
      </div>
      {deposit.instructions && (
        <p className="mt-3 whitespace-pre-wrap rounded-tile bg-surface px-4 py-3 text-sm leading-relaxed text-ink">{deposit.instructions}</p>
      )}
      {deadlineLabel && <p className="mt-3 text-sm text-ink-soft">{t('booking_deposit_deadline', { fecha_limite: deadlineLabel })}</p>}
      <div className="mt-4 flex flex-col gap-2 sm:flex-row sm:flex-wrap">
        {deposit.link && (
          <Button href={deposit.link} target="_blank" rel="noopener noreferrer" icon={CreditCard} className="sm:flex-1">
            {t('booking_pay_link_button')}
          </Button>
        )}
        {whatsapp?.url && (
          <Button variant="secondary" href={whatsapp.url} target="_blank" rel="noopener noreferrer" icon={MessageCircle} className="sm:flex-1">
            {t('booking_whatsapp_button')}
          </Button>
        )}
        {!notified && token && (
          <Button variant="soft" onClick={notify} loading={busy} icon={CheckCircle2} className="sm:flex-1">
            {t('booking_notified_button')}
          </Button>
        )}
      </div>
      {notified && <Alert tone="success" className="mt-3">{t('booking_notified_done')}</Alert>}
      {error && <Alert tone="danger" className="mt-3">{error}</Alert>}
    </section>
  );
}
