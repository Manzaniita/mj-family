/**
 * Modal para ver y aprobar/rechazar el comprobante de la seña desde el panel de Turnos,
 * sin necesidad de navegar a wp-admin > MJ > Comprobantes.
 */
import { useState } from 'react';
import { Check, Download, ExternalLink, FileText, Receipt, X, XCircle } from 'lucide-react';
import { panelApi } from '@/lib/api';
import { dateLabel, formatPrice, formatTime, fullName } from '@/lib/format';
import { Badge, Button, Modal, Textarea, useToast } from '@/ui';
import { usePanel } from '../store';

const num = (v) => (Number.isFinite(Number(v)) ? Number(v) : 0);

/**
 * @param {{ appointment: object, receipt?: object, open: boolean, onClose: () => void, onChanged?: (appointment, meta) => void }} props
 */
export function ReceiptDialog({ appointment, receipt: propReceipt, open, onClose, onChanged }) {
  const store = usePanel();
  const toast = useToast();
  const [rejecting, setRejecting] = useState(false);
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [fullImage, setFullImage] = useState(null);

  if (!appointment) return null;

  const receipt = propReceipt || appointment.receipt || null;
  const files = receipt?.files || [];
  const name = fullName(appointment.client?.firstName, appointment.client?.lastName);
  const state = receipt?.state || 'pendiente';
  const isApproved = state === 'aprobado';
  const isRejected = state === 'rechazado';

  const handleDecision = async (decision) => {
    setBusy(true);
    setError('');
    try {
      const payload = { decision };
      if (decision === 'rechazado' && reason.trim()) {
        payload.reason = reason.trim();
      }
      const updated = await panelApi.reviewReceipt(appointment.id, payload);
      toast.success(
        decision === 'aprobado'
          ? 'Comprobante aprobado y turno confirmado.'
          : 'Comprobante marcado como rechazado.'
      );
      setRejecting(false);
      setReason('');
      onChanged?.(updated, { action: 'deposit', id: appointment.id });
      onClose();
    } catch (e) {
      setError(e?.message || 'Error al actualizar el comprobante.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={receipt?.nounTitle || 'Comprobante de la seña'}
      description={`${name} · ${dateLabel(appointment.date)} · ${formatTime(appointment.time)}`}
      icon={Receipt}
      size="md"
    >
      <div className="space-y-4">
        {/* Resumen del estado actual del comprobante */}
        <div className="flex flex-wrap items-center justify-between gap-3 rounded-tile bg-surface-2 p-3.5 text-sm">
          <div>
            <p className="text-xs font-medium text-ink-soft">Estado del comprobante</p>
            <div className="mt-1 flex items-center gap-2">
              <Badge
                tone={isApproved ? 'success' : isRejected ? 'danger' : 'warning'}
                icon={Receipt}
                size="md"
              >
                {receipt?.stateLabel || (isApproved ? 'Aprobado' : isRejected ? 'Rechazado' : 'A revisar')}
              </Badge>
            </div>
          </div>
          <div className="text-right">
            <p className="text-xs font-medium text-ink-soft">Seña requerida</p>
            <p className="mt-0.5 font-display text-base font-bold text-ink mjto-tabular">
              {store.formatPrice(num(appointment.depositRequired))}
            </p>
          </div>
        </div>

        {/* Archivos subidos */}
        {files.length > 0 ? (
          <div className="space-y-3">
            <p className="text-xs font-bold uppercase tracking-wider text-ink-faint">
              Archivos adjuntos ({files.length})
            </p>
            {files.map((file) => {
              const isImg = file.url && /\.(jpe?g|png|webp|gif|bmp)(\?.*)?$/i.test(file.url);
              return (
                <div
                  key={file.id || file.url}
                  className="overflow-hidden rounded-tile border border-line bg-surface p-3 transition-colors"
                >
                  <div className="flex items-start gap-3">
                    {isImg ? (
                      <button
                        type="button"
                        onClick={() => setFullImage(file.url)}
                        className="group relative size-20 shrink-0 overflow-hidden rounded-lg border border-line bg-surface-2 focus:outline-hidden"
                        title="Ver imagen completa"
                      >
                        <img
                          src={file.url}
                          alt="Comprobante"
                          className="size-full object-cover transition-transform group-hover:scale-105"
                          loading="lazy"
                        />
                        <span className="absolute inset-0 grid place-items-center bg-black/25 opacity-0 transition-opacity group-hover:opacity-100">
                          <ExternalLink size={16} className="text-white" />
                        </span>
                      </button>
                    ) : (
                      <div className="grid size-16 shrink-0 place-items-center rounded-lg bg-surface-2 text-ink-soft">
                        <FileText size={24} />
                      </div>
                    )}
                    <div className="min-w-0 flex-1 text-sm">
                      <p className="font-semibold text-ink truncate">{file.filename || 'Comprobante'}</p>
                      {file.date && (
                        <p className="text-xs text-ink-soft">Subido: {dateLabel(file.date)}</p>
                      )}
                      {file.amountText && (
                        <p className="mt-1 text-xs text-ink">
                          <span className="text-ink-soft">Monto pagado:</span>{' '}
                          <span className="font-semibold font-mono">{file.amountText}</span>
                        </p>
                      )}
                      {file.reference && (
                        <p className="text-xs text-ink">
                          <span className="text-ink-soft">N° de operación:</span>{' '}
                          <span className="font-semibold font-mono">{file.reference}</span>
                        </p>
                      )}
                      {file.url && (
                        <div className="mt-2 flex items-center gap-2">
                          <a
                            href={file.url}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="inline-flex items-center gap-1 text-xs font-semibold text-accent hover:underline"
                          >
                            <ExternalLink size={12} />
                            Ver original
                          </a>
                        </div>
                      )}
                    </div>
                  </div>
                </div>
              );
            })}
          </div>
        ) : (
          <div className="rounded-tile border border-dashed border-line p-6 text-center text-sm text-ink-soft">
            La clienta todavía no subió ningún archivo de comprobante.
          </div>
        )}

        {/* Modal interno para ver imagen completa en grande */}
        {fullImage && (
          <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-black/85 p-4"
            onClick={() => setFullImage(null)}
          >
            <button
              type="button"
              className="absolute right-4 top-4 rounded-full bg-white/20 p-2 text-white hover:bg-white/30"
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

        {/* Formulario de motivo de rechazo */}
        {rejecting && (
          <div className="rounded-tile border border-danger/30 bg-danger-soft p-3.5 text-sm space-y-2">
            <p className="font-semibold text-danger">Motivo del rechazo (opcional):</p>
            <Textarea
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              placeholder="Ej: Foto ilegible, monto no coincide, etc."
              rows={2}
              disabled={busy}
            />
            <div className="flex justify-end gap-2 pt-1">
              <Button size="xs" variant="ghost" onClick={() => setRejecting(false)} disabled={busy}>
                Cancelar
              </Button>
              <Button
                size="xs"
                variant="danger"
                icon={XCircle}
                loading={busy}
                onClick={() => handleDecision('rechazado')}
              >
                Confirmar rechazo
              </Button>
            </div>
          </div>
        )}

        {error && <p className="text-sm font-semibold text-danger">{error}</p>}

        {/* Acciones principales */}
        <div className="flex flex-wrap items-center justify-between gap-2 border-t border-line/60 pt-4">
          <div className="flex flex-wrap gap-2">
            {!isApproved && (
              <Button
                variant="primary"
                icon={Check}
                loading={busy && !rejecting}
                onClick={() => handleDecision('aprobado')}
              >
                Aprobar comprobante
              </Button>
            )}
            {!rejecting && (
              <Button
                variant="outline"
                tone="danger"
                icon={XCircle}
                disabled={busy}
                onClick={() => setRejecting(true)}
              >
                {isRejected ? 'Editar motivo de rechazo' : 'Rechazar'}
              </Button>
            )}
          </div>
          <Button variant="ghost" onClick={onClose} disabled={busy}>
            Cerrar
          </Button>
        </div>
      </div>
    </Modal>
  );
}

export default ReceiptDialog;
