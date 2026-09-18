/**
 * Plugin MJ Turnos Online (window.MJ.turnos): servicios con duración y
 * precio, reserva de turnos online (con seña, confirmación manual o
 * automática) y página de estado de cada reserva. La SPA muestra: página de
 * servicios (/{servicesSlug}), reserva (/{slug}), estado (/{slug}/t/{token}),
 * "Mis turnos" en Mi cuenta (/cuenta/turnos, solo con cuentas de clientas),
 * secciones en la portada, enlaces en el menú, la barra inferior y el header.
 *
 * Sin plugin → TURNOS = null y nada de esto se renderiza.
 * Todos los textos (títulos, pasos, botones, etiquetas, estados) vienen del
 * plugin (Turnos → Ajustes → Textos); los defaults de acá son el espejo de
 * los del plugin para el modo standalone y el último fallback.
 */
import { WP, CUR } from './config';

/* ---------------------------------------------------------------------------
 * Defaults (espejo de mjto_option_defaults() en el plugin)
 * ------------------------------------------------------------------------- */

export const TURNOS_COPY_DEFAULTS = {
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
  booking_retry: 'Reintentar',
  booking_error_required: 'Completá este campo.',
  booking_error_email: 'Revisá el email: no parece válido.',
  booking_error_phone: 'Revisá el teléfono: necesitamos al menos 6 números.',
  booking_error_number: 'Tiene que ser un número.',
  booking_slots_morning: 'Mañana',
  booking_slots_afternoon: 'Tarde',
  booking_slots_evening: 'Noche',
  booking_status_title: 'Estado de tu turno',
  booking_status_not_found: 'No encontramos ese turno. Revisá el link o escribinos.',
  booking_new_booking: 'Hacer otra reserva',
  booking_whatsapp_label: 'WhatsApp',
  // Cuenta en la reserva
  booking_account_title: 'Tu cuenta',
  booking_account_text: 'Creá una contraseña: con tu cuenta ves tus turnos y la próxima vez no tenés que cargar todo de nuevo.',
  booking_account_optional: 'Crear mi cuenta con estos datos',
  booking_password_label: 'Contraseña',
  booking_password_hint: 'Mínimo {min} caracteres.',
  booking_account_login: 'Ya tengo cuenta: ingresar',
  booking_logged_as: 'Reservás como {nombre}.',
  booking_not_you: '¿No sos vos? Salir',
  // Seña con saldo a favor y comprobante
  booking_credit_applied: 'Se usan {monto} de tu saldo a favor.',
  booking_deposit_to_pay: 'A transferir',
  booking_receipt_title: 'Comprobante de la transferencia',
  // Reprogramar
  booking_reschedule_button: 'Reprogramar',
  booking_reschedule_title: 'Elegí el nuevo día y horario',
  booking_reschedule_confirm: 'Confirmar el cambio',
  booking_reschedule_keep: 'Faltan {horas} h o más para tu turno: si reprogramás, la seña se conserva.',
  booking_reschedule_lose: 'Faltan menos de {horas} h para tu turno: si reprogramás, la seña no se conserva y vas a tener que abonar una nueva.',
  booking_reschedule_blocked: 'Faltan menos de {horas} h para tu turno: ya no se puede reprogramar desde acá.',
  booking_rescheduled_done: '¡Listo! Tu turno quedó para el {fecha} a las {hora}.',
  // Cancelar según las reglas (el servidor los manda ya armados en cancelTerms.text)
  booking_cancel_keep: 'Cancelás con {horas} h o más de anticipación: tu seña queda como saldo a favor para el próximo turno.',
  booking_cancel_lose: 'Faltan menos de {horas} h para tu turno: si cancelás, la seña no se devuelve.',
  // Cuando cancela la dueña
  booking_offer_title: 'Tuvimos que cancelar tu turno',
  booking_offer_text: 'Perdón por el cambio. Tu seña no se pierde: elegí qué preferís.',
  booking_offer_reschedule: 'Elegir otro día',
  booking_offer_refund: 'Pedir la devolución',
  booking_offer_credit: 'Dejarla como saldo a favor',
  booking_offer_refund_done: 'Listo: te avisamos cuando hagamos la devolución.',
  booking_offer_refunded: 'La seña ya te fue devuelta.',
  booking_offer_credit_done: 'Listo: la seña quedó como saldo a favor en tu cuenta.',
  // Mis turnos
  my_bookings_title: 'Mis turnos',
  my_bookings_empty: 'Todavía no tenés turnos.',
  my_bookings_upcoming: 'Próximos',
  my_bookings_past: 'Anteriores',
  my_bookings_view: 'Ver turno',
  my_bookings_login: 'Ingresá a tu cuenta para ver tus turnos.',
  my_bookings_book: 'Reservar un turno',
  // Opinión
  review_title: 'Contanos cómo te fue',
  review_text: 'Tu opinión ayuda a otras personas a animarse.',
  review_rating_label: 'Tu puntaje',
  review_body_label: 'Tu opinión',
  review_name_label: 'Tu nombre (como querés que aparezca)',
  review_submit: 'Enviar opinión',
  review_thanks: '¡Gracias por tu opinión!',
  review_thanks_pending: '¡Gracias! La vamos a publicar después de leerla.',
  review_section_title: 'Opiniones',
  services_eyebrow: 'Servicios',
  services_title: 'Servicios',
  services_subtitle: '',
  services_book_button: 'Reservar',
  services_empty: 'Pronto vas a poder ver los servicios acá.',
  label_duration: 'Duración',
  label_price: 'Precio',
  label_deposit: 'Seña',
  label_from: 'desde',
  label_minutes: 'min',
  label_hours: 'h',
  label_credit: 'Saldo a favor',
  label_maintenance: 'Mantenimiento recomendado: cada {periodo}',
  label_day: 'día',
  label_days: 'días',
  label_week: 'semana',
  label_weeks: 'semanas',
  label_month: 'mes',
  label_months: 'meses',
};

