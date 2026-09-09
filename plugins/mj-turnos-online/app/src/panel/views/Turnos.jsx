/**
 * Turnos: listado con filtros. Rangos rápidos (Hoy, Mañana, Semana, Mes,
 * Próximos, Pasados, Personalizado), chips de estado (multi), buscador por
 * nombre/teléfono (debounce 300 ms), lista agrupada por día con las mismas
 * tarjetas/acciones que Hoy y "Cargar más" (paginado de /panel/appointments).
 *
 * Los filtros viven en la URL (?rango&desde&hasta&estado&q) así se pueden
 * compartir y "atrás" los conserva. Las pastillas de la barra superior
 * pueden enlazar a /turnos?estado=pending, etc.
 */
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { BellRing, CalendarDays, Plus, Search, WifiOff, X } from 'lucide-react';
import { panelApi } from '@/lib/api';
import { friendlyDate, pluralize } from '@/lib/format';
import { addDaysStr, endOfMonth, endOfWeek, isDateStr, startOfMonth, startOfWeek } from '@/lib/dates';
import { Button, Chip, ChipGroup, DateInput, EmptyState, FieldRow, Input, PageHeader, SkeletonCard, STATUS_ORDER } from '@/ui';
import { usePanel } from '../store';
import { AppointmentCard } from '../components/AppointmentCard';
import { applyAppointmentChange } from '../components/AppointmentActions';
import { NuevoTurno } from '../components/NuevoTurno';

const PER_PAGE = 50;

const RANGES = [
  { key: 'hoy', label: 'Hoy' },
  { key: 'manana', label: 'Mañana' },
  { key: 'semana', label: 'Semana' },
  { key: 'mes', label: 'Mes' },
  { key: 'proximos', label: 'Próximos' },
  { key: 'pasados', label: 'Pasados' },
  { key: 'custom', label: 'Personalizado' },
];
const RANGE_KEYS = RANGES.map((r) => r.key);
const DEFAULT_RANGE = 'proximos';

/** Límites y orden del rango elegido. */
function rangeBounds(key, today, weekStart, custom) {
  switch (key) {
    case 'hoy':
      return { from: today, to: today, order: 'asc' };
    case 'manana': {
      const d = addDaysStr(today, 1);
      return { from: d, to: d, order: 'asc' };
    }
    case 'semana':
      return { from: startOfWeek(today, weekStart), to: endOfWeek(today, weekStart), order: 'asc' };
    case 'mes':
      return { from: startOfMonth(today), to: endOfMonth(today), order: 'asc' };
    case 'pasados':
      return { from: '', to: addDaysStr(today, -1), order: 'desc' };
    case 'custom':
      return { from: custom.from, to: custom.to, order: 'asc' };
    case 'proximos':
    default:
      return { from: today, to: '', order: 'asc' };
  }
}

