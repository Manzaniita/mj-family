/**
 * Campos dinámicos de la ficha técnica: renderiza los bloques de
 * `options.fichaBlocks` (spec A.3) en modo edición o lectura.
 *
 *   <VisitFields blocks={fichaBlocks} values={data} onChange={(key, value) => …}
 *                visitedAt="2026-09-12" paymentMethods={paymentMethods} />
 *   <VisitFields blocks={fichaBlocks} values={visit.data} readOnly warnings={visit.warnings} />
 *
 * Tipos: checkbox_group (chips multi), radio (chips single), select, text,
 * textarea, number, money (MoneyInput), date (DateInput con "Sugerir" si el
 * campo tiene role control_date / retouch_date) y toggle.
 * - Una opción con `warn` elegida muestra un aviso inline con `warn_text`.
 * - `payment_method` sin options usa `paymentMethods`.
 * - `deposit_flag` en false deshabilita (edición) u oculta (lectura) el campo `deposit`.
 *
 * Además exporta helpers puros que usan el editor y la línea de tiempo:
 * flattenFields, roleField, roleValue, fieldOptions, normalizeValue(s),
 * isEmptyValue, fieldWarnings, computeWarnings, suggestDateFor, valueLabel.
 */
import { useMemo } from 'react';
import { AlertTriangle, Check } from 'lucide-react';
import { cn, dateLabel } from '@/lib/format';
import { addDaysStr, addMonthsStr, isDateStr } from '@/lib/dates';
import { Chip, ChipGroup, DateInput, Field, Input, MoneyInput, Select, Textarea, Toggle } from '@/ui';
import { usePanel } from '../store';

/** Roles de fecha que tienen botón "Sugerir" (visited_at + offsets). */
export const SUGGEST_ROLES = ['control_date', 'retouch_date'];

/** Offsets por defecto cuando el campo no trae ninguno (espejo de mjto_suggest_dates). */
export const ROLE_DEFAULT_OFFSETS = {
  control_date: { days: 3, months: 0 },
  retouch_date: { days: 0, months: 4 },
};

/** Roles que el servidor sincroniza a columnas de la visita. */
export const COLUMN_ROLES = ['total', 'deposit', 'payment_method', 'control_date', 'retouch_date'];

export const toBool = (v) => v === true || v === 1 || v === '1' || v === 'true' || v === 'on';

/* ------------------------------ Helpers puros ---------------------------- */

/** Campos planos de todos los bloques (con blockKey). */
export function flattenFields(blocks) {
  return (Array.isArray(blocks) ? blocks : []).flatMap((b) =>
    (Array.isArray(b?.fields) ? b.fields : []).filter((f) => f && f.key).map((f) => ({ ...f, blockKey: b.key }))
  );
}

/** Primer campo con ese role (o null). */
export function roleField(blocks, role) {
  return flattenFields(blocks).find((f) => f.role === role) || null;
}

/** Valor del campo con ese role dentro de `values` (undefined si no hay campo). */
export function roleValue(blocks, values, role) {
  const f = roleField(blocks, role);
  return f && values ? values[f.key] : undefined;
}

/** Opciones normalizadas; `payment_method` sin options → métodos de pago. */
export function fieldOptions(field, paymentMethods = []) {
  const raw = Array.isArray(field?.options) ? field.options : [];
  const opts = raw
    .filter((o) => o && o.value !== undefined && o.value !== null)
    .map((o) => ({ ...o, value: String(o.value), label: o.label != null && o.label !== '' ? String(o.label) : String(o.value), warn: toBool(o.warn) }));
  if (opts.length || field?.role !== 'payment_method') return opts;
  return (Array.isArray(paymentMethods) ? paymentMethods : []).map((m) => ({ value: String(m), label: String(m), warn: false }));
}

/** Valor crudo (del servidor o del formulario) → valor tipado para el control. */
export function normalizeValue(field, raw) {
  switch (field?.type) {
    case 'checkbox_group':
      if (Array.isArray(raw)) return raw.map(String);
      if (raw && typeof raw === 'object') return Object.values(raw).map(String);
      return raw === '' || raw === null || raw === undefined ? [] : [String(raw)];
    case 'toggle':
      return toBool(raw);
    case 'money': {
      if (raw === '' || raw === null || raw === undefined) return '';
      const n = Number(raw);
      return Number.isFinite(n) ? n : '';
    }
    case 'date':
      return isDateStr(raw) ? raw : '';
    default:
      return raw === null || raw === undefined ? '' : String(raw);
  }
}

/** Normaliza todo el mapa data según los campos de la ficha. */
export function normalizeValues(blocks, data) {
  const out = {};
  flattenFields(blocks).forEach((f) => {
    out[f.key] = normalizeValue(f, data ? data[f.key] : undefined);
  });
  return out;
}