export const TURNOS_STATUS_DEFAULTS = {
  pending: 'Pendiente',
  awaiting_deposit: 'Esperando seña',
  confirmed: 'Confirmado',
  completed: 'Atendida',
  cancelled: 'Cancelado',
  no_show: 'No vino',
};

/** Estados válidos de una reserva (mismos que la columna `status` del plugin). */
export const TURNOS_STATUSES = Object.keys(TURNOS_STATUS_DEFAULTS);

const CONFIRM_MODES = ['deposit', 'manual', 'auto'];
const FIELD_TYPES = ['text', 'tel', 'email', 'textarea', 'select', 'radio', 'number', 'date'];
const ACCOUNT_MODES = ['off', 'optional', 'required'];
const MAINTENANCE_UNITS = ['days', 'weeks', 'months'];

/* ---------------------------------------------------------------------------
 * Normalización de window.MJ.turnos (defaults defensivos campo por campo)
 * ------------------------------------------------------------------------- */

const str = (value, fallback = '') => (typeof value === 'string' ? value : fallback);
const text = (value, fallback = '') => (typeof value === 'string' && value.trim() !== '' ? value : fallback);
const num = (value, fallback = 0) => (Number.isFinite(Number(value)) && value !== null && value !== '' ? Number(value) : fallback);
const bool = (value, fallback = true) => (typeof value === 'boolean' ? value : fallback);
const pick = (value, allowed, fallback) => (allowed.includes(value) ? value : fallback);

/** Categoría (de la config o de un servicio): { id, slug, name, description, order }. */
function normalizeCategory(c) {
  return {
    id: Number(c.id),
    slug: str(c.slug, String(c.id)),
    name: String(c.name),
    description: str(c.description),
    order: num(c.order, 0),
  };
}

const categoryList = (value) =>
  (Array.isArray(value) ? value : []).filter((c) => c && c.id && typeof c.name === 'string' && c.name !== '').map(normalizeCategory);

