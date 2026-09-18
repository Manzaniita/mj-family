/**
 * Widget de reserva standalone (shortcode [mj_turnos servicio=""]).
 *
 * Wizard de 4 pasos (spec A.11): servicio → día y hora → tus datos → listo.
 * Lee window.MJTO.config (mjto_public_config(), A.10) o lo pide a GET /config,
 * y usa publicApi.* de @/lib/api. Maneja 409 (horario tomado → vuelve al paso 2
 * y recarga la disponibilidad), guarda el token en sessionStorage y muestra el
 * estado de la reserva inline (StatusView).
 *
 * Preselección: prop `preselect` (slug o id) que main.jsx toma de
 * data-servicio / BOOT.preselect / ?servicio=.
 *
 * Vistas: 'wizard' (reserva), 'status' (estado de un turno por token) y
 * 'mine' ("Mis turnos", con ?mis-turnos=1). Arranca en 'status' si la URL es
 * /{slug}/t/{token} o trae ?turno={token}, si trae ?opinar=1 con un token guardado, o si la reserva
 * anterior creó la cuenta y recargó la página para tomar la sesión.
 *
 * Con sesión y cuentas prendidas, GET /me da el nombre ("Reservás como …") y,
 * con account.prefill, precarga el formulario (solo los campos vacíos).
 */
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { ArrowRight, CalendarCheck, CalendarDays, LayoutGrid, LogIn } from 'lucide-react';
import { PUBLIC_CONFIG } from '@/lib/config';
import { publicApi } from '@/lib/api';
import { cn } from '@/lib/format';
import { monthKey } from '@/lib/dates';
import { Button, LoadingBlock } from '@/ui';
import {
  Alert,
  BookingProvider,
  COPY_DEFAULTS,
  Stepper,
  clearToken,
  dropUrlParam,
  loadToken,
  loginUrl,
  normalizeConfig,
  panelUrl,
  pathToken,
  saveToken,
  takeOpenFlag,
  urlParam,
  useBooking,
} from './shared';
import ServiceStep from './steps/ServiceStep';
import DateStep, { useAvailability } from './steps/DateStep';
import DetailsStep from './steps/DetailsStep';
import ResultStep from './steps/ResultStep';
import StatusView from './StatusView';
import MyBookings from './MyBookings';

/** Busca el servicio preseleccionado por slug o id. */
function findPreselect(services, preselect) {
  const key = String(preselect || '').trim().toLowerCase();
  if (!key) return null;
  return services.find((s) => s.slug.toLowerCase() === key) || services.find((s) => String(s.id) === key) || null;
}

/** Campos de GET /me (client) → claves del formulario. Los demás salen de client.extra. */
const ME_KEYS = { first_name: 'firstName', last_name: 'lastName', phone: 'phone', email: 'email', locality: 'locality', birthdate: 'birthdate' };

/** Vista y token con los que arranca el widget (URL y sessionStorage). */
function initialState(cfg) {
  // Link del turno: /{slug}/t/{token} (con el tema) o ?turno={token} (página del shortcode).
  const fromQuery = urlParam('turno');
  const fromPath = pathToken(cfg.slug) || (/^[A-Za-z0-9]{8,64}$/.test(fromQuery) ? fromQuery : '');
  if (fromPath) return { view: 'status', token: fromPath };
  const saved = loadToken(cfg.slug);
  const open = takeOpenFlag(cfg.slug, { consume: false });
  if (urlParam('mis-turnos') === '1') return { view: 'mine', token: saved };
  if (saved && (open || urlParam('opinar') === '1')) return { view: 'status', token: saved };
  return { view: 'wizard', token: saved };
}

