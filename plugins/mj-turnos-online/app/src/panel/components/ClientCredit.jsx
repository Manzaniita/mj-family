/**
 * Saldo a favor de una clienta (Ficha, 1.3): saldo, movimientos y un
 * formulario para sumar o descontar con motivo.
 *
 *   <ClientCredit clientId={id} credit={{ balance, movements }} onChange={(credit) => …} />
 *
 * POST /panel/clients/{id}/credit { amount (+ suma / - descuenta), reason } →
 * { balance, movements }. El servidor nunca deja el saldo por debajo de cero:
 * un descuento mayor al saldo se recorta a lo que hay.
 */
import { useEffect, useState } from 'react';
import { ChevronDown, Minus, PiggyBank, Plus } from 'lucide-react';
import { panelApi } from '@/lib/api';
import { cn, pluralize } from '@/lib/format';
import { Button, Chip, ChipGroup, Input, Modal, MoneyInput, SectionTitle, useToast } from '@/ui';
import { usePanel } from '../store';

const num = (v) => (Number.isFinite(Number(v)) ? Number(v) : 0);

/** Tipo de movimiento → texto del panel (mjto_credit_kinds()). */
const KIND_LABELS = {
  deposit: 'Seña que pasó a saldo',
  applied: 'Usado en una seña',
  returned: 'Vuelve: la reserva no siguió',
  manual: 'Ajuste a mano',
};

const VISIBLE = 5;

/** Normaliza { balance, movements } del servidor. */
export function normalizeCredit(raw) {
  const c = raw && typeof raw === 'object' ? raw : {};
  return { balance: num(c.balance), movements: Array.isArray(c.movements) ? c.movements : [] };
}

/** Formulario (modal) para sumar o descontar saldo. */
function CreditDialog({ open, onClose, clientId, balance, onSaved }) {
  const store = usePanel();
  const toast = useToast();
  const [mode, setMode] = useState('add');
  const [amount, setAmount] = useState('');
  const [reason, setReason] = useState('');
  const [error, setError] = useState('');
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (!open) return;
    setMode('add');
    setAmount('');
    setReason('');
    setError('');
    setSaving(false);
  }, [open]);

  const submit = async (e) => {
    e?.preventDefault?.();
    if (saving) return;
    const value = num(amount);
    if (!(value > 0)) {
      setError('Escribí un monto mayor a cero.');
      return;
    }
    if (mode === 'sub' && value > balance) {
      setError(`No puede superar el saldo (${store.formatPrice(balance)}).`);
      return;
    }
    setSaving(true);
    try {
      const res = await panelApi.addClientCredit(clientId, mode === 'sub' ? -value : value, reason.trim());
      toast.success(mode === 'sub' ? 'Saldo descontado.' : 'Saldo sumado.');
      onSaved?.(normalizeCredit(res));
      onClose?.();
    } catch (err) {
      if (err?.data && typeof err.data === 'object' && err.data.field === 'amount') setError(err.message);
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
      title="Mover saldo a favor"
      description={`Saldo actual: ${store.formatPrice(balance)}`}
      dismissible={!saving}
      footer={
        <>
          <Button variant="ghost" onClick={onClose} disabled={saving}>
            Volver
          </Button>
          <Button icon={mode === 'sub' ? Minus : Plus} onClick={submit} loading={saving}>
            {mode === 'sub' ? 'Descontar' : 'Sumar'}
          </Button>
        </>
      }
    >
      <form onSubmit={submit} noValidate className="flex flex-col gap-4 pt-1">
        <ChipGroup role="group" aria-label="Qué hacer con el saldo">
          <Chip active={mode === 'add'} onClick={() => setMode('add')} icon={Plus}>
            Sumar
          </Chip>
          <Chip active={mode === 'sub'} onClick={() => setMode('sub')} icon={Minus} disabled={balance <= 0}>
            Descontar
          </Chip>
        </ChipGroup>
        <MoneyInput
          label="Monto"
          required
          value={amount}
          onChange={(v) => {
            setAmount(v);
            setError('');
          }}
          min={0}
          currency={store.currency}
          error={error}
          autoFocus
        />
        <Input
          label="Motivo"
          help="Queda en los movimientos. Sólo lo ves vos."
          value={reason}
          maxLength={255}
          onChange={(e) => setReason(e.target.value)}
          placeholder={mode === 'sub' ? 'Ej: se lo devolví en efectivo' : 'Ej: compensación por la demora'}
          autoComplete="off"
        />
      </form>
    </Modal>
  );
}

/**
 * @param {{ clientId: number, credit: { balance, movements }, onChange?: (credit) => void, className? }} props
 */
export function ClientCredit({ clientId, credit, onChange, className }) {
  const store = usePanel();
  const [open, setOpen] = useState(false);
  const [showAll, setShowAll] = useState(false);
  const { balance, movements } = normalizeCredit(credit);
  const list = showAll ? movements : movements.slice(0, VISIBLE);

  return (
    <section className={cn('mjto-card mjto-client-credit p-4 sm:p-5', className)} aria-label="Saldo a favor">
      <SectionTitle
        title="Saldo a favor"
        action={
          <Button size="xs" variant="soft" icon={Plus} onClick={() => setOpen(true)}>
            Mover saldo
          </Button>
        }
      />
      <div className="flex items-center gap-3">
        <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-accent-soft text-accent" aria-hidden="true">
          <PiggyBank size={19} />
        </span>
        <div className="min-w-0">
          <p className={cn('font-display text-2xl font-bold leading-none tracking-tight mjto-tabular', balance > 0 ? 'text-ink' : 'text-ink-faint')}>{store.formatPrice(balance)}</p>
          <p className="mt-1 text-xs text-ink-faint">
            {balance > 0 ? 'Se descuenta sola de la seña de su próxima reserva.' : 'Sin saldo por ahora.'}
          </p>
        </div>
      </div>

      {movements.length > 0 && (
        <>
          <ul className="mt-4 divide-y divide-line border-t border-line">
            {list.map((m) => {
              const amount = num(m.amount);
              return (
                <li key={m.id} className="flex items-start gap-3 py-2.5">
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-sm text-ink">{m.reason || KIND_LABELS[m.kind] || 'Movimiento'}</p>
                    <p className="truncate text-xs text-ink-faint">
                      {m.dateLabel}
                      {m.reason && KIND_LABELS[m.kind] ? ` · ${KIND_LABELS[m.kind]}` : ''}
                    </p>
                  </div>
                  <span className={cn('shrink-0 text-sm font-semibold mjto-tabular', amount >= 0 ? 'text-success' : 'text-danger')}>
                    {amount >= 0 ? '+' : '−'}
                    {store.formatPrice(Math.abs(amount))}
                  </span>
                </li>
              );
            })}
          </ul>
          {movements.length > VISIBLE && (
            <button
              type="button"
              onClick={() => setShowAll((v) => !v)}
              aria-expanded={showAll}
              className="mt-1 inline-flex items-center gap-1.5 text-[13px] font-semibold text-ink-faint hover:text-ink"
            >
              <ChevronDown size={16} className={cn('transition-transform', showAll && 'rotate-180')} aria-hidden="true" />
              {showAll ? 'Ver menos' : `Ver los ${pluralize(movements.length, 'movimiento')}`}
            </button>
          )}
        </>
      )}

      <CreditDialog open={open} onClose={() => setOpen(false)} clientId={clientId} balance={balance} onSaved={onChange} />
    </section>
  );
}

export default ClientCredit;
