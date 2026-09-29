/**
 * "Seña recibida": visualización del comprobante adjunto (si se subió),
 * ajuste del monto recibido + método de pago → PATCH /panel/appointments/{id}
 * { status: 'confirmed', deposit_paid, deposit_method, notify } y aprobación
 * del comprobante en MJ Medios de Pago.
 * Sirve también para registrar/corregir la seña de un turno ya confirmado.
 */
import { useEffect, useState } from 'react';
import { Coins, ExternalLink, FileText, Receipt, X, XCircle } from 'lucide-react';
import { panelApi } from '@/lib/api';
import { dateLabel, fullName, timeRange } from '@/lib/format';
import { Badge, Button, Checkbox, Chip, ChipGroup, Field, Input, Modal, MoneyInput, Textarea, useToast } from '@/ui';
import { usePanel } from '../store';

const num = (v) => (Number.isFinite(Number(v)) ? Number(v) : 0);

const parseMoney = (text) => {
  if (!text) return 0;
  const cleaned = String(text).replace(/[^\d.,]/g, '').trim();
  if (cleaned.includes('.') && cleaned.includes(',')) {
    return parseFloat(cleaned.replace(/\./g, '').replace(',', '.')) || 0;
  }
  if (cleaned.includes(',')) {
    return parseFloat(cleaned.replace(',', '.')) || 0;
  }
  if (/^\d{1,3}(\.\d{3})+$/.test(cleaned)) {
    return parseFloat(cleaned.replace(/\./g, '')) || 0;
  }
  return parseFloat(cleaned) || 0;
};

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
  const [fullImage, setFullImage] = useState(null);
  const [rejecting, setRejecting] = useState(false);
  const [rejectReason, setRejectReason] = useState('');

  const methods = store.paymentMethods;

  const receipt = appointment?.receipt || null;
  const receiptFiles = receipt?.files || [];
  const hasReceipt = receiptFiles.length > 0;
  const receiptState = receipt?.state || '';
  const isReceiptPending = receiptState === 'pendiente';
  const isReceiptApproved = receiptState === 'aprobado';
  const isReceiptRejected = receiptState === 'rechazado';

  useEffect(() => {
    if (!open || !appointment) return;
    const credit = num(appointment.creditApplied);
    const paid = num(appointment.depositPaid) - credit;
    const required = num(appointment.depositRequired) - credit;
    const suggested = required > 0 ? required : credit > 0 ? 0 : store.depositFor(store.serviceById(appointment.serviceId) || appointment.price);
    const parsedReceipt = parseMoney(receiptFiles[0]?.amountText);

    // Con saldo a favor aplicado se carga solo lo transferido: el saldo se suma al guardar.
    const initialAmount = paid > 0 ? paid : suggested > 0 ? suggested : parsedReceipt > 0 ? parsedReceipt : '';
    setAmount(initialAmount);

    const current = appointment.depositMethod || methods[0] || '';
    setMethod(current);
    setOther(!!current && !methods.includes(current));
    setNotify(appointment.status !== 'confirmed' && !!appointment.client?.email);
    setSaving(false);
    setError('');
    setRejecting(false);
    setRejectReason('');
    setFullImage(null);
  }, [open, appointment?.id]); // eslint-disable-line react-hooks/exhaustive-deps

  if (!appointment) return null;

  const credit = num(appointment.creditApplied);
  const required = num(appointment.depositRequired);
  const alreadyConfirmed = appointment.status === 'confirmed';

  const handleRejectReceipt = async () => {
    if (saving) return;
    setSaving(true);
    try {
      const payload = { decision: 'rechazado' };
      if (rejectReason.trim()) payload.reason = rejectReason.trim();
      const updated = await panelApi.reviewReceipt(appointment.id, payload);
      toast.success('Comprobante marcado como rechazado.');
      store.refresh().catch(() => {});
      onChanged?.(updated, { action: 'deposit', id: appointment.id });
      onClose?.();
    } catch (e) {
      toast.error(e?.message || 'Error al rechazar el comprobante.');
    } finally {
      setSaving(false);
      setRejecting(false);
    }
  };

  const submit = async () => {
    if (saving) return;
    const value = num(amount) + credit;
    if (!(num(amount) > 0) && !(credit > 0)) {
      setError('Cargá el monto que recibiste.');
      return;
    }
    setSaving(true);
    setError('');
    try {
      if (hasReceipt && isReceiptPending) {
        try {
          await panelApi.reviewReceipt(appointment.id, { decision: 'aprobado', amount: value });
        } catch (_) {
          // No bloqueante si el backend ya lo aprueba en cascada
        }
      }
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
      size={hasReceipt ? 'md' : 'sm'}
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
        {/* Comprobante adjunto (si la clienta lo subió) */}
        {hasReceipt && (
          <div className="rounded-tile border border-line bg-surface-2 p-3 space-y-2.5">
            <div className="flex items-center justify-between gap-2">
              <div className="flex items-center gap-2">
                <Receipt size={16} className="text-accent" />
                <span className="text-xs font-bold uppercase tracking-wider text-ink">
                  Comprobante adjunto
                </span>
              </div>
              {receiptState && (
                <Badge
                  tone={isReceiptApproved ? 'success' : isReceiptRejected ? 'danger' : 'warning'}
                  size="sm"
                >
                  {receipt.stateLabel || (isReceiptApproved ? 'Aprobado' : isReceiptRejected ? 'Rechazado' : 'A revisar')}
                </Badge>
              )}
            </div>

            {receiptFiles.map((file) => {
              const isImg = file.isImage || (file.url && /\.(jpe?g|png|webp|gif|bmp)(\?.*)?$/i.test(file.url));
              const fileAmount = parseMoney(file.amountText);
              return (
                <div
                  key={file.id || file.url}
                  className="flex items-start gap-3 rounded-lg border border-line bg-surface p-2.5"
                >
                  {isImg ? (
                    <button
                      type="button"
                      onClick={() => setFullImage(file.url)}
                      className="group relative size-20 shrink-0 overflow-hidden rounded-md border border-line bg-surface-2 focus:outline-hidden cursor-pointer"
                      title="Tocar para ver en tamaño completo"
                    >
                      <img
                        src={file.url}
                        alt="Comprobante"
                        className="size-full object-cover transition-transform group-hover:scale-105"
                        loading="lazy"
                      />
                      <span className="absolute inset-0 grid place-items-center bg-black/30 opacity-0 transition-opacity group-hover:opacity-100">
                        <ExternalLink size={14} className="text-white" />
                      </span>
                    </button>
                  ) : (
                    <div className="grid size-16 shrink-0 place-items-center rounded-md bg-surface-2 text-ink-soft">
                      <FileText size={22} />
                    </div>
                  )}

                  <div className="min-w-0 flex-1 text-xs space-y-1">
                    <p className="font-semibold text-ink truncate">{file.name || 'Comprobante'}</p>
                    {file.date && <p className="text-ink-soft">Subido: {dateLabel(file.date)}</p>}
                    {file.amountText && (
                      <div className="flex flex-wrap items-center gap-1.5 pt-0.5">
                        <span className="text-ink-soft">Monto informado:</span>
                        <span className="font-semibold font-mono text-ink">{file.amountText}</span>
                        {fileAmount > 0 && num(amount) !== fileAmount && (
                          <button
                            type="button"
                            onClick={() => {
                              setAmount(fileAmount);
                              if (error) setError('');
                            }}
                            className="font-medium text-accent underline hover:text-accent-soft ml-1 cursor-pointer"
                          >
                            Usar este monto
                          </button>
                        )}
                      </div>
                    )}
                    {file.reference && (
                      <p className="text-ink">
                        <span className="text-ink-soft">N° operación:</span>{' '}
                        <span className="font-semibold font-mono">{file.reference}</span>
                      </p>
                    )}
                    {file.url && (
                      <div className="pt-0.5">
                        <a
                          href={file.url}
                          target="_blank"
                          rel="noopener noreferrer"
                          className="inline-flex items-center gap-1 text-[11px] font-semibold text-accent hover:underline"
                        >
                          <ExternalLink size={11} />
                          Abrir original en pestaña nueva
                        </a>
                      </div>
                    )}
                  </div>
                </div>
              );
            })}

            {!alreadyConfirmed && isReceiptPending && !rejecting && (
              <div className="pt-1 text-right">
                <button
                  type="button"
                  onClick={() => setRejecting(true)}
                  className="text-xs font-medium text-danger hover:underline inline-flex items-center gap-1 cursor-pointer"
                >
                  <XCircle size={13} />
                  Rechazar comprobante
                </button>
              </div>
            )}

            {rejecting && (
              <div className="rounded-lg border border-danger/30 bg-danger-soft p-3 text-xs space-y-2">
                <p className="font-semibold text-danger">Motivo del rechazo (opcional):</p>
                <Textarea
                  value={rejectReason}
                  onChange={(e) => setRejectReason(e.target.value)}
                  placeholder="Ej: Foto ilegible, monto no coincide, etc."
                  rows={2}
                  disabled={saving}
                />
                <div className="flex justify-end gap-2 pt-1">
                  <Button size="xs" variant="ghost" onClick={() => setRejecting(false)} disabled={saving}>
                    Cancelar
                  </Button>
                  <Button
                    size="xs"
                    variant="danger"
                    icon={XCircle}
                    loading={saving}
                    onClick={handleRejectReceipt}
                  >
                    Confirmar rechazo
                  </Button>
                </div>
              </div>
            )}
          </div>
        )}

        <MoneyInput
          label={credit > 0 ? 'Transferencia recibida' : 'Monto recibido'}
          required
          value={amount}
          onChange={(v) => {
            setAmount(v);
            if (error) setError('');
          }}
          error={error}
          help={
            credit > 0
              ? `Seña pedida: ${store.formatPrice(required)}. Ya cubierto con saldo a favor: ${store.formatPrice(credit)} (se suma solo).`
              : required > 0
                ? `Seña pedida: ${store.formatPrice(required)}`
                : `Precio del turno: ${store.formatPrice(appointment.price)}`
          }
          min={0}
          autoFocus={!hasReceipt}
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

      {/* Lightbox imagen completa */}
      {fullImage && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/85 p-4"
          onClick={() => setFullImage(null)}
        >
          <button
            type="button"
            className="absolute right-4 top-4 rounded-full bg-white/20 p-2 text-white hover:bg-white/30 cursor-pointer"
            onClick={() => setFullImage(null)}
            aria-label="Cerrar"
          >
            <X size={20} />
          </button>
          <img
            src={fullImage}
            alt="Comprobante en tamaño completo"
            className="max-h-[90vh] max-w-[90vw] rounded-lg object-contain shadow-2xl"
            onClick={(e) => e.stopPropagation()}
          />
        </div>
      )}
    </Modal>
  );
}

export default DepositDialog;
