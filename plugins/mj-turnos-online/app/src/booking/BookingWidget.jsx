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
 */
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { ArrowRight, CalendarCheck, LayoutGrid, LogIn } from 'lucide-react';
import { PUBLIC_CONFIG } from '@/lib/config';
import { publicApi } from '@/lib/api';
import { cn } from '@/lib/format';
import { monthKey } from '@/lib/dates';
import { Button, LoadingBlock } from '@/ui';
import { Alert, BookingProvider, COPY_DEFAULTS, Stepper, clearToken, loadToken, loginUrl, normalizeConfig, panelUrl, saveToken, useBooking } from './shared';
import ServiceStep from './steps/ServiceStep';
import DateStep, { useAvailability } from './steps/DateStep';
import DetailsStep from './steps/DetailsStep';
import ResultStep from './steps/ResultStep';
import StatusView from './StatusView';

/** Busca el servicio preseleccionado por slug o id. */
function findPreselect(services, preselect) {
  const key = String(preselect || '').trim().toLowerCase();
  if (!key) return null;
  return services.find((s) => s.slug.toLowerCase() === key) || services.find((s) => String(s.id) === key) || null;
}

function Wizard({ preselect }) {
  const { t, cfg } = useBooking();
  const rootRef = useRef(null);
  const [view, setView] = useState('wizard');
  const [step, setStep] = useState(1);
  const [service, setService] = useState(null);
  const [date, setDate] = useState('');
  const [time, setTime] = useState('');
  const [values, setValues] = useState({});
  const [result, setResult] = useState(null);
  const [notice, setNotice] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [submitError, setSubmitError] = useState('');
  const [savedToken, setSavedToken] = useState(() => loadToken(cfg.slug));
  const [lastStatus, setLastStatus] = useState('');
  const availability = useAvailability(service ? service.id : 0, cfg.schedule);

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
      try {
        const res = await publicApi.createBooking(payload);
        setResult(res || {});
        if (res && res.token) {
          saveToken(cfg.slug, res.token);
          setSavedToken(res.token);
        }
        setStep(4);
      } catch (e) {
        if (e && (e.status === 409 || e.code === 'mjto_slot_taken')) {
          availability.invalidate(monthKey(payload.date));
          setTime('');
          setNotice(e.message || '');
          setStep(2);
        } else {
          setSubmitError(e?.message || '');
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
    setStep(1);
    setView('wizard');
  };

  const forgetToken = () => {
    clearToken(cfg.slug);
    setSavedToken('');
  };

  // Volver desde el estado: si el turno recién creado se canceló, no tiene sentido mostrar "¡Reserva recibida!".
  const backFromStatus = () => {
    if (step === 4 && lastStatus === 'cancelled') restart();
    else setView('wizard');
  };

  const panelHref = panelUrl(cfg);

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
          <StatusView token={savedToken} onBack={backFromStatus} onForget={forgetToken} onStatusChange={setLastStatus} />
        </div>
      ) : (
        <>
          {savedToken && step !== 4 && (
            <button
              type="button"
              onClick={() => setView('status')}
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
              />
            )}
            {step === 4 && <ResultStep result={result} service={service} onViewStatus={() => setView('status')} onRestart={restart} />}
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