/** Secciones informativas del servicio (solo las que tienen texto). */
const sectionList = (value) =>
  (Array.isArray(value) ? value : [])
    .filter((s) => s && typeof s.text === 'string' && s.text.trim() !== '')
    .map((s, i) => ({ key: str(s.key, `s${i}`), label: text(s.label, ''), text: s.text }));

/** Mantenimiento recomendado: null o { value, unit, label }. */
function normalizeMaintenance(m) {
  if (!m || typeof m !== 'object' || !(num(m.value, 0) > 0)) return null;
  return { value: num(m.value, 0), unit: pick(m.unit, MAINTENANCE_UNITS, 'weeks'), label: str(m.label) };
}

/** Opinión pública: { id, name, rating (1-5), body, serviceId, serviceName, date, dateLabel }. */
export function normalizeReview(r) {
  return {
    id: Number(r?.id) || 0,
    name: str(r?.name),
    rating: Math.min(5, Math.max(0, Math.round(num(r?.rating, 0)))),
    body: str(r?.body),
    serviceId: Number(r?.serviceId) || 0,
    serviceName: str(r?.serviceName),
    date: str(r?.date),
    dateLabel: str(r?.dateLabel),
  };
}

/** Servicio público (mjto_service_public) con todos sus campos garantizados. */
function normalizeService(s) {
  return {
    id: Number(s.id),
    slug: str(s.slug, String(s.id)),
    name: String(s.name),
    description: str(s.description),
    excerpt: str(s.excerpt),
    duration: Math.max(0, Math.round(num(s.duration, 0))),
    price: num(s.price, 0),
    priceVisible: bool(s.priceVisible, true),
    deposit: num(s.deposit, 0),
    buffer: s.buffer === null || s.buffer === undefined || s.buffer === '' ? null : num(s.buffer, 0),
    color: str(s.color),
    image: typeof s.image === 'string' && s.image ? s.image : null,
    order: num(s.order, 0),
    active: bool(s.active, true),
    bookable: bool(s.bookable, true),
    categories: categoryList(s.categories),
    sections: sectionList(s.sections),
    maintenance: normalizeMaintenance(s.maintenance),
  };
}

/** Campo del formulario público (form.fields) — solo los que el plugin manda habilitados. */
function normalizeField(f) {
  const options = Array.isArray(f.options) ? f.options : [];
  return {
    key: String(f.key),
    label: text(f.label, String(f.key)),
    placeholder: str(f.placeholder),
    type: pick(f.type, FIELD_TYPES, 'text'),
    required: !!f.required,
    system: !!f.system,
    options: options
      .filter((o) => o && (typeof o.value === 'string' || typeof o.value === 'number'))
      .map((o) => ({ value: String(o.value), label: text(o.label, String(o.value)) })),
  };
}

/** Días ISO abiertos (1 = lunes … 7 = domingo). Acepta array de números o de strings. */
/**
 * Días de la semana (ISO 1..7) en los que se atiende. PHP manda un objeto
 * { "1": [["09:00","18:00"]], …, "7": [] } (día → franjas); también se acepta
 * una lista simple de números por si cambia el formato.
 */
function normalizeOpenDays(value) {
  let days = [];
  if (Array.isArray(value)) {
    days = value;
  } else if (value && typeof value === 'object') {
    days = Object.keys(value).filter((k) => Array.isArray(value[k]) && value[k].length > 0);
  }
  return [...new Set(days.map((d) => Number(d)).filter((d) => Number.isInteger(d) && d >= 1 && d <= 7))].sort();
}

