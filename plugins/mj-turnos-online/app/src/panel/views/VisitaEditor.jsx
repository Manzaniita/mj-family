/**
 * Editor de visita (/clientas/:id/visitas/:visitId, spec A.11): fecha de la
 * visita, servicio (select + nombre libre) y los bloques de `fichaBlocks`
 * renderizados por VisitFields. Advertencias inline al elegir opciones con
 * `warn` + resumen arriba; "Sugerir" en control / retoque (visited_at +
 * offsets, sin zonas horarias). Guardar explícito (PATCH) con toast; aviso si
 * hay cambios sin guardar al salir (beforeunload + enlaces internos);
 * eliminar visita con confirmación.
 *
 * No existe GET /panel/visits/{id}: la visita se saca de GET /panel/clients/{id}.
 */
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { AlertTriangle, CalendarClock, Check, ClipboardX, Save, Trash2 } from 'lucide-react';
import { panelApi } from '@/lib/api';
import { BASE_PATH } from '@/lib/config';
import { cn, dateLabel, formatDateTime, fullName, pluralize } from '@/lib/format';
import { isDateStr } from '@/lib/dates';
import { useToast, Button, ConfirmDialog, DateInput, EmptyState, FieldRow, Input, PageHeader, Select, Skeleton, SkeletonCard, StatusBadge } from '@/ui';
import { usePanel } from '../store';
import VisitFields, { COLUMN_ROLES, computeWarnings, flattenFields, isEmptyValue, normalizeValue, normalizeValues, roleField, toBool } from '../components/VisitFields';

const OTHER = '__other__';

/*
 * Si la ficha configurada no tiene campo para alguno de los roles que el
 * servidor guarda en columnas (total, seña, método, control, retoque), el
 * editor agrega un bloque "Caja y seguimiento" con esos campos para que la
 * Caja y los recordatorios sigan funcionando. Los rótulos son fijos del panel.
 */
const COLUMN_FIELD_DEFS = {
  total: { key: '_total', label: 'Total cobrado', type: 'money', role: 'total', width: 'half' },
  deposit: { key: '_deposit', label: 'Seña', type: 'money', role: 'deposit', width: 'half' },
  payment_method: { key: '_payment_method', label: 'Método de pago', type: 'radio', role: 'payment_method', options: [] },
  control_date: { key: '_control_date', label: 'Control', type: 'date', role: 'control_date', width: 'half' },
  retouch_date: { key: '_retouch_date', label: 'Retoque sugerido', type: 'date', role: 'retouch_date', width: 'half' },
};

const COLUMN_PROP = { total: 'total', deposit: 'deposit', payment_method: 'paymentMethod', control_date: 'controlDate', retouch_date: 'retouchDate' };

const num0 = (v) => {
  if (v === '' || v === null || v === undefined) return 0;
  const n = Number(v);
  return Number.isFinite(n) ? n : 0;
};

/** Bloque sintético con los roles de columna que la ficha no cubre. */
function missingColumnBlock(blocks) {
  const missing = COLUMN_ROLES.filter((role) => !roleField(blocks, role));
  if (!missing.length) return null;
  return { key: '_caja', title: 'Caja y seguimiento', fields: missing.map((role) => COLUMN_FIELD_DEFS[role]) };
}

/** Estado del formulario a partir de la visita del servidor. */
function buildForm(visit, blocks, extraBlock, services) {
  const serviceId = Number(visit.serviceId) || 0;
  const known = serviceId && services.some((s) => Number(s.id) === serviceId);
  const cols = {};
  if (extraBlock) {
    extraBlock.fields.forEach((f) => {
      cols[f.key] = normalizeValue(f, visit[COLUMN_PROP[f.role]]);
    });
  }
  return {
    visitedAt: isDateStr(visit.visitedAt) ? visit.visitedAt : '',
    serviceId: known ? serviceId : 0,
    serviceName: String(visit.serviceName || ''),
    data: normalizeValues(blocks, visit.data),
    cols,
  };
}

