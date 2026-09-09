/**
 * Clientas (spec A.11): buscador por nombre o teléfono (debounce 300 ms),
 * filtros Año / Mes ("Todos"), orden (última visita / nombre / alta), lista
 * con teléfono + WhatsApp, localidad, última visita, cantidad de visitas y
 * próximo turno; "Nueva clienta" (ClientForm) y "Cargar más" (paginado).
 */
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { CalendarClock, ChevronRight, MapPin, MessageCircle, Search, SlidersHorizontal, UserPlus, Users } from 'lucide-react';
import { panelApi } from '@/lib/api';
import { LOCALE } from '@/lib/config';
import { cn, dateLabel, formatDateTime, fullName, initials, pluralize, prettyPhone } from '@/lib/format';
import { useToast, Button, EmptyState, Fab, Input, PageHeader, Select, SkeletonCard, StatusBadge } from '@/ui';
import { usePanel } from '../store';
import ClientForm from '../components/ClientForm';

const PER_PAGE = 50;

const ORDERS = [
  { value: 'last_visit_desc', label: 'Última visita' },
  { value: 'name_asc', label: 'Nombre (A → Z)' },
  { value: 'created_desc', label: 'Alta más reciente' },
];

/** Valor con retardo (para no pegarle al servidor en cada tecla). */
function useDebounced(value, ms = 300) {
  const [v, setV] = useState(value);
  useEffect(() => {
    const t = setTimeout(() => setV(value), ms);
    return () => clearTimeout(t);
  }, [value, ms]);
  return v;
}

/** Opciones de año (desde el que viene hasta seis atrás) y de mes (nombres del locale). */
function useYearMonthOptions(today) {
  return useMemo(() => {
    const cur = Number(String(today || '').slice(0, 4)) || new Date().getFullYear();
    const years = [];
    for (let y = cur + 1; y >= cur - 6; y -= 1) years.push({ value: String(y), label: String(y) });
    const fmt = new Intl.DateTimeFormat(LOCALE, { month: 'long' });
    const months = Array.from({ length: 12 }, (_, i) => {
      const label = fmt.format(new Date(2024, i, 1, 12));
      return { value: String(i + 1), label: label.charAt(0).toUpperCase() + label.slice(1) };
    });
    return { years, months };
  }, [today]);
}

function ClientCard({ client, store }) {
  const name = fullName(client) || 'Sin nombre';
  const wa = client.whatsapp?.url || store.whatsappLink(client.phone);
  const next = client.nextAppointment;
  const visits = Number(client.visitsCount) || 0;
  return (
    <li className="mjto-client-card relative">
      <article className="mjto-card flex items-start gap-3 p-3.5 transition-[box-shadow,transform] hover:shadow-float sm:p-4">
        <span className="grid size-11 shrink-0 place-items-center rounded-2xl bg-accent-soft font-display text-sm font-bold text-accent" aria-hidden="true">
          {initials(name)}
        </span>
        <div className="min-w-0 flex-1">
          <Link to={`/clientas/${client.id}`} className="mjto-client-card__link block after:absolute after:inset-0 after:rounded-card after:content-['']">
            <p className="truncate font-display text-[15px] font-semibold tracking-tight text-ink">{name}</p>
          </Link>
          <p className="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-sm text-ink-soft">
            {client.phone ? <span className="mjto-tabular">{prettyPhone(client.phoneRaw || client.phone)}</span> : <span className="text-ink-faint">Sin teléfono</span>}
            {client.locality && (
              <span className="inline-flex items-center gap-1 truncate">
                <MapPin size={13} className="shrink-0 text-ink-faint" aria-hidden="true" />
                {client.locality}
              </span>
            )}
          </p>
          <p className="mt-1.5 text-xs text-ink-faint">
            {client.lastVisit ? `Última visita ${dateLabel(client.lastVisit, { year: true })}` : 'Sin visitas todavía'}
            {visits > 0 && ` · ${pluralize(visits, 'visita')}`}
          </p>
          {next && next.startsAt && (
            <p className="mt-1.5 flex flex-wrap items-center gap-1.5 text-xs text-ink-soft">
              <CalendarClock size={13} className="shrink-0 text-accent" aria-hidden="true" />
              <span>Próximo: {formatDateTime(next.startsAt)}</span>
              <StatusBadge status={next.status} label={store.statusLabel(next.status)} size="sm" />
            </p>
          )}
        </div>
        <div className="relative z-10 flex shrink-0 flex-col items-end gap-1">
          {wa && (
            <a
              href={wa}
              target="_blank"
              rel="noopener noreferrer"
              aria-label={`WhatsApp a ${name}`}
              className="grid size-10 place-items-center rounded-full bg-success-soft text-success transition-transform hover:brightness-95 active:scale-95"
            >
              <MessageCircle size={19} aria-hidden="true" />
            </a>
          )}
          <ChevronRight size={18} className="mr-2.5 mt-1 text-ink-faint" aria-hidden="true" />
        </div>
      </article>
    </li>
  );
}

