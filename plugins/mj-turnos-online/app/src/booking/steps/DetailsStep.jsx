/**
 * Paso 3 — Tus datos: los campos de form.fields (sólo los activos, en el
 * orden configurado), honeypot `website` oculto, la política si hay, y el
 * resumen de la reserva arriba (mobile) / a la derecha (desktop).
 *
 * Valida en el cliente (obligatorios, email, teléfono con ≥ 6 dígitos,
 * números) y arma el payload de POST /bookings:
 * { service_id, date, time, first_name, last_name, phone, email, locality, notes, extra:{}, website }
 * (+ birthdate si el campo de sistema está activo, + password / create_account
 * según cfg.account sin sesión).
 *
 * Cuenta (cfg.account; con el modo apagado, todo como siempre):
 *  - con sesión: "Reservás como {nombre}" (+ salir). El widget precarga el formulario con GET /me.
 *  - 'required' sin sesión: contraseña + enlace para ingresar con una cuenta que ya existe.
 *  - 'optional' sin sesión: casilla "crear mi cuenta" que muestra la contraseña.
 */
import { useEffect, useRef, useState } from 'react';
import { ArrowLeft, Check, LogIn, LogOut, ShieldCheck, UserRound } from 'lucide-react';
import { phoneDigits } from '@/lib/format';
import { todayStr } from '@/lib/dates';
import { Button, Checkbox, DateInput, Input, Select, Textarea } from '@/ui';
import { RadioGroup } from '@/ui/RadioGroup';
import { Alert, StepFooter, Summary, accountLoginUrl, summaryItem, useBooking } from '../shared';

const SYSTEM_KEYS = ['first_name', 'last_name', 'phone', 'email', 'locality', 'notes'];

/* Campos de sistema que viajan arriba en el body solo si el formulario los tiene activos. */
const OPTIONAL_SYSTEM_KEYS = ['birthdate'];

const AUTOCOMPLETE = {
  first_name: 'given-name',
  last_name: 'family-name',
  phone: 'tel',
  email: 'email',
  locality: 'address-level2',
};

const INPUT_MODE = { tel: 'tel', email: 'email', number: 'decimal' };

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;

/** Valida un campo → mensaje (clave de copy) o ''. */
function validateField(field, value) {
  const v = String(value ?? '').trim();
  if (field.required && v === '') return 'booking_error_required';
  if (v === '') return '';
  if (field.type === 'email' && !EMAIL_RE.test(v)) return 'booking_error_email';
  if (field.type === 'tel' || field.key === 'phone') {
    if (phoneDigits(v).length < 6) return 'booking_error_phone';
  }
  if (field.type === 'number' && !/^-?\d+([.,]\d+)?$/.test(v)) return 'booking_error_number';
  if (field.type === 'date' && !/^\d{4}-\d{2}-\d{2}$/.test(v)) return 'booking_error_required';
  return '';
}

/**
 * Arma el payload a partir de los valores (sistema arriba, custom en extra).
 * `account`: null (no se pide cuenta) o { password, createAccount? }.
 */
export function buildPayload({ service, date, time, fields, values, website, account = null }) {
  const payload = {
    service_id: service?.id || 0,
    date,
    time,
    first_name: '',
    last_name: '',
    phone: '',
    email: '',
    locality: '',
    notes: '',
    extra: {},
    website: website || '',
  };
  fields.forEach((f) => {
    const v = String(values[f.key] ?? '').trim();
    if (SYSTEM_KEYS.includes(f.key) || OPTIONAL_SYSTEM_KEYS.includes(f.key)) payload[f.key] = v;
    else payload.extra[f.key] = v;
  });
  // Cuenta: solo sin sesión y si el modo la pide. Con sesión, la reserva queda en la cuenta sola.
  if (account && typeof account.password === 'string') {
    payload.password = account.password;
    if (account.createAccount !== undefined) payload.create_account = !!account.createAccount;
  }
  return payload;
}

/**
 * @param {{ service, date, time, values: object, onChange: (key, value) => void, onBack, onSubmit: (payload) => Promise|void,
 *   submitting?: boolean, error?: string, serverErrors?: object (clave → mensaje, de data.errors),
 *   accountExists?: boolean (409 mjto_account_exists: ofrecer el login), userName?: string (con sesión, de GET /me) }} props
 */