export default function VisitaEditor() {
  const { id, visitId } = useParams();
  const navigate = useNavigate();
  const store = usePanel();
  const toast = useToast();

  const blocks = store.fichaBlocks;
  const extraBlock = useMemo(() => missingColumnBlock(blocks), [blocks]);
  const fields = useMemo(() => flattenFields(blocks), [blocks]);

  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [client, setClient] = useState(null);
  const [visit, setVisit] = useState(null);
  const [appointment, setAppointment] = useState(null);
  const [form, setForm] = useState(null);
  const [snapshot, setSnapshot] = useState('');
  const [errors, setErrors] = useState({});
  const [saving, setSaving] = useState(false);
  const [deleteOpen, setDeleteOpen] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [leaveTo, setLeaveTo] = useState(null);
  const [savedOnce, setSavedOnce] = useState(false);

  // La carga lee la ficha y los servicios por ref: un refresh del bootstrap
  // (por ejemplo al volver a la pestaña) no debe pisar lo que se está editando.
  const cfgRef = useRef({ blocks, extraBlock, services: store.services });
  cfgRef.current = { blocks, extraBlock, services: store.services };

  /* -------------------------------- Carga -------------------------------- */

  const load = useCallback(async () => {
    const { blocks: cfgBlocks, extraBlock: cfgExtra, services } = cfgRef.current;
    setLoading(true);
    try {
      const res = await panelApi.getClient(id);
      const visits = Array.isArray(res?.visits) ? res.visits : [];
      const v = visits.find((x) => Number(x.id) === Number(visitId)) || null;
      if (!v) {
        setError(Object.assign(new Error('No encontramos esa visita en la ficha de la clienta.'), { status: 404 }));
        setClient(res?.client || null);
        setVisit(null);
        return;
      }
      const appts = Array.isArray(res?.appointments) ? res.appointments : [];
      setClient(res?.client || null);
      setVisit(v);
      setAppointment(v.appointmentId ? appts.find((a) => Number(a.id) === Number(v.appointmentId)) || null : null);
      const f = buildForm(v, cfgBlocks, cfgExtra, services);
      setForm(f);
      setSnapshot(JSON.stringify(f));
      setErrors({});
      setError(null);
    } catch (err) {
      setError(err);
    } finally {
      setLoading(false);
    }
  }, [id, visitId]);

  useEffect(() => {
    load();
  }, [load]);

  const dirty = !!form && JSON.stringify(form) !== snapshot;

  /* ------------------------- Aviso de cambios sin guardar ------------------ */

  useEffect(() => {
    if (!dirty) return undefined;
    const onBeforeUnload = (e) => {
      e.preventDefault();
      e.returnValue = '';
    };
    // Enlaces internos (nav inferior, sidebar, volver): se interceptan en fase de captura.
    const onClick = (e) => {
      if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
      const a = e.target?.closest?.('a[href]');
      if (!a || (a.target && a.target !== '_self') || a.hasAttribute('download')) return;
      let url;
      try {
        url = new URL(a.getAttribute('href'), window.location.href);
      } catch (err) {
        return;
      }
      if (url.origin !== window.location.origin) return;
      if (url.pathname === window.location.pathname && url.search === window.location.search) return;
      e.preventDefault();
      e.stopPropagation();
      const inApp = BASE_PATH ? url.pathname === BASE_PATH || url.pathname.startsWith(`${BASE_PATH}/`) : true;
      setLeaveTo(inApp ? { route: (url.pathname.slice(BASE_PATH.length) || '/') + url.search } : { href: url.href });
    };
    window.addEventListener('beforeunload', onBeforeUnload);
    document.addEventListener('click', onClick, true);
    return () => {
      window.removeEventListener('beforeunload', onBeforeUnload);
      document.removeEventListener('click', onClick, true);
    };
  }, [dirty]);

  const fichaPath = `/clientas/${client?.id || id}`;

  const goBack = () => {
    if (dirty) setLeaveTo({ route: fichaPath });
    else navigate(fichaPath);
  };

  const confirmLeave = () => {
    const target = leaveTo;
    setLeaveTo(null);
    setSnapshot(JSON.stringify(form)); // deja de estar "sucio" para no volver a interceptar
    if (!target) return;
    if (target.route) navigate(target.route);
    else if (target.href) window.location.assign(target.href);
  };

  /* ------------------------------- Cambios -------------------------------- */

  const setField = (key, value) => setForm((f) => ({ ...f, data: { ...f.data, [key]: value } }));
  const setCol = (key, value) => setForm((f) => ({ ...f, cols: { ...f.cols, [key]: value } }));

  const onServiceChange = (value) => {
    if (value === OTHER) {
      setForm((f) => ({ ...f, serviceId: 0 }));
      return;
    }
    const svc = store.serviceById(value);
    setForm((f) => ({ ...f, serviceId: svc ? Number(svc.id) : 0, serviceName: svc ? svc.name : f.serviceName }));
  };

  const serviceOptions = useMemo(() => {
    const list = store.services.filter((s) => s.active !== false || Number(s.id) === Number(form?.serviceId));
    return [
      ...list.map((s) => ({ value: String(s.id), label: s.active === false ? `${s.name} (inactivo)` : s.name })),
      { value: OTHER, label: 'Otro / sin servicio' },
    ];
  }, [store.services, form?.serviceId]);

  /* ----------------------------- Advertencias ----------------------------- */

  const warnings = useMemo(() => (form ? computeWarnings(blocks, form.data, store.paymentMethods) : []), [form, blocks, store.paymentMethods]);

  /* -------------------------------- Guardar ------------------------------- */

  const depositFlagField = roleField(blocks, 'deposit_flag');

  const columnValue = (role) => {
    const f = roleField(blocks, role);
    if (f) return form.data[f.key];
    return form.cols[`_${role}`];
  };

  const validate = () => {
    const next = {};
    if (!isDateStr(form.visitedAt)) next.visitedAt = 'Elegí la fecha de la visita.';
    fields.forEach((f) => {
      if (!f.required) return;
      if (f.role === 'deposit' && depositFlagField && !toBool(form.data[depositFlagField.key])) return;
      if (isEmptyValue(normalizeValue(f, form.data[f.key])) && f.type !== 'toggle') next[f.key] = 'Este campo es obligatorio.';
    });
    setErrors(next);
    return next;
  };

  const save = async () => {
    if (!form || saving) return;
    const errs = validate();
    if (Object.keys(errs).length) {
      toast.error(errs.visitedAt || 'Faltan completar campos obligatorios.');
      const firstKey = Object.keys(errs).find((k) => k !== 'visitedAt');
      const el = firstKey ? document.querySelector(`[data-field="${firstKey}"]`) : null;
      el?.scrollIntoView?.({ behavior: 'smooth', block: 'center' });
      return;
    }
    const depositOn = depositFlagField ? toBool(form.data[depositFlagField.key]) : true;
    const payload = {
      visited_at: form.visitedAt,
      service_id: form.serviceId || 0,
      service_name: form.serviceName.trim(),
      data: form.data,
      total: num0(columnValue('total')),
      deposit: depositOn ? num0(columnValue('deposit')) : 0,
      payment_method: String(columnValue('payment_method') || ''),
      control_date: isDateStr(columnValue('control_date')) ? columnValue('control_date') : '',
      retouch_date: isDateStr(columnValue('retouch_date')) ? columnValue('retouch_date') : '',
    };
    setSaving(true);
    try {
      const saved = await panelApi.updateVisit(visit.id, payload);
      const merged = { ...visit, ...(saved || {}) };
      setVisit(merged);
      const f = buildForm(merged, blocks, extraBlock, store.services);
      setForm(f);
      setSnapshot(JSON.stringify(f));
      setSavedOnce(true);
      const count = Array.isArray(merged.warnings) ? merged.warnings.length : warnings.length;
      toast.success(count ? `Visita guardada con ${pluralize(count, 'advertencia')}.` : 'Visita guardada.');
    } catch (err) {
      if (err?.data && typeof err.data === 'object' && err.data.field) setErrors({ [err.data.field]: err.message });
      toast.error(err);
    } finally {
      setSaving(false);
    }
  };

  const remove = async () => {
    setDeleting(true);
    try {
      await panelApi.deleteVisit(visit.id);
      toast.success('Visita eliminada.');
      setSnapshot(JSON.stringify(form));
      setDeleteOpen(false);
      navigate(fichaPath, { replace: true });
    } catch (err) {
      toast.error(err);
      setDeleting(false);
    }
  };

  /* --------------------------------- Render ------------------------------- */

  if (error && !visit) {
    return (
      <div className="mjto-view mjto-view--visita">
        <PageHeader title="Visita" back={fichaPath} backLabel="Volver a la ficha" />
        <EmptyState
          icon={ClipboardX}
          title={error.status === 404 ? 'No encontramos esa visita' : 'No se pudo cargar la visita'}
          text={error.message}
          action={
            <>
              <Button onClick={load}>Reintentar</Button>
              <Button variant="ghost" to={fichaPath}>
                Volver a la ficha
              </Button>
            </>
          }
        />
      </div>
    );
  }

  if (loading || !form) {
    return (
      <div className="mjto-view mjto-view--visita" aria-busy="true">
        <PageHeader title={<Skeleton className="h-8 w-56" />} back={fichaPath} backLabel="Volver a la ficha" />
        <div className="flex flex-col gap-3">
          <SkeletonCard />
          <SkeletonCard />
          <SkeletonCard />
        </div>
      </div>
    );
  }

  const clientName = fullName(client) || 'Clienta';
  const title = isDateStr(form.visitedAt) ? `Visita del ${dateLabel(form.visitedAt, { year: true })}` : 'Visita';

  return (
    <div className="mjto-view mjto-view--visita">
      <PageHeader
        eyebrow="Ficha técnica"
        title={title}
        subtitle={
          <span className="inline-flex flex-wrap items-center gap-x-2 gap-y-1">
            <Link to={fichaPath} className="font-semibold text-accent hover:underline">
              {clientName}
            </Link>
            {appointment && (
              <>
                <span aria-hidden="true">·</span>
                <span className="inline-flex items-center gap-1.5">
                  <CalendarClock size={14} className="text-ink-faint" aria-hidden="true" />
                  Turno {formatDateTime(appointment.startsAt)}
                </span>
                <StatusBadge status={appointment.status} label={appointment.statusLabel || store.statusLabel(appointment.status)} size="sm" />
              </>
            )}
          </span>
        }
        back={goBack}
        backLabel="Volver a la ficha"
        actions={
          <Button icon={Save} loading={saving} onClick={save} disabled={!dirty && savedOnce} className="hidden sm:inline-flex">
            Guardar
          </Button>
        }
      />

      {warnings.length > 0 && (
        <section className="mjto-visit-warnings mb-4 rounded-card border border-warning/30 bg-warning-soft p-4" role="note" aria-live="polite">
          <p className="flex items-center gap-2 text-sm font-semibold text-warning">
            <AlertTriangle size={17} aria-hidden="true" />
            {pluralize(warnings.length, 'advertencia')} en esta ficha
          </p>
          <ul className="mt-2 flex flex-col gap-1.5 pl-6 text-[13px] leading-snug text-warning">
            {warnings.map((w, i) => (
              <li key={`${w.field}-${i}`} className="list-disc">
                <button
                  type="button"
                  className="text-left hover:underline"
                  onClick={() => document.querySelector(`[data-field="${w.field}"]`)?.scrollIntoView?.({ behavior: 'smooth', block: 'center' })}
                >
                  {w.text}
                </button>
              </li>
            ))}
          </ul>
        </section>
      )}

      <div className="flex flex-col gap-4">
        <section className="mjto-card p-4 sm:p-5" aria-label="Datos de la visita">
          <FieldRow>
            <DateInput label="Fecha de la visita" required value={form.visitedAt} error={errors.visitedAt} onChange={(v) => setForm((f) => ({ ...f, visitedAt: v }))} />
            <Select
              label="Servicio"
              value={form.serviceId ? String(form.serviceId) : OTHER}
              options={serviceOptions}
              onChange={(e) => onServiceChange(e.target.value)}
            />
            {!form.serviceId && (
              <Input
                label="Nombre del servicio"
                placeholder="Ej: Alisado + corte"
                value={form.serviceName}
                onChange={(e) => setForm((f) => ({ ...f, serviceName: e.target.value }))}
                className="sm:col-span-2"
              />
            )}
          </FieldRow>
        </section>

        <VisitFields
          blocks={blocks}
          values={form.data}
          onChange={setField}
          paymentMethods={store.paymentMethods}
          visitedAt={form.visitedAt}
          errors={errors}
        />

        {extraBlock && (
          <VisitFields blocks={[extraBlock]} values={form.cols} onChange={setCol} paymentMethods={store.paymentMethods} visitedAt={form.visitedAt} />
        )}

        <section className="flex flex-wrap items-center justify-between gap-3 rounded-card border border-dashed border-line p-4">
          <p className="text-xs text-ink-faint">
            {visit.updatedAt ? `Última modificación: ${formatDateTime(visit.updatedAt, { year: true })}.` : ''} Eliminar la visita no borra el turno.
          </p>
          <Button variant="ghost" size="sm" icon={Trash2} className="text-danger hover:bg-danger-soft hover:text-danger" onClick={() => setDeleteOpen(true)}>
            Eliminar visita
          </Button>
        </section>
      </div>

      {/* Barra de guardado fija (mobile) */}
      <div
        className={cn(
          'mjto-savebar sticky z-20 mt-4 flex items-center justify-between gap-3 rounded-card border border-line bg-surface/95 px-4 py-3 shadow-float backdrop-blur sm:hidden',
          'bottom-[calc(var(--mjto-nav-h)+env(safe-area-inset-bottom)+0.75rem)]'
        )}
      >
        <p className={cn('text-sm', dirty ? 'font-medium text-ink' : 'text-ink-faint')}>
          {dirty ? 'Cambios sin guardar' : savedOnce ? (
            <span className="inline-flex items-center gap-1.5 text-success">
              <Check size={15} aria-hidden="true" />
              Guardado
            </span>
          ) : 'Sin cambios'}
        </p>
        <Button icon={Save} loading={saving} onClick={save} disabled={!dirty && savedOnce}>
          Guardar
        </Button>
      </div>

      <ConfirmDialog
        open={deleteOpen}
        onClose={() => setDeleteOpen(false)}
        onConfirm={remove}
        tone="danger"
        loading={deleting}
        title="¿Eliminar esta visita?"
        text="Se borra la ficha técnica de esta visita. El turno, si existe, queda como está."
        confirmLabel="Eliminar visita"
      />

      <ConfirmDialog
        open={!!leaveTo}
        onClose={() => setLeaveTo(null)}
        onConfirm={confirmLeave}
        tone="danger"
        icon={AlertTriangle}
        title="Tenés cambios sin guardar"
        text="Si salís ahora se pierden los cambios de esta visita."
        confirmLabel="Salir sin guardar"
        cancelLabel="Seguir editando"
      />
    </div>
  );
}
