/**
 * Constructor de campos (Turnos → Ajustes → Formulario / Ficha).
 *
 *  - kind='form':  lista plana de campos del formulario público
 *                  {key, label, placeholder, type, required, enabled, options, system}.
 *  - kind='ficha': bloques {key, title, fields:[{key, label, type, options, required, carry, role,
 *                  offset_days, offset_months, help, placeholder, width}]}.
 *
 * Lee el JSON inicial del <textarea> `target` (el que manda el form de WP),
 * lo edita en una lista ordenable (↑ ↓) con edición en línea y lo vuelve a
 * escribir en el textarea en cada cambio. Si alguien edita el JSON a mano
 * (edición avanzada) y queda válido, el constructor lo toma al salir del campo.
 *
 * Reglas: claves únicas (auto desde la etiqueta, estilo sanitize_key),
 * campos de sistema del formulario protegidos (no se borran; first_name y
 * phone no se desactivan ni se des-requieren), "Restaurar por defecto"
 * desde window.MJTO.defaults. Los textos de esta UI son fijos en español:
 * es el panel privado de la dueña (wp-admin).
 */
import { useEffect, useMemo, useRef, useState } from 'react';
import {
  AlertTriangle,
  ChevronDown,
  ChevronUp,
  FolderPlus,
  GripVertical,
  Info,
  Lock,
  Plus,
  RotateCcw,
  Trash2,
} from 'lucide-react';
import { BOOT } from '@/lib/config';
import { cn, slugKey } from '@/lib/format';
import { Badge, Button, ConfirmDialog, Input, Select, Textarea, Toggle } from '@/ui';

/* ---------------------------------------------------------------------------
 * Catálogos
 * ------------------------------------------------------------------------- */
const TYPE_LABELS = {
  text: 'Texto corto',
  tel: 'Teléfono',
  email: 'Email',
  textarea: 'Texto largo',
  select: 'Lista desplegable',
  number: 'Número',
  checkbox_group: 'Casillas (varias opciones)',
  radio: 'Opción única (chips)',
  money: 'Importe',
  date: 'Fecha',
  toggle: 'Sí / No',
};

/* En el formulario público la opción única se muestra como lista de opciones, no como chips. */
const FORM_TYPE_LABELS = {
  radio: 'Opciones (una sola)',
};

const typeLabel = (kind, type) => (kind === 'form' && FORM_TYPE_LABELS[type]) || TYPE_LABELS[type] || type;

const FORM_TYPES_DEFAULT = ['text', 'tel', 'email', 'textarea', 'select', 'radio', 'number', 'date'];
/* Tipos del formulario que llevan opciones. */
const FORM_OPTION_TYPES = ['select', 'radio'];
const FICHA_TYPES_DEFAULT = ['checkbox_group', 'radio', 'select', 'text', 'textarea', 'number', 'money', 'date', 'toggle'];

const ROLE_DEFS = [
  { value: '', label: 'Sin rol' },
  { value: 'total', label: 'Total cobrado (caja)', types: ['money', 'number'] },
  { value: 'deposit', label: 'Seña: monto (caja)', types: ['money', 'number'] },
  { value: 'deposit_flag', label: '¿Dejó seña? (sí / no)', types: ['toggle'] },
  { value: 'payment_method', label: 'Método de pago', types: ['radio', 'select', 'checkbox_group'] },
  { value: 'control_date', label: 'Fecha de control', types: ['date'], offsets: { offset_days: 3 } },
  { value: 'retouch_date', label: 'Fecha de retoque', types: ['date'], offsets: { offset_months: 4 } },
  { value: 'product', label: 'Producto usado', types: ['text', 'textarea', 'select', 'radio'] },
];

const OPTION_TYPES = ['checkbox_group', 'radio', 'select'];
const PLACEHOLDER_TYPES = ['text', 'textarea', 'number', 'money', 'tel', 'email'];
const LOCKED_KEYS = ['first_name', 'phone'];

/* Espejo de mjto_default_form_fields() por si BOOT.defaults no viene. */
const SYSTEM_FORM_FIELDS = [
  { key: 'first_name', label: 'Nombre', placeholder: '', type: 'text', required: true, enabled: true, options: [], system: true },
  { key: 'last_name', label: 'Apellido', placeholder: '', type: 'text', required: true, enabled: true, options: [], system: true },
  { key: 'phone', label: 'Teléfono / WhatsApp', placeholder: 'Ej: 11 5555 1234', type: 'tel', required: true, enabled: true, options: [], system: true },
  { key: 'email', label: 'Email', placeholder: 'Para mandarte la confirmación', type: 'email', required: false, enabled: true, options: [], system: true },
  { key: 'locality', label: 'Localidad', placeholder: '', type: 'text', required: true, enabled: true, options: [], system: true },
  { key: 'notes', label: '¿Algo que quieras contarnos?', placeholder: '', type: 'textarea', required: false, enabled: true, options: [], system: true },
  { key: 'birthdate', label: 'Fecha de nacimiento', placeholder: '', type: 'date', required: false, enabled: false, options: [], system: true },
];

const typeOptions = (kind) => {
  const fromBoot = BOOT.fieldTypes && Array.isArray(BOOT.fieldTypes[kind]) ? BOOT.fieldTypes[kind] : null;
  const list = fromBoot && fromBoot.length ? fromBoot : kind === 'ficha' ? FICHA_TYPES_DEFAULT : FORM_TYPES_DEFAULT;
  return list.map((v) => ({ value: v, label: typeLabel(kind, v) }));
};

