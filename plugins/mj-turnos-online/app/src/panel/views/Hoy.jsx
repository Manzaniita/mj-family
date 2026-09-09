/**
 * Hoy: agenda de un día. Navegación ‹ hoy › + selector de fecha, tira semanal
 * con puntitos por cantidad de turnos, bloqueos del día, lista cronológica
 * (tarjetas con acciones), cancelados/no vino plegados abajo, FAB "Nuevo turno".
 *
 * La fecha vive en ?fecha=YYYY-MM-DD (así "atrás" del navegador funciona y
 * NuevoTurno puede volver al día creado). Se carga la semana completa de una
 * vez (GET /panel/appointments?from&to) para la tira y el día.
 */
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { CalendarDays, CalendarOff, ChevronDown, ChevronLeft, ChevronRight, Plus, Sun, WifiOff } from 'lucide-react';
import { panelApi } from '@/lib/api';
import { cn, formatTime, longDateLabel, pluralize, relativeDayLabel, weekdayLabel } from '@/lib/format';
import { addDaysStr, isDateStr, splitDateTime, weekDates } from '@/lib/dates';
import { Button, EmptyState, Fab, PageHeader, SkeletonCard } from '@/ui';
import { usePanel } from '../store';
import { AppointmentCard } from '../components/AppointmentCard';
import { ACTIVE_STATUSES, applyAppointmentChange } from '../components/AppointmentActions';
import { NuevoTurno } from '../components/NuevoTurno';

const PER_PAGE = 100;
const MAX_PAGES = 5;

/** Trae todas las páginas de un rango (una semana rara vez pasa de 100 turnos). */
async function fetchRange(from, to, signal) {
  const items = [];
  let page = 1;
  let total = 0;
  do {
    const res = await panelApi.listAppointments({ from, to, order: 'asc', page, per_page: PER_PAGE }, { signal });
    const batch = Array.isArray(res?.items) ? res.items : [];
    items.push(...batch);
    total = Number(res?.total) || items.length;
    page += 1;
    if (!batch.length) break;
  } while (items.length < total && page <= MAX_PAGES);
  return items;
}

const byTime = (a, b) => String(a.time || '').localeCompare(String(b.time || '')) || Number(a.id) - Number(b.id);

