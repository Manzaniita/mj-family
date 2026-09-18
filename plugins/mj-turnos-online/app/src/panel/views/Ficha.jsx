/**
 * Ficha de la clienta (/clientas/:id, spec A.11 — Ficha): cabecera con datos
 * básicos + "Editar", botones WhatsApp (plantillas del próximo/último turno),
 * "Nuevo turno" (NuevoTurno con la clienta fija) y "Nueva visita" (crea la
 * visita con carry y abre el editor); banner con las advertencias de la última
 * visita; pestañas Visitas (línea de tiempo, más reciente arriba) y Turnos
 * (tarjetas con acciones); eliminar clienta con confirmación doble.
 *
 * 1.3: "Saldo a favor" (si options.creditEnabled o si ya tiene saldo) y
 * "Cuenta en la web" (si las cuentas están prendidas o ya tiene una vinculada).
 */
import { useCallback, useEffect, useMemo, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { AlertTriangle, CalendarPlus, Cake, ClipboardList, ClipboardPlus, Mail, MapPin, MessageCircle, Pencil, Phone, StickyNote, Trash2, UserX } from 'lucide-react';
import { panelApi } from '@/lib/api';
import { cn, dateLabel, fullName, initials, pluralize, prettyPhone, shortDateLabel } from '@/lib/format';
import { useToast, Button, ConfirmDialog, EmptyState, PageHeader, SectionTitle, Skeleton, SkeletonCard, Tabs } from '@/ui';
import { usePanel } from '../store';
import ClientForm from '../components/ClientForm';
import VisitSummary from '../components/VisitSummary';
import AppointmentCard from '../components/AppointmentCard';
import NuevoTurno from '../components/NuevoTurno';
import WhatsAppMenu from '../components/WhatsAppMenu';
import ClientCredit, { normalizeCredit } from '../components/ClientCredit';
import ClientAccount from '../components/ClientAccount';

const CANCELLED = ['cancelled', 'no_show'];

const byVisitDesc = (a, b) => {
  const da = String(a.visitedAt || '');
  const db = String(b.visitedAt || '');
  if (da !== db) return da < db ? 1 : -1;
  return (Number(b.id) || 0) - (Number(a.id) || 0);
};

function InfoLine({ icon: Icon, children, className }) {
  if (!children) return null;
  return (
    <span className={cn('inline-flex min-w-0 items-center gap-1.5 text-sm text-ink-soft', className)}>
      <Icon size={14} className="shrink-0 text-ink-faint" aria-hidden="true" />
      <span className="truncate">{children}</span>
    </span>
  );
}

function HeaderSkeleton() {
  return (
    <div className="mjto-card flex gap-4 p-4 sm:p-5" aria-busy="true">
      <Skeleton className="size-14 shrink-0 rounded-2xl" />
      <div className="flex-1">
        <Skeleton lines={3} />
      </div>
    </div>
  );
}

export default function Ficha() {
  const { id } = useParams();
  const navigate = useNavigate();
  const store = usePanel();
  const toast = useToast();

  const [client, setClient] = useState(null);
  const [visits, setVisits] = useState([]);
  const [appointments, setAppointments] = useState([]);
  const [account, setAccount] = useState(null);
  const [credit, setCredit] = useState(() => normalizeCredit(null));
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  const [tab, setTab] = useState('visitas');
  const [editOpen, setEditOpen] = useState(false);
  const [nuevoOpen, setNuevoOpen] = useState(false);
  const [expanded, setExpanded] = useState(null);
  const [visitToDelete, setVisitToDelete] = useState(null);
  const [deleteStep, setDeleteStep] = useState(0);
  const [busy, setBusy] = useState('');

  const load = useCallback(
    async ({ silent = false } = {}) => {
      if (!silent) setLoading(true);
      try {
        const res = await panelApi.getClient(id);
        setClient(res?.client || null);
        setVisits(Array.isArray(res?.visits) ? [...res.visits].sort(byVisitDesc) : []);
        setAppointments(Array.isArray(res?.appointments) ? res.appointments : []);
        setAccount(res?.account && typeof res.account === 'object' ? res.account : null);
        setCredit(normalizeCredit(res?.credit));
        setError(null);
      } catch (err) {
        if (!silent) setError(err);
        else toast.error(err);
      } finally {
        setLoading(false);
      }
    },
    [id, toast]
  );

  useEffect(() => {
    setExpanded(null);
    load();
  }, [load]);

  const name = fullName(client) || 'Clienta';
  const latest = visits[0] || null;
  const latestWarnings = Array.isArray(latest?.warnings) ? latest.warnings : [];

  // Turnos: próximos (asc) y anteriores (desc).
  const { upcoming, past } = useMemo(() => {
    const now = store.now;
    const up = appointments.filter((a) => a.startsAt >= now).sort((a, b) => (a.startsAt < b.startsAt ? -1 : 1));
    const pa = appointments.filter((a) => a.startsAt < now).sort((a, b) => (a.startsAt < b.startsAt ? 1 : -1));
    return { upcoming: up, past: pa };
  }, [appointments, store.now]);

  /** Turno de referencia para WhatsApp: próximo no cancelado, si no el último. */
  const refAppointment = useMemo(() => {
    const next = upcoming.find((a) => !CANCELLED.includes(a.status)) || upcoming[0];
    return next || past[0] || null;
  }, [upcoming, past]);

  const showCredit = !!client && (store.creditEnabled || credit.balance > 0);
  const showAccount = !!client && (store.accountMode !== 'off' || !!client.userId || !!account);

  const visitAppointment = (visit) => (visit?.appointmentId ? appointments.find((a) => Number(a.id) === Number(visit.appointmentId)) || null : null);

  /* -------------------------------- Acciones ------------------------------ */

  const newVisit = async () => {
    if (busy) return;
    setBusy('visit');
    try {
      const visit = await panelApi.createVisit(client.id, { carry: true, visited_at: store.today });
      navigate(`/clientas/${client.id}/visitas/${visit.id}`);
    } catch (err) {
      toast.error(err);
      setBusy('');
    }
  };

  const openFicha = async (appointment) => {
    if (!appointment) return;
    const clientId = appointment.clientId || client?.id;
    if (appointment.visitId) {
      navigate(`/clientas/${clientId}/visitas/${appointment.visitId}`);
      return;
    }
    if (busy) return;
    setBusy('ficha');
    try {
      const visit = await panelApi.createVisit(clientId, { appointment_id: appointment.id });
      navigate(`/clientas/${clientId}/visitas/${visit.id}`);
    } catch (err) {
      toast.error(err);
      setBusy('');
    }
  };

  /** Contrato de AppointmentCard/AppointmentActions: onChange(next, { action, id }); next = null si se eliminó. */
  const onAppointmentChange = (next, meta) => {
    const removedId = meta?.action === 'delete' || next === null ? Number(meta?.id) : 0;
    if (removedId) setAppointments((list) => list.filter((a) => Number(a.id) !== removedId));
    else if (next && next.id) setAppointments((list) => list.map((a) => (Number(a.id) === Number(next.id) ? { ...a, ...next } : a)));
    // Confirmar crea la ficha, cancelar cambia contadores: refrescamos todo en silencio.
    load({ silent: true });
    store.refresh().catch(() => {});
  };

  const deleteVisit = async () => {
    if (!visitToDelete) return;
    setBusy('delete-visit');
    try {
      await panelApi.deleteVisit(visitToDelete.id);
      setVisits((list) => list.filter((v) => v.id !== visitToDelete.id));
      setAppointments((list) => list.map((a) => (Number(a.visitId) === Number(visitToDelete.id) ? { ...a, visitId: null } : a)));
      toast.success('Visita eliminada.');
      setVisitToDelete(null);
    } catch (err) {
      toast.error(err);
    } finally {
      setBusy('');
    }
  };

  const deleteClient = async () => {
    setBusy('delete-client');
    try {
      await panelApi.deleteClient(client.id);
      toast.success(`Se eliminó a ${name}.`);
      store.refresh().catch(() => {});
      navigate('/clientas', { replace: true });
    } catch (err) {
      toast.error(err);
      setBusy('');
      setDeleteStep(0);
    }
  };

  /* --------------------------------- Render ------------------------------- */

  if (error && !client) {
    return (
      <div className="mjto-view mjto-view--ficha">
        <PageHeader title="Ficha" back="/clientas" />
        <EmptyState
          icon={UserX}
          title={error.status === 404 ? 'No encontramos esa clienta' : 'No se pudo cargar la ficha'}
          text={error.message}
          action={
            <>
              <Button onClick={() => load()}>Reintentar</Button>
              <Button variant="ghost" to="/clientas">
                Volver a clientas
              </Button>
            </>
          }
        />
      </div>
    );
  }

  // WhatsAppMenu arma las plantillas del turno de referencia y, sin turno, el mensaje libre con el teléfono.
  const waTrigger = (
    <Button variant="success" icon={MessageCircle} size="sm">
      WhatsApp
    </Button>
  );

  return (
    <div className="mjto-view mjto-view--ficha">
      <PageHeader
        eyebrow="Ficha"
        title={loading && !client ? <Skeleton className="h-8 w-48" /> : name}
        subtitle={
          client
            ? `${pluralize(visits.length, 'visita')}${latest ? ` · última ${dateLabel(latest.visitedAt, { year: true })}` : ''}${client.createdAt ? ` · desde ${dateLabel(client.createdAt, { year: true })}` : ''}`
            : undefined
        }
        back="/clientas"
        backLabel="Volver a clientas"
        actions={
          client && (
            <Button variant="secondary" size="sm" icon={Pencil} onClick={() => setEditOpen(true)}>
              Editar
            </Button>
          )
        }
      />

      {loading && !client ? (
        <HeaderSkeleton />
      ) : (
        client && (
          <section className="mjto-card mjto-ficha-head p-4 sm:p-5" aria-label="Datos de la clienta">
            <div className="flex items-start gap-4">
              <span className="grid size-14 shrink-0 place-items-center rounded-2xl bg-accent font-display text-lg font-bold text-on-accent shadow-glow" aria-hidden="true">
                {initials(name)}
              </span>
              <div className="min-w-0 flex-1">
                <div className="flex flex-wrap gap-x-4 gap-y-1">
                  {client.phone ? (
                    <InfoLine icon={Phone}>
                      <span className="mjto-tabular">{prettyPhone(client.phoneRaw || client.phone)}</span>
                    </InfoLine>
                  ) : (
                    <InfoLine icon={Phone}>Sin teléfono</InfoLine>
                  )}
                  {client.age !== null && client.age !== undefined && client.age !== '' ? (
                    <InfoLine icon={Cake}>{`${client.age} años${client.birthdate ? ` · cumple el ${shortDateLabel(client.birthdate)}` : ''}`}</InfoLine>
                  ) : (
                    client.birthdate && <InfoLine icon={Cake}>{`Cumple el ${shortDateLabel(client.birthdate)}`}</InfoLine>
                  )}
                  <InfoLine icon={MapPin}>{client.locality}</InfoLine>
                  {client.email && (
                    <InfoLine icon={Mail}>
                      <a href={`mailto:${client.email}`} className="hover:text-accent hover:underline">
                        {client.email}
                      </a>
                    </InfoLine>
                  )}
                </div>
                {client.notes && (
                  <p className="mt-3 flex items-start gap-2 rounded-tile bg-surface-2 px-3 py-2 text-sm leading-snug text-ink-soft">
                    <StickyNote size={15} className="mt-0.5 shrink-0 text-ink-faint" aria-hidden="true" />
                    <span className="whitespace-pre-line">{client.notes}</span>
                  </p>
                )}
                {client.extra && typeof client.extra === 'object' && Object.keys(client.extra).some((k) => client.extra[k] !== '' && client.extra[k] !== null) && (
                  <dl className="mt-3 grid grid-cols-1 gap-x-4 gap-y-1 text-sm sm:grid-cols-2">
                    {Object.entries(client.extra)
                      .filter(([, v]) => v !== '' && v !== null && v !== undefined)
                      .map(([k, v]) => {
                        const def = store.formFields.find((f) => f.key === k);
                        let shown = Array.isArray(v) ? v.join(', ') : String(v);
                        // Tipos nuevos de 1.3: fecha legible y la etiqueta de la opción elegida.
                        if (def?.type === 'date' && /^\d{4}-\d{2}-\d{2}$/.test(shown)) shown = dateLabel(shown, { year: true });
                        if (def?.type === 'radio' && Array.isArray(def.options)) shown = def.options.find((o) => String(o.value) === shown)?.label || shown;
                        return (
                          <div key={k} className="flex min-w-0 gap-2">
                            <dt className="shrink-0 text-ink-faint">{def?.label || k}:</dt>
                            <dd className="m-0 min-w-0 truncate text-ink">{shown}</dd>
                          </div>
                        );
                      })}
                  </dl>
                )}
              </div>
            </div>
            <div className="mt-4 flex flex-wrap gap-2">
              <WhatsAppMenu appointment={refAppointment || undefined} client={client} links={refAppointment?.whatsapp?.links} trigger={waTrigger} />

              <Button variant="soft" size="sm" icon={CalendarPlus} onClick={() => setNuevoOpen(true)}>
                Nuevo turno
              </Button>
              <Button size="sm" icon={ClipboardPlus} loading={busy === 'visit'} onClick={newVisit}>
                Nueva visita
              </Button>
            </div>
          </section>
        )
      )}

      {(showCredit || showAccount) && (
        <div className={cn('mjto-ficha-extras mt-3 grid grid-cols-1 gap-3', showCredit && showAccount && 'md:grid-cols-2')}>
          {showCredit && <ClientCredit clientId={client.id} credit={credit} onChange={setCredit} />}
          {showAccount && (
            <ClientAccount
              client={client}
              account={account}
              onChanged={(c) => {
                if (c && c.id) setClient((cur) => ({ ...(cur || {}), ...c }));
                load({ silent: true });
              }}
            />
          )}
        </div>
      )}

      {latestWarnings.length > 0 && (
        <section className="mjto-ficha-warnings mt-3 rounded-card border border-warning/30 bg-warning-soft p-4" role="note" aria-label="Advertencias de la última visita">
          <p className="flex items-center gap-2 text-sm font-semibold text-warning">
            <AlertTriangle size={17} aria-hidden="true" />
            {pluralize(latestWarnings.length, 'advertencia')} en la última visita
            {latest?.visitedAt && <span className="font-normal opacity-80">({dateLabel(latest.visitedAt, { year: true })})</span>}
          </p>
          <ul className="mt-2 flex flex-col gap-1.5 pl-6 text-[13px] leading-snug text-warning">
            {latestWarnings.map((w, i) => (
              <li key={i} className="list-disc">
                {w.text || `${w.label}: ${w.option}`}
              </li>
            ))}
          </ul>
        </section>
      )}

      <div className="mt-5">
        <Tabs
          items={[
            { key: 'visitas', label: 'Visitas', count: visits.length, icon: ClipboardList },
            { key: 'turnos', label: 'Turnos', count: appointments.length, icon: CalendarPlus },
          ]}
          value={tab}
          onChange={setTab}
          full
          className="sm:w-fit"
        />
      </div>

      <div className="mt-4">
        {loading && !client ? (
          <div className="flex flex-col gap-3" aria-busy="true">
            <SkeletonCard />
            <SkeletonCard />
          </div>
        ) : tab === 'visitas' ? (
          visits.length === 0 ? (
            <EmptyState
              icon={ClipboardList}
              title="Todavía no hay visitas"
              text="La ficha técnica se arma sola al confirmar un turno, o podés cargar una visita ahora."
              compact
              action={
                <Button icon={ClipboardPlus} loading={busy === 'visit'} onClick={newVisit}>
                  Nueva visita
                </Button>
              }
            />
          ) : (
            <ol className="mjto-timeline flex flex-col">
              {visits.map((v, i) => (
                <VisitSummary
                  key={v.id}
                  visit={v}
                  blocks={store.fichaBlocks}
                  paymentMethods={store.paymentMethods}
                  appointment={visitAppointment(v)}
                  expanded={expanded === v.id}
                  onToggle={() => setExpanded((cur) => (cur === v.id ? null : v.id))}
                  onEdit={() => navigate(`/clientas/${client.id}/visitas/${v.id}`)}
                  onDelete={() => setVisitToDelete(v)}
                  isLast={i === visits.length - 1}
                />
              ))}
            </ol>
          )
        ) : appointments.length === 0 ? (
          <EmptyState
            icon={CalendarPlus}
            title="Sin turnos"
            text="Esta clienta todavía no tiene turnos."
            compact
            action={
              <Button icon={CalendarPlus} onClick={() => setNuevoOpen(true)}>
                Nuevo turno
              </Button>
            }
          />
        ) : (
          <div className="flex flex-col gap-5">
            {upcoming.length > 0 && (
              <section>
                <SectionTitle title={`Próximos (${upcoming.length})`} />
                <ul className="flex flex-col gap-3">
                  {upcoming.map((a) => (
                    <li key={a.id}>
                      <AppointmentCard appointment={a} onChange={onAppointmentChange} onOpenFicha={openFicha} />
                    </li>
                  ))}
                </ul>
              </section>
            )}
            {past.length > 0 && (
              <section>
                <SectionTitle title={`Anteriores (${past.length})`} />
                <ul className="flex flex-col gap-3">
                  {past.map((a) => (
                    <li key={a.id}>
                      <AppointmentCard appointment={a} onChange={onAppointmentChange} onOpenFicha={openFicha} />
                    </li>
                  ))}
                </ul>
              </section>
            )}
          </div>
        )}
      </div>

      {client && (
        <section className="mt-10 rounded-card border border-dashed border-line p-4">
          <p className="text-xs text-ink-faint">
            Eliminar a la clienta borra también sus {pluralize(visits.length, 'visita')} y {pluralize(appointments.length, 'turno')}. No se puede deshacer.
          </p>
          <Button variant="ghost" size="sm" icon={Trash2} className="mt-2 text-danger hover:bg-danger-soft hover:text-danger" onClick={() => setDeleteStep(1)}>
            Eliminar clienta
          </Button>
        </section>
      )}

      {/* Diálogos */}
      <ClientForm open={editOpen} onClose={() => setEditOpen(false)} client={client} onSaved={(c) => setClient((cur) => ({ ...(cur || {}), ...c }))} />

      {client && (
        <NuevoTurno
          open={nuevoOpen}
          onClose={() => setNuevoOpen(false)}
          client={client}
          date={store.today}
          onCreated={() => {
            setNuevoOpen(false);
            setTab('turnos');
            load({ silent: true });
            store.refresh().catch(() => {});
          }}
        />
      )}

      <ConfirmDialog
        open={!!visitToDelete}
        onClose={() => setVisitToDelete(null)}
        onConfirm={deleteVisit}
        tone="danger"
        loading={busy === 'delete-visit'}
        title="¿Eliminar esta visita?"
        text={visitToDelete ? `Se borra la ficha del ${dateLabel(visitToDelete.visitedAt, { year: true })}${visitToDelete.serviceName ? ` (${visitToDelete.serviceName})` : ''}. No se puede deshacer.` : ''}
        confirmLabel="Eliminar visita"
      />

      <ConfirmDialog
        open={deleteStep === 1}
        onClose={() => setDeleteStep(0)}
        onConfirm={() => setDeleteStep(2)}
        tone="danger"
        title={`¿Eliminar a ${name}?`}
        text={`Se van a borrar sus datos, ${pluralize(visits.length, 'visita')} y ${pluralize(appointments.length, 'turno')}.`}
        confirmLabel="Sí, continuar"
      />

      <ConfirmDialog
        open={deleteStep === 2}
        onClose={() => setDeleteStep(0)}
        onConfirm={deleteClient}
        tone="danger"
        loading={busy === 'delete-client'}
        title="Última confirmación"
        text="Esta acción no se puede deshacer. ¿Eliminar definitivamente a la clienta y todo su historial?"
        confirmLabel="Eliminar definitivamente"
        cancelLabel="No, volver"
      />
    </div>
  );
}