const roleOptions = () => {
  const fromBoot = Array.isArray(BOOT.roles) ? BOOT.roles : null;
  if (!fromBoot || !fromBoot.length) return ROLE_DEFS;
  const known = ROLE_DEFS.filter((r) => r.value === '' || fromBoot.includes(r.value));
  fromBoot.forEach((v) => {
    if (!known.some((r) => r.value === v)) known.push({ value: v, label: v });
  });
  return known;
};

const roleDef = (role) => ROLE_DEFS.find((r) => r.value === role) || null;

/* ---------------------------------------------------------------------------
 * Normalización (espejo tolerante de mjto_normalize_*), ids internos y claves
 * ------------------------------------------------------------------------- */
let seq = 0;
const uid = () => {
  seq += 1;
  return `b${seq}`;
};

const bool = (v, d = false) => (v === undefined || v === null ? d : v === true || v === 1 || v === '1' || v === 'true');
const int = (v, d = 0, max = 100000) => {
  const n = parseInt(v, 10);
  return Number.isFinite(n) ? Math.max(0, Math.min(max, n)) : d;
};

/** Clave estilo sanitize_key: minúsculas, [a-z0-9_-], máx. 60. */
export function fieldKey(key, label = '') {
  let k = slugKey(key);
  if (!k) k = slugKey(label);
  return k.slice(0, 60);
}

/** Clave única contra un Set de claves ya usadas (campo, campo_2, campo_3…). */
export function uniqueKey(base, taken, fallback = 'campo') {
  const k = base || fallback;
  if (!taken.has(k)) return k;
  let i = 2;
  while (taken.has(`${k}_${i}`)) i += 1;
  return `${k}_${i}`;
}

function normOptions(raw) {
  const out = [];
  const seen = new Set();
  (Array.isArray(raw) ? raw : []).forEach((o) => {
    const opt = typeof o === 'string' ? { label: o } : o && typeof o === 'object' ? o : null;
    if (!opt) return;
    const label = String(opt.label ?? '').trim();
    let value = fieldKey(opt.value ?? '', label);
    if (!value && !label) return;
    if (!value) value = `op_${out.length + 1}`;
    if (seen.has(value)) return;
    seen.add(value);
    out.push({ _id: uid(), _auto: false, value, label: label || value, warn: bool(opt.warn), warn_text: String(opt.warn_text ?? '') });
  });
  return out;
}

function systemMapFrom(defaults) {
  const list = Array.isArray(defaults) && defaults.length ? defaults : SYSTEM_FORM_FIELDS;
  const map = {};
  list.forEach((f) => {
    if (f && f.key) map[f.key] = { ...f, options: [], system: true };
  });
  if (!Object.keys(map).length) SYSTEM_FORM_FIELDS.forEach((f) => (map[f.key] = f));
  return map;
}

/** Campos del formulario: los de sistema siempre están; first_name y phone quedan activos y obligatorios. */
export function normFormFields(raw, defaults) {
  const system = systemMapFrom(defaults);
  const out = [];
  const seen = new Set();
  (Array.isArray(raw) ? raw : []).forEach((f) => {
    if (!f || typeof f !== 'object') return;
    const label = String(f.label ?? '').trim();
    const key = fieldKey(f.key ?? '', label);
    if (!key || seen.has(key)) return;
    const isSystem = !!system[key];
    let type = String(f.type || 'text');
    if (isSystem) type = system[key].type;
    else if (!FORM_TYPES_DEFAULT.includes(type) && !(BOOT.fieldTypes?.form || []).includes(type)) type = 'text';
    const locked = LOCKED_KEYS.includes(key);
    seen.add(key);
    out.push({
      _id: uid(),
      _auto: false,
      _open: false,
      key,
      label: label || (isSystem ? system[key].label : key),
      placeholder: String(f.placeholder ?? ''),
      type,
      required: locked ? true : bool(f.required),
      enabled: locked ? true : bool(f.enabled, true),
      options: FORM_OPTION_TYPES.includes(type) ? normOptions(f.options) : [],
      system: isSystem,
    });
  });
  Object.keys(system).forEach((key) => {
    if (!seen.has(key)) {
      const def = system[key];
      out.push({ _id: uid(), _auto: false, _open: false, ...def, options: [], system: true });
    }
  });
  return out;
}

function normFichaField(f, seen) {
  if (!f || typeof f !== 'object') return null;
  const label = String(f.label ?? '').trim();
  const key = fieldKey(f.key ?? '', label);
  if (!key || seen.has(key)) return null;
  let type = String(f.type || 'text');
  if (!FICHA_TYPES_DEFAULT.includes(type) && !(BOOT.fieldTypes?.ficha || []).includes(type)) type = 'text';
  const role = String(f.role || '');
  seen.add(key);
  return {
    _id: uid(),
    _auto: false,
    _open: false,
    key,
    label: label || key,
    type,
    options: OPTION_TYPES.includes(type) ? normOptions(f.options) : [],
    required: bool(f.required),
    carry: bool(f.carry),
    role: ROLE_DEFS.some((r) => r.value === role) || (BOOT.roles || []).includes(role) ? role : '',
    offset_days: int(f.offset_days, 0, 3650),
    offset_months: int(f.offset_months, 0, 120),
    help: String(f.help ?? ''),
    placeholder: String(f.placeholder ?? ''),
    width: f.width === 'half' ? 'half' : 'full',
  };
}

/** Bloques de la ficha: claves únicas (bloques y campos entre todos los bloques). */
export function normFichaBlocks(raw) {
  const out = [];
  const seenBlocks = new Set();
  const seenFields = new Set();
  (Array.isArray(raw) ? raw : []).forEach((b) => {
    if (!b || typeof b !== 'object') return;
    const title = String(b.title ?? '').trim();
    let key = fieldKey(b.key ?? '', title);
    if (!key) key = `bloque_${out.length + 1}`;
    if (seenBlocks.has(key)) return;
    seenBlocks.add(key);
    const fields = (Array.isArray(b.fields) ? b.fields : []).map((f) => normFichaField(f, seenFields)).filter(Boolean);
    out.push({ _id: uid(), _auto: false, _open: true, key, title: title || key, fields });
  });
  return out;
}

