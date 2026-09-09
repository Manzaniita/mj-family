/**
 * Más (spec A.11): bloqueos de agenda (próximos + BlockForm + eliminar),
 * exportar CSV (clientas / visitas / turnos por rango), enlaces (reserva
 * pública, Ajustes, Servicios, cerrar sesión) e info (versión, zona horaria).
 */
import { useCallback, useEffect, useMemo, useState } from 'react';
import { CalendarOff, Download, ExternalLink, Info, LogOut, Plus, RefreshCw, Scissors, Settings, Trash2 } from 'lucide-react';
import { exportUrl, panelApi } from '@/lib/api';
import { BOOT } from '@/lib/config';
import { cn, dateLabel, formatTime, pluralize } from '@/lib/format';
import { addDaysStr, addMonthsStr, endOfMonth, isDateStr, splitDateTime, startOfMonth } from '@/lib/dates';
import { useToast, Button, ConfirmDialog, DateInput, EmptyState, SectionTitle, Select, Skeleton, PageHeader } from '@/ui';
import { usePanel } from '../store';
import BlockForm from '../components/BlockForm';

const EXPORTS = [
  { value: 'visits', label: 'Visitas (ficha técnica)' },
  { value: 'appointments', label: 'Turnos' },
  { value: 'clients', label: 'Clientas' },
];

/** "sáb 12 sep" | "sáb 12 sep → lun 14 sep" + horario si no es todo el día. */
function blockLabel(block) {
  const a = splitDateTime(block.startsAt);
  const b = splitDateTime(block.endsAt);
  if (!a) return block.startsAt || '';
  // Un fin a las 00:00 de otro día es exclusivo (termina el día anterior); 23:59 es inclusivo.
  let endDate = b ? b.date : a.date;
  if (b && b.time === '00:00' && b.date > a.date) endDate = addDaysStr(b.date, -1);
  const dates = endDate <= a.date ? dateLabel(a.date, { year: true }) : `${dateLabel(a.date)} → ${dateLabel(endDate, { year: true })}`;
  if (block.allDay) return dates;
  return `${dates}, ${formatTime(a.time)}${b ? ` – ${formatTime(b.time)}` : ''}`;
}

function LinkRow({ icon: Icon, label, hint, href, external = true }) {
  if (!href) return null;
  return (
    <li>
      <a
        href={href}
        {...(external ? { target: '_blank', rel: 'noopener noreferrer' } : {})}
        className="flex items-center gap-3 px-4 py-3 transition-colors hover:bg-surface-2"
      >
        <span className="grid size-9 shrink-0 place-items-center rounded-xl bg-accent-soft text-accent" aria-hidden="true">
          <Icon size={17} />
        </span>
        <span className="min-w-0 flex-1">
          <span className="block text-[15px] font-medium text-ink">{label}</span>
          {hint && <span className="block truncate text-xs text-ink-faint">{hint}</span>}
        </span>
        <ExternalLink size={15} className="shrink-0 text-ink-faint" aria-hidden="true" />
      </a>
    </li>
  );
}

