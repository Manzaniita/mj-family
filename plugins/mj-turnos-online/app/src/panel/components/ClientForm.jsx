/**
 * Alta / edición de clienta (modal). Los rótulos de los campos de sistema
 * salen de `options.formFields` (los mismos del formulario público); los
 * campos custom (system=false) se guardan en `extra`.
 *
 *   <ClientForm open={open} onClose={() => setOpen(false)} client={clientOrNull}
 *               onSaved={(client) => …} />
 *
 * Llama a `onSaved(client)` con la clienta devuelta por el servidor y después a `onClose()`.
 */
import { useEffect, useMemo, useState } from 'react';
import { useToast, Button, FieldRow, Input, Modal, Select, Textarea } from '@/ui';
import { panelApi } from '@/lib/api';
import { phoneDigits } from '@/lib/format';
import { useUid } from '@/lib/a11y';
import { usePanel } from '../store';

const SYSTEM_DEFAULTS = {
  first_name: { label: 'Nombre', type: 'text' },
  last_name: { label: 'Apellido', type: 'text' },
  phone: { label: 'Teléfono / WhatsApp', type: 'tel' },
  email: { label: 'Email', type: 'email' },
  locality: { label: 'Localidad', type: 'text' },
};

const str = (v) => (v === null || v === undefined ? '' : String(v));

function initialForm(client, customFields) {
  const extra = client?.extra && typeof client.extra === 'object' ? client.extra : {};
  const custom = {};
  customFields.forEach((f) => {
    custom[f.key] = str(extra[f.key]);
  });
  return {
    first_name: str(client?.firstName),
    last_name: str(client?.lastName),
    phone: str(client?.phoneRaw || client?.phone),
    email: str(client?.email),
    locality: str(client?.locality),
    age: client?.age === null || client?.age === undefined || client?.age === '' ? '' : String(client.age),
    notes: str(client?.notes),
    extra: custom,
  };
}

/** Campo custom del formulario público (text|tel|email|textarea|select|number). */
function CustomField({ field, value, onChange, error }) {
  const common = { label: field.label || field.key, placeholder: field.placeholder || '', required: !!field.required, error };
  switch (field.type) {
    case 'textarea':
      return <Textarea {...common} rows={2} value={value} onChange={(e) => onChange(e.target.value)} />;
    case 'select':
      return (
        <Select
          {...common}
          value={value}
          placeholder={field.placeholder || 'Elegí una opción'}
          options={(Array.isArray(field.options) ? field.options : []).map((o) => ({ value: str(o.value), label: o.label ?? str(o.value) }))}
          onChange={(e) => onChange(e.target.value)}
        />
      );
    case 'number':
      return <Input {...common} type="number" inputMode="decimal" value={value} onChange={(e) => onChange(e.target.value)} />;
    case 'tel':
      return <Input {...common} type="tel" inputMode="tel" autoComplete="off" value={value} onChange={(e) => onChange(e.target.value)} />;
    case 'email':
      return <Input {...common} type="email" inputMode="email" autoComplete="off" value={value} onChange={(e) => onChange(e.target.value)} />;
    default:
      return <Input {...common} type="text" value={value} onChange={(e) => onChange(e.target.value)} />;
  }
}

/**
 * @param {{ client?: object|null, open: boolean, onClose: () => void, onSaved?: (client: object) => void }} props
 */