function normalize(raw) {
  if (!raw || typeof raw !== 'object' || raw.enabled === false) return null;

  const business = raw.business || {};
  const menu = raw.menu || {};
  const home = raw.home || {};
  const homeServices = home.services || {};
  const homeBooking = home.booking || {};
  const rawCopy = raw.copy && typeof raw.copy === 'object' ? raw.copy : {};
  const form = raw.form || {};
  const schedule = raw.schedule || {};
  const deposit = raw.deposit || {};
  const cancel = raw.cancel || {};
  const reschedule = raw.reschedule || {};
  const account = raw.account || {};
  const reviews = raw.reviews || {};

  const copy = { ...TURNOS_COPY_DEFAULTS };
  for (const key of Object.keys(TURNOS_COPY_DEFAULTS)) {
    if (typeof rawCopy[key] === 'string' && rawCopy[key].trim() !== '') copy[key] = rawCopy[key];
  }
  const statuses = { ...TURNOS_STATUS_DEFAULTS };
  const rawStatuses = rawCopy.statuses && typeof rawCopy.statuses === 'object' ? rawCopy.statuses : {};
  for (const key of TURNOS_STATUSES) {
    if (typeof rawStatuses[key] === 'string' && rawStatuses[key].trim() !== '') statuses[key] = rawStatuses[key];
  }
  copy.statuses = statuses;

  const slug = text(raw.slug, 'turnos').replace(/^\/+|\/+$/g, '') || 'turnos';
  const servicesSlug = text(raw.servicesSlug, 'servicios').replace(/^\/+|\/+$/g, '') || 'servicios';
  const restBase = text(raw.restUrl, `${WP?.restUrl || '/wp-json/'}mjto/v1/`);

  return {
    enabled: true,
    slug,
    servicesSlug,
    /* true = el usuario logueado administra la agenda: la SPA ofrece "Abrir el panel". */
    canManage: !!raw.canManage,
    business: {
      name: text(business.name, WP?.siteName || ''),
      address: str(business.address),
      whatsapp: String(business.whatsapp ?? '').replace(/\D+/g, ''),
      instagram: str(business.instagram),
    },
    menu: {
      label: text(menu.label, 'Turnos'),
      showInMenu: bool(menu.showInMenu, true),
      showBottomNav: bool(menu.showBottomNav, true),
      headerCtaEnabled: bool(menu.headerCtaEnabled, true),
      headerCta: text(menu.headerCta, 'Reservar turno'),
      servicesLabel: text(menu.servicesLabel, 'Servicios'),
      servicesInMenu: bool(menu.servicesInMenu, true),
      servicesPageEnabled: bool(menu.servicesPageEnabled, true),
    },
    home: {
      services: {
        enabled: bool(homeServices.enabled, true),
        eyebrow: str(homeServices.eyebrow, 'Servicios'),
        title: text(homeServices.title, 'Lo que hacemos'),
        subtitle: str(homeServices.subtitle),
        link: str(homeServices.link, 'Ver todos los servicios'),
        count: Math.max(1, Math.round(num(homeServices.count, 4))),
      },
      booking: {
        enabled: bool(homeBooking.enabled, true),
        eyebrow: str(homeBooking.eyebrow, 'Turnos'),
        title: text(homeBooking.title, 'Reservá tu turno online'),
        subtitle: str(homeBooking.subtitle, 'Elegí el servicio, el día y la hora. Te confirmamos enseguida.'),
        button: text(homeBooking.button, 'Reservar ahora'),
        image: str(homeBooking.image),
      },
    },
    copy,
    form: {
      fields: (Array.isArray(form.fields) ? form.fields : [])
        .filter((f) => f && typeof f.key === 'string' && f.key !== '')
        .map(normalizeField),
      policy: str(form.policy),
    },
    services: (Array.isArray(raw.services) ? raw.services : [])
      .filter((s) => s && s.id && s.name)
      .map(normalizeService),
    schedule: {
      slotStep: Math.max(5, Math.round(num(schedule.slotStep, 30))),
      minNoticeHours: Math.max(0, num(schedule.minNoticeHours, 12)),
      maxDaysAhead: Math.max(1, Math.round(num(schedule.maxDaysAhead, 60))),
      weekStart: Number(schedule.weekStart) === 7 ? 7 : 1,
      openDays: normalizeOpenDays(schedule.openDays),
    },
    confirmMode: pick(raw.confirmMode, CONFIRM_MODES, 'deposit'),
    deposit: {
      type: deposit.type === 'percent' ? 'percent' : 'fixed',
      amount: Math.max(0, num(deposit.amount, 0)),
      percent: Math.max(0, num(deposit.percent, 30)),
      instructions: str(deposit.instructions),
      link: str(deposit.link),
      hours: Math.max(0, num(deposit.hours, 24)),
    },
    cancel: {
      allowed: bool(cancel.allowed, true),
      hoursBefore: Math.max(0, num(cancel.hoursBefore, 24)),
    },
    /* Todo lo de acá abajo viene apagado por defecto (plugin 1.3.0): sin el dato, nada cambia. */
    reschedule: {
      allowed: bool(reschedule.allowed, false),
      hoursBefore: Math.max(0, num(reschedule.hoursBefore, 48)),
    },
    creditEnabled: bool(raw.creditEnabled, false),
    account: {
      mode: pick(account.mode, ACCOUNT_MODES, 'off'),
      passwordMin: Math.max(6, Math.round(num(account.passwordMin, 8))),
      loggedIn: bool(account.loggedIn, false),
      prefill: bool(account.prefill, false),
      myBookingsUrl: str(account.myBookingsUrl),
    },
    categories: categoryList(raw.categories),
    groupByCategory: bool(raw.groupByCategory, false),
    reviews: {
      enabled: bool(reviews.enabled, false),
      count: Math.max(0, Math.round(num(reviews.count, 0))),
      average: Math.max(0, num(reviews.average, 0)),
      items: (Array.isArray(reviews.items) ? reviews.items : []).filter((r) => r && typeof r === 'object').map(normalizeReview),
    },
    logoutUrl: str(raw.logoutUrl),
    priceVisible: bool(raw.priceVisible, true),
    currency: { ...CUR, ...(raw.currency && typeof raw.currency === 'object' ? raw.currency : {}) },
    restUrl: restBase.endsWith('/') ? restBase : `${restBase}/`,
    /* URLs de primer nivel que manda el plugin (portada y login de WordPress), por si una skin las necesita. */
    homeUrl: str(raw.homeUrl),
    loginUrl: str(raw.loginUrl),
  };
}

