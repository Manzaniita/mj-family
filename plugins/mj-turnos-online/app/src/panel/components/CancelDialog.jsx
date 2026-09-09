/**
 * Cancelar un turno desde el panel: motivo (opcional) + aviso por email →
 * PATCH /panel/appointments/{id} { status: 'cancelled', cancel_reason, notify }.
 */
import { useEffect, useState } from 'react';
import { XCircle } from 'lucide-react';
import { panelApi } from '@/lib/api';
import { dateLabel, fullName, timeRange } from '@/lib/format';
import { Button, Checkbox, Modal, Textarea, useToast } from '@/ui';
import { usePanel } from '../store';

const num = (v) => (Number.isFinite(Number(v)) ? Number(v) : 0);

/**
 * @param {{ appointment: object, open: boolean, onClose: () => void, onChanged?: (appointment) => void }} props
 */
export function CancelDialog({ appointment, open, onClose, onChanged }) {
  const store = usePanel();
  const toast = useToast();
  const [reason, setReason] = useState('');
  const [notify, setNotify] = useState(true);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (!open || !appointment) return;
    setReason('');
    setNotify(!!appointment.client?.email);
    setSaving(false);
  }, [open, appointment?.id]); // eslint-disable-line react-hooks/exhaustive-deps

  if (!appointment) return null;

  const paid = num(appointment.depositPaid);

  const submit = async () => {
    if (saving) return;
    setSaving(true);
    try {
      const next = await panelApi.setAppointmentStatus(appointment.id, 'cancelled', {
        cancel_reason: reason.trim(),
        notify: !!notify,
      });
      toast.success('Turno cancelado.');
      store.refresh().catch(() => {});
      onChanged?.(next, { action: 'cancel', id: appointment.id });
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
      title="Cancelar el turno"
      description={`${fullName(appointment.client)} · ${dateLabel(appointment.date)}, ${timeRange(appointment.time, appointment.endTime)}${appointment.serviceName ? ` · ${appointment.serviceName}` : ''}`}
      dismissible={!saving}
      footer={
        <>
          <Button variant="ghost" onClick={onClose} disabled={saving}>
            Volver
          </Button>
          <Button variant="dangerSolid" icon={XCircle} onClick={submit} loading={saving}>
            Cancelar turno
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        {paid > 0 && (
          <p className="rounded-tile bg-warning-soft px-3.5 py-2.5 text-sm text-warning">
            Esta clienta ya dejó una seña de {store.formatPrice(paid)}
            {appointment.depositMethod ? ` (${appointment.depositMethod})` : ''}. Acordá con ella qué hacer con ese dinero.
          </p>
        )}
        <Textarea
          label="Motivo"
          help="Queda guardado en el turno y, si avisás por email, se lo contamos a la clienta."
          value={reason}
          onChange={(e) => setReason(e.target.value)}
          rows={3}
          placeholder="Ej: reprogramamos por WhatsApp"
          autoFocus
        />
        <Checkbox
          checked={notify}
          onChange={setNotify}
          label="Avisar a la clienta por email"
          description={appointment.client?.email ? `Se manda a ${appointment.client.email}.` : 'La clienta no tiene email cargado: avisale por WhatsApp.'}
          disabled={!appointment.client?.email}
        />
      </div>
    </Modal>
  );
}

export default CancelDialog;