export default function Hoy() {
  const store = usePanel();
  const [params, setParams] = useSearchParams();
  const paramDate = params.get('fecha');
  const date = isDateStr(paramDate) ? paramDate : store.today;

  const paramsRef = useRef(params);
  paramsRef.current = params;
  const setDate = useCallback(
    (d) => {
      const next = new URLSearchParams(paramsRef.current);
      if (!d || d === store.today) next.delete('fecha');
      else next.set('fecha', d);
      setParams(next, { replace: true });
    },
    [setParams, store.today]
  );

  const week = useMemo(() => weekDates(date, store.weekStart), [date, store.weekStart]);
  const from = week[0];
  const to = week[6];

  const [items, setItems] = useState([]);
  const [blocks, setBlocks] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [showInactive, setShowInactive] = useState(false);
  const [creating, setCreating] = useState(false);
  const [tick, setTick] = useState(0);
  const dateInput = useRef(null);

  // Carga de la semana (turnos + bloqueos). Se cancela si cambia la semana antes de terminar.
  useEffect(() => {
    const ctrl = new AbortController();
    setLoading(true);
    setError(null);
    Promise.all([
      fetchRange(from, to, ctrl.signal),
      panelApi.listBlocks(from, to, { signal: ctrl.signal }).catch(() => []),
    ])
      .then(([appts, blks]) => {
        if (ctrl.signal.aborted) return;
        setItems(appts);
        setBlocks(Array.isArray(blks) ? blks : Array.isArray(blks?.items) ? blks.items : []);
      })
      .catch((e) => {
        if (ctrl.signal.aborted || e?.name === 'AbortError') return;
        setError(e);
      })
      .finally(() => {
        if (!ctrl.signal.aborted) setLoading(false);
      });
    return () => ctrl.abort();
  }, [from, to, tick]);

  const reload = () => setTick((t) => t + 1);

  const handleChange = useCallback((next, meta) => {
    setItems((list) => applyAppointmentChange(list, next, meta));
  }, []);

  // Turnos del día elegido: activos en orden, cancelados/no vino aparte.
  const day = useMemo(() => items.filter((a) => a.date === date).sort(byTime), [items, date]);
  const active = useMemo(() => day.filter((a) => !['cancelled', 'no_show'].includes(a.status)), [day]);
  const inactive = useMemo(() => day.filter((a) => ['cancelled', 'no_show'].includes(a.status)), [day]);

  // Contadores por día para la tira (sólo los que ocupan agenda).
  const countByDay = useMemo(() => {
    const map = {};
    items.forEach((a) => {
      if (!ACTIVE_STATUSES.includes(a.status)) return;
      map[a.date] = (map[a.date] || 0) + 1;
    });
    return map;
  }, [items]);

  // Bloqueos que tocan el día.
  const dayBlocks = useMemo(() => {
    const start = `${date} 00:00`;
    const end = `${date} 23:59`;
    return blocks.filter((b) => String(b.startsAt || '').slice(0, 16) <= end && String(b.endsAt || '').slice(0, 16) >= start);
  }, [blocks, date]);

  const summary = useMemo(() => {
    if (!day.length) return '';
    const parts = [pluralize(day.length, 'turno')];
    const counts = {};
    day.forEach((a) => {
      counts[a.status] = (counts[a.status] || 0) + 1;
    });
    ['confirmed', 'pending', 'awaiting_deposit', 'completed'].forEach((k) => {
      if (counts[k]) parts.push(`${counts[k]} ${store.statusLabel(k).toLowerCase()}`);
    });
    return parts.join(' · ');
  }, [day, store]);

  const rel = relativeDayLabel(date, store.today);
  const title = rel || weekdayLabel(date, 'long');
  const isToday = date === store.today;

  // El input nativo (transparente) tapa el botón: el click cae en el input y
  // abrimos el picker desde ahí; el botón queda para el teclado.
  const openDatePicker = () => {
    const el = dateInput.current;
    if (!el) return;
    if (typeof el.showPicker === 'function') {
      try {
        el.showPicker();
        return;
      } catch (e) {
        /* navegadores que exigen gesto directo: cae al foco */
      }
    }
    el.focus();
  };

  return (
    <div className="mjto-view mjto-view--hoy">
      <PageHeader
        eyebrow="Agenda"
        title={title}
        subtitle={longDateLabel(date, { year: true })}
        actions={
          <div className="mjto-daynav flex items-center gap-1">
            <Button variant="ghost" size="sm" iconOnly icon={ChevronLeft} aria-label="Día anterior" onClick={() => setDate(addDaysStr(date, -1))} />
            <Button variant={isToday ? 'soft' : 'secondary'} size="sm" icon={Sun} onClick={() => setDate(store.today)} disabled={isToday} className="hidden sm:inline-flex">
              Hoy
            </Button>
            <Button variant="ghost" size="sm" iconOnly icon={ChevronRight} aria-label="Día siguiente" onClick={() => setDate(addDaysStr(date, 1))} />
            <span className="relative inline-flex">
              <Button variant="ghost" size="sm" iconOnly icon={CalendarDays} aria-label="Ir a una fecha" onClick={openDatePicker} />
              <input
                ref={dateInput}
                type="date"
                value={date}
                onClick={openDatePicker}
                onChange={(e) => {
                  if (isDateStr(e.target.value)) setDate(e.target.value);
                }}
                className="absolute inset-0 h-full w-full cursor-pointer opacity-0"
                tabIndex={-1}
                aria-hidden="true"
              />
            </span>
          </div>
        }
      >
        <WeekStrip week={week} date={date} today={store.today} counts={countByDay} onPick={setDate} />
      </PageHeader>

      {!isToday && (
        <div className="mb-3 sm:hidden">
          <Button variant="soft" size="sm" icon={Sun} onClick={() => setDate(store.today)}>
            Volver a hoy
          </Button>
        </div>
      )}

      {dayBlocks.length > 0 && (
        <ul className="mjto-day-blocks mb-3 flex flex-col gap-2">
          {dayBlocks.map((b) => (
            <li key={b.id} className="flex items-center gap-2.5 rounded-tile bg-warning-soft px-3.5 py-2.5 text-sm text-warning">
              <CalendarOff size={16} className="shrink-0" aria-hidden="true" />
              <span className="min-w-0 flex-1 truncate">
                <span className="font-semibold">Bloqueo</span> · {blockLabel(b, date)}
                {b.reason ? ` · ${b.reason}` : ''}
              </span>
            </li>
          ))}
        </ul>
      )}

      {summary && <p className="mjto-day-summary mb-3 text-sm text-ink-soft">{summary}</p>}

      {error ? (
        <EmptyState
          icon={WifiOff}
          title="No se pudo cargar la agenda"
          text={error.message}
          action={<Button onClick={reload}>Reintentar</Button>}
        />
      ) : loading && !items.length ? (
        <div className="flex flex-col gap-3" aria-busy="true">
          <SkeletonCard />
          <SkeletonCard />
          <SkeletonCard />
        </div>
      ) : !day.length ? (
        <EmptyState
          icon={CalendarDays}
          title={isToday ? 'Hoy no tenés turnos' : `Sin turnos el ${weekdayLabel(date, 'long').toLowerCase()}`}
          text="Cuando reserven desde la web los vas a ver acá. También podés cargar uno vos."
          action={
            <Button icon={Plus} onClick={() => setCreating(true)}>
              Nuevo turno
            </Button>
          }
        />
      ) : (
        <>
          <ul className={cn('mjto-day-list flex flex-col gap-3', loading && 'opacity-70')} aria-busy={loading || undefined}>
            {active.map((a) => (
              <li key={a.id}>
                <AppointmentCard appointment={a} onChange={handleChange} />
              </li>
            ))}
          </ul>
          {!active.length && <p className="mb-3 text-sm text-ink-faint">Todos los turnos de este día están cancelados.</p>}
          {inactive.length > 0 && (
            <div className="mjto-day-inactive mt-4">
              <button
                type="button"
                onClick={() => setShowInactive((v) => !v)}
                aria-expanded={showInactive}
                className="inline-flex items-center gap-1.5 text-[13px] font-semibold text-ink-faint hover:text-ink"
              >
                <ChevronDown size={16} className={cn('transition-transform', showInactive && 'rotate-180')} aria-hidden="true" />
                {pluralize(inactive.length, 'turno cancelado o no vino', 'turnos cancelados o no vino')}
              </button>
              {showInactive && (
                <ul className="mt-3 flex flex-col gap-3">
                  {inactive.map((a) => (
                    <li key={a.id}>
                      <AppointmentCard appointment={a} onChange={handleChange} />
                    </li>
                  ))}
                </ul>
              )}
            </div>
          )}
        </>
      )}

      <Fab icon={Plus} label="Nuevo turno" onClick={() => setCreating(true)} />

      <NuevoTurno
        open={creating}
        onClose={() => setCreating(false)}
        date={date}
        onCreated={(appt) => {
          if (appt?.date) setDate(appt.date);
          reload();
        }}
      />
    </div>
  );
}

