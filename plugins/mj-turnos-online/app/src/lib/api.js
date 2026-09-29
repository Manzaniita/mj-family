/**
 * Cliente REST de MJ Turnos Online (wp-json/mjto/v1).
 *
 * Una función por endpoint de la spec A.8, agrupadas en `publicApi` (sin
 * sesión) y `panelApi` (capability mjto_manage + cookie auth + X-WP-Nonce).
 * Todas devuelven la respuesta JSON ya parseada y tiran `ApiError`
 * ({ message, code, status, data }) cuando el servidor responde con error.
 *
 * PATCH/DELETE viajan como POST + X-HTTP-Method-Override (WP lo respeta):
 * varios hostings compartidos bloquean esos verbos en el firewall.
 */
import { REST_URL, NONCE, URLS } from './config';

export class ApiError extends Error {
  constructor(message, code = 'error', status = 0, data = null) {
    super(message);
    this.name = 'ApiError';
    this.code = code;
    this.status = status;
    this.data = data;
  }
}

let nonce = NONCE;

/** Reemplaza el nonce (por ejemplo, si el servidor manda uno fresco). */
export function setNonce(value) {
  nonce = value || '';
}

const MESSAGES = {
  0: 'No se pudo conectar con el servidor. Revisá tu conexión.',
  400: 'Los datos enviados no son válidos.',
  401: 'Tu sesión venció. Volvé a ingresar.',
  403: 'No tenés permiso para hacer esto.',
  404: 'No se encontró lo que buscabas.',
  409: 'Ese horario ya no está disponible. Elegí otro.',
  429: 'Demasiados intentos seguidos. Esperá unos minutos y probá de nuevo.',
  500: 'Hubo un error en el servidor. Probá de nuevo en un momento.',
};

const defaultMessage = (status) => MESSAGES[status] || (status >= 500 ? MESSAGES[500] : 'Ocurrió un error inesperado.');

/** Serializa params a query string; arrays → key[]=a&key[]=b; vacíos se omiten. */
export function toQuery(params) {
  if (!params) return '';
  const sp = new URLSearchParams();
  Object.entries(params).forEach(([key, value]) => {
    if (value === undefined || value === null || value === '') return;
    if (Array.isArray(value)) {
      value.forEach((v) => {
        if (v !== undefined && v !== null && v !== '') sp.append(`${key}[]`, String(v));
      });
      return;
    }
    if (typeof value === 'boolean') {
      sp.set(key, value ? '1' : '0');
      return;
    }
    sp.set(key, String(value));
  });
  const q = sp.toString();
  return q ? `?${q}` : '';
}

const OVERRIDE = new Set(['PATCH', 'PUT', 'DELETE']);

/**
 * Llamada base. `path` relativo a mjto/v1 (sin barra inicial o con ella).
 * @param {string} path
 * @param {{ method?: string, body?: object, params?: object, signal?: AbortSignal }} [opts]
 */
export async function request(path, { method = 'GET', body, params, signal } = {}) {
  const url = REST_URL + String(path).replace(/^\/+/, '') + toQuery(params);
  const verb = method.toUpperCase();
  const override = OVERRIDE.has(verb);
  let res;
  try {
    res = await fetch(url, {
      method: override ? 'POST' : verb,
      credentials: 'same-origin',
      signal,
      headers: {
        Accept: 'application/json',
        ...(body !== undefined ? { 'Content-Type': 'application/json' } : {}),
        ...(nonce ? { 'X-WP-Nonce': nonce } : {}),
        ...(override ? { 'X-HTTP-Method-Override': verb } : {}),
      },
      body: body !== undefined ? JSON.stringify(body) : undefined,
    });
  } catch (err) {
    if (err && err.name === 'AbortError') throw err;
    throw new ApiError(MESSAGES[0], 'network_error', 0, null);
  }

  const fresh = res.headers.get('X-WP-Nonce');
  if (fresh) nonce = fresh;

  const text = await res.text();
  let data = null;
  if (text) {
    try {
      data = JSON.parse(text);
    } catch (e) {
      data = null;
    }
  }

  if (!res.ok) {
    const message = (data && data.message) || defaultMessage(res.status);
    const code = (data && data.code) || `http_${res.status}`;
    throw new ApiError(message, code, res.status, data && data.data ? data.data : data);
  }
  return data;
}

