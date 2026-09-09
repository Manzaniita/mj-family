/**
 * Caja (spec A.11): selector de mes/año (‹ ›) o rango personalizado,
 * tarjetas Cobrado / Señas / Visitas, desglose por método de pago, lista de
 * visitas del período (fecha, clienta, servicio, total, seña, método) con
 * link a la ficha y "Exportar CSV" (admin-post, se abre en otra pestaña).
 */
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { ChevronLeft, ChevronRight, ClipboardList, Coins, Download, Receipt, Wallet } from 'lucide-react';
import { exportUrl, panelApi } from '@/lib/api';
import { LOCALE } from '@/lib/config';
import { cn, dateLabel, pluralize } from '@/lib/format';
import { endOfMonth, isDateStr, monthKey, monthLabel, parseMonthKey, shiftMonthKey, startOfMonth, toMonthKey } from '@/lib/dates';
import { useToast, Button, DateInput, EmptyState, PageHeader, Select, Skeleton, Tabs } from '@/ui';
import { usePanel } from '../store';

const num = (v) => {
  const n = Number(v);
  return Number.isFinite(n) ? n : 0;
};

function StatTile({ icon: Icon, label, value, tone = 'accent', sub }) {
  const tones = {
    accent: 'bg-accent-soft text-accent',
    violet: 'bg-violet-soft text-violet',
    info: 'bg-info-soft text-info',
  };
  return (
    <div className="mjto-card mjto-stat flex items-center gap-3 p-4">
      <span className={cn('grid size-11 shrink-0 place-items-center rounded-2xl', tones[tone] || tones.accent)} aria-hidden="true">
        <Icon size={21} />
      </span>
      <div className="min-w-0">
        <p className="text-[11px] font-bold uppercase tracking-wider text-ink-faint">{label}</p>
        <p className="truncate font-display text-xl font-bold tracking-tight text-ink mjto-tabular">{value}</p>
        {sub && <p className="truncate text-xs text-ink-soft">{sub}</p>}
      </div>
    </div>
  );
}

