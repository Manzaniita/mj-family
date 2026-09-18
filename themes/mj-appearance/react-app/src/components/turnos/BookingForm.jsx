import { Link } from 'react-router-dom';
import { Info, LogOut, UserRound } from 'lucide-react';
import { Input, Select, Textarea } from '@/components/ui/Input';
import { TURNOS, tcopy, fillTemplate } from '@/lib/turnos';
import { cn } from '@/lib/format';

/**
 * Paso "Tus datos" del wizard de reserva: campos dinámicos del plugin
 * (Turnos → Ajustes → Formulario, TURNOS.form.fields), honeypot `website`
 * oculto, la política de reserva (TURNOS.form.policy) si la dueña escribió una
 * y el bloque de la cuenta (Turnos → Ajustes → Cuentas) si está prendido.
 *
 * Es solo el bloque de campos: la página lo envuelve en su <form>, maneja
 * `values` (clave → valor, más `website`, `password` y `create_account`) y
 * pasa los errores del servidor por clave. La validación de obligatorios/tipos
 * la hace el navegador (required, type, minLength) y la definitiva el plugin:
 * acá no hay mensajes fijos.
 */

/* Claves que el plugin recibe en el nivel superior del POST /bookings; el resto va en `extra`. */
export const SYSTEM_FIELD_KEYS = ['first_name', 'last_name', 'phone', 'email', 'locality', 'birthdate', 'notes'];

const AUTOCOMPLETE = {
  first_name: 'given-name',
  last_name: 'family-name',
  phone: 'tel',
  email: 'email',
  locality: 'address-level2',
  birthdate: 'bday',
};

/**
 * Qué hace la reserva con la cuenta de la clienta:
 *  - 'none'     cuentas apagadas (como siempre), o la dueña mirando la reserva;
 *  - 'logged'   con sesión: la reserva queda en su cuenta, sin contraseña;
 *  - 'required' sin sesión: pide contraseña y crea la cuenta con la reserva;
 *  - 'optional' sin sesión: casilla para crear la cuenta.
 */
export function bookingAccountState() {
  if (!TURNOS || TURNOS.account.mode === 'off' || TURNOS.canManage) return 'none';
  if (TURNOS.account.loggedIn) return 'logged';
  return TURNOS.account.mode;
}

/** Valores iniciales del formulario: '' por campo + honeypot vacío (+ la cuenta, si corresponde). */
export function emptyBookingValues(fields = TURNOS?.form.fields ?? []) {
  const values = { website: '' };
  for (const f of fields) values[f.key] = '';
  const account = bookingAccountState();
  if (account === 'required' || account === 'optional') {
    values.password = '';
    values.create_account = false;
  }
  return values;
}

/**
 * Datos de GET /me → valores del formulario (solo los campos vacíos: nunca
 * pisa lo que la clienta ya escribió). Los extra se cargan por key.
 */
export function prefillBookingValues(values, client, fields = TURNOS?.form.fields ?? []) {
  if (!client || typeof client !== 'object') return values;
  const map = {
    first_name: client.firstName,
    last_name: client.lastName,
    phone: client.phone,
    email: client.email,
    locality: client.locality,
    birthdate: client.birthdate,
  };
  const extra = client.extra && typeof client.extra === 'object' ? client.extra : {};
  const next = { ...values };
  for (const f of fields) {
    if (String(next[f.key] ?? '').trim() !== '') continue;
    const value = f.key in map ? map[f.key] : extra[f.key];
    if (typeof value === 'string' || typeof value === 'number') next[f.key] = String(value);
  }
  return next;
}

/**
 * Cuerpo del POST /bookings a partir del servicio, día, horario y valores
 * del formulario: campos de sistema arriba, los custom en `extra`, y la
 * contraseña solo cuando la reserva crea la cuenta.
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
  const account = bookingAccountState();
  if (account === 'required') {
    payload.password = String(values.password ?? '');
  } else if (account === 'optional' && values.create_account) {
    payload.create_account = true;
    payload.password = String(values.password ?? '');
  }
  return payload;
}

/* Grupo de opciones (tipo 'radio'): chips grandes, cómodos con el dedo (ideal Sí / No). */
function RadioGroup({ field, value, onChange, error, disabled, label }) {
  const name = `mjto-field-${field.key}`;
  return (
    <fieldset
      className="mj-field mj-booking__radio"
      aria-invalid={error ? true : undefined}
      aria-describedby={error ? `mjto-error-${field.key}` : undefined}
    >
      <legend className="mj-field__label mb-1.5 block text-xs font-medium uppercase tracking-wider text-ink-faint">{label}</legend>
      <div className="flex flex-wrap gap-2">
        {field.options.map((o, i) => {
          const checked = value === o.value;
          return (
            <label
              key={o.value}
              className={cn(
                'mj-booking__radio-option mj-chip inline-flex h-11 min-w-16 cursor-pointer items-center justify-center rounded-(--mj-radius-chip) border px-4 text-sm font-medium transition-all duration-200 has-focus-visible:ring-2 has-focus-visible:ring-accent/40',
                checked
                  ? 'mj-chip--active border-accent bg-accent text-white shadow-glow'
                  : 'border-line bg-surface text-ink hover:border-accent/50 hover:text-accent',
                disabled && 'pointer-events-none opacity-50'
              )}
            >
              <input
                type="radio"
                className="sr-only"
                name={name}
                value={o.value}
                checked={checked}
                required={!!field.required && i === 0}
                disabled={disabled}
                onChange={() => onChange(field.key, o.value)}
              />
              {o.label}
            </label>
          );
        })}
      </div>
    </fieldset>
  );
}