/** ¿Está vacío para mostrar? ('' | null | [] | false). */
export function isEmptyValue(v) {
  if (v === '' || v === null || v === undefined || v === false) return true;
  if (Array.isArray(v)) return v.length === 0;
  return false;
}

/** Advertencias de un campo según las opciones con warn elegidas. */
export function fieldWarnings(field, value, paymentMethods) {
  const opts = fieldOptions(field, paymentMethods).filter((o) => o.warn);
  if (!opts.length) return [];
  const selected =
    field.type === 'checkbox_group'
      ? (Array.isArray(value) ? value : []).map(String)
      : value === '' || value === null || value === undefined
        ? []
        : [String(value)];
  return opts
    .filter((o) => selected.includes(o.value))
    .map((o) => ({
      field: field.key,
      label: field.label,
      option: o.label,
      text: o.warn_text && String(o.warn_text).trim() ? String(o.warn_text) : `Atención: ${field.label}: ${o.label}`,
    }));
}

/** Todas las advertencias de la ficha (espejo de mjto_compute_warnings). */
export function computeWarnings(blocks, values, paymentMethods) {
  return flattenFields(blocks).flatMap((f) => fieldWarnings(f, values ? values[f.key] : undefined, paymentMethods));
}

/** visited_at + offsets del campo (sin zonas horarias). '' si no hay fecha base. */
export function suggestDateFor(field, visitedAt) {
  if (!isDateStr(visitedAt)) return '';
  /*
   * Espejo de mjto_suggest_dates: los defaults del rol valen solo cuando el
   * campo no trae offsets propios (campo sintético de columna). Si la dueña
   * puso 0 y 0 en Ajustes → Ficha, ese campo no sugiere nada.
   */
  const hasOwn = field && (field.offset_days !== undefined || field.offset_months !== undefined);
  let days = Number(field?.offset_days) || 0;
  let months = Number(field?.offset_months) || 0;
  if (!hasOwn && !days && !months) {
    const def = ROLE_DEFAULT_OFFSETS[field?.role];
    if (def) {
      days = def.days;
      months = def.months;
    }
  }
  if (!days && !months) return '';
  return addDaysStr(addMonthsStr(visitedAt, months), days);
}

/** Etiqueta legible de un valor (string, o array de strings para checkbox_group). */
export function valueLabel(field, value, { formatPrice, paymentMethods } = {}) {
  const opts = fieldOptions(field, paymentMethods);
  const optLabel = (v) => opts.find((o) => o.value === String(v))?.label ?? String(v);
  switch (field?.type) {
    case 'checkbox_group':
      return (Array.isArray(value) ? value : []).map(optLabel);
    case 'radio':
    case 'select':
      return value === '' || value === null || value === undefined ? '' : optLabel(value);
    case 'toggle':
      return toBool(value) ? 'Sí' : 'No';
    case 'money':
      if (value === '' || value === null || value === undefined) return '';
      return formatPrice ? formatPrice(value) : String(value);
    case 'date':
      return isDateStr(value) ? dateLabel(value, { year: true }) : '';
    default:
      return value === null || value === undefined ? '' : String(value);
  }
}

/* ------------------------------ Piezas de UI ----------------------------- */

/** Aviso inline (amarillo) para una opción con `warn`. */
export function WarnBanner({ text, tone = 'warning', className }) {
  const danger = tone === 'danger';
  return (
    <div
      role="alert"
      className={cn(
        'mjto-warn flex items-start gap-2 rounded-tile border px-3 py-2 text-[13px] leading-snug',
        danger ? 'border-danger/30 bg-danger-soft text-danger' : 'border-warning/30 bg-warning-soft text-warning',
        className
      )}
    >
      <AlertTriangle size={16} className="mt-0.5 shrink-0" aria-hidden="true" />
      <span>{text}</span>
    </div>
  );
}

function OptionChips({ field, options, value, multi, onChange, disabled, size = 'md' }) {
  const selected = multi ? (Array.isArray(value) ? value : []) : value === '' || value == null ? [] : [String(value)];
  const toggle = (opt) => {
    if (multi) {
      const next = selected.includes(opt.value) ? selected.filter((v) => v !== opt.value) : [...selected, opt.value];
      onChange(next);
      return;
    }
    // Radio: tocar la activa la destilda (salvo que sea obligatorio).
    if (selected.includes(opt.value)) onChange(field.required ? opt.value : '');
    else onChange(opt.value);
  };
  return (
    <ChipGroup role={multi ? 'group' : 'radiogroup'} aria-label={field.label}>
      {options.map((opt) => {
        const active = selected.includes(opt.value);
        return (
          <Chip
            key={opt.value}
            active={active}
            onClick={() => toggle(opt)}
            disabled={disabled}
            size={size}
            tone={opt.warn ? 'warning' : 'neutral'}
            icon={active ? Check : opt.warn ? AlertTriangle : undefined}
          >
            {opt.label}
          </Chip>
        );
      })}
    </ChipGroup>
  );
}