const clean = (obj) => Object.fromEntries(Object.entries(obj).filter(([k]) => !k.startsWith('_')));

export function serializeForm(fields) {
  return fields.map((f) => ({
    ...clean(f),
    options: FORM_OPTION_TYPES.includes(f.type) ? f.options.map((o) => ({ value: o.value, label: o.label })) : [],
  }));
}

export function serializeFicha(blocks) {
  return blocks.map((b) => ({
    key: b.key,
    title: b.title,
    fields: b.fields.map((f) => ({
      ...clean(f),
      options: OPTION_TYPES.includes(f.type) ? f.options.map((o) => ({ value: o.value, label: o.label, warn: !!o.warn, warn_text: o.warn_text || '' })) : [],
    })),
  }));
}

function parseTarget(target) {
  if (!target) return null;
  const text = String(target.value || '').trim();
  if (!text) return null;
  try {
    return JSON.parse(text);
  } catch (e) {
    return null;
  }
}

function readInitial(kind, target, defaults) {
  const parsed = parseTarget(target);
  if (kind === 'ficha') {
    const src = Array.isArray(parsed) ? parsed : defaults && Array.isArray(defaults.ficha) ? defaults.ficha : [];
    return normFichaBlocks(src);
  }
  const src = Array.isArray(parsed) ? parsed : defaults && Array.isArray(defaults.form) ? defaults.form : SYSTEM_FORM_FIELDS;
  return normFormFields(src, defaults && defaults.form);
}

/* ---------------------------------------------------------------------------
 * Validación
 * ------------------------------------------------------------------------- */
const MSG = {
  keyEmpty: 'La clave no puede quedar vacía.',
  keyDup: 'Esta clave ya se usa en otro campo: al guardar se descarta el repetido.',
  labelEmpty: 'Poné una etiqueta.',
  titleEmpty: 'Poné un título al bloque.',
  blockKeyDup: 'Esta clave ya se usa en otro bloque.',
  noOptions: 'Agregá al menos una opción.',
  roleDup: 'Este rol ya está asignado a otro campo: el panel usa el primero.',
  roleType: (role, types) => `"${roleDef(role)?.label || role}" funciona con: ${types.map((t) => TYPE_LABELS[t] || t).join(', ')}.`,
};

function validateFields(fields, { withRoles = false } = {}) {
  const errors = {};
  const counts = {};
  const roleCounts = {};
  fields.forEach((f) => {
    counts[f.key] = (counts[f.key] || 0) + 1;
    if (withRoles && f.role) roleCounts[f.role] = (roleCounts[f.role] || 0) + 1;
  });
  const roleSeen = new Set();
  let total = 0;
  fields.forEach((f) => {
    const e = {};
    if (!f.key) e.key = MSG.keyEmpty;
    else if (counts[f.key] > 1) e.key = MSG.keyDup;
    if (!String(f.label || '').trim()) e.label = MSG.labelEmpty;
    if (OPTION_TYPES.includes(f.type) && !f.options.length && !(withRoles && f.role === 'payment_method')) e.options = MSG.noOptions;
    if (withRoles && f.role) {
      if (roleSeen.has(f.role)) e.role = MSG.roleDup;
      roleSeen.add(f.role);
      const def = roleDef(f.role);
      if (def && def.types && !def.types.includes(f.type)) e.roleType = MSG.roleType(f.role, def.types);
    }
    if (Object.keys(e).length) {
      errors[f._id] = e;
      total += Object.keys(e).length;
    }
  });
  return { errors, total };
}

function validateBlocks(blocks) {
  const blockErrors = {};
  const counts = {};
  blocks.forEach((b) => (counts[b.key] = (counts[b.key] || 0) + 1));
  let total = 0;
  blocks.forEach((b) => {
    const e = {};
    if (!b.key) e.key = MSG.keyEmpty;
    else if (counts[b.key] > 1) e.key = MSG.blockKeyDup;
    if (!String(b.title || '').trim()) e.title = MSG.titleEmpty;
    if (Object.keys(e).length) {
      blockErrors[b._id] = e;
      total += Object.keys(e).length;
    }
  });
  const flat = blocks.flatMap((b) => b.fields);
  const fields = validateFields(flat, { withRoles: true });
  return { blockErrors, fieldErrors: fields.errors, total: total + fields.total };
}

/* ---------------------------------------------------------------------------
 * UI chica
 * ------------------------------------------------------------------------- */
function Note({ tone = 'info', children, className }) {
  const Icon = tone === 'warning' ? AlertTriangle : Info;
  return (
    <div
      role={tone === 'warning' ? 'alert' : 'status'}
      className={cn(
        'flex items-start gap-2.5 rounded-tile border px-3.5 py-2.5 text-[13px] leading-snug',
        tone === 'warning' ? 'border-warning/25 bg-warning-soft text-warning' : 'border-info/20 bg-info-soft text-info',
        className
      )}
    >
      <Icon size={16} className="mt-0.5 shrink-0" aria-hidden="true" />
      <div className="min-w-0 flex-1">{children}</div>
    </div>
  );
}

