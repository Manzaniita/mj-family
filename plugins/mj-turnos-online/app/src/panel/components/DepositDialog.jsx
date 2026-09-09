/**
 * "Seña recibida": monto + método de pago → PATCH /panel/appointments/{id}
 * { status: 'confirmed', deposit_paid, deposit_method, notify }.
 * Sirve también para registrar/corregir la seña de un turno ya confirmado.
 */
import { useEffect, useState } from 'react';
import { Coins } from 'lucide-react';
import { panelApi } from '@/lib/api';
import { dateLabel, fullName, timeRange } from '@/lib/format';
import { Button, Checkbox, Chip, ChipGroup, Field, Input, Modal, MoneyInput, useToast } from '@/ui';
import { usePanel } from '../store';

const num = (v) => (Number.isFinite(Number(v)) ? Number(v) : 0);

/**
 * @param {{ appointment: object, open: boolean, onClose: () => void, onChanged?: (appointment) => void }} props
 */
export function DepositDialog({ appointment, open, onClose, onChanged }) {
  const store = usePanel();
  const toast = useToast();
  const [amount, setAmount] = useState('');
  const [method, setMethod] = useState('');
  const [other, setOther] = useState(false);
  const [notify, setNotify] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  const methods = store.paymentMethods;

  useEffect(() => {
    if (!open || !appointment) return;
    const paid = num(appointment.depositPaid);
    const required = num(appointment.depositRequired);
    const suggested = required > 0 ? required : store.depositFor(store.serviceById(appointment.serviceId) || appointment.price);
    setAmount(paid > 0 ? paid : suggested > 0 ? suggested : '');
    const current = appointment.depositMethod || methods[0] || '';
    setMethod(current);
    setOther(!!current && !methods.includes(current));
    setNotify(appointment.status !== 'confirmed' && !!appointment.client?.email);
    setSaving(false);
    setError('');
  }, [open, appointment?.id]); // eslint-disable-line react-hooks/exhaustive-deps

  if (!appointment) return null;

  const required = num(appointment.depositRequired);
  const alreadyConfirmed = appointment.status === 'confirmed';

  const submit = async () => {
    if (saving) return;
    const value = num(amount);
    if (!(value > 0)) {
      setError('Cargá el monto que recibiste.');
      return;
    }
    setSaving(true);
    setError('');
    try {
      const next = await panelApi.depositReceived(appointment.id, value, String(method || '').trim(), { notify: !!notify });
      toast.success(alreadyConfirmed ? 'Seña registrada.' : 'Seña registrada: el turno quedó confirmado.');
      store.refresh().catch(() => {});
      onChanged?.(next, { action: 'deposit', id: appointment.id });
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
      title={alreadyConfirmed ? 'Registrar seña' : 'Seña recibida'}
      description={`${fullName(appointment.client)} · ${dateLabel(appointment.date)}, ${timeRange(appointment.time, appointment.endTime)}`}
      dismissible={!saving}
      footer={
        <>
          <Button variant="ghost" onClick={onClose} disabled={saving}>
            Volver
          </Button>
          <Button icon={Coins} onClick={submit} loading={saving}>
            {alreadyConfirmed ? 'Guardar seña' : 'Confirmar turno'}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        <MoneyInput
          label="Monto recibido"
          required
          value={amount}
          onChange={(v) => {
            setAmount(v);
            if (error) setError('');
          }}
          error={error}
          help={required > 0 ? `Seña pedida: ${store.formatPrice(required)}` : `Precio del turno: ${store.formatPrice(appointment.price)}`}
          min={0}
          autoFocus
        />
        <Field label="Método de pago">
          {methods.length > 0 ? (
            <ChipGroup>
              {methods.map((m) => (
                <Chip
                  key={m}
                  active={!other && method === m}
                  onClick={() => {
                    setOther(false);
                    setMethod(m);
                  }}
                >
                  {m}
                </Chip>
              ))}
              <Chip
                active={other}
                onClick={() => {
                  setOther(true);
                  setMethod(methods.includes(method) ? '' : method);
                }}
              >
                Otro
              </Chip>
            </ChipGroup>
          ) : null}
          {(other || !methods.length) && (
            <Input className="mt-2" value={method} onChange={(e) => setMethod(e.target.value)} placeholder="Ej: Mercado Pago" autoComplete="off" />
          )}
        </Field>
        {!alreadyConfirmed && (
          <Checkbox
            checked={notify}
            onChange={setNotify}
            label="Avisar por email que quedó confirmado"
            description={appointment.client?.email ? `Se manda a ${appointment.client.email}.` : 'La clienta no tiene email cargado: avisale por WhatsApp.'}
            disabled={!appointment.client?.email}
          />
        )}
      </div>
    </Modal>
  );
}

export default DepositDialog;