function Wizard({ preselect }) {
  const { t, cfg } = useBooking();
  const rootRef = useRef(null);
  const [initial] = useState(() => initialState(cfg));
  const [view, setView] = useState(initial.view);
  const [statusFrom, setStatusFrom] = useState(initial.view === 'mine' ? 'mine' : 'wizard');
  const [step, setStep] = useState(1);
  const [service, setService] = useState(null);
  const [date, setDate] = useState('');
  const [time, setTime] = useState('');
  const [values, setValues] = useState({});
  const [result, setResult] = useState(null);
  const [notice, setNotice] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [submitError, setSubmitError] = useState('');
  const [fieldErrors, setFieldErrors] = useState(null);
  const [accountExists, setAccountExists] = useState(false);
  const [savedToken, setSavedToken] = useState(initial.token);
  const [lastStatus, setLastStatus] = useState('');
  const [userName, setUserName] = useState('');
  const availability = useAvailability(service ? service.id : 0, cfg.schedule);

  // El pedido de "abrir el estado al cargar" se usa una sola vez.
  useEffect(() => {
    takeOpenFlag(cfg.slug);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // Con sesión (cuentas prendidas): nombre para "Reservás como …" y precarga del formulario.
  const useAccount = cfg.account.mode !== 'off' && cfg.account.loggedIn && !cfg.canManage;
  useEffect(() => {
    if (!useAccount) return undefined;
    let alive = true;
    publicApi
      .getMe()
      .then((me) => {
        if (!alive || !me) return;
        setUserName(String(me.user?.name || ''));
        const client = me.client;
        if (!cfg.account.prefill || !client || typeof client !== 'object') return;
        const extra = client.extra && typeof client.extra === 'object' ? client.extra : {};
        const filled = {};
        cfg.form.fields.forEach((f) => {
          const raw = ME_KEYS[f.key] ? client[ME_KEYS[f.key]] : extra[f.key];
          if (raw === null || raw === undefined || typeof raw === 'object') return;
          const v = String(raw);
          if (v !== '') filled[f.key] = v;
        });
        // Solo lo que sigue vacío: si ya empezó a escribir, no se pisa.
        setValues((prev) => {
          const next = { ...prev };
          Object.entries(filled).forEach(([k, v]) => {
            if (next[k] === undefined || next[k] === '') next[k] = v;
          });
          return next;
        });
      })
      .catch(() => {
        /* Sin /me (sesión vencida, página en caché): se reserva igual, sin precarga. */
      });
    return () => {
      alive = false;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [useAccount]);

  // Preselección: arranca en el paso 2 con el servicio elegido.
  useEffect(() => {
    const found = findPreselect(cfg.services, preselect);
    if (found) {
      setService(found);
      setStep(2);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [preselect, cfg.services]);

  // Al cambiar de paso, llevar el widget a la vista (en celulares el paso nuevo queda abajo).
  const firstRender = useRef(true);
  useEffect(() => {
    if (firstRender.current) {
      firstRender.current = false;
      return;
    }
    const el = rootRef.current;
    if (!el || typeof el.getBoundingClientRect !== 'function' || typeof el.scrollIntoView !== 'function') return;
    const top = el.getBoundingClientRect().top;
    if (top < 0 || top > 120) el.scrollIntoView({ block: 'start', behavior: 'smooth' });
  }, [step, view]);

  const steps = useMemo(
    () => [t('booking_step_service'), t('booking_step_date'), t('booking_step_details'), t('booking_step_done')],
    [t]
  );

  const pickService = (s) => {
    if (!service || service.id !== s.id) {
      setDate('');
      setTime('');
    }
    setService(s);
    setNotice('');
  };

  const goTo = (n) => {
    if (n < step) {
      setStep(n);
      setSubmitError('');
    }
  };

  const submit = useCallback(
    async (payload) => {
      setSubmitting(true);
      setSubmitError('');
      setFieldErrors(null);
      setAccountExists(false);
      try {
        const res = await publicApi.createBooking(payload);
        setResult(res || {});
        if (res && res.token) {
          saveToken(cfg.slug, res.token);
          setSavedToken(res.token);
        }
        setStep(4);
      } catch (e) {
        const errs = e?.data && typeof e.data === 'object' ? e.data.errors || e.data.params || null : null;
        if (e && e.code === 'mjto_account_exists') {
          // Ya hay una cuenta con ese email: el mensaje y el enlace para ingresar.
          setAccountExists(true);
          setSubmitError(e.message || '');
          if (errs && typeof errs === 'object') setFieldErrors(errs);
        } else if (e && (e.status === 409 || e.code === 'mjto_slot_taken')) {
          availability.invalidate(monthKey(payload.date));
          setTime('');
          setNotice(e.message || '');
          setStep(2);
        } else {
          setSubmitError(e?.message || '');
          if (errs && typeof errs === 'object') setFieldErrors(errs);
        }
      } finally {
        setSubmitting(false);
      }
    },
    [availability, cfg.slug]
  );

  const restart = () => {
    setResult(null);
    setService(null);
    setDate('');
    setTime('');
    setValues({});
    setNotice('');
    setSubmitError('');
    setFieldErrors(null);
    setAccountExists(false);
    setStep(1);
    setView('wizard');
  };

  const forgetToken = () => {
    clearToken(cfg.slug);
    setSavedToken('');
  };

  // Volver desde el estado: si el turno recién creado se canceló, no tiene sentido mostrar "¡Reserva recibida!".
  const backFromStatus = () => {
    if (statusFrom === 'mine') setView('mine');
    else if (step === 4 && lastStatus === 'cancelled') restart();
    else setView('wizard');
  };

  const openStatus = (from = 'wizard') => {
    // La reserva creó la cuenta: recargar para que la página tome la sesión (y el nonce) nuevos.
    if (from === 'wizard' && result && result.accountCreated && savedToken) {
      saveToken(cfg.slug, savedToken, { open: true });
      try {
        window.location.reload();
        return;
      } catch (e) {
        /* sin recarga: se muestra igual */
      }
    }
    setStatusFrom(from);
    setView('status');
  };

  // "Mis turnos" → estado de uno de sus turnos (queda como el turno guardado).
  const openFromMine = (tk) => {
    saveToken(cfg.slug, tk);
    setSavedToken(tk);
    setLastStatus('');
    openStatus('mine');
  };

  // Reprogramado con turno nuevo (mode 'new'): seguir con ese token.
  const changeToken = (tk) => {
    saveToken(cfg.slug, tk);
    setSavedToken(tk);
  };

  const bookFromMine = () => {
    dropUrlParam('mis-turnos');
    setView('wizard');
  };

  const panelHref = panelUrl(cfg);
  const myBookingsHref = cfg.account.mode !== 'off' && cfg.account.loggedIn && !cfg.canManage ? cfg.account.myBookingsUrl : '';

  return (
    <div ref={rootRef} className="mjto-booking__widget scroll-mt-6">
      <header className="mjto-booking__header mb-6">
        <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
          <div className="min-w-0">
            {t('booking_eyebrow') && <p className="mb-1 text-[11px] font-bold uppercase tracking-[0.16em] text-accent">{t('booking_eyebrow')}</p>}
            <h2 className="mjto-balance font-display text-[1.75rem] font-bold leading-[1.08] tracking-tight text-ink sm:text-4xl">{t('booking_title')}</h2>
            {t('booking_subtitle') && <p className="mjto-balance mt-2 max-w-xl text-[15px] leading-relaxed text-ink-soft">{t('booking_subtitle')}</p>}
          </div>
          <div className="flex shrink-0 flex-wrap items-center gap-2">
            {cfg.canManage ? (
              <Button variant="soft" size="sm" href={panelHref} icon={LayoutGrid}>
                {t('booking_panel_link')}
              </Button>
            ) : myBookingsHref ? (
              view !== 'mine' && (
                <a href={myBookingsHref} className="inline-flex items-center gap-1.5 text-xs font-medium text-ink-faint hover:text-accent">
                  <CalendarDays size={13} aria-hidden="true" />
                  {t('my_bookings_title')}
                </a>
              )
            ) : (
              <a href={loginUrl(cfg)} className="inline-flex items-center gap-1.5 text-xs font-medium text-ink-faint hover:text-accent">
                <LogIn size={13} aria-hidden="true" />
                {t('booking_login_hint')}
              </a>
            )}
          </div>
        </div>
      </header>

      {view === 'status' ? (
        <div className="mjto-card p-5 sm:p-7">
          <StatusView token={savedToken} onBack={backFromStatus} onForget={forgetToken} onStatusChange={setLastStatus} onTokenChange={changeToken} />
        </div>
      ) : view === 'mine' ? (
        <div className="mjto-card p-5 sm:p-7">
          <MyBookings onOpen={openFromMine} onBook={bookFromMine} />
        </div>
      ) : (
        <>
          {savedToken && step !== 4 && (
            <button
              type="button"
              onClick={() => openStatus('wizard')}
              className="mjto-booking__status-link mb-5 flex w-full items-center gap-3 rounded-tile border border-accent/25 bg-accent-soft px-4 py-3 text-left text-sm font-semibold text-accent transition-colors hover:bg-accent/15"
            >
              <CalendarCheck size={18} className="shrink-0" aria-hidden="true" />
              <span className="min-w-0 flex-1">{t('booking_status_link')}</span>
              <ArrowRight size={16} className="shrink-0" aria-hidden="true" />
            </button>
          )}

          <div className="mb-6">
            <Stepper steps={steps} current={step} locked={step === 4 || submitting} onSelect={goTo} />
          </div>

          <div className={cn('mjto-booking__body mjto-card p-4 sm:p-6', step === 4 && 'sm:p-8')}>
            {step === 1 && <ServiceStep services={cfg.services} value={service} onChange={pickService} onNext={() => setStep(2)} />}
            {step === 2 && (
              <DateStep
                service={service}
                date={date}
                time={time}
                onDateChange={(d) => {
                  setDate(d);
                  setNotice('');
                }}
                onTimeChange={setTime}
                onBack={() => setStep(1)}
                onNext={() => setStep(3)}
                notice={notice}
                availability={availability}
              />
            )}
            {step === 3 && (
              <DetailsStep
                service={service}
                date={date}
                time={time}
                values={values}
                onChange={(key, v) => setValues((prev) => ({ ...prev, [key]: v }))}
                onBack={() => setStep(2)}
                onSubmit={submit}
                submitting={submitting}
                error={submitError}
                serverErrors={fieldErrors}
                accountExists={accountExists}
                userName={userName}
              />
            )}
            {step === 4 && <ResultStep result={result} service={service} onViewStatus={() => openStatus('wizard')} onRestart={restart} />}
          </div>
        </>
      )}
    </div>
  );
}

/**
 * @param {{ preselect?: string }} props
 */
export default function BookingWidget({ preselect = '' }) {
  const [raw, setRaw] = useState(PUBLIC_CONFIG);
  const [loading, setLoading] = useState(!PUBLIC_CONFIG);
  const [error, setError] = useState('');

  const load = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      const res = await publicApi.getConfig();
      setRaw(res || {});
    } catch (e) {
      setError(e?.message || '');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    if (!PUBLIC_CONFIG) load();
  }, [load]);

  const cfg = useMemo(() => (raw ? normalizeConfig(raw) : null), [raw]);

  if (loading) {
    return (
      <div className="mjto-booking__widget">
        <LoadingBlock />
      </div>
    );
  }

  if (!cfg) {
    return (
      <div className="mjto-booking__widget">
        <Alert
          tone="danger"
          action={
            <Button size="xs" variant="outline" onClick={load}>
              {COPY_DEFAULTS.booking_retry}
            </Button>
          }
        >
          {error}
        </Alert>
      </div>
    );
  }

  if (!cfg.enabled) return null;

  return (
    <BookingProvider cfg={cfg}>
      <Wizard preselect={preselect} />
    </BookingProvider>
  );
}