/** Un campo en modo edición. */
function EditField({ field, value, onChange, error, methods, visitedAt, warnings, disabled, disabledHelp, formatPrice }) {
  const options = fieldOptions(field, methods);
  const help = disabled && disabledHelp ? disabledHelp : field.help || undefined;
  const common = { label: field.label, help, error, required: !!field.required };
  const warnNodes = warnings.length > 0 && (
    <div className="mt-2 flex flex-col gap-2">
      {warnings.map((w, i) => (
        <WarnBanner key={`${w.field}-${i}`} text={w.text} />
      ))}
    </div>
  );

  switch (field.type) {
    case 'checkbox_group':
    case 'radio': {
      const multi = field.type === 'checkbox_group';
      return (
        <Field {...common}>
          <OptionChips field={field} options={options} value={value} multi={multi} onChange={onChange} disabled={disabled} />
          {warnNodes}
        </Field>
      );
    }
    case 'select':
      return (
        <div>
          <Select
            {...common}
            value={value ?? ''}
            placeholder={field.placeholder || 'Elegí una opción'}
            options={options.map((o) => ({ value: o.value, label: o.warn ? `⚠ ${o.label}` : o.label }))}
            onChange={(e) => onChange(e.target.value)}
            disabled={disabled}
          />
          {warnNodes}
        </div>
      );
    case 'textarea':
      return <Textarea {...common} value={value ?? ''} placeholder={field.placeholder || ''} rows={3} onChange={(e) => onChange(e.target.value)} disabled={disabled} />;
    case 'number':
      return (
        <Input
          {...common}
          type="number"
          inputMode="decimal"
          step="any"
          value={value ?? ''}
          placeholder={field.placeholder || ''}
          onChange={(e) => onChange(e.target.value)}
          disabled={disabled}
        />
      );
    case 'money':
      return <MoneyInput {...common} value={value === undefined || value === null ? '' : value} placeholder={field.placeholder || undefined} onChange={onChange} disabled={disabled} min={0} />;
    case 'date': {
      const canSuggest = SUGGEST_ROLES.includes(field.role) && isDateStr(visitedAt);
      const suggestion = canSuggest ? suggestDateFor(field, visitedAt) : '';
      const suggestHelp = canSuggest && !field.help && suggestion ? `Sugerido: ${dateLabel(suggestion, { year: true })}` : undefined;
      return (
        <DateInput
          {...common}
          help={help || suggestHelp}
          value={value || ''}
          onChange={onChange}
          onSuggest={suggestion ? () => onChange(suggestion) : undefined}
          suggestLabel="Sugerir"
          clearable
          disabled={disabled}
        />
      );
    }
    case 'toggle':
      return (
        <div className={cn('flex min-h-11 flex-col justify-center', field.width === 'half' && 'sm:pt-6')}>
          <Toggle checked={toBool(value)} onChange={(next) => onChange(next)} label={field.label} description={help} disabled={disabled} />
          {error && (
            <p className="mt-1.5 text-xs font-medium text-danger" role="alert">
              {error}
            </p>
          )}
        </div>
      );
    case 'text':
    default:
      return <Input {...common} type="text" value={value ?? ''} placeholder={field.placeholder || ''} onChange={(e) => onChange(e.target.value)} disabled={disabled} />;
  }
}

/** Un campo en modo lectura. */
function ReadField({ field, value, methods, warnings, formatPrice }) {
  const label = valueLabel(field, value, { formatPrice, paymentMethods: methods });
  const hasWarn = warnings.length > 0;
  let node;
  if (Array.isArray(label)) {
    node = (
      <ChipGroup>
        {label.map((l, i) => {
          const opt = fieldOptions(field, methods).find((o) => o.label === l);
          return (
            <Chip key={`${l}-${i}`} size="sm" tone={opt?.warn ? 'warning' : 'neutral'} icon={opt?.warn ? AlertTriangle : undefined}>
              {l}
            </Chip>
          );
        })}
      </ChipGroup>
    );
  } else if (field.type === 'textarea') {
    node = <p className="whitespace-pre-line text-[15px] leading-relaxed text-ink">{label || '—'}</p>;
  } else if (field.type === 'money') {
    node = <p className="text-[15px] font-semibold text-ink mjto-tabular">{label || '—'}</p>;
  } else {
    node = (
      <p className={cn('text-[15px] text-ink', hasWarn && 'inline-flex items-center gap-1.5 font-medium text-warning')}>
        {hasWarn && <AlertTriangle size={15} aria-hidden="true" />}
        {label || '—'}
      </p>
    );
  }
  return (
    <div className="mjto-visit-read">
      <dt className="mb-1 text-[11px] font-bold uppercase tracking-wider text-ink-faint">{field.label}</dt>
      <dd className="m-0">
        {node}
        {hasWarn && (
          <div className="mt-1.5 flex flex-col gap-1.5">
            {warnings.map((w, i) => (
              <WarnBanner key={i} text={w.text} className="py-1.5" />
            ))}
          </div>
        )}
      </dd>
    </div>
  );
}