export const TURNOS = normalize(WP?.turnos);

/* ---------------------------------------------------------------------------
 * Rutas
 * ------------------------------------------------------------------------- */

/** Reserva pública: /{slug}. Para la dueña es un <a href> con recarga completa (el plugin intercepta). */
export const bookingPath = () => `/${TURNOS?.slug || 'turnos'}`;

/** Página de servicios: /{servicesSlug}. */
export const servicesPath = () => `/${TURNOS?.servicesSlug || 'servicios'}`;

/** Estado de una reserva: /{slug}/t/{token}. */
export const statusPath = (token) => `${bookingPath()}/t/${encodeURIComponent(String(token || ''))}`;

/** Reserva con el servicio preseleccionado: /{slug}?servicio={slug-del-servicio}. */
export const serviceBookingPath = (serviceSlug) =>
  serviceSlug ? `${bookingPath()}?servicio=${encodeURIComponent(String(serviceSlug))}` : bookingPath();

/** Login con vuelta a la reserva (la dueña entra y el plugin le muestra el panel). */
export const bookingLoginPath = (back) => `/login?redirect=${encodeURIComponent(back || bookingPath())}`;

/** "Mis turnos" dentro de Mi cuenta del tema (inc: filtro mjto_my_bookings_url). */
export const myBookingsPath = () => '/cuenta/turnos';

/** ¿Hay cuentas de clientas (Turnos → Ajustes → Cuentas distinto de "off")? */
export const accountsEnabled = () => !!TURNOS && TURNOS.account.mode !== 'off';

/**
 * Link a "Mis turnos": si la URL que manda el plugin es de este sitio se
 * devuelve como path; si no, tal cual. '' = no mostrar.
 */
export function myBookingsHref(url) {
  const value = String(url || '').trim();
  if (!value) return '';
  if (typeof window === 'undefined') return value;
  try {
    const u = new URL(value, window.location.origin);
    return u.origin === window.location.origin ? `${u.pathname}${u.search}${u.hash}` : value;
  } catch {
    return value;
  }
}

/* ---------------------------------------------------------------------------
 * Textos, servicios y formato
 * ------------------------------------------------------------------------- */