function MoveButtons({ index, total, onMove, label }) {
  return (
    <div className="flex shrink-0 flex-col">
      <button
        type="button"
        onClick={() => onMove(-1)}
        disabled={index === 0}
        className="grid size-6 place-items-center rounded-md text-ink-faint hover:bg-surface-2 hover:text-ink disabled:opacity-30 disabled:hover:bg-transparent"
        aria-label={`Subir ${label}`}
      >
        <ChevronUp size={15} aria-hidden="true" />
      </button>
      <button
        type="button"
        onClick={() => onMove(1)}
        disabled={index >= total - 1}
        className="grid size-6 place-items-center rounded-md text-ink-faint hover:bg-surface-2 hover:text-ink disabled:opacity-30 disabled:hover:bg-transparent"
        aria-label={`Bajar ${label}`}
      >
        <ChevronDown size={15} aria-hidden="true" />
      </button>
    </div>
  );
}

function IconButton({ icon: Icon, label, onClick, tone = 'neutral', disabled }) {
  return (
    <button
      type="button"
      onClick={onClick}
      disabled={disabled}
      aria-label={label}
      title={label}
      className={cn(
        'grid size-8 shrink-0 place-items-center rounded-full transition-colors disabled:opacity-30',
        tone === 'danger' ? 'text-ink-faint hover:bg-danger-soft hover:text-danger' : 'text-ink-faint hover:bg-surface-2 hover:text-ink'
      )}
    >
      <Icon size={16} aria-hidden="true" />
    </button>
  );
}

/** Editor de opciones (select / radio / casillas). Con `withWarn` muestra advertencia + texto. */
function OptionsEditor({ options, onChange, withWarn = false, error }) {
  const taken = (exceptId) => new Set(options.filter((o) => o._id !== exceptId).map((o) => o.value));

  const update = (id, patch) => onChange(options.map((o) => (o._id === id ? { ...o, ...patch } : o)));
  const move = (id, dir) => {
    const i = options.findIndex((o) => o._id === id);
    const j = i + dir;
    if (i < 0 || j < 0 || j >= options.length) return;
    const next = options.slice();
    [next[i], next[j]] = [next[j], next[i]];
    onChange(next);
  };
  const remove = (id) => onChange(options.filter((o) => o._id !== id));
  const add = () => onChange([...options, { _id: uid(), _auto: true, value: uniqueKey('opcion', taken(null), 'opcion'), label: '', warn: false, warn_text: '' }]);

  const counts = {};
  options.forEach((o) => (counts[o.value] = (counts[o.value] || 0) + 1));

  return (
    <div className="mjto-builder__options sm:col-span-2">
      <p className="mb-1.5 text-[13px] font-semibold text-ink-soft">Opciones</p>
      {error && <p className="mb-2 text-xs font-medium text-danger">{error}</p>}
      <div className="flex flex-col gap-2">
        {options.map((o, i) => {
          const dup = counts[o.value] > 1;
          return (
            <div key={o._id} className="rounded-tile border border-line bg-surface-2/60 p-2.5">
              <div className="flex items-start gap-2">
                <MoveButtons index={i} total={options.length} onMove={(d) => move(o._id, d)} label="opción" />
                <div className="grid min-w-0 flex-1 grid-cols-1 gap-2 sm:grid-cols-2">
                  <Input
                    size="sm"
                    placeholder="Etiqueta (lo que se ve)"
                    aria-label="Etiqueta de la opción"
                    value={o.label}
                    onChange={(e) => {
                      const label = e.target.value;
                      const patch = { label };
                      if (o._auto) patch.value = uniqueKey(fieldKey('', label), taken(o._id), 'opcion');
                      update(o._id, patch);
                    }}
                  />
                  <Input
                    size="sm"
                    placeholder="valor"
                    aria-label="Valor interno de la opción"
                    value={o.value}
                    error={dup ? 'Valor repetido.' : !o.value ? 'Falta el valor.' : ''}
                    inputClassName="font-mono text-[13px]"
                    onChange={(e) => update(o._id, { value: fieldKey(e.target.value, ''), _auto: false })}
                  />
                </div>
                <IconButton icon={Trash2} label="Quitar opción" tone="danger" onClick={() => remove(o._id)} />
              </div>
              {withWarn && (
                <div className="mt-2 flex flex-col gap-2 pl-8">
                  <Toggle
                    size="sm"
                    checked={!!o.warn}
                    onChange={(v) => update(o._id, { warn: v })}
                    label="Advertencia"
                    description="Al elegir esta opción se muestra un aviso y queda marcado en la ficha."
                  />
                  {o.warn && (
                    <Textarea
                      rows={2}
                      placeholder="Texto del aviso (opcional: si queda vacío se arma con la etiqueta)"
                      aria-label="Texto de la advertencia"
                      value={o.warn_text}
                      onChange={(e) => update(o._id, { warn_text: e.target.value })}
                    />
                  )}
                </div>
              )}
            </div>
          );
        })}
      </div>
      <Button variant="soft" size="sm" icon={Plus} onClick={add} className="mt-2">
        Agregar opción
      </Button>
    </div>
  );
}

/* ---------------------------------------------------------------------------
 * Tarjeta de campo (form y ficha)
 * ------------------------------------------------------------------------- */
