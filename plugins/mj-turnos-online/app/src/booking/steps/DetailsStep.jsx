/**
 * Paso 3 — Tus datos: los campos de form.fields (sólo los activos, en el
 * orden configurado), honeypot `website` oculto, la política si hay, y el
 * resumen de la reserva arriba (mobile) / a la derecha (desktop).
 *
 * Valida en el cliente (obligatorios, email, teléfono con ≥ 6 dígitos,
 * números) y arma el payload de POST /bookings:
 * { service_id, date, time, first_name, last_name, phone, email, locality, notes, extra:{}, website }.
 */
import { useRef, useState } from 'react';
import { ArrowLeft, Check, ShieldCheck } from 'lucide-react';
import { phoneDigits } from '@/lib/format';
import { Button, Input, Select, Textarea } from '@/ui';
import { Alert, StepFooter, Summary, summaryItem, useBooking } from '../shared';

const SYSTEM_KEYS = ['first_name', 'last_name', 'phone', 'email', 'locality', 'notes'];

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
  return '';
}

/** Arma el payload a partir de los valores (sistema arriba, custom en extra). */
export function buildPayload({ service, date, time, fields, values, website }) {
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
    if (SYSTEM_KEYS.includes(f.key)) payload[f.key] = v;
    else payload.extra[f.key] = v;
  });
  return payload;
}

/**
 * @param {{ service, date, time, values: object, onChange: (key, value) => void, onBack, onSubmit: (payload) => Promise|void,
 *   submitting?: boolean, error?: string }} props
 */
export default function DetailsStep({ service, date, time, values, onChange, onBack, onSubmit, submitting = false, error = '' }) {
  const { t, cfg } = useBooking();
  const fields = cfg.form.fields;
  const [errors, setErrors] = useState({});
  const [website, setWebsite] = useState('');
  const formRef = useRef(null);

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
    setErrors(next);
    const firstKey = Object.keys(next)[0];
    if (firstKey) {
      const el = formRef.current?.querySelector(`[data-key="${firstKey}"] input, [data-key="${firstKey}"] select, [data-key="${firstKey}"] textarea`);
      el?.focus?.();
      el?.scrollIntoView?.({ block: 'center', behavior: 'smooth' });
      return;
    }
    onSubmit(buildPayload({ service, date, time, fields, values, website }));
  };

  const set = (f, v) => {
    onChange(f.key, v);
    if (errors[f.key]) setErrors((prev) => ({ ...prev, [f.key]: '' }));
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
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">{fields.map(renderField)}</div>

          {/* Honeypot: los bots lo completan, las personas no lo ven. */}
          <div className="absolute -left-[9999px] top-auto h-px w-px overflow-hidden" aria-hidden="true">
            <label htmlFor="mjto-website">Website</label>
            <input id="mjto-website" type="text" name="website" tabIndex={-1} autoComplete="off" value={website} onChange={(e) => setWebsite(e.target.value)} />
          </div>

          {error && <Alert tone="danger" className="mt-5">{error}</Alert>}

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
