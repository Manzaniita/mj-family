/**
 * Opiniones de las clientas (Más, 1.3): filtros Pendientes / Publicadas /
 * Ocultas con sus cantidades y, por opinión, puntaje, texto, clienta,
 * servicio y fecha; acciones publicar, ocultar, editar el nombre que se
 * muestra y borrar (con confirmación).
 *
 * GET /panel/reviews?status → { items, total, counts }; PATCH y DELETE
 * /panel/reviews/{id}. Se muestra si las opiniones están prendidas o si ya
 * hay alguna cargada (así no aparece nada en una tienda que no las usa).
 */
import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Eye, EyeOff, MessageSquareQuote, Pencil, Star, Trash2 } from 'lucide-react';
import { panelApi } from '@/lib/api';
import { cn } from '@/lib/format';
import { Badge, Button, Chip, ChipGroup, ConfirmDialog, EmptyState, Input, Modal, SectionTitle, Skeleton, useToast } from '@/ui';
import { usePanel } from '../store';

const FILTERS = [
  { key: 'pending', label: 'Pendientes' },
  { key: 'approved', label: 'Publicadas' },
  { key: 'hidden', label: 'Ocultas' },
];

const STATUS_BADGES = {
  pending: { label: 'Pendiente', tone: 'warning' },
  approved: { label: 'Publicada', tone: 'success' },
  hidden: { label: 'Oculta', tone: 'neutral' },
};

const emptyCounts = { pending: 0, approved: 0, hidden: 0 };
const num = (v) => (Number.isFinite(Number(v)) ? Number(v) : 0);

/** Estrellas de solo lectura (1 a 5). */
function Stars({ rating }) {
  const r = Math.max(0, Math.min(5, Math.round(num(rating))));
  return (
    <span className="inline-flex items-center gap-0.5 text-warning" role="img" aria-label={`${r} de 5 estrellas`}>
      {Array.from({ length: 5 }, (_, i) => (
        <Star key={i} size={15} className={cn('shrink-0', i < r ? 'fill-current' : 'opacity-30')} aria-hidden="true" />
      ))}
    </span>
  );
}

/** Editar el nombre con el que se muestra la opinión en la web. */
function NameDialog({ review, open, onClose, onSaved }) {
  const toast = useToast();
  const [name, setName] = useState('');
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (!open || !review) return;
    setName(review.authorName || '');
    setSaving(false);
  }, [open, review?.id]); // eslint-disable-line react-hooks/exhaustive-deps

  if (!review) return null;

  const submit = async (e) => {
    e?.preventDefault?.();
    if (saving) return;
    setSaving(true);
    try {
      const next = await panelApi.updateReview(review.id, { author_name: name.trim() });
      toast.success('Nombre actualizado.');
      onSaved?.(next);
      onClose?.();
    } catch (err) {
      toast.error(err);
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal
      open={open}
      onClose={onClose}
      size="sm"
      title="Nombre que se muestra"
      description={review.clientName ? `Opinión de ${review.clientName}` : undefined}
      dismissible={!saving}
      footer={
        <>
          <Button variant="ghost" onClick={onClose} disabled={saving}>
            Volver
          </Button>
          <Button icon={Pencil} onClick={submit} loading={saving}>
            Guardar
          </Button>
        </>
      }
    >
      <form onSubmit={submit} noValidate className="pt-1">
        <Input
          label="Nombre"
          help={`En la web se ve según el formato de Ajustes${review.name ? ` (hoy: «${review.name}»)` : ''}.`}
          value={name}
          maxLength={160}
          onChange={(e) => setName(e.target.value)}
          autoComplete="off"
          autoFocus
        />
      </form>
    </Modal>
  );
}