export default function Caja() {
  const store = usePanel();
  const toast = useToast();

  const [mode, setMode] = useState('month');
  const [month, setMonth] = useState(() => monthKey(store.today));
  const [customFrom, setCustomFrom] = useState(() => startOfMonth(store.today));
  const [customTo, setCustomTo] = useState(() => store.today);
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const reqSeq = useRef(0);

  const { from, to } = useMemo(() => {
    if (mode === 'range') return { from: customFrom, to: customTo };
    return { from: `${month}-01`, to: endOfMonth(`${month}-01`) };
  }, [mode, month, customFrom, customTo]);

  const rangeOk = isDateStr(from) && isDateStr(to) && from <= to;

  const load = useCallback(async () => {
    if (!rangeOk) return;
    reqSeq.current += 1;
    const seq = reqSeq.current;
    setLoading(true);
    try {
      const res = await panelApi.getCash(from, to);
      if (seq !== reqSeq.current) return;
      setData(res || null);
    } catch (err) {
      if (seq !== reqSeq.current) return;
      toast.error(err);
    } finally {
      if (seq === reqSeq.current) setLoading(false);
    }
  }, [from, to, rangeOk, toast]);

  useEffect(() => {
    load();
  }, [load]);

  /* --------------------------- Opciones de mes/año ------------------------ */

  const { years, months } = useMemo(() => {
    const cur = Number(store.today.slice(0, 4)) || new Date().getFullYear();
    const ys = [];
    for (let y = cur + 1; y >= cur - 6; y -= 1) ys.push({ value: String(y), label: String(y) });
    const fmt = new Intl.DateTimeFormat(LOCALE, { month: 'long' });
    const ms = Array.from({ length: 12 }, (_, i) => {
      const label = fmt.format(new Date(2024, i, 1, 12));
      return { value: String(i + 1), label: label.charAt(0).toUpperCase() + label.slice(1) };
    });
    return { years: ys, months: ms };
  }, [store.today]);

  const { year: y, month: m } = parseMonthKey(month);

  /* -------------------------------- Totales ------------------------------- */

  const totals = data?.totals || {};
  const collected = num(totals.collected);
  const deposits = num(totals.deposits);
  const visitsCount = Number(totals.visits) || (Array.isArray(data?.items) ? data.items.length : 0);
  const byMethod = useMemo(() => {
    const src = totals.byMethod && typeof totals.byMethod === 'object' ? totals.byMethod : {};
    return Object.entries(src)
      .map(([method, amount]) => ({ method: method || 'Sin método', amount: num(amount) }))
      .sort((a, b) => b.amount - a.amount);
  }, [totals.byMethod]);
  const methodMax = byMethod.reduce((max, x) => Math.max(max, x.amount), 0);
  const items = useMemo(() => (Array.isArray(data?.items) ? [...data.items].sort((a, b) => (a.date < b.date ? 1 : a.date > b.date ? -1 : 0)) : []), [data]);

  const periodLabel = mode === 'month' ? monthLabel(y, m, LOCALE) : rangeOk ? `${dateLabel(from, { year: true })} → ${dateLabel(to, { year: true })}` : 'Elegí un rango válido';
  const csv = rangeOk ? exportUrl('visits', from, to) : '';

  return (
    <div className="mjto-view mjto-view--caja">
      <PageHeader
        title="Caja"
        subtitle={periodLabel}
        actions={
          csv && (
            <Button variant="secondary" size="sm" icon={Download} href={csv} target="_blank" rel="noopener noreferrer">
              Exportar CSV
            </Button>
          )
        }
      >
        <div className="flex flex-col gap-3">
          <Tabs
            items={[
              { key: 'month', label: 'Por mes' },
              { key: 'range', label: 'Rango' },
            ]}
            value={mode}
            onChange={setMode}
            size="sm"
          />
          {mode === 'month' ? (
            <div className="flex flex-wrap items-center gap-2">
              <Button variant="secondary" size="sm" iconOnly icon={ChevronLeft} aria-label="Mes anterior" onClick={() => setMonth((k) => shiftMonthKey(k, -1))} />
              <Select aria-label="Mes" size="sm" value={String(m)} onChange={(e) => setMonth(toMonthKey(y, Number(e.target.value)))} options={months} className="w-40" />
              <Select aria-label="Año" size="sm" value={String(y)} onChange={(e) => setMonth(toMonthKey(Number(e.target.value), m))} options={years.some((o) => o.value === String(y)) ? years : [{ value: String(y), label: String(y) }, ...years]} className="w-28" />
              <Button variant="secondary" size="sm" iconOnly icon={ChevronRight} aria-label="Mes siguiente" onClick={() => setMonth((k) => shiftMonthKey(k, 1))} />
              {month !== monthKey(store.today) && (
                <Button variant="ghost" size="sm" onClick={() => setMonth(monthKey(store.today))}>
                  Este mes
                </Button>
              )}
            </div>
          ) : (
            <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 sm:max-w-md">
              <DateInput label="Desde" size="sm" value={customFrom} onChange={setCustomFrom} error={!isDateStr(customFrom) ? 'Fecha inválida.' : undefined} />
              <DateInput label="Hasta" size="sm" value={customTo} min={customFrom || undefined} onChange={setCustomTo} error={isDateStr(customFrom) && isDateStr(customTo) && customTo < customFrom ? 'Tiene que ser posterior a "Desde".' : undefined} />
            </div>
          )}
        </div>
      </PageHeader>

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-3" aria-busy={loading}>
        {loading && !data ? (
          Array.from({ length: 3 }, (_, i) => (
            <div key={i} className="mjto-card flex items-center gap-3 p-4">
              <Skeleton className="size-11 rounded-2xl" />
              <div className="flex-1">
                <Skeleton lines={2} />
              </div>
            </div>
          ))
        ) : (
          <>
            <StatTile icon={Wallet} label="Cobrado" value={store.formatPrice(collected)} tone="accent" sub={visitsCount ? `${store.formatPrice(visitsCount ? collected / visitsCount : 0)} por visita` : undefined} />
            <StatTile icon={Coins} label="Señas" value={store.formatPrice(deposits)} tone="violet" sub={collected > 0 ? `${Math.round((deposits / collected) * 100)}% del total` : undefined} />
            <StatTile icon={ClipboardList} label="Visitas" value={String(visitsCount)} tone="info" sub={pluralize(visitsCount, 'ficha cargada', 'fichas cargadas')} />
          </>
        )}
      </div>

      {byMethod.length > 0 && (
        <section className="mjto-card mt-4 p-4 sm:p-5" aria-label="Por método de pago">
          <h2 className="mb-3 text-[11px] font-bold uppercase tracking-[0.14em] text-ink-faint">Por método de pago</h2>
          <ul className="flex flex-col gap-2.5">
            {byMethod.map(({ method, amount }) => (
              <li key={method} className="min-w-0">
                <div className="flex items-baseline justify-between gap-3 text-sm">
                  <span className="truncate font-medium text-ink">{method}</span>
                  <span className="shrink-0 font-semibold text-ink mjto-tabular">{store.formatPrice(amount)}</span>
                </div>
                <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-surface-3" aria-hidden="true">
                  <div className="h-full rounded-full bg-accent transition-[width] duration-500" style={{ width: `${methodMax ? Math.max(3, (amount / methodMax) * 100) : 0}%` }} />
                </div>
              </li>
            ))}
          </ul>
        </section>
      )}

      <section className="mt-5" aria-label="Visitas del período">
        <h2 className="mb-2 text-[11px] font-bold uppercase tracking-[0.14em] text-ink-faint">Visitas del período</h2>
        {loading && !data ? (
          <div className="mjto-card divide-y divide-line">
            {Array.from({ length: 4 }, (_, i) => (
              <div key={i} className="p-4">
                <Skeleton lines={2} />
              </div>
            ))}
          </div>
        ) : items.length === 0 ? (
          <EmptyState icon={Receipt} title="Sin visitas en este período" text="Las visitas aparecen acá cuando les cargás la ficha con el total cobrado." compact />
        ) : (
          <div className={cn('mjto-card overflow-hidden transition-opacity', loading && 'opacity-60')}>
            <div className="hidden grid-cols-[6.5rem_1fr_1fr_6rem_6rem_7rem] gap-3 px-4 py-2 text-[11px] font-bold uppercase tracking-wider text-ink-faint sm:grid">
              <span>Fecha</span>
              <span>Clienta</span>
              <span>Servicio</span>
              <span className="text-right">Total</span>
              <span className="text-right">Seña</span>
              <span>Método</span>
            </div>
            <ul className="divide-y divide-line">
              {items.map((it) => (
                <li key={it.visitId} className="grid grid-cols-1 gap-1 px-4 py-3 sm:grid-cols-[6.5rem_1fr_1fr_6rem_6rem_7rem] sm:items-center sm:gap-3">
                  <span className="text-xs text-ink-faint sm:text-sm sm:text-ink-soft mjto-tabular">{dateLabel(it.date)}</span>
                  <span className="min-w-0 truncate">
                    {it.clientId ? (
                      <Link to={`/clientas/${it.clientId}`} className="font-semibold text-ink hover:text-accent hover:underline">
                        {it.clientName || 'Clienta'}
                      </Link>
                    ) : (
                      <span className="font-semibold text-ink">{it.clientName || 'Clienta'}</span>
                    )}
                  </span>
                  <span className="min-w-0 truncate text-sm text-ink-soft">{it.serviceName || '—'}</span>
                  <span className="text-sm font-semibold text-ink mjto-tabular sm:text-right">
                    <span className="sm:hidden text-xs font-normal text-ink-faint">Total </span>
                    {store.formatPrice(num(it.total))}
                  </span>
                  <span className="text-sm text-ink-soft mjto-tabular sm:text-right">
                    <span className="sm:hidden text-xs text-ink-faint">Seña </span>
                    {num(it.deposit) > 0 ? store.formatPrice(num(it.deposit)) : '—'}
                  </span>
                  <span className="truncate text-xs text-ink-faint sm:text-sm sm:text-ink-soft">{it.paymentMethod || '—'}</span>
                </li>
              ))}
            </ul>
            <div className="mjto-hairline-t flex flex-wrap items-center justify-between gap-2 bg-surface-2 px-4 py-3 text-sm">
              <span className="text-ink-soft">{pluralize(items.length, 'visita')}</span>
              <span className="font-semibold text-ink mjto-tabular">
                Total {store.formatPrice(collected)}
                {deposits > 0 && <span className="ml-2 font-normal text-ink-soft">· señas {store.formatPrice(deposits)}</span>}
              </span>
            </div>
          </div>
        )}
      </section>
    </div>
  );
}