/** Texto del plugin por clave (con el default del espejo como último fallback). */
export function tcopy(key, fallback) {
  const value = TURNOS?.copy?.[key];
  if (typeof value === 'string' && value.trim() !== '') return value;
  return fallback ?? TURNOS_COPY_DEFAULTS[key] ?? '';
}

/** Etiqueta legible de un estado de reserva (Turnos → Ajustes → Textos → Estados). */
export const statusLabel = (status) =>
  TURNOS?.copy?.statuses?.[status] || TURNOS_STATUS_DEFAULTS[status] || String(status || '');

export const serviceBySlug = (slug) => TURNOS?.services.find((s) => s.slug === slug) ?? null;
export const serviceById = (id) => TURNOS?.services.find((s) => s.id === Number(id)) ?? null;

/**
 * Seña efectiva de un servicio (misma regla que mjto_service_deposit en PHP):
 * la propia del servicio si es > 0; si no, la global (fija o % del precio).
 * En modo confirmación 'manual' o 'auto' no se pide seña → 0.
 */
export function serviceDeposit(service) {
  if (!TURNOS || !service || TURNOS.confirmMode !== 'deposit') return 0;
  const decimals = Number(TURNOS.currency?.decimals ?? 0);
  const round = (n) => Number(Math.max(0, n).toFixed(Number.isFinite(decimals) ? decimals : 0));
  if (Number(service.deposit) > 0) return round(Number(service.deposit));
  const { type, amount, percent } = TURNOS.deposit;
  if (type === 'percent') return round((Number(service.price) || 0) * percent / 100);
  return round(amount);
}

/**
 * Servicios agrupados por categoría (Turnos → Servicios → Categorías), en el
 * orden de TURNOS.categories; los que no tienen categoría van al final
 * (category = null). Cada servicio va una sola vez, en su primera categoría.
 * Devuelve null si no corresponde agrupar (ajuste apagado, sin categorías o
 * ningún servicio con categoría): la pantalla muestra la grilla de siempre.
 */
export function groupServicesByCategory(services) {
  if (!TURNOS?.groupByCategory || !TURNOS.categories.length || !Array.isArray(services)) return null;
  const order = TURNOS.categories;
  const buckets = new Map(order.map((c) => [c.id, []]));
  const rest = [];
  for (const svc of services) {
    const ids = (svc.categories || []).map((c) => c.id);
    const first = order.find((c) => ids.includes(c.id));
    if (first) buckets.get(first.id).push(svc);
    else rest.push(svc);
  }
  const groups = order.filter((c) => buckets.get(c.id).length > 0).map((c) => ({ category: c, services: buckets.get(c.id) }));
  if (!groups.length) return null;
  if (rest.length) groups.push({ category: null, services: rest });
  return groups;
}

/** "Mantenimiento recomendado: cada 4 semanas" (label_maintenance con {periodo}), o ''. */
export function maintenanceText(service) {
  const m = service?.maintenance;
  if (!m) return '';
  let periodo = m.label;
  if (!periodo) {
    const keys = { days: ['label_day', 'label_days'], weeks: ['label_week', 'label_weeks'], months: ['label_month', 'label_months'] }[m.unit];
    periodo = `${m.value} ${tcopy(m.value === 1 ? keys[0] : keys[1])}`;
  }
  return fillTemplate(tcopy('label_maintenance'), { periodo });
}

/** "2 h 30 min", "45 min", "1 h" — con las etiquetas del plugin (label_hours / label_minutes). */
export function formatDuration(min, copy) {
  const c = copy || TURNOS?.copy || TURNOS_COPY_DEFAULTS;
  const total = Math.max(0, Math.round(Number(min) || 0));
  const h = Math.floor(total / 60);
  const m = total % 60;
  const hl = c.label_hours || TURNOS_COPY_DEFAULTS.label_hours;
  const ml = c.label_minutes || TURNOS_COPY_DEFAULTS.label_minutes;
  if (h && m) return `${h} ${hl} ${m} ${ml}`;
  if (h) return `${h} ${hl}`;
  return `${m} ${ml}`;
}