export function ReviewsSection() {
  const store = usePanel();
  const toast = useToast();
  const [filter, setFilter] = useState('pending');
  const [items, setItems] = useState([]);
  const [counts, setCounts] = useState(emptyCounts);
  const [loading, setLoading] = useState(true);
  const [loaded, setLoaded] = useState(false);
  const [busy, setBusy] = useState(''); // 'id:estado' mientras se guarda
  const [editing, setEditing] = useState(null);
  const [toDelete, setToDelete] = useState(null);
  const [deleting, setDeleting] = useState(false);

  const load = useCallback(
    async (status, signal) => {
      setLoading(true);
      try {
        const res = await panelApi.listReviews({ status, per_page: 100 }, { signal });
        if (signal?.aborted) return;
        setItems(Array.isArray(res?.items) ? res.items : []);
        setCounts({ ...emptyCounts, ...(res?.counts || {}) });
        setLoaded(true);
      } catch (err) {
        if (signal?.aborted || err?.name === 'AbortError') return;
        // Sin opiniones prendidas no molestamos con errores: la sección no se ve.
        if (store.reviewsEnabled) toast.error(err);
      } finally {
        if (!signal?.aborted) setLoading(false);
      }
    },
    [store.reviewsEnabled, toast]
  );

  useEffect(() => {
    const ctrl = new AbortController();
    load(filter, ctrl.signal);
    return () => ctrl.abort();
  }, [filter, load]);

  // Primera carga: si no hay pendientes, arrancar en las publicadas.
  const [autoPicked, setAutoPicked] = useState(false);
  useEffect(() => {
    if (!loaded || autoPicked) return;
    setAutoPicked(true);
    if (filter === 'pending' && !counts.pending && counts.approved) setFilter('approved');
  }, [loaded]); // eslint-disable-line react-hooks/exhaustive-deps

  const anyReview = num(counts.pending) + num(counts.approved) + num(counts.hidden) > 0;
  if (!store.reviewsEnabled && !anyReview && !(store.counts.pendingReviews > 0)) return null;

  /** Aplica una opinión actualizada: si cambió de estado sale del filtro y se ajustan las cantidades. */
  const applyUpdate = (prev, next) => {
    if (!next) return;
    if (next.status !== prev.status) {
      setCounts((c) => ({ ...c, [prev.status]: Math.max(0, num(c[prev.status]) - 1), [next.status]: num(c[next.status]) + 1 }));
      setItems((list) => list.filter((r) => r.id !== prev.id));
      store.refresh().catch(() => {});
    } else {
      setItems((list) => list.map((r) => (r.id === next.id ? next : r)));
    }
  };

  const setStatus = async (review, status) => {
    if (busy) return;
    setBusy(`${review.id}:${status}`);
    try {
      const next = await panelApi.updateReview(review.id, { status });
      toast.success(status === 'approved' ? 'Opinión publicada.' : 'Opinión oculta.');
      applyUpdate(review, next);
    } catch (err) {
      toast.error(err);
    } finally {
      setBusy('');
    }
  };

  const remove = async () => {
    if (!toDelete) return;
    setDeleting(true);
    try {
      await panelApi.deleteReview(toDelete.id);
      setItems((list) => list.filter((r) => r.id !== toDelete.id));
      setCounts((c) => ({ ...c, [toDelete.status]: Math.max(0, num(c[toDelete.status]) - 1) }));
      store.refresh().catch(() => {});
      toast.success('Opinión borrada.');
      setToDelete(null);
    } catch (err) {
      toast.error(err);
    } finally {
      setDeleting(false);
    }
  };

  const emptyText = {
    pending: 'No hay opiniones esperando revisión.',
    approved: 'Todavía no publicaste ninguna opinión.',
    hidden: 'No hay opiniones ocultas.',
  }[filter];

  return (
    <section aria-label="Opiniones" className="mjto-reviews">
      <SectionTitle title="Opiniones" />
      <ChipGroup scroll className="mb-3 sm:mx-0 sm:flex-wrap sm:px-0" role="group" aria-label="Filtrar opiniones">
        {FILTERS.map((f) => (
          <Chip key={f.key} size="sm" active={filter === f.key} onClick={() => setFilter(f.key)} count={num(counts[f.key])}>
            {f.label}
          </Chip>
        ))}
      </ChipGroup>

      {loading && !items.length ? (
        <div className="mjto-card divide-y divide-line" aria-busy="true">
          {Array.from({ length: 2 }, (_, i) => (
            <div key={i} className="p-4">
              <Skeleton lines={3} />
            </div>
          ))}
        </div>
      ) : !items.length ? (
        <div className="mjto-card">
          <EmptyState icon={MessageSquareQuote} compact title="Nada por acá" text={emptyText} />
        </div>
      ) : (
        <ul className={cn('mjto-card divide-y divide-line', loading && 'opacity-70')} aria-busy={loading || undefined}>
          {items.map((r) => {
            const badge = STATUS_BADGES[r.status] || null;
            return (
              <li key={r.id} className="p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <Stars rating={r.rating} />
                  <span className="flex items-center gap-2">
                    {r.dateLabel && <span className="text-xs text-ink-faint">{r.dateLabel}</span>}
                    {badge && (
                      <Badge tone={badge.tone} size="sm">
                        {badge.label}
                      </Badge>
                    )}
                  </span>
                </div>
                {r.body ? (
                  <p className="mt-2 whitespace-pre-line text-[15px] leading-relaxed text-ink">{r.body}</p>
                ) : (
                  <p className="mt-2 text-sm italic text-ink-faint">Sin comentario, solo el puntaje.</p>
                )}
                <p className="mt-2 text-xs text-ink-faint">
                  <span className="font-semibold text-ink-soft">{r.name || 'Sin nombre'}</span>
                  {r.clientId && r.clientName ? (
                    <>
                      {' · '}
                      <Link to={`/clientas/${r.clientId}`} className="hover:text-accent hover:underline">
                        {r.clientName}
                      </Link>
                    </>
                  ) : null}
                  {r.serviceName ? ` · ${r.serviceName}` : ''}
                </p>
                <div className="mt-3 flex flex-wrap items-center gap-1.5">
                  {r.status !== 'approved' && (
                    <Button size="xs" variant="soft" icon={Eye} loading={busy === `${r.id}:approved`} disabled={!!busy && busy !== `${r.id}:approved`} onClick={() => setStatus(r, 'approved')}>
                      Publicar
                    </Button>
                  )}
                  {r.status !== 'hidden' && (
                    <Button size="xs" variant="ghost" icon={EyeOff} loading={busy === `${r.id}:hidden`} disabled={!!busy && busy !== `${r.id}:hidden`} onClick={() => setStatus(r, 'hidden')}>
                      Ocultar
                    </Button>
                  )}
                  <Button size="xs" variant="ghost" icon={Pencil} onClick={() => setEditing(r)}>
                    Nombre
                  </Button>
                  <Button size="xs" variant="ghost" iconOnly icon={Trash2} aria-label={`Borrar la opinión de ${r.name || r.clientName || 'la clienta'}`} onClick={() => setToDelete(r)} className="ml-auto text-danger hover:bg-danger-soft hover:text-danger" />
                </div>
              </li>
            );
          })}
        </ul>
      )}
      {!store.reviewsEnabled && anyReview && (
        <p className="mt-2 text-xs text-ink-faint">Las opiniones están apagadas en Ajustes: no se piden nuevas ni se muestran en la web.</p>
      )}
      {filter === 'pending' && num(counts.pending) > 0 && (
        <p className="mt-2 text-xs text-ink-faint">{num(counts.pending) === 1 ? 'Una opinión espera' : `${num(counts.pending)} opiniones esperan`} tu revisión antes de salir en la web.</p>
      )}

      <NameDialog review={editing} open={!!editing} onClose={() => setEditing(null)} onSaved={(next) => editing && applyUpdate(editing, next)} />

      <ConfirmDialog
        open={!!toDelete}
        onClose={() => setToDelete(null)}
        onConfirm={remove}
        tone="danger"
        loading={deleting}
        title="¿Borrar esta opinión?"
        text={toDelete ? `${toDelete.name || toDelete.clientName || 'La opinión'} · ${num(toDelete.rating)} de 5. Se borra y no se puede recuperar. Si solo no querés mostrarla, ocultala.` : ''}
        confirmLabel="Borrar"
        cancelLabel="Volver"
      />
    </section>
  );
}

export default ReviewsSection;