export default function DetailsStep({
  service,
  date,
  time,
  values,
  onChange,
  onBack,
  onSubmit,
  submitting = false,
  error = '',
  serverErrors = null,
  accountExists = false,
  userName = '',
}) {
  const { t, cfg } = useBooking();
  const fields = cfg.form.fields;
  const account = cfg.account;
  const [errors, setErrors] = useState({});
  const [website, setWebsite] = useState('');
  const [password, setPassword] = useState('');
  const [createAccount, setCreateAccount] = useState(false);
  const formRef = useRef(null);

  // Qué pide la cuenta en este paso (nada con el modo apagado, modo auto, con sesión ni para la dueña).
  const showLoggedAs = account.loggedIn && account.mode !== 'off' && !cfg.canManage;
  const isAuto = account.mode === 'auto';
  const askAccount = !account.loggedIn && account.mode !== 'off' && !isAuto;
  const needsPassword = askAccount && (account.mode === 'required' || createAccount);
  const loginHref = (!account.loggedIn && account.mode !== 'off') || accountExists ? accountLoginUrl(cfg) : '';

  // Errores por campo que devolvió el servidor (data.errors / data.params).
  useEffect(() => {
    if (serverErrors && typeof serverErrors === 'object' && Object.keys(serverErrors).length) {
      setErrors((prev) => ({ ...prev, ...serverErrors }));
    }
  }, [serverErrors]);

  // Resumen a partir de la selección (todavía no hay turno creado).
  const item = summaryItem({ service, date, time, cfg });

  const submit = (e) => {
    e.preventDefault();
    if (submitting) return;
    const next = {};
    fields.forEach((f) => {
      const err = validateField(f, values[f.key]);
      if (err) next[f.key] = t(err);
    });
    if (needsPassword) {
      if (password === '') next.password = t('booking_error_required');
      else if (password.length < account.passwordMin) next.password = t('booking_password_hint', { min: account.passwordMin });
    }
    setErrors(next);
    const firstKey = Object.keys(next)[0];
    if (firstKey) {
      const el = formRef.current?.querySelector(`[data-key="${firstKey}"] input, [data-key="${firstKey}"] select, [data-key="${firstKey}"] textarea`);
      el?.focus?.();
      el?.scrollIntoView?.({ block: 'center', behavior: 'smooth' });
      return;
    }
    let accountPayload = null;
    if (isAuto) accountPayload = { createAccount: true };
    else if (askAccount && account.mode === 'required') accountPayload = { password };
    else if (askAccount && createAccount) accountPayload = { password, createAccount: true };
    onSubmit(buildPayload({ service, date, time, fields, values, website, account: accountPayload }));
  };

  const set = (f, v) => {
    onChange(f.key, v);
    if (errors[f.key]) setErrors((prev) => ({ ...prev, [f.key]: '' }));
  };

  const setPass = (v) => {
    setPassword(v);
    if (errors.password) setErrors((prev) => ({ ...prev, password: '' }));
  };

  const renderField = (f) => {
    const common = {
      label: f.label,
      required: f.required,
      error: errors[f.key] || '',
      placeholder: f.placeholder || undefined,
      value: values[f.key] ?? '',
      disabled: submitting,
    };
    const wrap = (node, full = false) => (
      <div key={f.key} data-key={f.key} className={full ? 'sm:col-span-2' : ''}>
        {node}
      </div>
    );
    if (f.type === 'textarea') {
      return wrap(<Textarea {...common} rows={3} onChange={(e) => set(f, e.target.value)} />, true);
    }
    if (f.type === 'select') {
      return wrap(
        <Select {...common} placeholder={f.placeholder || '—'} options={f.options} onChange={(e) => set(f, e.target.value)} />
      );
    }
    if (f.type === 'radio') {
      return wrap(
        <RadioGroup
          label={f.label}
          required={f.required}
          error={errors[f.key] || ''}
          name={`mjto-${f.key}`}
          value={values[f.key] ?? ''}
          options={f.options}
          disabled={submitting}
          onChange={(v) => set(f, v)}
        />,
        true
      );
    }
    if (f.type === 'date') {
      return wrap(
        <DateInput
          label={f.label}
          required={f.required}
          error={errors[f.key] || ''}
          value={values[f.key] ?? ''}
          disabled={submitting}
          max={f.key === 'birthdate' ? todayStr() : undefined}
          autoComplete={f.key === 'birthdate' ? 'bday' : 'off'}
          onChange={(v) => set(f, v)}
        />
      );
    }
    const type = f.type === 'number' ? 'text' : f.type === 'tel' ? 'tel' : f.type === 'email' ? 'email' : 'text';
    return wrap(
      <Input
        {...common}
        type={type}
        inputMode={INPUT_MODE[f.type]}
        autoComplete={AUTOCOMPLETE[f.key] || 'on'}
        onChange={(e) => set(f, e.target.value)}
      />
    );
  };

  return (
    <div className="mjto-booking__details animate-fade">
      <div className="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_20rem] lg:gap-10">
        <aside className="mjto-booking__aside order-first lg:order-last">
          <div className="flex flex-col gap-4 lg:sticky lg:top-6">
            <Summary item={item} compact />
            {cfg.form.policy && (
              <section className="mjto-booking__policy rounded-tile border border-line bg-surface-2 p-4" aria-label={t('booking_policy_title')}>
                <p className="mb-1.5 flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-[0.14em] text-ink-faint">
                  <ShieldCheck size={14} aria-hidden="true" />
                  {t('booking_policy_title')}
                </p>
                <p className="whitespace-pre-wrap text-[13px] leading-relaxed text-ink-soft">{cfg.form.policy}</p>
              </section>
            )}
          </div>
        </aside>

        <form ref={formRef} onSubmit={submit} noValidate className="mjto-booking__form relative min-w-0" aria-busy={submitting || undefined}>
          {showLoggedAs && (
            <div className="mjto-booking__session mb-5 flex flex-wrap items-center gap-x-3 gap-y-1 rounded-tile border border-line bg-surface-2 px-4 py-3 text-sm">
              <UserRound size={17} className="shrink-0 text-accent" aria-hidden="true" />
              <span className="min-w-0 flex-1 font-medium text-ink">{t('booking_logged_as', { nombre: userName || values.first_name || '' })}</span>
              {cfg.logoutUrl && t('booking_not_you') && (
                <a href={cfg.logoutUrl} className="inline-flex items-center gap-1.5 text-xs font-semibold text-ink-faint hover:text-accent">
                  <LogOut size={13} aria-hidden="true" />
                  {t('booking_not_you')}
                </a>
              )}
            </div>
          )}

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">{fields.map(renderField)}</div>

          {isAuto && loginHref && (
            <p className="mt-3 text-xs text-ink-soft">
              ¿Ya tenés cuenta?{' '}
              <a href={loginHref} className="inline-flex items-center gap-1 font-semibold text-accent hover:underline">
                <LogIn size={13} aria-hidden="true" />
                {t('booking_account_login')}
              </a>
            </p>
          )}

          {askAccount && (
            <section className="mjto-booking__account mt-6 rounded-tile border border-line bg-surface-2 p-4" aria-label={t('booking_account_title')}>
              {account.mode === 'required' ? (
                <>
                  {t('booking_account_title') && <p className="font-display text-[15px] font-semibold tracking-tight text-ink">{t('booking_account_title')}</p>}
                  {t('booking_account_text') && <p className="mt-1 text-[13px] leading-relaxed text-ink-soft">{t('booking_account_text')}</p>}
                </>
              ) : (
                <Checkbox
                  checked={createAccount}
                  onChange={(v) => {
                    setCreateAccount(v);
                    if (!v && errors.password) setErrors((prev) => ({ ...prev, password: '' }));
                  }}
                  label={t('booking_account_optional')}
                  description={t('booking_account_text') || undefined}
                  disabled={submitting}
                />
              )}
              {needsPassword && (
                <div data-key="password" className="mt-4">
                  <Input
                    type="password"
                    label={t('booking_password_label')}
                    required
                    value={password}
                    onChange={(e) => setPass(e.target.value)}
                    autoComplete="new-password"
                    help={t('booking_password_hint', { min: account.passwordMin })}
                    error={errors.password || ''}
                    disabled={submitting}
                  />
                </div>
              )}
              {account.mode === 'required' && loginHref && t('booking_account_login') && (
                <a href={loginHref} className="mt-3 inline-flex items-center gap-1.5 text-sm font-semibold text-accent hover:underline">
                  <LogIn size={15} aria-hidden="true" />
                  {t('booking_account_login')}
                </a>
              )}
            </section>
          )}

          {/* Honeypot: los bots lo completan, las personas no lo ven. */}
          <div className="absolute -left-[9999px] top-auto h-px w-px overflow-hidden" aria-hidden="true">
            <label htmlFor="mjto-website">Website</label>
            <input id="mjto-website" type="text" name="website" tabIndex={-1} autoComplete="off" value={website} onChange={(e) => setWebsite(e.target.value)} />
          </div>

          {error && (
            <Alert
              tone="danger"
              className="mt-5"
              action={
                accountExists && loginHref && t('booking_account_login') ? (
                  <Button size="xs" variant="outline" href={loginHref} icon={LogIn}>
                    {t('booking_account_login')}
                  </Button>
                ) : null
              }
            >
              {error}
            </Alert>
          )}

          <StepFooter>
            <Button type="button" variant="ghost" onClick={onBack} icon={ArrowLeft} disabled={submitting}>
              {t('booking_back')}
            </Button>
            <Button type="submit" size="lg" icon={Check} loading={submitting}>
              {t('booking_submit')}
            </Button>
          </StepFooter>
        </form>
      </div>
    </div>
  );
}
