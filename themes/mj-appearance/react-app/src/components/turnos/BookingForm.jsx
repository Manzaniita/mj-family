import { Info } from 'lucide-react';
import { Input, Select, Textarea } from '@/components/ui/Input';
import { TURNOS, tcopy } from '@/lib/turnos';
import { cn } from '@/lib/format';

/**
 * Paso "Tus datos" del wizard de reserva: campos dinámicos del plugin
 * (Turnos → Ajustes → Formulario, TURNOS.form.fields), honeypot `website`
 * oculto y la política de reserva (TURNOS.form.policy) si la dueña escribió una.
 *
 * Es solo el bloque de campos: la página lo envuelve en su <form>, maneja
 * `values` (clave → valor, más `website`) y pasa los errores del servidor
 * por clave. La validación de obligatorios/tipos la hace el navegador
 * (required, type) y la definitiva el plugin: acá no hay mensajes fijos.
 */

/* Claves que el plugin recibe en el nivel superior del POST /bookings; el resto va en `extra`. */
export const SYSTEM_FIELD_KEYS = ['first_name', 'last_name', 'phone', 'email', 'locality', 'notes'];

const AUTOCOMPLETE = {
  first_name: 'given-name',
  last_name: 'family-name',
  phone: 'tel',
  email: 'email',
  locality: 'address-level2',
};

/** Valores iniciales del formulario: '' por campo + honeypot vacío. */
export function emptyBookingValues(fields = TURNOS?.form.fields ?? []) {
  const values = { website: '' };
  for (const f of fields) values[f.key] = '';
  return values;
}

/**
 * Cuerpo del POST /bookings a partir del servicio, día, horario y valores
 * del formulario: campos de sistema arriba, los custom en `extra`.
 */
export function bookingPayload({ service, date, time, values = {}, fields = TURNOS?.form.fields ?? [] }) {
  const clean = (v) => (typeof v === 'string' ? v.trim() : v ?? '');
  const payload = { service_id: service?.id ?? 0, date, time, website: clean(values.website) };
  const extra = {};
  for (const f of fields) {
    const value = clean(values[f.key]);
    if (f.system || SYSTEM_FIELD_KEYS.includes(f.key)) payload[f.key] = value;
    else extra[f.key] = value;
  }
  payload.extra = extra;
  return payload;
}

/* Un campo según su tipo (text | tel | email | number | textarea | select). */
function Field({ field, value, onChange, error, disabled }) {
  const label = field.required ? `${field.label} *` : field.label;
  const common = {
    id: `mjto-field-${field.key}`,
    name: field.key,
    label,
    value: value ?? '',
    onChange: (e) => onChange(field.key, e.target.value),
    required: !!field.required,
    disabled,
    'aria-invalid': error ? true : undefined,
    'aria-describedby': error ? `mjto-error-${field.key}` : undefined,
  };

  let control;
  if (field.type === 'textarea') {
    control = <Textarea {...common} placeholder={field.placeholder || undefined} className="min-h-28" />;
  } else if (field.type === 'select') {
    control = (
      <Select {...common}>
        <option value="">{field.placeholder || field.label}</option>
        {field.options.map((o) => (
          <option key={o.value} value={o.value}>{o.label}</option>
        ))}
      </Select>
    );
  } else {
    const type = field.type === 'tel' || field.type === 'email' || field.type === 'number' ? field.type : 'text';
    control = (
      <Input
        {...common}
        type={type}
        placeholder={field.placeholder || undefined}
        autoComplete={AUTOCOMPLETE[field.key] || 'off'}
        inputMode={type === 'tel' ? 'tel' : type === 'number' ? 'numeric' : undefined}
      />
    );
  }

  const wide = field.type === 'textarea';
  return (
    <div className={cn('mj-booking__field', wide && 'sm:col-span-2', error && 'mj-booking__field--error')}>
      {control}
      {error && (
        <p id={`mjto-error-${field.key}`} role="alert" className="mj-field__error mt-1.5 text-xs text-danger">
          {error}
        </p>
      )}
    </div>
  );
}

export function BookingForm({
  fields = TURNOS?.form.fields ?? [],
  values = {},
  onChange,
  errors = {},
  policy = TURNOS?.form.policy ?? '',
  policyTitle,
  disabled = false,
  className,
}) {
  const set = typeof onChange === 'function' ? onChange : () => {};
  const title = policyTitle ?? tcopy('booking_policy_title');
  const policyText = String(policy || '').trim();

  return (
    <div className={cn('mj-booking__form relative', className)}>
      {policyText && (
        <div className="mj-booking__policy mb-6 flex gap-3 rounded-xl2 border border-accent/20 bg-accent-soft p-4 text-sm">
          <Info size={17} className="mt-0.5 shrink-0 text-accent" aria-hidden="true" />
          <div>
            {title && <p className="mj-booking__policy-title font-medium text-ink">{title}</p>}
            <p className={cn('mj-booking__policy-text whitespace-pre-line leading-relaxed text-ink-soft', title && 'mt-1')}>{policyText}</p>
          </div>
        </div>
      )}

      <div className="mj-booking__fields grid gap-4 sm:grid-cols-2">
        {fields.map((field) => (
          <Field
            key={field.key}
            field={field}
            value={values[field.key]}
            onChange={set}
            error={errors?.[field.key]}
            disabled={disabled}
          />
        ))}
      </div>

      {/* Honeypot: los humanos no lo ven ni lo tocan; el plugin rechaza la reserva si viene lleno. */}
      <div className="mj-booking__hp absolute -left-[10000px] top-0 h-px w-px overflow-hidden" aria-hidden="true">
        <input
          type="text"
          name="website"
          tabIndex={-1}
          autoComplete="off"
          value={values.website ?? ''}
          onChange={(e) => set('website', e.target.value)}
        />
      </div>
    </div>
  );
}