/** Reemplaza {placeholders} de un texto del plugin (p. ej. booking_deposit_deadline). */
export function fillTemplate(template, values = {}) {
  return String(template || '').replace(/\{([a-z_]+)\}/gi, (match, key) =>
    values[key] !== undefined && values[key] !== null ? String(values[key]) : match
  );
}

/* ---------------------------------------------------------------------------
 * Fechas (locales, sin zona horaria: 'YYYY-MM-DD' y 'HH:MM' tal como las
 * maneja el plugin en la zona horaria del sitio)
 * ------------------------------------------------------------------------- */

const pad2 = (n) => String(n).padStart(2, '0');

/** Date local → 'YYYY-MM-DD'. */
export const toISODate = (date) => `${date.getFullYear()}-${pad2(date.getMonth() + 1)}-${pad2(date.getDate())}`;

/** 'YYYY-MM-DD' → Date local (medianoche). null si no es válida. */
export function parseISODate(iso) {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(iso || ''));
  if (!m) return null;
  const date = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]));
  return Number.isNaN(date.getTime()) ? null : date;
}

export const todayISO = () => toISODate(new Date());

/** Suma días a una fecha ISO. */
export function addDays(iso, days) {
  const date = parseISODate(iso) || new Date();
  date.setDate(date.getDate() + Number(days || 0));
  return toISODate(date);
}

/** Día ISO de la semana (1 = lunes … 7 = domingo) de una fecha ISO. */
export function isoWeekday(iso) {
  const date = parseISODate(iso);
  if (!date) return 0;
  const dow = date.getDay();
  return dow === 0 ? 7 : dow;
}

/** Primer y último día de un mes (monthIndex 0–11) → { from, to } en ISO. */
export function monthBounds(year, monthIndex) {
  const first = new Date(year, monthIndex, 1);
  const last = new Date(year, monthIndex + 1, 0);
  return { from: toISODate(first), to: toISODate(last) };
}

/** Último día reservable según maxDaysAhead (ISO). */
export const lastBookableISO = () => addDays(todayISO(), TURNOS?.schedule.maxDaysAhead ?? 60);

/** "sáb 12 sep" (o el formato que pida `options`) en el locale del sitio. */
export function formatDateLabel(iso, options) {
  const date = parseISODate(iso);
  if (!date) return String(iso || '');
  try {
    return new Intl.DateTimeFormat(WP?.locale || 'es-AR', options || { weekday: 'short', day: 'numeric', month: 'short' }).format(date);
  } catch {
    return iso;
  }
}

/** "sábado 12 de septiembre" — fecha larga para resúmenes y confirmaciones. */
export const formatDateLong = (iso) => formatDateLabel(iso, { weekday: 'long', day: 'numeric', month: 'long' });

/* ---------------------------------------------------------------------------
 * REST público mjto/v1 (mismo manejo de errores que lib/wc.js:
 * throw Error con { data, code, status })
 * ------------------------------------------------------------------------- */

/**
 * Los endpoints públicos no necesitan nonce: solo se manda con sesión iniciada
 * (así la dueña llega identificada). Si WordPress lo rechaza por vencido
 * (rest_cookie_invalid_nonce: página cacheada o pestaña de ayer) se reintenta
 * una sola vez sin el header, en vez de romper la reserva con un 403.
 */
async function req(path, { method = 'GET', body } = {}, withNonce = !!(WP?.user && WP?.wpNonce)) {
  const base = TURNOS?.restUrl || `${WP?.restUrl || '/wp-json/'}mjto/v1/`;
  const res = await fetch(`${base}${path.replace(/^\//, '')}`, {
    method,
    credentials: 'same-origin',
    headers: {
      ...(body ? { 'Content-Type': 'application/json' } : {}),
      ...(withNonce ? { 'X-WP-Nonce': WP.wpNonce } : {}),
    },
    body: body ? JSON.stringify(body) : undefined,
  });
  const data = await res.json().catch(() => null);
  if (!res.ok) {
    if (withNonce && data?.code === 'rest_cookie_invalid_nonce') return req(path, { method, body }, false);
    throw Object.assign(new Error(data?.message || `mjto/v1 ${res.status}`), {
      data,
      code: data?.code || `http_${res.status}`,
      status: res.status,
    });
  }
  return data;
}