function FieldCard({ field, kind, index, total, takenKeys, errors = {}, onChange, onMove, onRemove, blocks, onMoveToBlock }) {
  const isFicha = kind === 'ficha';
  const locked = !isFicha && LOCKED_KEYS.includes(field.key);
  const types = useMemo(() => typeOptions(kind), [kind]);
  const roles = useMemo(() => roleOptions(), []);
  const hasErrors = Object.keys(errors).length > 0;
  const open = !!field._open;
  const def = roleDef(field.role);

  const onLabel = (label) => {
    const patch = { label };
    if (field._auto && !field.system) patch.key = uniqueKey(fieldKey('', label), takenKeys);
    onChange(patch);
  };
  const onKey = (key) => onChange({ key: fieldKey(key, ''), _auto: false });
  const onType = (type) => {
    const patch = { type };
    if (isFicha && !OPTION_TYPES.includes(type) && field.role === 'payment_method') patch.role = '';
    onChange(patch);
  };
  const onRole = (role) => {
    const patch = { role };
    const d = roleDef(role);
    if (d && d.types && !d.types.includes(field.type)) patch.type = d.types[0];
    if (d && d.offsets) {
      Object.entries(d.offsets).forEach(([k, v]) => {
        if (!field[k]) patch[k] = v;
      });
    }
    onChange(patch);
  };

  const summaryBadges = (
    <>
      <Badge tone="neutral" size="sm">{typeLabel(kind, field.type)}</Badge>
      {field.system && (
        <Badge tone="info" size="sm" icon={Lock}>
          Sistema
        </Badge>
      )}
      {field.required && <Badge tone="accent" size="sm">Obligatorio</Badge>}
      {!isFicha && !field.enabled && <Badge tone="warning" size="sm">Desactivado</Badge>}
      {isFicha && field.carry && <Badge tone="violet" size="sm">Precarga</Badge>}
      {isFicha && field.role && (
        <Badge tone="success" size="sm">
          {def ? def.label : field.role}
        </Badge>
      )}
      {hasErrors && (
        <Badge tone="danger" size="sm" icon={AlertTriangle}>
          Revisar
        </Badge>
      )}
    </>
  );

  return (
    <li className={cn('mjto-builder__field mb-0 rounded-tile border bg-surface shadow-card transition-colors', hasErrors ? 'border-danger/40' : 'border-line', open && 'ring-4 ring-accent/10')}>
      <div className="flex items-center gap-2 px-2.5 py-2 sm:px-3">
        <GripVertical size={16} className="hidden shrink-0 text-ink-faint sm:block" aria-hidden="true" />
        <MoveButtons index={index} total={total} onMove={onMove} label="campo" />
        <button
          type="button"
          onClick={() => onChange({ _open: !open })}
          aria-expanded={open}
          className="flex min-w-0 flex-1 flex-wrap items-center gap-x-2 gap-y-1 rounded-md py-1 text-left"
        >
          <span className={cn('font-semibold text-ink', !field.label && 'italic text-ink-faint')}>{field.label || 'Sin etiqueta'}</span>
          <code className="rounded bg-surface-2 px-1.5 py-0.5 font-mono text-[11px] text-ink-faint">{field.key || '—'}</code>
          {summaryBadges}
        </button>
        <IconButton icon={open ? ChevronUp : ChevronDown} label={open ? 'Cerrar' : 'Editar'} onClick={() => onChange({ _open: !open })} />
        {!field.system && <IconButton icon={Trash2} label="Eliminar campo" tone="danger" onClick={onRemove} />}
      </div>

      {open && (
        <div className="mjto-hairline-t grid grid-cols-1 gap-4 px-3 py-4 sm:grid-cols-2 sm:px-4 animate-fade">
          <Input label="Etiqueta" required value={field.label} error={errors.label} onChange={(e) => onLabel(e.target.value)} placeholder="Ej: Grosor" />
          <Input
            label="Clave"
            required
            value={field.key}
            error={errors.key}
            help={field.system ? 'Los campos de sistema mantienen su clave.' : 'Interna, sin espacios. Se arma sola desde la etiqueta.'}
            inputClassName="font-mono text-[13px]"
            readOnly={field.system}
            disabled={field.system}
            onChange={(e) => onKey(e.target.value)}
          />
          <Select label="Tipo" value={field.type} options={types} disabled={field.system} onChange={(e) => onType(e.target.value)} help={field.system ? 'El tipo de los campos de sistema no se cambia.' : undefined} />

          {isFicha ? (
            <Select label="Ancho" value={field.width} options={[{ value: 'full', label: 'Ancho completo' }, { value: 'half', label: 'Media columna' }]} onChange={(e) => onChange({ width: e.target.value })} />
          ) : (
            PLACEHOLDER_TYPES.includes(field.type) && (
              <Input label="Placeholder" value={field.placeholder} onChange={(e) => onChange({ placeholder: e.target.value })} placeholder="Texto de ejemplo dentro del campo" />
            )
          )}

          {isFicha && (
            <>
              <Select
                label="Rol"
                value={field.role}
                options={roles}
                error={errors.role}
                help={errors.roleType || (def && def.types ? `Conecta el campo con la caja o las fechas sugeridas. Tipos compatibles: ${def.types.map((t) => TYPE_LABELS[t] || t).join(', ')}.` : 'Conecta el campo con la caja (total, seña, método) o con las fechas sugeridas (control, retoque).')}
                onChange={(e) => onRole(e.target.value)}
              />
              {field.type === 'date' ? (
                <div className="grid grid-cols-2 gap-3">
                  <Input
                    label="Sugerir: días"
                    type="number"
                    min="0"
                    inputMode="numeric"
                    value={field.offset_days}
                    onChange={(e) => onChange({ offset_days: int(e.target.value, 0, 3650) })}
                    help="Botón Sugerir: visita + N días."
                  />
                  <Input
                    label="Sugerir: meses"
                    type="number"
                    min="0"
                    inputMode="numeric"
                    value={field.offset_months}
                    onChange={(e) => onChange({ offset_months: int(e.target.value, 0, 120) })}
                    help="Visita + N meses (se suman a los días)."
                  />
                </div>
              ) : (
                PLACEHOLDER_TYPES.includes(field.type) && (
                  <Input label="Placeholder" value={field.placeholder} onChange={(e) => onChange({ placeholder: e.target.value })} placeholder="Texto de ejemplo dentro del campo" />
                )
              )}
              <Textarea label="Ayuda" rows={2} value={field.help} onChange={(e) => onChange({ help: e.target.value })} placeholder="Texto chico debajo del campo (opcional)" className="sm:col-span-2" />
            </>
          )}

          <div className="flex flex-col gap-3 sm:col-span-2 sm:flex-row sm:flex-wrap sm:gap-6">
            <Toggle checked={!!field.required} disabled={locked} onChange={(v) => onChange({ required: v })} label="Obligatorio" description={locked ? 'Nombre y teléfono siempre son obligatorios.' : undefined} />
            {!isFicha && (
              <Toggle checked={!!field.enabled} disabled={locked} onChange={(v) => onChange({ enabled: v })} label="Activo" description={locked ? 'No se puede desactivar.' : 'Desactivado no se muestra en la reserva.'} />
            )}
            {isFicha && <Toggle checked={!!field.carry} onChange={(v) => onChange({ carry: v })} label="Precargar" description="Arranca con lo de la visita anterior." />}
          </div>

          {OPTION_TYPES.includes(field.type) && (
            <>
              {isFicha && field.role === 'payment_method' && !field.options.length && (
                <Note className="sm:col-span-2">
                  Sin opciones propias usa los métodos de pago de Ajustes
                  {Array.isArray(BOOT.paymentMethods) && BOOT.paymentMethods.length ? `: ${BOOT.paymentMethods.join(', ')}.` : '.'}
                </Note>
              )}
              <OptionsEditor options={field.options} onChange={(options) => onChange({ options })} withWarn={isFicha} error={errors.options} />
            </>
          )}

          {isFicha && blocks && blocks.length > 1 && (
            <Select
              label="Mover a otro bloque"
              value=""
              placeholder="Elegí un bloque…"
              options={blocks.map((b) => ({ value: b._id, label: b.title || b.key }))}
              onChange={(e) => e.target.value && onMoveToBlock(e.target.value)}
              className="sm:col-span-2"
            />
          )}
        </div>
      )}
    </li>
  );
}

