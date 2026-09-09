/**
 * Paso 2 — Día y hora: calendario mensual (días habilitados según la
 * disponibilidad del mes cargado, navegación hasta maxDaysAhead) + horarios
 * libres del día como chips.
 *
 * `useAvailability()` vive acá y lo usa el widget: cachea la disponibilidad
 * por servicio y mes, expone `invalidate()` para el 409 (horario tomado) y
 * toma "hoy" de la respuesta del servidor (zona horaria del sitio).
 */
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { ArrowLeft, ArrowRight, CalendarDays, Clock } from 'lucide-react';
import { publicApi } from '@/lib/api';
import { cn, longDateLabel } from '@/lib/format';
import { addDaysStr, endOfMonth, monthKey, startOfMonth, todayStr } from '@/lib/dates';
import { Button, Calendar, SlotPicker } from '@/ui';
import { Alert, StepFooter, useBooking } from '../shared';

/**
 * @param {number} serviceId
 * @param {{ maxDaysAhead: number }} schedule
 * @returns {{ today, maxDate, month, setMonth, days, loading, loaded, error, hasDays, isEnabled, slotsFor, reload, invalidate }}
 */
export function useAvailability(serviceId, schedule) {
  const maxDaysAhead = Math.max(1, Number(schedule?.maxDaysAhead) || 60);
  const [today, setToday] = useState(() => todayStr());
  const [month, setMonth] = useState(() => monthKey(todayStr()));
  const [cache, setCache] = useState({});
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [version, setVersion] = useState(0);
  const abortRef = useRef(null);

  const maxDate = addDaysStr(today, maxDaysAhead);
  const entry = serviceId ? cache[`${serviceId}:${month}`] : null;

  useEffect(() => {
    if (!serviceId || entry) return undefined;
    const from = startOfMonth(`${month}-01`) < today ? today : startOfMonth(`${month}-01`);
    const to = endOfMonth(`${month}-01`) > maxDate ? maxDate : endOfMonth(`${month}-01`);
    const key = `${serviceId}:${month}`;
    if (from > to) {
      setCache((c) => ({ ...c, [key]: { days: {} } }));
      return undefined;
    }
    abortRef.current?.abort();
    const controller = new AbortController();
    abortRef.current = controller;
    setLoading(true);
    setError('');
    publicApi
      .getAvailability(serviceId, from, to, { signal: controller.signal })
      .then((res) => {
        const days = {};
        Object.entries((res && res.days) || {}).forEach(([d, slots]) => {
          days[d] = Array.isArray(slots) ? slots.map(String) : [];
        });
        setCache((c) => ({ ...c, [key]: { days } }));
        const now = res && typeof res.now === 'string' ? res.now.slice(0, 10) : '';
        if (/^\d{4}-\d{2}-\d{2}$/.test(now)) setToday((t) => (t === now ? t : now));
        setLoading(false);
      })
      .catch((e) => {
        if (e && e.name === 'AbortError') return;
        setError(e?.message || '');
        setLoading(false);
      });
    return () => controller.abort();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [serviceId, month, version, !!entry]);

  const days = entry?.days || {};
  const hasDays = useMemo(() => Object.values(days).some((s) => s.length > 0), [days]);

  const slotsFor = useCallback(
    (date) => {
      if (!date || !serviceId) return [];
      const e = cache[`${serviceId}:${monthKey(date)}`];
      return (e && e.days[date]) || [];
    },
    [cache, serviceId]
  );

  const isEnabled = useCallback((date) => (days[date] || []).length > 0, [days]);

  const invalidate = useCallback(
    (mk) => {
      if (!serviceId) return;
      setCache((c) => {
        const next = { ...c };
        if (mk) delete next[`${serviceId}:${mk}`];
        else Object.keys(next).forEach((k) => k.startsWith(`${serviceId}:`) && delete next[k]);
        return next;
      });
      setVersion((v) => v + 1);
    },
    [serviceId]
  );

  const reload = useCallback(() => invalidate(month), [invalidate, month]);

  return { today, maxDate, month, setMonth, days, loading, loaded: !!entry, error, hasDays, isEnabled, slotsFor, reload, invalidate };
}

/**
 * @param {{ service, date, time, onDateChange, onTimeChange, onBack, onNext, notice?: string, availability }} props
 */
export default function DateStep({ service, date, time, onDateChange, onTimeChange, onBack, onNext, notice, availability }) {
  const { t, cfg, durationOf } = useBooking();
  const { today, maxDate, month, setMonth, loading, loaded, error, hasDays, isEnabled, slotsFor, reload } = availability;
  const slots = slotsFor(date);

  // Si el día elegido perdió sus horarios (recarga tras un 409), soltar la hora.
  useEffect(() => {
    if (time && date && loaded && !loading && !slots.includes(time)) onTimeChange('');
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [slots, loaded, loading]);

  return (
    <div className="mjto-booking__datetime animate-fade">
      {notice && <Alert tone="warning" className="mb-4">{notice}</Alert>}
      {service && (
        <p className="mb-4 text-sm text-ink-soft">
          <span className="font-semibold text-ink">{service.name}</span>
          {service.duration > 0 && <span> · {durationOf(service.duration)}</span>}
        </p>
      )}
      <div className="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,22rem)_minmax(0,1fr)] lg:gap-10">
        <section className="mjto-booking__calendar" aria-label={t('booking_pick_day')}>
          <h3 className="mb-3 flex items-center gap-2 font-display text-[15px] font-semibold tracking-tight text-ink">
            <CalendarDays size={17} className="text-accent" aria-hidden="true" />
            {t('booking_pick_day')}
          </h3>
          <div className="mjto-card p-3 sm:p-4">
            <Calendar
              value={date}
              onChange={(d) => {
                onDateChange(d);
                onTimeChange('');
              }}
              month={month}
              onMonthChange={setMonth}
              weekStart={cfg.schedule.weekStart}
              min={today}
              max={maxDate}
              today={today}
              isEnabled={isEnabled}
              loading={loading}
            />
          </div>
          {loaded && !loading && !hasDays && !error && <p className="mt-3 text-sm text-ink-soft">{t('booking_no_days')}</p>}
          {error && (
            <Alert
              tone="danger"
              className="mt-3"
              action={
                <Button size="xs" variant="outline" onClick={reload}>
                  {t('booking_retry')}
                </Button>
              }
            >
              {error}
            </Alert>
          )}
        </section>

        <section className="mjto-booking__slots" aria-label={t('booking_pick_time')} aria-live="polite">
          <h3 className="mb-3 flex items-center gap-2 font-display text-[15px] font-semibold tracking-tight text-ink">
            <Clock size={17} className="text-accent" aria-hidden="true" />
            {t('booking_pick_time')}
            {date && <span className="ml-1 text-sm font-medium capitalize text-ink-faint">· {longDateLabel(date)}</span>}
          </h3>
          {date ? (
            <SlotPicker
              slots={slots}
              value={time}
              onChange={onTimeChange}
              loading={loading && !slots.length}
              emptyText={t('booking_no_slots')}
              groupLabels={{ morning: t('booking_slots_morning'), afternoon: t('booking_slots_afternoon'), evening: t('booking_slots_evening') }}
            />
          ) : (
            <p className={cn('rounded-tile border border-dashed border-line px-4 py-8 text-center text-sm text-ink-faint')}>{t('booking_pick_day')}</p>
          )}
        </section>
      </div>
      <StepFooter>
        <Button variant="ghost" onClick={onBack} icon={ArrowLeft}>
          {t('booking_back')}
        </Button>
        <Button onClick={onNext} disabled={!date || !time} iconRight={ArrowRight} size="lg">
          {t('booking_next')}
        </Button>
      </StepFooter>
    </div>
  );
}