/* ------------------------------ Componente ------------------------------ */

/**
 * @param {{ blocks: Array, values: Object, onChange?: (key: string, value: any) => void, readOnly?: boolean,
 *   paymentMethods?: string[], visitedAt?: 'YYYY-MM-DD', warnings?: Array<{field,label,option,text}>,
 *   errors?: Record<string,string>, showEmpty?: boolean, className? }} props
 */
export default function VisitFields({
  blocks,
  values,
  onChange,
  readOnly = false,
  paymentMethods,
  visitedAt,
  warnings,
  errors,
  showEmpty = false,
  className,
}) {
  const store = usePanel();
  const methods = Array.isArray(paymentMethods) ? paymentMethods : store.paymentMethods;
  const vals = values || {};
  const list = useMemo(() => (Array.isArray(blocks) ? blocks.filter((b) => b && b.key) : []), [blocks]);
  const fields = useMemo(() => flattenFields(list), [list]);

  const depositFlag = fields.find((f) => f.role === 'deposit_flag') || null;
  const depositOn = depositFlag ? toBool(vals[depositFlag.key]) : true;

  const warnList = useMemo(
    () => (readOnly && Array.isArray(warnings) ? warnings : computeWarnings(list, vals, methods)),
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [readOnly, warnings, list, vals, methods]
  );
  const warnByField = useMemo(() => {
    const map = {};
    warnList.forEach((w) => {
      if (!w || !w.field) return;
      (map[w.field] = map[w.field] || []).push(w);
    });
    return map;
  }, [warnList]);

  const set = (key, value) => onChange?.(key, value);

  const visibleFields = (block) => {
    const fs = (Array.isArray(block.fields) ? block.fields : []).filter((f) => f && f.key);
    if (!readOnly) return fs;
    return fs.filter((f) => {
      if (f.role === 'deposit' && !depositOn) return false;
      return showEmpty || !isEmptyValue(normalizeValue(f, vals[f.key]));
    });
  };

  return (
    <div className={cn('mjto-visit-fields flex flex-col', readOnly ? 'gap-3' : 'gap-4', className)}>
      {list.map((block) => {
        const fs = visibleFields(block);
        if (!fs.length) return null;
        return (
          <section
            key={block.key}
            className={cn('mjto-visit-block', readOnly ? 'rounded-tile bg-surface-2 p-3.5 sm:p-4' : 'mjto-card p-4 sm:p-5')}
            aria-label={block.title}
          >
            {block.title && (
              <h3 className={cn('font-display font-semibold tracking-tight text-ink', readOnly ? 'mb-3 text-sm' : 'mb-4 text-[15px]')}>{block.title}</h3>
            )}
            <div className={cn('grid grid-cols-1 sm:grid-cols-2', readOnly ? 'gap-3 sm:gap-4' : 'gap-4')} {...(readOnly ? { role: 'list' } : {})}>
              {fs.map((f) => {
                const isDeposit = f.role === 'deposit';
                const disabled = isDeposit && !depositOn;
                const disabledHelp = disabled && depositFlag ? `Activá «${depositFlag.label}» para cargar la seña.` : '';
                const inner = readOnly ? (
                  <ReadField field={f} value={normalizeValue(f, vals[f.key])} methods={methods} warnings={warnByField[f.key] || []} formatPrice={store.formatPrice} />
                ) : (
                  <EditField
                    field={f}
                    value={vals[f.key]}
                    onChange={(v) => set(f.key, v)}
                    error={errors?.[f.key]}
                    methods={methods}
                    visitedAt={visitedAt}
                    warnings={warnByField[f.key] || []}
                    disabled={disabled}
                    disabledHelp={disabledHelp}
                    formatPrice={store.formatPrice}
                  />
                );
                return (
                  <div key={f.key} className={cn('min-w-0', f.width === 'half' ? '' : 'sm:col-span-2', disabled && 'opacity-60')} data-field={f.key} data-role={f.role || undefined}>
                    {readOnly ? <dl className="m-0">{inner}</dl> : inner}
                  </div>
                );
              })}
            </div>
          </section>
        );
      })}
    </div>
  );
}

export { VisitFields };
