/**
 * Acciones de un turno: botón rápido según el estado + menú "…" con todo:
 * Confirmar, Seña recibida, Abrir ficha, WhatsApp, Reprogramar, Marcar
 * atendida, No vino, Volver a confirmado, Editar precio/notas, Cancelar,
 * Eliminar. Desde 1.3, "Marcar seña devuelta" cuando la clienta pidió la
 * devolución (o todavía no eligió) después de que la dueña canceló con opciones.
 *
 * Contrato con quien lo usa:
 *  - onChange(next, meta): después de cada cambio, `next` es el turno
 *    actualizado (mjto_appointment_public) o null si se eliminó;
 *    meta = { action: 'status'|'deposit'|'reschedule'|'cancel'|'update'|'visit'|'refund'|'delete', id }.
 *    applyAppointmentChange(lista, next, meta) aplica eso a una lista.
 *  - onOpenFicha(appointment): reemplaza el comportamiento por defecto de
 *    "Abrir ficha" (useOpenFicha: navega a la visita del turno o la crea con
 *    POST /panel/clients/{id}/visits { appointment_id } y navega).
 *  - compact: sólo el botón "…" (para listas apretadas); si no, también el
 *    botón rápido y el de WhatsApp.
 */
import { useCallback, useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { CalendarClock, Check, CheckCheck, ClipboardList, Coins, HandCoins, MessageCircle, MoreHorizontal, Pencil, RotateCcw, Trash2, UserX, XCircle } from 'lucide-react';
import { panelApi } from '@/lib/api';
import { cn, dateLabel, formatTime, fullName, prettyPhone } from '@/lib/format';
import { Button, Checkbox, ConfirmDialog, Modal, MoneyInput, Textarea, useToast } from '@/ui';
import { usePanel } from '../store';
import { PopMenu, WhatsAppMenu, useWhatsAppItems } from './WhatsAppMenu';
import { DepositDialog } from './DepositDialog';
import { CancelDialog } from './CancelDialog';
import { Reprogramar } from './Reprogramar';

/** Estados que ocupan agenda. */
export const ACTIVE_STATUSES = ['pending', 'awaiting_deposit', 'confirmed'];

const num = (v) => (Number.isFinite(Number(v)) ? Number(v) : 0);

/** Qué acciones tienen sentido para el estado del turno. */
export function appointmentAbilities(appointment) {
  const s = appointment?.status;
  const active = ACTIVE_STATUSES.includes(s);
  return {
    confirm: s === 'pending' || s === 'awaiting_deposit',
    deposit: active,
    ficha: s !== 'cancelled' && s !== 'no_show',
    reschedule: active,
    complete: active,
    noShow: active,
    reactivate: s === 'completed' || s === 'cancelled' || s === 'no_show',
    cancel: active,
    /* La devolución se puede marcar mientras la clienta no eligió otra cosa. */
    refund: appointment?.ownerOffer === 'refund_requested' || appointment?.ownerOffer === 'open',
  };
}

/**
 * Aplica un cambio (onChange) a una lista de turnos: reemplaza por id o quita
 * si `next` es null. Si el turno no estaba en la lista, la deja igual.
 */
export function applyAppointmentChange(list, next, meta = {}) {
  const arr = Array.isArray(list) ? list : [];
  const id = Number(next?.id ?? meta.id ?? 0);
  if (!id) return arr;
  if (!next) return arr.filter((a) => Number(a.id) !== id);
  const idx = arr.findIndex((a) => Number(a.id) === id);
  if (idx === -1) return arr;
  const out = arr.slice();
  out[idx] = next;
  return out;
}

/**
 * "Abrir ficha": si el turno ya tiene visita navega a ella; si no, la crea
 * (POST /panel/clients/{clientId}/visits { appointment_id }) y navega.
 * @returns {{ openFicha: (appointment) => Promise<number|null>, openingId: number }}
 */
export function useOpenFicha({ onChange } = {}) {
  const navigate = useNavigate();
  const toast = useToast();
  const [openingId, setOpeningId] = useState(0);
  const onChangeRef = useRef(onChange);
  onChangeRef.current = onChange;

  const openFicha = useCallback(
    async (appointment) => {
      if (!appointment) return null;
      const clientId = Number(appointment.clientId || appointment.client?.id || 0);
      if (!clientId) {
        toast.error('Este turno no tiene una clienta asociada.');
        return null;
      }
      if (appointment.visitId) {
        navigate(`/clientas/${clientId}/visitas/${appointment.visitId}`);
        return Number(appointment.visitId);
      }
      setOpeningId(Number(appointment.id));
      try {
        const visit = await panelApi.createVisit(clientId, { appointment_id: Number(appointment.id) });
        const visitId = Number(visit?.id || 0);
        if (!visitId) throw new Error('No se pudo crear la ficha de la visita.');
        onChangeRef.current?.({ ...appointment, visitId }, { action: 'visit', id: Number(appointment.id) });
        navigate(`/clientas/${clientId}/visitas/${visitId}`);
        return visitId;
      } catch (e) {
        toast.error(e);
        return null;
      } finally {
        setOpeningId(0);
      }
    },
    [navigate, toast]
  );

  return { openFicha, openingId };
}

/* ------------------------------------------------------------------------- */

/** Editar precio y notas internas (PATCH directo). */
function EditDialog({ appointment, open, onClose, onChanged }) {
  const store = usePanel();
  const toast = useToast();
  const [price, setPrice] = useState('');
  const [notes, setNotes] = useState('');
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (!open || !appointment) return;
    setPrice(num(appointment.price));
    setNotes(appointment.internalNotes || '');
    setSaving(false);
  }, [open, appointment?.id]); // eslint-disable-line react-hooks/exhaustive-deps

  if (!appointment) return null;

  const submit = async () => {
    if (saving) return;
    setSaving(true);
    try {
      const next = await panelApi.updateAppointment(appointment.id, { price: num(price), internal_notes: notes });
      toast.success('Turno actualizado.');
      onChanged?.(next, { action: 'update', id: appointment.id });
      onClose?.();
    } catch (e) {
      toast.error(e);
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal
      open={open}
      onClose={onClose}
      size="sm"
      title="Editar turno"
      description={`${fullName(appointment.client)} · ${dateLabel(appointment.date)}, ${formatTime(appointment.time)}`}
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
      <div className="flex flex-col gap-4">
        <MoneyInput label="Precio" value={price} onChange={setPrice} min={0} allowEmpty={false} currency={store.currency} />
        <Textarea label="Notas internas" help="Sólo las ves vos." value={notes} onChange={(e) => setNotes(e.target.value)} rows={4} placeholder="Ej: pidió venir con el pelo lavado" />
      </div>
    </Modal>
  );
}