/* ---------------------------------------------------------------------------
 * Lista plana (formulario)
 * ------------------------------------------------------------------------- */
function FormList({ fields, setFields, errors, onAskRemove }) {
  const update = (id, patch) => setFields((list) => list.map((f) => (f._id === id ? { ...f, ...patch } : f)));
  const move = (id, dir) =>
    setFields((list) => {
      const i = list.findIndex((f) => f._id === id);
      const j = i + dir;
      if (i < 0 || j < 0 || j >= list.length) return list;
      const next = list.slice();
      [next[i], next[j]] = [next[j], next[i]];
      return next;
    });
  return (
    <ul className="mjto-builder__list flex flex-col gap-2">
      {fields.map((f, i) => (
        <FieldCard
          key={f._id}
          field={f}
          kind="form"
          index={i}
          total={fields.length}
          takenKeys={new Set(fields.filter((x) => x._id !== f._id).map((x) => x.key))}
          errors={errors[f._id]}
          onChange={(patch) => update(f._id, patch)}
          onMove={(dir) => move(f._id, dir)}
          onRemove={() => onAskRemove({ type: 'field', id: f._id, label: f.label || f.key })}
        />
      ))}
    </ul>
  );
}

/* ---------------------------------------------------------------------------
 * Bloques (ficha)
 * ------------------------------------------------------------------------- */
