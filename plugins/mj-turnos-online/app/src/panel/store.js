/**
 * Estado global del panel: bootstrap (opciones, servicios, contadores, moneda,
 * usuario, hoy) + refresh() + helpers para etiquetas, servicios y dinero.
 *
 *   const { services, statusLabel, formatPrice, counts, refresh } = usePanel();
 *
 * El bootstrap viene inyectado en window.MJTO.bootstrap (inc/panel.php); si
 * falta (dev) se pide a GET /panel/bootstrap. `refresh()` vuelve a pedirlo
 * (contadores, servicios nuevos, opciones cambiadas) sin recargar la página.
 */
import { createContext, createElement, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import { BOOT, BUSINESS, CUR, URLS, USER } from '@/lib/config';
import { panelApi } from '@/lib/api';
import { formatPrice as fmtPrice, minutesToLabel, roundMoney, whatsappNumber, whatsappUrl } from '@/lib/format';
import { nowTimeStr, todayStr } from '@/lib/dates';

/* Fallbacks del panel (uso privado de la dueña): sólo si el bootstrap no trae la clave. */
const FALLBACK_STATUS_LABELS = {
  pending: 'Pendiente',
  awaiting_deposit: 'Esperando seña',
  confirmed: 'Confirmado',
  completed: 'Atendida',
  cancelled: 'Cancelado',
  no_show: 'No vino',
};

export const STATUS_KEYS = ['pending', 'awaiting_deposit', 'confirmed', 'completed', 'cancelled', 'no_show'];

const DEFAULT_SCHEDULE = {
  weeklyHours: {},
  slotStep: 30,
  bufferMin: 0,
  minNoticeHours: 12,
  maxDaysAhead: 60,
  weekStart: 1,
  defaultDurationMin: 120,
};

const DEFAULT_DEPOSIT = { type: 'fixed', amount: 0, percent: 30, hours: 24, instructions: '', link: '' };

const num = (v, d = 0) => (Number.isFinite(Number(v)) && v !== '' && v !== null ? Number(v) : d);
const bool = (v, d = false) => (v === undefined || v === null ? d : v === true || v === 1 || v === '1' || v === 'true');

/** Normaliza mjto_panel_bootstrap() con defaults para cada clave. */
export function normalizeBootstrap(raw) {
  const b = raw && typeof raw === 'object' ? raw : {};
  const o = b.options && typeof b.options === 'object' ? b.options : {};
  const schedule = { ...DEFAULT_SCHEDULE, ...(o.schedule || {}) };
  ['slotStep', 'bufferMin', 'minNoticeHours', 'maxDaysAhead', 'weekStart', 'defaultDurationMin'].forEach((k) => {
    schedule[k] = num(schedule[k], DEFAULT_SCHEDULE[k]);
  });
  const deposit = { ...DEFAULT_DEPOSIT, ...(o.deposit || {}) };
  deposit.amount = num(deposit.amount, 0);
  deposit.percent = num(deposit.percent, 30);
  deposit.hours = num(deposit.hours, 24);
  const cancel = { allowed: bool(o.cancel?.allowed, true), hoursBefore: num(o.cancel?.hoursBefore, 24) };
  const counts = { today: 0, pending: 0, awaitingDeposit: 0, depositNotified: 0, refundRequests: 0, pendingReviews: 0, ...(b.counts || {}) };
  Object.keys(counts).forEach((k) => {
    counts[k] = num(counts[k], 0);
  });
  return {
    options: {
      labels: o.labels && typeof o.labels === 'object' ? o.labels : {},
      fichaBlocks: Array.isArray(o.fichaBlocks) ? o.fichaBlocks : [],
      formFields: Array.isArray(o.formFields) ? o.formFields : [],
      paymentMethods: Array.isArray(o.paymentMethods) ? o.paymentMethods.map(String).filter(Boolean) : [],
      schedule,
      confirmMode: ['deposit', 'manual', 'auto'].includes(o.confirmMode) ? o.confirmMode : 'deposit',
      deposit,
      cancel,
      cajaEnabled: bool(o.cajaEnabled, true),
      /* 1.3: todo apagado si el bootstrap no lo trae (el panel se ve igual que antes). */
      ownerCancelOffer: bool(o.ownerCancelOffer, false),
      creditEnabled: bool(o.creditEnabled, false),
      reviewsEnabled: bool(o.reviewsEnabled, false),
      accountMode: ['optional', 'required'].includes(o.accountMode) ? o.accountMode : 'off',
      /* Agenda del día en Hoy (huecos libres, bloqueos intercalados, calendario del mes): prendida si no viene. */
      dayFreeGaps: bool(o.dayFreeGaps, true),
      business: { ...BUSINESS, ...(o.business || {}) },
      whatsappCountry: String(o.whatsappCountry || '54'),
      whatsappPrefix: String(o.whatsappPrefix || '549'),
    },
    services: Array.isArray(b.services) ? b.services : [],
    currency: { ...CUR, ...(b.currency || {}) },
    user: { ...USER, ...(b.user || {}) },
    today: typeof b.today === 'string' && b.today ? b.today : todayStr(),
    now: typeof b.now === 'string' && b.now ? b.now : `${todayStr()} ${nowTimeStr()}`,
    counts,
    urls: { ...URLS, ...(b.urls || {}) },
    version: b.version || '',
    timezone: b.timezone || '',
  };
}

const PanelContext = createContext(null);

/** Proveedor del estado del panel. */
export function PanelProvider({ children }) {
  const initial = BOOT.bootstrap && typeof BOOT.bootstrap === 'object' ? normalizeBootstrap(BOOT.bootstrap) : null;
  const [boot, setBoot] = useState(initial);
  const [loading, setLoading] = useState(!initial);
  const [error, setError] = useState(null);
  const lastRefresh = useRef(initial ? Date.now() : 0);
  const inflight = useRef(null);

  /** Vuelve a pedir el bootstrap (contadores, servicios, opciones). */
  const refresh = useCallback(async () => {
    if (inflight.current) return inflight.current;
    inflight.current = (async () => {
      try {
        const data = await panelApi.bootstrap();
        const next = normalizeBootstrap(data);
        setBoot(next);
        setError(null);
        lastRefresh.current = Date.now();
        return next;
      } finally {
        inflight.current = null;
      }
    })();
    return inflight.current;
  }, []);

  /** Carga inicial con estado de error (para el botón "Reintentar"). */
  const reload = useCallback(async () => {
    setLoading(true);
    try {
      await refresh();
    } catch (e) {
      setError(e);
    } finally {
      setLoading(false);
    }
  }, [refresh]);

  useEffect(() => {
    if (!initial) reload();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // Al volver a la pestaña (celu) refrescar contadores si pasó más de un minuto.
  useEffect(() => {
    const onVisible = () => {
      if (document.visibilityState === 'visible' && Date.now() - lastRefresh.current > 60000) {
        refresh().catch(() => {});
      }
    };
    document.addEventListener('visibilitychange', onVisible);
    return () => document.removeEventListener('visibilitychange', onVisible);
  }, [refresh]);

  /** Ajusta contadores en memoria (optimista) sin ir al servidor. */
  const setCounts = useCallback((patch) => {
    setBoot((b) => {
      if (!b) return b;
      const next = typeof patch === 'function' ? patch(b.counts) : { ...b.counts, ...patch };
      return { ...b, counts: next };
    });
  }, []);

  const value = useMemo(() => buildStore(boot, { loading, error, refresh, reload, setCounts }), [boot, loading, error, refresh, reload, setCounts]);

  return createElement(PanelContext.Provider, { value }, children);
}

/** Hook del panel. Fuera del proveedor devuelve un store vacío (no rompe). */
export function usePanel() {
  const ctx = useContext(PanelContext);
  return ctx || buildStore(null, { loading: true, error: null, refresh: async () => null, reload: async () => null, setCounts: () => {} });
}

function buildStore(boot, { loading, error, refresh, reload, setCounts }) {
  const b = boot || normalizeBootstrap(null);
  const o = b.options;
  const labels = o.labels;
  const currency = b.currency;

  const label = (key, fallback = '') => (labels[key] !== undefined && labels[key] !== '' ? String(labels[key]) : fallback);
  const statusLabel = (status) => label(status, labels.statuses?.[status] ?? FALLBACK_STATUS_LABELS[status] ?? status);
  const statuses = STATUS_KEYS.map((key) => ({ key, label: statusLabel(key) }));

  const services = b.services;
  const activeServices = services.filter((s) => s.active !== false);
  const bookableServices = activeServices.filter((s) => s.bookable !== false);
  const serviceById = (id) => services.find((s) => Number(s.id) === Number(id)) || null;
  const serviceName = (id, fallback = '') => serviceById(id)?.name || fallback;

  const fichaFields = o.fichaBlocks.flatMap((blk) => (Array.isArray(blk.fields) ? blk.fields : []).map((f) => ({ ...f, blockKey: blk.key })));
  const fieldsByRole = (role) => fichaFields.filter((f) => f.role === role);
  const fieldByRole = (role) => fichaFields.find((f) => f.role === role) || null;

  const formatPrice = (n) => fmtPrice(n, currency);
  const durationLabel = (min) => minutesToLabel(min, { hours: label('hours', 'h'), minutes: label('minutes', 'min') });

  /** Seña efectiva de un servicio (propia > global fixed/percent sobre el precio). */
  const depositFor = (serviceOrPrice) => {
    const svc = typeof serviceOrPrice === 'object' && serviceOrPrice ? serviceOrPrice : null;
    const price = svc ? num(svc.price, 0) : num(serviceOrPrice, 0);
    if (svc && num(svc.deposit, 0) > 0) return roundMoney(num(svc.deposit, 0), currency);
    if (o.deposit.type === 'percent') return roundMoney((price * o.deposit.percent) / 100, currency);
    return roundMoney(o.deposit.amount, currency);
  };

  /** Estado inicial de un turno nuevo según confirm_mode y la seña. */
  const initialStatus = (depositAmount = 0) => {
    if (o.confirmMode === 'auto') return 'confirmed';
    if (o.confirmMode === 'manual') return 'pending';
    return num(depositAmount, 0) > 0 ? 'awaiting_deposit' : 'pending';
  };

  const whatsappNumberFor = (phone) => whatsappNumber(phone, { country: o.whatsappCountry, prefix: o.whatsappPrefix });
  const whatsappLink = (phone, text = '') => whatsappUrl(whatsappNumberFor(phone), text);

  return {
    ready: !!boot,
    loading,
    error,
    boot: b,
    refresh,
    reload,
    setCounts,

    options: o,
    labels,
    label,
    statusLabel,
    statuses,
    services,
    activeServices,
    bookableServices,
    serviceById,
    serviceName,
    fichaBlocks: o.fichaBlocks,
    fichaFields,
    fieldsByRole,
    fieldByRole,
    formFields: o.formFields,
    paymentMethods: o.paymentMethods,
    schedule: o.schedule,
    weekStart: o.schedule.weekStart === 7 ? 7 : 1,
    confirmMode: o.confirmMode,
    deposit: o.deposit,
    cancel: o.cancel,
    cajaEnabled: o.cajaEnabled,
    ownerCancelOffer: o.ownerCancelOffer,
    creditEnabled: o.creditEnabled,
    reviewsEnabled: o.reviewsEnabled,
    accountMode: o.accountMode,
    dayFreeGaps: o.dayFreeGaps,
    business: o.business,
    currency,
    user: b.user,
    today: b.today,
    now: b.now,
    counts: b.counts,
    urls: b.urls,
    version: b.version,
    timezone: b.timezone,

    formatPrice,
    durationLabel,
    depositFor,
    initialStatus,
    whatsappNumberFor,
    whatsappLink,
  };
}