/* ------------------------------------------------------------------------- */

/**
 * @param {{ appointment: object, onChange?: (next, meta) => void, onOpenFicha?: (appointment) => void,
 *   compact?: boolean, className? }} props
 */
export function AppointmentActions({ appointment, onChange, onOpenFicha, compact = false, className }) {
  const store = usePanel();
  const toast = useToast();
  const [dialog, setDialog] = useState(null);
  const [menu, setMenu] = useState(false);
  const [wa, setWa] = useState(false);
  const [busy, setBusy] = useState('');
  const [notifyConfirm, setNotifyConfirm] = useState(true);
  const anchor = useRef(null);
  const { openFicha, openingId } = useOpenFicha({ onChange });
  const waItems = useWhatsAppItems({ appointment });

  if (!appointment) return null;

  const s = appointment.status;
  const can = appointmentAbilities(appointment);
  const client = appointment.client || {};
  const name = fullName(client);
  const lower = (key) => String(store.statusLabel(key) || key).toLowerCase();

  const emit = (next, action) => onChange?.(next, { action, id: Number(appointment.id) });

  const setStatus = async (status, extra = {}, { key = 'status', success = '' } = {}) => {
    if (busy) return;
    setBusy(key);
    try {
      const next = await panelApi.setAppointmentStatus(appointment.id, status, extra);
      emit(next, 'status');
      store.refresh().catch(() => {});
      if (success) toast.success(success);
    } catch (e) {
      toast.error(e);
    } finally {
      setBusy('');
      setDialog(null);
    }
  };

  const ficha = () => {
    if (onOpenFicha) onOpenFicha(appointment);
    else openFicha(appointment);
  };

  const remove = async () => {
    if (busy) return;
    setBusy('delete');
    try {
      await panelApi.deleteAppointment(appointment.id);
      emit(null, 'delete');
      store.refresh().catch(() => {});
      toast.success('Turno eliminado.');
    } catch (e) {
      toast.error(e);
    } finally {
      setBusy('');
      setDialog(null);
    }
  };

  const markRefunded = async () => {
    if (busy) return;
    setBusy('refund');
    try {
      const next = await panelApi.markRefunded(appointment.id);
      emit(next, 'refund');
      store.refresh().catch(() => {});
      toast.success('Seña marcada como devuelta.');
    } catch (e) {
      toast.error(e);
    } finally {
      setBusy('');
      setDialog(null);
    }
  };

  const depositLabel = num(appointment.depositPaid) > 0 ? 'Editar seña' : s === 'confirmed' ? 'Registrar seña' : 'Seña recibida';
  const fichaLabel = appointment.visitId ? 'Ver ficha' : 'Abrir ficha';

  const items = [
    can.confirm && { key: 'confirm', label: 'Confirmar', icon: Check, tone: 'accent', onSelect: () => setDialog('confirm') },
    can.deposit && { key: 'deposit', label: depositLabel, icon: Coins, onSelect: () => setDialog('deposit') },
    can.ficha && { key: 'ficha', label: fichaLabel, icon: ClipboardList, onSelect: ficha },
    waItems.length > 0 && { key: 'wa', label: 'WhatsApp', icon: MessageCircle, onSelect: () => setWa(true) },
    can.reschedule && { key: 'reschedule', label: 'Reprogramar', icon: CalendarClock, onSelect: () => setDialog('reschedule') },
    can.complete && {
      key: 'complete',
      label: `Marcar como ${lower('completed')}`,
      icon: CheckCheck,
      onSelect: () => setStatus('completed', {}, { key: 'complete', success: `Turno marcado como ${lower('completed')}.` }),
    },
    can.noShow && { key: 'no_show', label: `Marcar como ${lower('no_show')}`, icon: UserX, onSelect: () => setDialog('no_show') },
    can.reactivate && {
      key: 'reactivate',
      label: `Volver a ${lower('confirmed')}`,
      icon: RotateCcw,
      onSelect: () => setStatus('confirmed', { notify: false }, { key: 'reactivate', success: `Turno ${lower('confirmed')} de nuevo.` }),
    },
    can.refund && { key: 'refund', label: 'Marcar seña devuelta', icon: HandCoins, onSelect: () => setDialog('refund') },
    { key: 'edit', label: 'Editar precio y notas', icon: Pencil, onSelect: () => setDialog('edit') },
    can.cancel && { key: 'cancel', label: 'Cancelar turno', icon: XCircle, tone: 'danger', divider: true, onSelect: () => setDialog('cancel') },
    { key: 'delete', label: 'Eliminar', icon: Trash2, tone: 'danger', divider: !can.cancel, onSelect: () => setDialog('delete') },
  ];

  // Botón rápido: lo más probable que quiera hacer con este turno.
  let quick = null;
  if (s === 'pending') quick = { label: 'Confirmar', icon: Check, onClick: () => setDialog('confirm') };
  else if (s === 'awaiting_deposit') quick = { label: 'Seña recibida', icon: Coins, onClick: () => setDialog('deposit') };
  else if (appointment.ownerOffer === 'refund_requested') quick = { label: 'Marcar devuelta', icon: HandCoins, onClick: () => setDialog('refund') };
  else if (can.ficha) quick = { label: appointment.visitId ? 'Ficha' : 'Abrir ficha', icon: ClipboardList, onClick: ficha, loading: openingId === Number(appointment.id) };

  const menuDescription = `${dateLabel(appointment.date)} · ${formatTime(appointment.time)}${appointment.serviceName ? ` · ${appointment.serviceName}` : ''}`;

  return (
    <div className={cn('mjto-appt-actions flex items-center gap-1.5', className)}>
      {!compact && quick && (
        <Button size="sm" variant="soft" icon={quick.icon} onClick={quick.onClick} loading={!!quick.loading}>
          {quick.label}
        </Button>
      )}
      {!compact && waItems.length > 0 && <WhatsAppMenu appointment={appointment} />}
      <Button ref={anchor} size="sm" variant="ghost" iconOnly icon={MoreHorizontal} aria-label="Más acciones" aria-haspopup="menu" aria-expanded={menu} onClick={() => setMenu((o) => !o)} loading={!!busy} />

      <PopMenu open={menu} onClose={() => setMenu(false)} anchorRef={anchor} items={items} title={name || 'Turno'} description={menuDescription} />
      <PopMenu open={wa} onClose={() => setWa(false)} anchorRef={anchor} items={waItems} title={name ? `WhatsApp a ${name}` : 'WhatsApp'} description={prettyPhone(client.phoneRaw || client.phone)} />

      <ConfirmDialog
        open={dialog === 'confirm'}
        onClose={() => setDialog(null)}
        onConfirm={() => setStatus('confirmed', { notify: !!notifyConfirm && !!client.email }, { key: 'confirm', success: 'Turno confirmado.' })}
        title="¿Confirmar el turno?"
        text={`${name} · ${menuDescription}. ${num(appointment.depositRequired) > 0 && num(appointment.depositPaid) <= 0 ? 'Si ya recibiste la seña, usá «Seña recibida» para dejarla registrada.' : ''}`}
        confirmLabel="Confirmar"
        cancelLabel="Volver"
        icon={Check}
        loading={busy === 'confirm'}
      >
        <Checkbox
          checked={notifyConfirm && !!client.email}
          onChange={setNotifyConfirm}
          label="Avisar a la clienta por email"
          description={client.email ? `Se manda a ${client.email}.` : 'La clienta no tiene email cargado.'}
          disabled={!client.email}
        />
      </ConfirmDialog>

      <ConfirmDialog
        open={dialog === 'no_show'}
        onClose={() => setDialog(null)}
        onConfirm={() => setStatus('no_show', {}, { key: 'no_show', success: `Turno marcado como ${lower('no_show')}.` })}
        title={`¿Marcar como ${lower('no_show')}?`}
        text={`${name} · ${menuDescription}. El horario queda liberado.`}
        confirmLabel={`Sí, ${lower('no_show')}`}
        cancelLabel="Volver"
        tone="danger"
        icon={UserX}
        loading={busy === 'no_show'}
      />

      <ConfirmDialog
        open={dialog === 'delete'}
        onClose={() => setDialog(null)}
        onConfirm={remove}
        title="¿Eliminar este turno?"
        text={`${name} · ${menuDescription}. Se borra del historial y no se puede recuperar. Si querés conservarlo, cancelalo en vez de eliminarlo.`}
        confirmLabel="Eliminar"
        cancelLabel="Volver"
        tone="danger"
        loading={busy === 'delete'}
      />

      <ConfirmDialog
        open={dialog === 'refund'}
        onClose={() => setDialog(null)}
        onConfirm={markRefunded}
        title="¿Ya le devolviste la seña?"
        text={`${name} · ${menuDescription}. ${num(appointment.depositPaid) > 0 ? `Seña de ${store.formatPrice(appointment.depositPaid)}. ` : ''}Queda registrado que la devolución está hecha.`}
        confirmLabel="Sí, está devuelta"
        cancelLabel="Volver"
        icon={HandCoins}
        loading={busy === 'refund'}
      />

      <DepositDialog appointment={appointment} open={dialog === 'deposit'} onClose={() => setDialog(null)} onChanged={(next) => emit(next, 'deposit')} />
      <CancelDialog appointment={appointment} open={dialog === 'cancel'} onClose={() => setDialog(null)} onChanged={(next) => emit(next, 'cancel')} />
      <Reprogramar appointment={appointment} open={dialog === 'reschedule'} onClose={() => setDialog(null)} onChanged={(next) => emit(next, 'reschedule')} />
      <EditDialog appointment={appointment} open={dialog === 'edit'} onClose={() => setDialog(null)} onChanged={(next) => emit(next, 'update')} />
    </div>
  );
}

export default AppointmentActions;