/* ------------------------------------------------------------------------- */

/** Tira semanal: día, número y puntitos (hasta 3) por cantidad de turnos. */
function WeekStrip({ week, date, today, counts, onPick }) {
  return (
    <div className="mjto-week mjto-card grid grid-cols-7 gap-1 p-1.5" role="group" aria-label="Semana">
      {week.map((d) => {
        const n = counts[d] || 0;
        const selected = d === date;
        const isToday = d === today;
        return (
          <button
            key={d}
            type="button"
            onClick={() => onPick(d)}
            aria-pressed={selected}
            aria-label={`${longDateLabel(d)}${n ? `, ${pluralize(n, 'turno')}` : ''}`}
            className={cn(
              'mjto-week__day flex flex-col items-center gap-1 rounded-xl py-2 transition-[background-color,color,transform] active:scale-95',
              selected ? 'mjto-week__day--active bg-accent text-on-accent shadow-glow' : 'text-ink hover:bg-surface-2'
            )}
            data-today={isToday ? 'true' : undefined}
          >
            <span className={cn('text-[10px] font-semibold uppercase tracking-wider', selected ? 'opacity-80' : 'text-ink-faint')}>
              {weekdayLabel(d, 'short').slice(0, 3)}
            </span>
            <span className={cn('font-display text-lg font-bold leading-none mjto-tabular', isToday && !selected && 'text-accent')}>{Number(d.slice(8, 10))}</span>
            <span className="flex h-1.5 items-center gap-0.5" aria-hidden="true">
              {Array.from({ length: Math.min(3, n) }, (_, i) => (
                <span key={i} className={cn('size-1.5 rounded-full', selected ? 'bg-on-accent' : 'bg-accent')} />
              ))}
              {n > 3 && <span className={cn('text-[9px] font-bold leading-none', selected ? 'text-on-accent' : 'text-accent')}>+</span>}
            </span>
          </button>
        );
      })}
    </div>
  );
}

/** "Todo el día" o la franja del bloqueo dentro de la fecha. */
function blockLabel(block, date) {
  const s = splitDateTime(block.startsAt);
  const e = splitDateTime(block.endsAt);
  if (block.allDay || !s || !e) return 'Todo el día';
  const startsBefore = s.date < date;
  const endsAfter = e.date > date;
  if (startsBefore && endsAfter) return 'Todo el día';
  const a = startsBefore ? '00:00' : formatTime(s.time);
  const b = endsAfter ? '23:59' : formatTime(e.time);
  return `${a} – ${b}`;
}