export default function Mas() {
  const store = usePanel();
  const toast = useToast();

  /* ------------------------------- Bloqueos ------------------------------- */

  const [blocks, setBlocks] = useState([]);
  const [blocksLoading, setBlocksLoading] = useState(true);
  const [blockOpen, setBlockOpen] = useState(false);
  const [blockToDelete, setBlockToDelete] = useState(null);
  const [deleting, setDeleting] = useState(false);

  const loadBlocks = useCallback(async () => {
    setBlocksLoading(true);
    try {
      const res = await panelApi.listBlocks(store.today, addMonthsStr(store.today, 12));
      const list = Array.isArray(res) ? res : Array.isArray(res?.items) ? res.items : [];
      setBlocks([...list].sort((a, b) => (a.startsAt < b.startsAt ? -1 : 1)));
    } catch (err) {
      toast.error(err);
    } finally {
      setBlocksLoading(false);
    }
  }, [store.today, toast]);

  useEffect(() => {
    loadBlocks();
  }, [loadBlocks]);

  const deleteBlock = async () => {
    if (!blockToDelete) return;
    setDeleting(true);
    try {
      await panelApi.deleteBlock(blockToDelete.id);
      setBlocks((list) => list.filter((b) => b.id !== blockToDelete.id));
      toast.success('Bloqueo eliminado.');
      setBlockToDelete(null);
    } catch (err) {
      toast.error(err);
    } finally {
      setDeleting(false);
    }
  };

  /* -------------------------------- Export -------------------------------- */

  const [what, setWhat] = useState('visits');
  const [from, setFrom] = useState(() => startOfMonth(store.today));
  const [to, setTo] = useState(() => endOfMonth(store.today));
  const rangeOk = isDateStr(from) && isDateStr(to) && from <= to;
  const csv = useMemo(() => (rangeOk ? exportUrl(what, from, to) : ''), [what, from, to, rangeOk]);

  /* --------------------------------- Info --------------------------------- */

  const [refreshing, setRefreshing] = useState(false);
  const refreshAll = async () => {
    setRefreshing(true);
    try {
      await store.refresh();
      await loadBlocks();
      toast.success('Datos actualizados.');
    } catch (err) {
      toast.error(err);
    } finally {
      setRefreshing(false);
    }
  };

  const urls = store.urls;
  const version = store.version || BOOT.version || '';
  const timezone = store.timezone || BOOT.timezone || '';

  return (
    <div className="mjto-view mjto-view--mas flex flex-col gap-6">
      <PageHeader title="Más" subtitle="Bloqueos de agenda, exportaciones, enlaces e información del panel." />

      {/* Bloqueos */}
      <section aria-label="Bloqueos de agenda">
        <SectionTitle
          title="Bloqueos de agenda"
          action={
            <Button size="sm" variant="soft" icon={Plus} onClick={() => setBlockOpen(true)}>
              Nuevo bloqueo
            </Button>
          }
        />
        {blocksLoading ? (
          <div className="mjto-card divide-y divide-line" aria-busy="true">
            {Array.from({ length: 2 }, (_, i) => (
              <div key={i} className="p-4">
                <Skeleton lines={2} />
              </div>
            ))}
          </div>
        ) : blocks.length === 0 ? (
          <div className="mjto-card">
            <EmptyState
              icon={CalendarOff}
              compact
              title="No hay bloqueos próximos"
              text="Bloqueá vacaciones, feriados o franjas para que no se puedan reservar."
              action={
                <Button size="sm" icon={Plus} onClick={() => setBlockOpen(true)}>
                  Nuevo bloqueo
                </Button>
              }
            />
          </div>
        ) : (
          <ul className="mjto-card divide-y divide-line">
            {blocks.map((b) => (
              <li key={b.id} className="flex items-center gap-3 px-4 py-3">
                <span className="grid size-9 shrink-0 place-items-center rounded-xl bg-danger-soft text-danger" aria-hidden="true">
                  <CalendarOff size={17} />
                </span>
                <div className="min-w-0 flex-1">
                  <p className="text-[15px] font-medium text-ink">{blockLabel(b)}</p>
                  <p className="truncate text-xs text-ink-faint">{b.reason || (b.allDay ? 'Todo el día' : 'Franja bloqueada')}</p>
                </div>
                <Button variant="ghost" size="sm" iconOnly icon={Trash2} aria-label={`Eliminar bloqueo ${blockLabel(b)}`} onClick={() => setBlockToDelete(b)} className="text-danger hover:bg-danger-soft hover:text-danger" />
              </li>
            ))}
          </ul>
        )}
        <p className="mt-2 text-xs text-ink-faint">Se muestran los bloqueos de los próximos 12 meses ({pluralize(blocks.length, 'bloqueo')}).</p>
      </section>

      {/* Exportar */}
      <section aria-label="Exportar">
        <SectionTitle title="Exportar" />
        <div className="mjto-card p-4 sm:p-5">
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
            <Select label="Qué exportar" value={what} onChange={(e) => setWhat(e.target.value)} options={EXPORTS} />
            <DateInput label="Desde" value={from} onChange={setFrom} error={!isDateStr(from) ? 'Fecha inválida.' : undefined} />
            <DateInput label="Hasta" value={to} min={from || undefined} onChange={setTo} error={isDateStr(from) && isDateStr(to) && to < from ? 'Tiene que ser posterior a "Desde".' : undefined} />
          </div>
          <div className="mt-4 flex flex-wrap items-center justify-between gap-3">
            <p className="text-xs text-ink-faint">CSV separado por punto y coma (se abre bien en Excel). {what === 'visits' ? 'Incluye una columna por campo de la ficha.' : what === 'clients' ? 'Clientas con actividad en el rango.' : 'Turnos del rango por fecha.'}</p>
            <Button icon={Download} href={csv || undefined} target="_blank" rel="noopener noreferrer" disabled={!csv}>
              Descargar CSV
            </Button>
          </div>
        </div>
      </section>

      {/* Enlaces */}
      <section aria-label="Enlaces">
        <SectionTitle title="Enlaces" />
        <ul className="mjto-card divide-y divide-line overflow-hidden">
          <LinkRow icon={ExternalLink} label="Ver la reserva como clienta" hint={urls.publicBooking} href={urls.publicBooking} />
          <LinkRow icon={Settings} label="Ajustes (WordPress)" hint="Horarios, seña, textos, formulario y ficha" href={urls.settings} />
          <LinkRow icon={Scissors} label="Servicios (WordPress)" hint="Duración, precio y seña de cada servicio" href={urls.services} />
          <LinkRow icon={LogOut} label="Cerrar sesión" hint={store.user.name} href={urls.logout} external={false} />
        </ul>
      </section>

      {/* Info */}
      <section aria-label="Información">
        <SectionTitle
          title="Info"
          action={
            <Button size="xs" variant="ghost" icon={RefreshCw} loading={refreshing} onClick={refreshAll}>
              Actualizar datos
            </Button>
          }
        />
        <dl className="mjto-card grid grid-cols-1 gap-x-6 gap-y-3 p-4 text-sm sm:grid-cols-2 sm:p-5">
          <div className="flex items-start gap-3">
            <Info size={16} className="mt-0.5 shrink-0 text-ink-faint" aria-hidden="true" />
            <div className="min-w-0">
              <dt className="text-xs text-ink-faint">Negocio</dt>
              <dd className="m-0 truncate font-medium text-ink">{store.business.name || BOOT.siteName || '—'}</dd>
            </div>
          </div>
          <div className={cn('flex items-start gap-3')}>
            <Info size={16} className="mt-0.5 shrink-0 text-ink-faint" aria-hidden="true" />
            <div className="min-w-0">
              <dt className="text-xs text-ink-faint">Zona horaria</dt>
              <dd className="m-0 truncate font-medium text-ink mjto-tabular">{timezone || '—'}{store.now ? ` · ahora ${store.now.slice(11, 16)}` : ''}</dd>
            </div>
          </div>
          <div className="flex items-start gap-3">
            <Info size={16} className="mt-0.5 shrink-0 text-ink-faint" aria-hidden="true" />
            <div className="min-w-0">
              <dt className="text-xs text-ink-faint">Versión del plugin</dt>
              <dd className="m-0 truncate font-medium text-ink mjto-tabular">{version ? `MJ Turnos Online ${version}` : 'MJ Turnos Online'}</dd>
            </div>
          </div>
          <div className="flex items-start gap-3">
            <Info size={16} className="mt-0.5 shrink-0 text-ink-faint" aria-hidden="true" />
            <div className="min-w-0">
              <dt className="text-xs text-ink-faint">Usuario</dt>
              <dd className="m-0 truncate font-medium text-ink">{store.user.name || '—'}</dd>
            </div>
          </div>
        </dl>
      </section>

      <BlockForm open={blockOpen} onClose={() => setBlockOpen(false)} onSaved={() => loadBlocks()} />

      <ConfirmDialog
        open={!!blockToDelete}
        onClose={() => setBlockToDelete(null)}
        onConfirm={deleteBlock}
        tone="danger"
        loading={deleting}
        title="¿Eliminar este bloqueo?"
        text={blockToDelete ? `${blockLabel(blockToDelete)}${blockToDelete.reason ? ` · ${blockToDelete.reason}` : ''}. Ese rango vuelve a estar disponible para reservar.` : ''}
        confirmLabel="Eliminar bloqueo"
      />
    </div>
  );
}