export default function Clientas() {
  const store = usePanel();
  const toast = useToast();
  const navigate = useNavigate();
  const { years, months } = useYearMonthOptions(store.today);

  const [q, setQ] = useState('');
  const dq = useDebounced(q, 300);
  const [year, setYear] = useState('');
  const [month, setMonth] = useState('');
  const [order, setOrder] = useState('last_visit_desc');
  const [showFilters, setShowFilters] = useState(false);

  const [items, setItems] = useState([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [loadingMore, setLoadingMore] = useState(false);
  const [formOpen, setFormOpen] = useState(false);
  const reqSeq = useRef(0);

  const params = useMemo(
    () => ({ q: dq.trim(), year: year || undefined, month: year && month ? month : undefined, order, per_page: PER_PAGE }),
    [dq, year, month, order]
  );

  const load = useCallback(
    async (p, append) => {
      reqSeq.current += 1;
      const seq = reqSeq.current;
      if (append) setLoadingMore(true);
      else setLoading(true);
      try {
        const res = await panelApi.listClients({ ...params, page: p });
        if (seq !== reqSeq.current) return;
        const list = Array.isArray(res?.items) ? res.items : [];
        setItems((prev) => (append ? [...prev, ...list.filter((c) => !prev.some((x) => x.id === c.id))] : list));
        setTotal(Number(res?.total) || list.length);
        setPage(p);
      } catch (err) {
        if (seq !== reqSeq.current) return;
        toast.error(err);
      } finally {
        if (seq === reqSeq.current) {
          setLoading(false);
          setLoadingMore(false);
        }
      }
    },
    [params, toast]
  );

  useEffect(() => {
    load(1, false);
  }, [load]);

  const hasMore = items.length < total;
  const filtered = !!(dq.trim() || year || month);
  const monthLabelOf = (m) => months.find((x) => x.value === String(m))?.label || '';

  return (
    <div className="mjto-view mjto-view--clientas">
      <PageHeader
        title="Clientas"
        subtitle={loading ? 'Cargando…' : total ? `${pluralize(total, 'clienta')}${filtered ? ' con este filtro' : ''}` : 'Todavía no hay clientas cargadas.'}
        actions={
          <Button icon={UserPlus} onClick={() => setFormOpen(true)} className="hidden sm:inline-flex">
            Nueva clienta
          </Button>
        }
      >
        <div className="flex flex-col gap-2">
          <div className="flex items-center gap-2">
            <Input
              type="search"
              icon={Search}
              placeholder="Buscar por nombre o teléfono"
              value={q}
              onChange={(e) => setQ(e.target.value)}
              autoComplete="off"
              aria-label="Buscar clientas"
              className="flex-1"
            />
            <Button
              variant={showFilters || year || month ? 'soft' : 'secondary'}
              iconOnly
              icon={SlidersHorizontal}
              aria-label="Filtros"
              aria-expanded={showFilters}
              onClick={() => setShowFilters((v) => !v)}
              className="sm:hidden"
            />
          </div>
          <div className={cn('grid grid-cols-1 gap-2 sm:grid-cols-3', !showFilters && 'hidden sm:grid')}>
            <Select
              aria-label="Año"
              value={year}
              onChange={(e) => {
                setYear(e.target.value);
                if (!e.target.value) setMonth('');
              }}
              options={[{ value: '', label: 'Todos los años' }, ...years]}
              size="sm"
            />
            <Select
              aria-label="Mes"
              value={month}
              disabled={!year}
              onChange={(e) => setMonth(e.target.value)}
              options={[{ value: '', label: year ? 'Todo el año' : 'Elegí un año primero' }, ...months]}
              size="sm"
            />
            <Select aria-label="Orden" value={order} onChange={(e) => setOrder(e.target.value)} options={ORDERS} size="sm" />
          </div>
          {(year || month) && (
            <p className="text-xs text-ink-faint">
              Mostrando clientas con visitas o turnos en {month ? `${monthLabelOf(month)} ${year}` : year}.{' '}
              <button
                type="button"
                className="font-semibold text-accent hover:underline"
                onClick={() => {
                  setYear('');
                  setMonth('');
                }}
              >
                Quitar filtro
              </button>
            </p>
          )}
        </div>
      </PageHeader>

      {loading ? (
        <ul className="flex flex-col gap-3" aria-busy="true">
          {Array.from({ length: 5 }, (_, i) => (
            <SkeletonCard key={i} />
          ))}
        </ul>
      ) : items.length === 0 ? (
        <EmptyState
          icon={Users}
          title={filtered ? 'No encontramos clientas con ese filtro' : 'Todavía no hay clientas'}
          text={filtered ? 'Probá con otro nombre, teléfono o período.' : 'Las clientas se crean solas cuando reservan online, o podés cargarlas vos.'}
          action={
            <Button icon={UserPlus} onClick={() => setFormOpen(true)}>
              Nueva clienta
            </Button>
          }
        />
      ) : (
        <>
          <ul className="flex flex-col gap-3">
            {items.map((c) => (
              <ClientCard key={c.id} client={c} store={store} />
            ))}
          </ul>
          {hasMore && (
            <div className="mt-4 flex justify-center">
              <Button variant="secondary" loading={loadingMore} onClick={() => load(page + 1, true)}>
                Cargar más ({total - items.length} restantes)
              </Button>
            </div>
          )}
        </>
      )}

      <Fab icon={UserPlus} label="Nueva clienta" onClick={() => setFormOpen(true)} className="sm:hidden" />

      <ClientForm open={formOpen} onClose={() => setFormOpen(false)} onSaved={(client) => navigate(`/clientas/${client.id}`)} />
    </div>
  );
}