export const get = (path, params, opts = {}) => request(path, { ...opts, method: 'GET', params });
export const post = (path, body, opts = {}) => request(path, { ...opts, method: 'POST', body });
export const patch = (path, body, opts = {}) => request(path, { ...opts, method: 'PATCH', body });
export const del = (path, opts = {}) => request(path, { ...opts, method: 'DELETE' });

/* ---------------------------------------------------------------------------
 * Público (reserva online, estado de un turno)
 * ------------------------------------------------------------------------- */
export const publicApi = {
  /** GET /config → mjto_public_config() (mismo shape que window.MJ.turnos). */
  getConfig: (opts) => get('config', undefined, opts),

  /**
   * GET /availability?service_id&from&to → { days: {date: [slots]}, timezone, now }
   * Rango máximo 62 días (el servidor recorta).
   */
  getAvailability: (serviceId, from, to, opts) =>
    get('availability', { service_id: serviceId, from, to }, opts),

  /**
   * POST /bookings → 201 { token, status, statusLabel, message, appointment, deposit, whatsapp, canCancel }
   * @param {{ service_id, date, time, first_name, last_name, phone, email, locality, notes, extra, website }} payload
   */
  createBooking: (payload, opts) => post('bookings', payload, opts),

  /** GET /bookings/{token} → { status, statusLabel, appointment, deposit, whatsapp, canCancel, depositNotified } */
  getBooking: (token, opts) => get(`bookings/${encodeURIComponent(token)}`, undefined, opts),

  /** POST /bookings/{token}/cancel → { ok, status, statusLabel } (400 si ya no se puede). */
  cancelBooking: (token, opts) => post(`bookings/${encodeURIComponent(token)}/cancel`, {}, opts),

  /** POST /bookings/{token}/deposit-notice { note } → { ok } ("Ya transferí la seña"). */
  notifyDeposit: (token, note = '', opts) =>
    post(`bookings/${encodeURIComponent(token)}/deposit-notice`, { note }, opts),

  /**
   * GET /availability?token&from&to → igual que getAvailability, pero para
   * reprogramar ese turno (su duración, sin contar su propio horario). Sin service_id.
   */
  getRescheduleAvailability: (token, from, to, opts) =>
    get('availability', { token, from, to }, opts),

  /**
   * POST /bookings/{token}/reschedule { date, time } → vista del turno resultante.
   * Con rescheduleTerms.mode 'new' vuelve OTRO token (turno nuevo que espera seña).
   */
  rescheduleBooking: (token, date, time, opts) =>
    post(`bookings/${encodeURIComponent(token)}/reschedule`, { date, time }, opts),

  /** POST /bookings/{token}/choice { choice: 'refund'|'credit' } → vista del turno (cambia offer.state). */
  offerChoice: (token, choice, opts) =>
    post(`bookings/${encodeURIComponent(token)}/choice`, { choice }, opts),

  /** POST /bookings/{token}/review { rating, body, name } → { ok, status, message, review } */
  submitReview: (token, { rating, body = '', name = '' }, opts) =>
    post(`bookings/${encodeURIComponent(token)}/review`, { rating, body, name }, opts),

  /** GET /me (con sesión) → { user: {id, name, email}, client: {firstName, …, extra}, credit } */
  getMe: (opts) => get('me', undefined, opts),

  /** GET /me/bookings (con sesión) → { upcoming, past, credit, movements }; 401 sin sesión. */
  getMyBookings: (opts) => get('me/bookings', undefined, opts),
};

/* ---------------------------------------------------------------------------
 * Panel (capability mjto_manage)
 * ------------------------------------------------------------------------- */