/* Un campo según su tipo (text | tel | email | number | date | textarea | select | radio). */
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
  } else if (field.type === 'radio' && field.options.length > 0) {
    control = <RadioGroup field={field} value={value ?? ''} onChange={onChange} error={error} disabled={disabled} label={label} />;
  } else if (field.type === 'date') {
    control = <Input {...common} type="date" autoComplete={AUTOCOMPLETE[field.key] || 'off'} />;
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

  const wide = field.type === 'textarea' || field.type === 'radio';
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

/* Con sesión: "Reservás como {nombre}" y la salida por si no es ella. */
function LoggedAs({ name }) {
  const logout = TURNOS?.logoutUrl || '';
  return (
    <div className="mj-booking__logged mb-6 flex flex-wrap items-center justify-between gap-3 rounded-xl2 border border-line glass p-4 text-sm">
      <p className="inline-flex items-center gap-2.5 text-ink">
        <UserRound size={16} className="shrink-0 text-accent" aria-hidden="true" />
        {fillTemplate(tcopy('booking_logged_as'), { nombre: name || '' })}
      </p>
      {logout && (
        <a href={logout} className="inline-flex items-center gap-1.5 text-xs text-ink-faint transition-colors hover:text-accent">
          <LogOut size={13} aria-hidden="true" />
          {tcopy('booking_not_you')}
        </a>
      )}
    </div>
  );
}

/* Sin sesión: contraseña para crear la cuenta (obligatoria u opcional con casilla) y el link al login. */
function AccountFields({ state, values, onChange, error, disabled, loginPath }) {
  const min = TURNOS?.account.passwordMin ?? 8;
  const showPassword = state === 'required' || !!values.create_account;
  return (
    <div className="mj-booking__account mt-6 rounded-xl2 border border-line glass p-5">
      <p className="font-display text-base font-semibold tracking-tight">{tcopy('booking_account_title')}</p>
      {state === 'optional' && (
        <label className="mj-booking__account-optional mt-3 flex cursor-pointer items-start gap-3 text-sm text-ink">
          <input
            type="checkbox"
            className="mt-0.5 size-4 shrink-0 accent-accent"
            checked={!!values.create_account}
            disabled={disabled}
            onChange={(e) => onChange('create_account', e.target.checked)}
          />
          <span>{tcopy('booking_account_optional')}</span>
        </label>
      )}
      {showPassword && (
        <>
          <p className="mt-2 text-sm leading-relaxed text-ink-soft">{tcopy('booking_account_text')}</p>
          <div className="mt-4 max-w-sm">
            <Input
              id="mjto-field-password"
              name="password"
              type="password"
              label={`${tcopy('booking_password_label')} *`}
              autoComplete="new-password"
              minLength={min}
              required
              disabled={disabled}
              value={values.password ?? ''}
              onChange={(e) => onChange('password', e.target.value)}
              error={error}
            />
            {!error && (
              <p className="mt-1.5 text-xs text-ink-faint">{fillTemplate(tcopy('booking_password_hint'), { min })}</p>
            )}
          </div>
        </>
      )}
      {loginPath && (
        <p className="mt-4">
          <Link to={loginPath} className="text-sm font-medium text-accent hover:underline">
            {tcopy('booking_account_login')}
          </Link>
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
  /* Nombre de quien tiene la sesión (cuentas prendidas). */
  accountName = '',
  /* Login con vuelta a la reserva (cuentas prendidas, sin sesión). */
  loginPath = '',
  className,
}) {
  const set = typeof onChange === 'function' ? onChange : () => {};
  const title = policyTitle ?? tcopy('booking_policy_title');
  const policyText = String(policy || '').trim();
  const account = bookingAccountState();

  return (
    <div className={cn('mj-booking__form relative', className)}>
      {account === 'logged' && <LoggedAs name={accountName} />}

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

      {(account === 'required' || account === 'optional') && (
        <AccountFields
          state={account}
          values={values}
          onChange={set}
          error={errors?.password}
          disabled={disabled}
          loginPath={loginPath}
        />
      )}

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