export default function ClientForm({ client = null, open, onClose, onSaved }) {
  const store = usePanel();
  const toast = useToast();
  const formId = useUid('mjto-client-form');
  const editing = !!client?.id;

  const sysField = (key) => {
    const found = store.formFields.find((f) => f && f.key === key);
    return { ...SYSTEM_DEFAULTS[key], ...(found || {}) };
  };
  const customFields = useMemo(
    () => store.formFields.filter((f) => f && f.key && !f.system && f.enabled !== false && !SYSTEM_DEFAULTS[f.key] && f.key !== 'notes'),
    [store.formFields]
  );

  const [form, setForm] = useState(() => initialForm(client, customFields));
  const [errors, setErrors] = useState({});
  const [saving, setSaving] = useState(false);

  // Al abrir (o cambiar de clienta) se recarga el formulario.
  useEffect(() => {
    if (open) {
      setForm(initialForm(client, customFields));
      setErrors({});
      setSaving(false);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, client?.id]);

  const set = (key, value) => setForm((f) => ({ ...f, [key]: value }));
  const setExtra = (key, value) => setForm((f) => ({ ...f, extra: { ...f.extra, [key]: value } }));

  const validate = () => {
    const next = {};
    if (!form.first_name.trim()) next.first_name = 'Escribí el nombre.';
    if (phoneDigits(form.phone).length < 6) next.phone = 'Escribí un teléfono válido (al menos 6 dígitos).';
    if (form.email.trim() && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email.trim())) next.email = 'Ese email no parece válido.';
    if (form.age !== '' && (!/^\d{1,3}$/.test(form.age) || Number(form.age) > 120)) next.age = 'Edad inválida.';
    customFields.forEach((f) => {
      if (f.required && !str(form.extra[f.key]).trim()) next[`extra.${f.key}`] = 'Completá este campo.';
    });
    setErrors(next);
    return Object.keys(next).length === 0;
  };

  const submit = async (e) => {
    e?.preventDefault?.();
    if (saving) return;
    if (!validate()) return;
    const payload = {
      first_name: form.first_name.trim(),
      last_name: form.last_name.trim(),
      phone: form.phone.trim(),
      email: form.email.trim(),
      locality: form.locality.trim(),
      age: form.age === '' ? null : Number(form.age),
      notes: form.notes,
      extra: form.extra,
    };
    setSaving(true);
    try {
      const saved = editing ? await panelApi.updateClient(client.id, payload) : await panelApi.createClient(payload);
      toast.success(editing ? 'Datos guardados.' : 'Clienta creada.');
      onSaved?.(saved);
      onClose?.();
    } catch (err) {
      if (err?.status === 400 && err?.data && typeof err.data === 'object' && err.data.field) {
        setErrors({ [err.data.field]: err.message });
      }
      toast.error(err);
    } finally {
      setSaving(false);
    }
  };

  const f = {
    first_name: sysField('first_name'),
    last_name: sysField('last_name'),
    phone: sysField('phone'),
    email: sysField('email'),
    locality: sysField('locality'),
  };

  return (
    <Modal
      open={open}
      onClose={saving ? () => {} : onClose}
      title={editing ? 'Editar clienta' : 'Nueva clienta'}
      description={editing ? undefined : 'Con nombre y teléfono alcanza; lo demás lo completás después.'}
      size="md"
      dismissible={!saving}
      footer={
        <>
          <Button variant="ghost" onClick={onClose} disabled={saving}>
            Cancelar
          </Button>
          <Button type="submit" form={formId} loading={saving}>
            {editing ? 'Guardar cambios' : 'Crear clienta'}
          </Button>
        </>
      }
    >
      <form id={formId} onSubmit={submit} noValidate className="flex flex-col gap-4 pt-1">
        <FieldRow>
          <Input
            label={f.first_name.label}
            placeholder={f.first_name.placeholder || ''}
            required
            autoComplete="off"
            value={form.first_name}
            error={errors.first_name}
            onChange={(e) => set('first_name', e.target.value)}
          />
          <Input
            label={f.last_name.label}
            placeholder={f.last_name.placeholder || ''}
            autoComplete="off"
            value={form.last_name}
            error={errors.last_name}
            onChange={(e) => set('last_name', e.target.value)}
          />
        </FieldRow>
        <FieldRow>
          <Input
            label={f.phone.label}
            placeholder={f.phone.placeholder || ''}
            type="tel"
            inputMode="tel"
            required
            autoComplete="off"
            value={form.phone}
            error={errors.phone}
            help={!errors.phone && editing && client?.phone && phoneDigits(form.phone) !== client.phone ? 'Se va a actualizar el teléfono de contacto.' : undefined}
            onChange={(e) => set('phone', e.target.value)}
          />
          <Input
            label={f.email.label}
            placeholder={f.email.placeholder || ''}
            type="email"
            inputMode="email"
            autoComplete="off"
            value={form.email}
            error={errors.email}
            onChange={(e) => set('email', e.target.value)}
          />
        </FieldRow>
        <FieldRow cols={3}>
          <Input
            label={f.locality.label}
            placeholder={f.locality.placeholder || ''}
            autoComplete="off"
            value={form.locality}
            error={errors.locality}
            onChange={(e) => set('locality', e.target.value)}
            className="sm:col-span-2"
          />
          <Input label="Edad" type="number" inputMode="numeric" min={0} max={120} value={form.age} error={errors.age} onChange={(e) => set('age', e.target.value)} />
        </FieldRow>
        {customFields.length > 0 && (
          <FieldRow>
            {customFields.map((cf) => (
              <div key={cf.key} className={cf.type === 'textarea' ? 'sm:col-span-2' : ''}>
                <CustomField field={cf} value={str(form.extra[cf.key])} onChange={(v) => setExtra(cf.key, v)} error={errors[`extra.${cf.key}`]} />
              </div>
            ))}
          </FieldRow>
        )}
        <Textarea
          label="Notas internas"
          help="Sólo las ves vos: alergias, preferencias, cómo llegó, etc."
          rows={3}
          value={form.notes}
          onChange={(e) => set('notes', e.target.value)}
        />
      </form>
    </Modal>
  );
}

export { ClientForm };