/** Config pública del plugin (mismo shape que window.MJ.turnos), por si hay que refrescarla. */
export const getTurnosConfig = () => req('config');

/**
 * Disponibilidad de un servicio entre dos fechas ISO (máx. 62 días).
 * → { days: { 'YYYY-MM-DD': ['09:00', '09:30', …] }, timezone, now }
 * Con `token` es la disponibilidad para reprogramar ese turno (usa su
 * duración y no cuenta su propio horario): no se manda service_id.
 */
export const getAvailability = (serviceId, from, to, token = '') =>
  req(
    `availability?${token ? `token=${encodeURIComponent(token)}` : `service_id=${encodeURIComponent(serviceId)}`}` +
      `&from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`
  );

/**
 * Crea la reserva. payload: { service_id, date, time, first_name, last_name, phone,
 * email, locality, notes, extra: {}, website (honeypot, siempre vacío) }.
 * → { token, status, statusLabel, message, appointment, deposit, whatsapp, canCancel }
 * 409 (mjto_slot_taken) = el horario se ocupó: volver a elegir horario.
 */
export const createBooking = (payload) => req('bookings', { method: 'POST', body: payload });

/** Estado de una reserva por token → { status, statusLabel, appointment, deposit, whatsapp, canCancel, depositNotified }. */
export const getBooking = (token) => req(`bookings/${encodeURIComponent(token)}`);

/**
 * Reprograma el turno → vista del turno resultante. Con rescheduleTerms.mode
 * 'new' vuelve OTRO token (turno nuevo que espera seña).
 * 409 mjto_slot_taken, 400 mjto_cannot_reschedule / mjto_reschedule_same.
 */
export const rescheduleBooking = (token, date, time) =>
  req(`bookings/${encodeURIComponent(token)}/reschedule`, { method: 'POST', body: { date, time } });

/** La dueña canceló: la clienta elige 'refund' o 'credit' → vista del turno. ('reschedule' va por rescheduleBooking.) */
export const chooseOffer = (token, choice) =>
  req(`bookings/${encodeURIComponent(token)}/choice`, { method: 'POST', body: { choice } });

/** Opinión de un turno atendido → { ok, status: 'approved'|'pending', message, review }. */
export const submitReview = (token, { rating, body = '', name = '' }) =>
  req(`bookings/${encodeURIComponent(token)}/review`, { method: 'POST', body: { rating, body, name } });

/** Opiniones publicadas → { count, average, items: [review] }. */
export const getReviews = ({ limit = 12, serviceId = 0 } = {}) =>
  req(`reviews?limit=${encodeURIComponent(limit)}${serviceId ? `&service_id=${encodeURIComponent(serviceId)}` : ''}`);

/**
 * Con sesión: datos para precargar la reserva →
 * { user: {id, name, email}, client: {firstName, lastName, phone, email, locality, birthdate, extra}, credit }.
 */
export const getMe = () => req('me');

/** Con sesión: "Mis turnos" → { upcoming: [item], past: [item], credit, movements: [...] }. 401 sin sesión. */
export const getMyBookings = () => req('me/bookings');

/** Cancela la reserva (si el plugin lo permite) → { ok, status, statusLabel, booking: vista }. */
export const cancelBooking = (token) => req(`bookings/${encodeURIComponent(token)}/cancel`, { method: 'POST' });

/** "Ya transferí la seña" → { ok }. */
export const notifyDeposit = (token, note = '') =>
  req(`bookings/${encodeURIComponent(token)}/deposit-notice`, { method: 'POST', body: { note } });

/** ¿El error de createBooking es "horario tomado"? (409, salvo el de la cuenta que ya existe) */
export const isSlotTakenError = (error) =>
  error?.code === 'mjto_slot_taken' || (error?.status === 409 && error?.code !== 'mjto_account_exists');