export default function Turnos() {
  const store = usePanel();
  const [params, setParams] = useSearchParams();

  // Filtros desde la URL.
  const range = RANGE_KEYS.includes(params.get('rango')) ? params.get('rango') : DEFAULT_RANGE;
  const customFrom = isDateStr(params.get('desde')) ? params.get('desde') : '';
  const customTo = isDateStr(params.get('hasta')) ? params.get('hasta') : '';
  const statuses = useMemo(
    () =>
      String(params.get('estado') || '')
        .split(',')
        .map((s) => s.trim())
        .filter((s) => STATUS_ORDER.includes(s)),
    [params]
  );
  const q = String(params.get('q') || '').trim();
  const notifiedOnly = params.get('aviso') === '1';

  // Siempre sobre los params actuales (el debounce del buscador puede disparar
  // después de que se tocó un chip).
  const paramsRef = useRef(params);
  paramsRef.current = params;
  const update = useCallback(
    (patch) => {
      const next = new URLSearchParams(paramsRef.current);
      Object.entries(patch).forEach(([k, v]) => {
        if (v === undefined || v === null || v === '' || v === false) next.delete(k);
        else next.set(k, String(v));
      });
      setParams(next, { replace: true });
    },
    [setParams]
  );

  // Buscador con debounce hacia la URL.
  const [qInput, setQInput] = useState(q);
  useEffect(() => {
    setQInput(q);
  }, [q]);
  useEffect(() => {
    if (qInput.trim() === q) return undefined;
    const t = setTimeout(() => update({ q: qInput.trim() }), 300);
    return () => clearTimeout(t);
  }, [qInput]); // eslint-disable-line react-hooks/exhaustive-deps

  const bounds = useMemo(() => rangeBounds(range, store.today, store.weekStart, { from: customFrom, to: customTo }), [range, store.today, store.weekStart, customFrom, customTo]);
  const statusKey = statuses.join(',');

  // Lista paginada.
  const [items, setItems] = useState([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(0);
  const [loading, setLoading] = useState(true);
  const [more, setMore] = useState(false);
  const [error, setError] = useState(null);
  const [creating, setCreating] = useState(false);
  const [tick, setTick] = useState(0);
  const ctrlRef = useRef(null);

  const fetchPage = useCallback(
    async (nextPage) => {
      ctrlRef.current?.abort();
      const ctrl = new AbortController();
      ctrlRef.current = ctrl;
      if (nextPage === 1) setLoading(true);
      else setMore(true);
      setError(null);
      try {
        const res = await panelApi.listAppointments(
          {
            from: bounds.from || undefined,
            to: bounds.to || undefined,
            status: statuses.length ? statuses : undefined,
            q: q || undefined,
            order: bounds.order,
            page: nextPage,
            per_page: PER_PAGE,
          },
          { signal: ctrl.signal }
        );
        if (ctrl.signal.aborted) return;
        const batch = Array.isArray(res?.items) ? res.items : [];
        setItems((list) => (nextPage === 1 ? batch : [...list, ...batch.filter((b) => !list.some((a) => a.id === b.id))]));
        setTotal(Number(res?.total) || batch.length);
        setPage(nextPage);
      } catch (e) {
        if (ctrl.signal.aborted || e?.name === 'AbortError') return;
        setError(e);
      } finally {
        if (!ctrl.signal.aborted) {
          setLoading(false);
          setMore(false);
        }
      }
    },
    [bounds.from, bounds.to, bounds.order, statusKey, q] // eslint-disable-line react-hooks/exhaustive-deps
  );

  useEffect(() => {
    if (range === 'custom' && (!customFrom || !customTo)) {
      setItems([]);
      setTotal(0);
      setLoading(false);
      return undefined;
    }
    fetchPage(1);
    return () => ctrlRef.current?.abort();
  }, [fetchPage, range, customFrom, customTo, tick]);

  const reload = () => setTick((t) => t + 1);

  const handleChange = useCallback((next, meta) => {
    setItems((list) => applyAppointmentChange(list, next, meta));
    if (!next) setTotal((t) => Math.max(0, t - 1));
  }, []);

  const toggleStatus = (key) => {
    const set = new Set(statuses);
    if (set.has(key)) set.delete(key);
    else set.add(key);
    update({ estado: [...set].join(',') });
  };

  const visible = useMemo(() => (notifiedOnly ? items.filter((a) => !!a.depositNotifiedAt && a.status === 'awaiting_deposit') : items), [items, notifiedOnly]);

  const groups = useMemo(() => {
    const map = new Map();
    visible.forEach((a) => {
      if (!map.has(a.date)) map.set(a.date, []);
      map.get(a.date).push(a);
    });
    return [...map.entries()];
  }, [visible]);

  const hasMore = items.length < total;
  const customIncomplete = range === 'custom' && (!customFrom || !customTo);
  const hasFilters = statuses.length > 0 || !!q || notifiedOnly || range !== DEFAULT_RANGE;

  return (
    <div className="mjto-view mjto-view--turnos">
      <PageHeader
        eyebrow="Agenda"
        title="Turnos"
        subtitle={loading ? 'Cargando…' : customIncomplete ? 'Elegí las dos fechas del rango.' : `${pluralize(total, 'turno')}${notifiedOnly && visible.length !== items.length ? ` · ${visible.length} con aviso de seña` : ''}`}
        actions={
          <Button size="sm" icon={Plus} onClick={() => setCreating(true)}>
            <span className="hidden sm:inline">Nuevo turno</span>
            <span className="sm:hidden">Nuevo</span>
          </Button>
        }
      >
        <div className="mjto-filters flex flex-col gap-3">
          <ChipGroup scroll className="sm:flex-wrap sm:mx-0 sm:px-0">
            {RANGES.map((r) => (
              <Chip key={r.key} active={range === r.key} onClick={() => update({ rango: r.key === DEFAULT_RANGE ? '' : r.key })} size="sm" icon={r.key === 'custom' ? CalendarDays : undefined}>
                {r.label}
              </Chip>
            ))}
          </ChipGroup>

          {range === 'custom' && (
            <FieldRow className="max-w-md">
              <DateInput label="Desde" value={customFrom} onChange={(v) => update({ desde: v })} max={customTo || undefined} size="sm" />
              <DateInput label="Hasta" value={customTo} onChange={(v) => update({ hasta: v })} min={customFrom || undefined} size="sm" />
            </FieldRow>
          )}

          <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
            <Input
              type="search"
              icon={Search}
              size="sm"
              value={qInput}
              onChange={(e) => setQInput(e.target.value)}
              placeholder="Buscar por nombre o teléfono"
              aria-label="Buscar turnos"
              autoComplete="off"
              className="sm:max-w-xs sm:flex-1"
              suffix={
                qInput ? (
                  <button type="button" onClick={() => setQInput('')} className="grid size-7 place-items-center rounded-full hover:bg-surface-3" aria-label="Limpiar búsqueda">
                    <X size={14} aria-hidden="true" />
                  </button>
                ) : null
              }
            />
            <ChipGroup scroll className="sm:flex-wrap sm:mx-0 sm:px-0">
              {store.statuses.map((s) => (
                <Chip key={s.key} active={statuses.includes(s.key)} onClick={() => toggleStatus(s.key)} size="sm">
                  {s.label}
                </Chip>
              ))}
              <Chip
                active={notifiedOnly}
                onClick={() => update({ aviso: notifiedOnly ? '' : '1', estado: notifiedOnly ? statusKey : 'awaiting_deposit' })}
                size="sm"
                icon={BellRing}
                tone="info"
              >
                Avisaron seña
              </Chip>
              {hasFilters && (
                <Chip size="sm" tone="outline" icon={X} onClick={() => update({ rango: '', desde: '', hasta: '', estado: '', q: '', aviso: '' })}>
                  Limpiar
                </Chip>
              )}
            </ChipGroup>
          </div>
        </div>
      </PageHeader>

      {error ? (
        <EmptyState icon={WifiOff} title="No se pudieron cargar los turnos" text={error.message} action={<Button onClick={reload}>Reintentar</Button>} />
      ) : loading ? (
        <div className="flex flex-col gap-3" aria-busy="true">
          <SkeletonCard />
          <SkeletonCard />
          <SkeletonCard />
        </div>
      ) : customIncomplete ? (
        <EmptyState icon={CalendarDays} title="Elegí un rango" text="Marcá la fecha de inicio y de fin para ver los turnos de ese período." compact />
      ) : !groups.length ? (
        <EmptyState
          icon={CalendarDays}
          title={hasFilters ? 'Nada por acá' : 'No hay turnos próximos'}
          text={hasFilters ? 'Probá con otro rango, otro estado u otra búsqueda.' : 'Cuando reserven desde la web los vas a ver acá. También podés cargar uno vos.'}
          action={
            hasFilters ? (
              <Button variant="secondary" onClick={() => update({ rango: '', desde: '', hasta: '', estado: '', q: '', aviso: '' })}>
                Limpiar filtros
              </Button>
            ) : (
              <Button icon={Plus} onClick={() => setCreating(true)}>
                Nuevo turno
              </Button>
            )
          }
        />
      ) : (
        <div className="mjto-appt-groups flex flex-col gap-6">
          {groups.map(([date, list]) => (
            <section key={date} className="mjto-appt-group">
              <h2 className="mjto-appt-group__title sticky top-(--mjto-topbar-h) z-10 -mx-4 mb-2 flex items-baseline justify-between gap-3 bg-bg-soft/85 px-4 py-2 backdrop-blur-md sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8">
                <span className="font-display text-[15px] font-bold capitalize tracking-tight text-ink">{friendlyDate(date, store.today)}</span>
                <span className="text-xs text-ink-faint mjto-tabular">{pluralize(list.length, 'turno')}</span>
              </h2>
              <ul className="flex flex-col gap-3">
                {list.map((a) => (
                  <li key={a.id}>
                    <AppointmentCard appointment={a} onChange={handleChange} />
                  </li>
                ))}
              </ul>
            </section>
          ))}
          {hasMore && (
            <div className="flex justify-center">
              <Button variant="secondary" onClick={() => fetchPage(page + 1)} loading={more}>
                Cargar más ({items.length} de {total})
              </Button>
            </div>
          )}
        </div>
      )}

      <NuevoTurno open={creating} onClose={() => setCreating(false)} date={range === 'manana' ? addDaysStr(store.today, 1) : store.today} onCreated={reload} />
    </div>
  );
}