export const panelApi = {
  /** GET /panel/bootstrap → { options, services, currency, user, today, now, counts, urls } */
  bootstrap: (opts) => get('panel/bootstrap', undefined, opts),

  /* ---- Turnos ---- */

  /**
   * GET /panel/appointments → { items, total }
   * @param {{ from?, to?, status?: string[], q?, client_id?, order?: 'asc'|'desc', page?, per_page? }} params
   */
  listAppointments: (params = {}, opts) => get('panel/appointments', params, opts),

  /**
   * POST /panel/appointments → turno (201)
   * @param {{ client_id?, client?: {first_name,last_name,phone,email,locality}, service_id?, service_name?, date, time, duration_min?, price?, status?, internal_notes?, force?, notify? }} payload
   */
  createAppointment: (payload, opts) => post('panel/appointments', payload, opts),

  /**
   * PATCH /panel/appointments/{id} → turno
   * @param {{ status?, date?, time?, duration_min?, service_id?, price?, deposit_paid?, deposit_method?, internal_notes?, client_notes?, cancel_reason?, notify?, force? }} payload
   */
  updateAppointment: (id, payload, opts) => patch(`panel/appointments/${id}`, payload, opts),

  /** DELETE /panel/appointments/{id} → { ok } */
  deleteAppointment: (id, opts) => del(`panel/appointments/${id}`, opts),

  /** POST /panel/appointments/{id}/receipt { decision, reason?, amount? } → turno */
  reviewReceipt: (id, payload, opts) => post(`panel/appointments/${id}/receipt`, payload, opts),

  /** Atajo: cambio de estado (+ motivo / aviso / seña). */
  setAppointmentStatus: (id, status, extra = {}, opts) =>
    patch(`panel/appointments/${id}`, { status, ...extra }, opts),

  /** Atajo: seña recibida → confirma y guarda monto/método. */
  depositReceived: (id, depositPaid, depositMethod = '', extra = {}, opts) =>
    patch(
      `panel/appointments/${id}`,
      { status: 'confirmed', deposit_paid: depositPaid, deposit_method: depositMethod, ...extra },
      opts
    ),

  /** Atajo: reprogramar (date + time, opcional duración/servicio/force/notify). */
  rescheduleAppointment: (id, date, time, extra = {}, opts) =>
    patch(`panel/appointments/${id}`, { date, time, ...extra }, opts),

  /* ---- Clientas ---- */

  /**
   * GET /panel/clients → { items, total } (con visitsCount, lastVisit, nextAppointment)
   * @param {{ q?, year?, month?, order?: 'last_visit_desc'|'name_asc'|'created_desc', page?, per_page? }} params
   */
  listClients: (params = {}, opts) => get('panel/clients', params, opts),

  /** GET /panel/clients/{id} → { client, visits, appointments } */
  getClient: (id, opts) => get(`panel/clients/${id}`, undefined, opts),

  /** POST /panel/clients { first_name, last_name, phone, email, locality, age, notes, extra } → clienta (201) */
  createClient: (payload, opts) => post('panel/clients', payload, opts),

  /** PATCH /panel/clients/{id} → clienta */
  updateClient: (id, payload, opts) => patch(`panel/clients/${id}`, payload, opts),

  /** DELETE /panel/clients/{id} → { ok } (borra también visitas y turnos) */
  deleteClient: (id, opts) => del(`panel/clients/${id}`, opts),

  /* ---- Visitas (ficha técnica) ---- */

  /**
   * POST /panel/clients/{clientId}/visits → visita (201)
   * @param {{ appointment_id?, visited_at?, service_id?, data?, carry?: boolean }} payload
   */
  createVisit: (clientId, payload = {}, opts) => post(`panel/clients/${clientId}/visits`, payload, opts),

  /**
   * PATCH /panel/visits/{id} → visita
   * @param {{ visited_at?, service_id?, service_name?, data?, total?, deposit?, payment_method?, control_date?, retouch_date? }} payload
   */
  updateVisit: (id, payload, opts) => patch(`panel/visits/${id}`, payload, opts),

  /** DELETE /panel/visits/{id} → { ok } */
  deleteVisit: (id, opts) => del(`panel/visits/${id}`, opts),

  /* ---- Caja ---- */

  /** GET /panel/cash?from&to → { totals: {collected, deposits, visits, byMethod}, items } */
  getCash: (from, to, opts) => get('panel/cash', { from, to }, opts),

  /* ---- Bloqueos ---- */

  /** GET /panel/blocks?from&to → [{ id, startsAt, endsAt, reason, allDay }] */
  listBlocks: (from, to, opts) => get('panel/blocks', { from, to }, opts),

  /** POST /panel/blocks { date, date_to, all_day, from_time, to_time, reason } → bloqueo (201) */
  createBlock: (payload, opts) => post('panel/blocks', payload, opts),

  /** DELETE /panel/blocks/{id} → { ok } */
  deleteBlock: (id, opts) => del(`panel/blocks/${id}`, opts),

  /* ---- Disponibilidad para el panel ---- */

  /**
   * GET /panel/availability?service_id&date&exclude_id&ignore_notice → { slots: ['09:00', …] }
   * @param {{ serviceId?, date, excludeId?, ignoreNotice?: boolean }} args
   */
  getSlots: ({ serviceId = 0, date, excludeId = 0, ignoreNotice = true, durationMin = 0 } = {}, opts) =>
    get(
      'panel/availability',
      {
        service_id: serviceId,
        date,
        exclude_id: excludeId || undefined,
        ignore_notice: ignoreNotice ? 1 : undefined,
        // Turno libre ("Otro"): sin servicio la grilla se calcula con esta duración.
        duration_min: !serviceId && durationMin > 0 ? durationMin : undefined,
      },
      opts
    ),

  /* ---- Agenda del día y del mes (1.3) ---- */

  /** GET /panel/day?date → { date, open, closedReason, ranges, free: [{from, to, minutes}], blocks, appointments } */
  getDay: (date, opts) => get('panel/day', { date }, opts),

  /** GET /panel/month?month=YYYY-MM → { month, days: { 'YYYY-MM-DD': { count, open, blocked } } } */
  getMonth: (month, opts) => get('panel/month', { month }, opts),

  /* ---- Seña cancelada por la dueña (1.3) ---- */

  /** Atajo: la devolución de la seña ya se hizo → PATCH { owner_offer: 'refunded' }. */
  markRefunded: (id, opts) => patch(`panel/appointments/${id}`, { owner_offer: 'refunded' }, opts),

  /* ---- Saldo a favor (1.3) ---- */

  /** GET /panel/clients/{id}/credit → { balance, movements } */
  getClientCredit: (id, opts) => get(`panel/clients/${id}/credit`, undefined, opts),

  /** POST /panel/clients/{id}/credit { amount (+ suma / - descuenta), reason } → { balance, movements } */
  addClientCredit: (id, amount, reason = '', opts) => post(`panel/clients/${id}/credit`, { amount, reason }, opts),

  /* ---- Opiniones (1.3) ---- */

  /** GET /panel/reviews?status → { items, total, counts: { pending, approved, hidden } } */
  listReviews: (params = {}, opts) => get('panel/reviews', params, opts),

  /** PATCH /panel/reviews/{id} { status?, author_name?, body? } → opinión */
  updateReview: (id, payload, opts) => patch(`panel/reviews/${id}`, payload, opts),

  /** DELETE /panel/reviews/{id} → { ok } */
  deleteReview: (id, opts) => del(`panel/reviews/${id}`, opts),
};

/**
 * URL del export CSV (admin-post con nonce): what ∈ clients|visits|appointments.
 * Abrir con <a href target="_blank"> (no es REST).
 */
export function exportUrl(what, from = '', to = '', base = URLS.exportBase) {
  if (!base) return '';
  const sep = base.includes('?') ? '&' : '?';
  const q = new URLSearchParams({ what });
  if (from) q.set('from', from);
  if (to) q.set('to', to);
  return `${base}${sep}${q.toString()}`;
}

const api = { public: publicApi, panel: panelApi, request, get, post, patch, del, exportUrl, setNonce, ApiError };
export default api;
