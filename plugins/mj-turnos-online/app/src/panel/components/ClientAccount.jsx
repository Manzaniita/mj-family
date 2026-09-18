/**
 * Cuenta de la web vinculada a una clienta (Ficha, 1.3): muestra el email de
 * la cuenta y permite vincular otra por email o desvincular.
 *
 *   <ClientAccount client={client} account={account|null} onChanged={() => recargar()} />
 *
 * PATCH /panel/clients/{id} { account_email } ('' desvincula). Errores:
 * 404 mjto_account_not_found, 409 mjto_account_taken (van al campo).
 * La respuesta es la clienta sin `account`: quien lo usa recarga la ficha.
 */
import { useEffect, useState } from 'react';
import { Link2, Mail, Unlink, UserCheck, UserRound } from 'lucide-react';
import { panelApi } from '@/lib/api';
import { cn } from '@/lib/format';
import { Button, ConfirmDialog, Input, Modal, SectionTitle, useToast } from '@/ui';

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

/**
 * @param {{ client: object, account: { id, email, name }|null, onChanged?: (client) => void, className? }} props
 */
export function ClientAccount({ client, account, onChanged, className }) {
  const toast = useToast();
  const [open, setOpen] = useState(false);
  const [unlinkOpen, setUnlinkOpen] = useState(false);
  const [email, setEmail] = useState('');
  const [error, setError] = useState('');
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (!open) return;
    setEmail(account?.email || client?.email || '');
    setError('');
    setSaving(false);
  }, [open]); // eslint-disable-line react-hooks/exhaustive-deps

  if (!client) return null;

  const save = async (value) => {
    if (saving) return;
    setSaving(true);
    try {
      const next = await panelApi.updateClient(client.id, { account_email: value });
      toast.success(value ? 'Cuenta vinculada.' : 'Cuenta desvinculada.');
      setOpen(false);
      setUnlinkOpen(false);
      onChanged?.(next);
    } catch (err) {
      if (err?.data && typeof err.data === 'object' && err.data.field === 'account_email') setError(err.message);
      else if (err?.code === 'mjto_account_taken') setError(err.message);
      toast.error(err);
    } finally {
      setSaving(false);
    }
  };

  const submit = (e) => {
    e?.preventDefault?.();
    const value = email.trim();
    if (!EMAIL_RE.test(value)) {
      setError('Escribí el email de la cuenta.');
      return;
    }
    save(value);
  };

  // Vinculada por userId pero la cuenta ya no existe en WordPress.
  const orphan = !account && !!client.userId;

  return (
    <section className={cn('mjto-card mjto-client-account p-4 sm:p-5', className)} aria-label="Cuenta en la web">
      <SectionTitle
        title="Cuenta en la web"
        action={
          <Button size="xs" variant="soft" icon={Link2} onClick={() => setOpen(true)}>
            {account ? 'Cambiar' : 'Vincular'}
          </Button>
        }
      />
      <div className="flex items-center gap-3">
        <span className={cn('grid size-10 shrink-0 place-items-center rounded-xl', account ? 'bg-success-soft text-success' : 'bg-surface-2 text-ink-faint')} aria-hidden="true">
          {account ? <UserCheck size={19} /> : <UserRound size={19} />}
        </span>
        <div className="min-w-0 flex-1">
          {account ? (
            <>
              <p className="flex min-w-0 items-center gap-1.5 text-sm font-medium text-ink">
                <Mail size={14} className="shrink-0 text-ink-faint" aria-hidden="true" />
                <span className="truncate">{account.email}</span>
              </p>
              <p className="mt-0.5 truncate text-xs text-ink-faint">Ve sus turnos y su saldo en «Mis turnos».{account.name ? ` Usuario: ${account.name}.` : ''}</p>
            </>
          ) : (
            <>
              <p className="text-sm font-medium text-ink">{orphan ? 'La cuenta vinculada ya no existe' : 'Sin cuenta vinculada'}</p>
              <p className="mt-0.5 text-xs text-ink-faint">Si ya tiene cuenta en la web, vinculala con su email para que vea sus turnos.</p>
            </>
          )}
        </div>
        {(account || orphan) && (
          <Button size="sm" variant="ghost" iconOnly icon={Unlink} aria-label="Desvincular la cuenta" onClick={() => setUnlinkOpen(true)} className="text-danger hover:bg-danger-soft hover:text-danger" />
        )}
      </div>

      <Modal
        open={open}
        onClose={() => setOpen(false)}
        size="sm"
        title={account ? 'Cambiar la cuenta vinculada' : 'Vincular una cuenta'}
        description="Escribí el email con el que la clienta se registró en la web."
        dismissible={!saving}
        footer={
          <>
            <Button variant="ghost" onClick={() => setOpen(false)} disabled={saving}>
              Volver
            </Button>
            <Button icon={Link2} onClick={submit} loading={saving}>
              Vincular
            </Button>
          </>
        }
      >
        <form onSubmit={submit} noValidate className="pt-1">
          <Input
            label="Email de la cuenta"
            type="email"
            inputMode="email"
            autoComplete="off"
            required
            value={email}
            error={error}
            onChange={(e) => {
              setEmail(e.target.value);
              setError('');
            }}
            autoFocus
          />
        </form>
      </Modal>

      <ConfirmDialog
        open={unlinkOpen}
        onClose={() => setUnlinkOpen(false)}
        onConfirm={() => save('')}
        tone="danger"
        icon={Unlink}
        loading={saving}
        title="¿Desvincular la cuenta?"
        text={`${account?.email ? `${account.email} deja de estar asociada a esta clienta.` : 'La clienta queda sin cuenta vinculada.'} La cuenta de la web no se borra y la ficha queda igual.`}
        confirmLabel="Desvincular"
        cancelLabel="Volver"
      />
    </section>
  );
}

export default ClientAccount;