function BlockCard({ block, index, total, blocks, errors, fieldErrors, setBlocks, onAskRemove }) {
  const open = !!block._open;
  const takenBlockKeys = new Set(blocks.filter((b) => b._id !== block._id).map((b) => b.key));
  const allFieldKeys = (exceptId) => new Set(blocks.flatMap((b) => b.fields).filter((f) => f._id !== exceptId).map((f) => f.key));

  const patchBlock = (patch) => setBlocks((list) => list.map((b) => (b._id === block._id ? { ...b, ...patch } : b)));
  const moveBlock = (dir) =>
    setBlocks((list) => {
      const i = list.findIndex((b) => b._id === block._id);
      const j = i + dir;
      if (i < 0 || j < 0 || j >= list.length) return list;
      const next = list.slice();
      [next[i], next[j]] = [next[j], next[i]];
      return next;
    });
  const updateField = (fid, patch) => patchBlock({ fields: block.fields.map((f) => (f._id === fid ? { ...f, ...patch } : f)) });
  const moveField = (fid, dir) => {
    const i = block.fields.findIndex((f) => f._id === fid);
    const j = i + dir;
    if (i < 0 || j < 0 || j >= block.fields.length) return;
    const next = block.fields.slice();
    [next[i], next[j]] = [next[j], next[i]];
    patchBlock({ fields: next });
  };
  const addField = () => {
    const field = {
      _id: uid(),
      _auto: true,
      _open: true,
      key: uniqueKey('campo', allFieldKeys(null)),
      label: '',
      type: 'text',
      options: [],
      required: false,
      carry: false,
      role: '',
      offset_days: 0,
      offset_months: 0,
      help: '',
      placeholder: '',
      width: 'full',
    };
    patchBlock({ _open: true, fields: [...block.fields, field] });
  };
  const moveFieldToBlock = (fid, targetId) => {
    if (targetId === block._id) return;
    setBlocks((list) => {
      const field = block.fields.find((f) => f._id === fid);
      if (!field) return list;
      return list.map((b) => {
        if (b._id === block._id) return { ...b, fields: b.fields.filter((f) => f._id !== fid) };
        if (b._id === targetId) return { ...b, _open: true, fields: [...b.fields, field] };
        return b;
      });
    });
  };

  const blockHasErrors = errors && Object.keys(errors).length > 0;
  const fieldsWithErrors = block.fields.filter((f) => fieldErrors[f._id]).length;

  return (
    <li className={cn('mjto-builder__block mb-0 rounded-card border bg-surface-2/50 shadow-card', blockHasErrors ? 'border-danger/40' : 'border-line')}>
      <div className="flex flex-col gap-3 p-3 sm:flex-row sm:items-start sm:p-4">
        <div className="flex items-center gap-2 sm:pt-6">
          <GripVertical size={16} className="hidden shrink-0 text-ink-faint sm:block" aria-hidden="true" />
          <MoveButtons index={index} total={total} onMove={moveBlock} label="bloque" />
        </div>
        <div className="grid min-w-0 flex-1 grid-cols-1 gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,14rem)]">
          <Input
            label="Título del bloque"
            required
            value={block.title}
            error={errors?.title}
            placeholder="Ej: Análisis de la fibra"
            onChange={(e) => {
              const title = e.target.value;
              const patch = { title };
              if (block._auto) patch.key = uniqueKey(fieldKey('', title), takenBlockKeys, 'bloque');
              patchBlock(patch);
            }}
          />
          <Input
            label="Clave"
            required
            value={block.key}
            error={errors?.key}
            inputClassName="font-mono text-[13px]"
            onChange={(e) => patchBlock({ key: fieldKey(e.target.value, ''), _auto: false })}
          />
        </div>
        <div className="flex items-center gap-1 sm:pt-6">
          <Badge tone={fieldsWithErrors ? 'danger' : 'neutral'} size="sm">
            {block.fields.length} {block.fields.length === 1 ? 'campo' : 'campos'}
          </Badge>
          <IconButton icon={open ? ChevronUp : ChevronDown} label={open ? 'Contraer bloque' : 'Expandir bloque'} onClick={() => patchBlock({ _open: !open })} />
          <IconButton icon={Trash2} label="Eliminar bloque" tone="danger" onClick={() => onAskRemove({ type: 'block', id: block._id, label: block.title || block.key, count: block.fields.length })} />
        </div>
      </div>

      {open && (
        <div className="mjto-hairline-t px-3 pb-3 pt-3 sm:px-4 sm:pb-4">
          {block.fields.length ? (
            <ul className="flex flex-col gap-2">
              {block.fields.map((f, i) => (
                <FieldCard
                  key={f._id}
                  field={f}
                  kind="ficha"
                  index={i}
                  total={block.fields.length}
                  takenKeys={allFieldKeys(f._id)}
                  errors={fieldErrors[f._id]}
                  onChange={(patch) => updateField(f._id, patch)}
                  onMove={(dir) => moveField(f._id, dir)}
                  onRemove={() => onAskRemove({ type: 'field', id: f._id, blockId: block._id, label: f.label || f.key })}
                  blocks={blocks}
                  onMoveToBlock={(targetId) => moveFieldToBlock(f._id, targetId)}
                />
              ))}
            </ul>
          ) : (
            <p className="rounded-tile border border-dashed border-line px-4 py-5 text-center text-sm text-ink-faint">Este bloque no tiene campos todavía.</p>
          )}
          <Button variant="soft" size="sm" icon={Plus} onClick={addField} className="mt-3">
            Agregar campo
          </Button>
        </div>
      )}
    </li>
  );
}

/* ---------------------------------------------------------------------------
 * Componente principal
 * ------------------------------------------------------------------------- */

/**
 * @param {{ kind: 'form'|'ficha', target: HTMLTextAreaElement|null, defaults: { form?: [], ficha?: [] }|null }} props
 */
