import { AlertTriangle } from 'lucide-react';
import { Modal } from './Modal';
import { Button } from './Button';

/**
 * Confirmación corta ("¿Eliminar este turno?").
 * @param {{ open, onClose, onConfirm: () => void|Promise, title, text?, children?, confirmLabel?, cancelLabel?,
 *   tone?: 'danger'|'primary', loading?: boolean, icon?: Component }} props
 */
export function ConfirmDialog({
  open,
  onClose,
  onConfirm,
  title,
  text,
  children,
  confirmLabel = 'Confirmar',
  cancelLabel = 'Cancelar',
  tone = 'primary',
  loading = false,
  icon: Icon = tone === 'danger' ? AlertTriangle : null,
}) {
  return (
    <Modal
      open={open}
      onClose={onClose}
      size="sm"
      dismissible={!loading}
      label={title}
      footer={
        <>
          <Button variant="ghost" onClick={onClose} disabled={loading}>
            {cancelLabel}
          </Button>
          <Button variant={tone === 'danger' ? 'dangerSolid' : 'primary'} onClick={onConfirm} loading={loading} autoFocus>
            {confirmLabel}
          </Button>
        </>
      }
    >
      <div className="flex gap-4 pt-5">
        {Icon && (
          <span
            className={`grid size-11 shrink-0 place-items-center rounded-full ${tone === 'danger' ? 'bg-danger-soft text-danger' : 'bg-accent-soft text-accent'}`}
            aria-hidden="true"
          >
            <Icon size={22} />
          </span>
        )}
        <div className="min-w-0 flex-1">
          <h2 className="font-display text-lg font-semibold leading-tight tracking-tight text-ink">{title}</h2>
          {text && <p className="mt-1.5 text-sm leading-relaxed text-ink-soft">{text}</p>}
          {children && <div className="mt-3">{children}</div>}
        </div>
      </div>
    </Modal>
  );
}