export default function FieldsBuilder({ kind = 'form', target = null, defaults = null }) {
  const isFicha = kind === 'ficha';
  const [items, setItems] = useState(() => readInitial(kind, target, defaults));
  const [pending, setPending] = useState(null);
  const [restoreOpen, setRestoreOpen] = useState(false);
  const writingRef = useRef(false);
  const lastJsonRef = useRef('');

  /* Escribe el JSON en el textarea en cada cambio. */
  useEffect(() => {
    if (!target) return;
    const json = JSON.stringify(isFicha ? serializeFicha(items) : serializeForm(items), null, 2);
    if (json === lastJsonRef.current) return;
    lastJsonRef.current = json;
    writingRef.current = true;
    try {
      target.value = json;
      target.dispatchEvent(new Event('input', { bubbles: true }));
      target.dispatchEvent(new Event('change', { bubbles: true }));
    } finally {
      writingRef.current = false;
    }
  }, [items, target, isFicha]);

  /* Edición avanzada: si alguien toca el JSON a mano y queda válido, lo tomamos. */
  useEffect(() => {
    if (!target) return undefined;
    const onManual = () => {
      if (writingRef.current) return;
      const text = String(target.value || '');
      if (text.trim() === lastJsonRef.current.trim()) return;
      try {
        const parsed = JSON.parse(text);
        if (!Array.isArray(parsed)) return;
        setItems(isFicha ? normFichaBlocks(parsed) : normFormFields(parsed, defaults && defaults.form));
      } catch (e) {
        /* JSON inválido: no se toca nada (PHP conserva el valor anterior al guardar). */
      }
    };
    target.addEventListener('change', onManual);
    return () => target.removeEventListener('change', onManual);
  }, [target, isFicha, defaults]);

  const validation = useMemo(() => (isFicha ? validateBlocks(items) : validateFields(items)), [items, isFicha]);
  const total = validation.total;
  const hasDefaults = !!(defaults && Array.isArray(defaults[kind]) && defaults[kind].length);

  /* Enter dentro de un input enviaría el formulario de WP: lo frenamos. */
  const onKeyDown = (e) => {
    if (e.key === 'Enter' && e.target && !['TEXTAREA', 'BUTTON', 'A'].includes(e.target.tagName)) e.preventDefault();
  };

  const addFormField = () => {
    const taken = new Set(items.map((f) => f.key));
    setItems((list) => [
      ...list,
      { _id: uid(), _auto: true, _open: true, key: uniqueKey('campo', taken), label: '', placeholder: '', type: 'text', required: false, enabled: true, options: [], system: false },
    ]);
  };

  const addBlock = () => {
    const taken = new Set(items.map((b) => b.key));
    setItems((list) => [...list, { _id: uid(), _auto: true, _open: true, key: uniqueKey('bloque', taken, 'bloque'), title: '', fields: [] }]);
  };

  const confirmRemove = () => {
    if (!pending) return;
    if (pending.type === 'block') setItems((list) => list.filter((b) => b._id !== pending.id));
    else if (isFicha) setItems((list) => list.map((b) => (b._id === pending.blockId ? { ...b, fields: b.fields.filter((f) => f._id !== pending.id) } : b)));
    else setItems((list) => list.filter((f) => f._id !== pending.id));
    setPending(null);
  };

  const restore = () => {
    if (hasDefaults) setItems(isFicha ? normFichaBlocks(defaults.ficha) : normFormFields(defaults.form, defaults.form));
    setRestoreOpen(false);
  };

  const count = isFicha ? items.reduce((n, b) => n + b.fields.length, 0) : items.length;

  return (
    <div className="mjto-builder" data-kind={kind} onKeyDown={onKeyDown}>
      <div className="mjto-builder__bar mb-4 flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="font-display text-base font-semibold tracking-tight text-ink">{isFicha ? 'Bloques de la ficha' : 'Campos del formulario'}</p>
          <p className="mt-0.5 text-xs text-ink-faint">
            {isFicha ? `${items.length} ${items.length === 1 ? 'bloque' : 'bloques'} · ` : ''}
            {count} {count === 1 ? 'campo' : 'campos'}. Los cambios se guardan con el botón "Guardar cambios" de la página.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          {hasDefaults && (
            <Button variant="outline" size="sm" icon={RotateCcw} onClick={() => setRestoreOpen(true)}>
              {isFicha ? 'Restaurar la ficha por defecto' : 'Restaurar por defecto'}
            </Button>
          )}
          <Button size="sm" icon={isFicha ? FolderPlus : Plus} onClick={isFicha ? addBlock : addFormField}>
            {isFicha ? 'Agregar bloque' : 'Agregar campo'}
          </Button>
        </div>
      </div>

      {!target && (
        <Note tone="warning" className="mb-3">
          No encontré el campo oculto donde se guarda el JSON: podés editar, pero los cambios no se van a guardar. Recargá la página o revisá la pestaña.
        </Note>
      )}

      {total > 0 && (
        <Note tone="warning" className="mb-3">
          Hay {total} {total === 1 ? 'cosa' : 'cosas'} para revisar (marcadas abajo). Podés guardar igual: las claves repetidas o vacías se descartan al guardar.
        </Note>
      )}

      {isFicha ? (
        items.length ? (
          <ul className="mjto-builder__blocks flex flex-col gap-3">
            {items.map((b, i) => (
              <BlockCard
                key={b._id}
                block={b}
                index={i}
                total={items.length}
                blocks={items}
                errors={validation.blockErrors[b._id]}
                fieldErrors={validation.fieldErrors}
                setBlocks={setItems}
                onAskRemove={setPending}
              />
            ))}
          </ul>
        ) : (
          <p className="rounded-card border border-dashed border-line px-4 py-8 text-center text-sm text-ink-faint">La ficha no tiene bloques. Agregá uno o restaurá la ficha por defecto.</p>
        )
      ) : (
        <FormList fields={items} setFields={setItems} errors={validation.errors} onAskRemove={setPending} />
      )}

      {!isFicha && (
        <Note className="mt-3">
          Los campos que agregues se guardan en la ficha de la clienta (datos extra). Nombre y teléfono son siempre obligatorios; el resto de los campos de sistema se puede renombrar o desactivar.
        </Note>
      )}

      <ConfirmDialog
        open={!!pending}
        onClose={() => setPending(null)}
        onConfirm={confirmRemove}
        tone="danger"
        title={pending?.type === 'block' ? `¿Eliminar el bloque "${pending?.label}"?` : `¿Eliminar el campo "${pending?.label}"?`}
        text={
          pending?.type === 'block'
            ? pending.count
              ? `Se quitan también sus ${pending.count} ${pending.count === 1 ? 'campo' : 'campos'}. Lo ya cargado en visitas anteriores no se borra, pero deja de verse en la ficha.`
              : 'El bloque está vacío.'
            : isFicha
              ? 'Lo ya cargado en visitas anteriores no se borra, pero deja de verse en la ficha.'
              : 'Deja de pedirse en la reserva.'
        }
        confirmLabel="Eliminar"
        cancelLabel="Cancelar"
      />

      <ConfirmDialog
        open={restoreOpen}
        onClose={() => setRestoreOpen(false)}
        onConfirm={restore}
        title={isFicha ? '¿Restaurar la ficha por defecto?' : '¿Restaurar los campos por defecto?'}
        text={'Se reemplaza todo lo que hay ahora por la configuración original. Recién queda guardado cuando toques "Guardar cambios".'}
        confirmLabel="Restaurar"
        cancelLabel="Cancelar"
      />
    </div>
  );
}
