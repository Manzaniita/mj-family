/**
 * Bloqueo de agenda (vacaciones, feriado, franja): modal con fecha, hasta
 * (opcional), todo el día o desde/hasta hora y motivo.
 *
 *   <BlockForm open={open} onClose={() => setOpen(false)} onSaved={(block) => …} date="2026-09-12" />
 *
 * POST /panel/blocks { date, date_to, all_day, from_time, to_time, reason } → onSaved(block) + onClose().
 * Las horas por defecto salen del horario semanal del día elegido.
 */
import { useEffect, useMemo, useState } from 'react';
import { useToast, Button, DateInput, FieldRow, Input, Modal, Toggle } from '@/ui';
import { panelApi } from '@/lib/api';
import { dateLabel } from '@/lib/format';
import { isDateStr, isoDow, isTimeStr, parseRanges, timeToMinutes, daysBetween } from '@/lib/dates';
import { useUid } from '@/lib/a11y';
import { usePanel } from '../store';

const FALLBACK_RANGE = ['09:00', '18:00'];

/** Primer inicio y último fin del horario semanal de esa fecha (o el fallback). */
function dayBounds(weeklyHours, date) {
  const dow = isoDow(date);
  const ranges = parseRanges(weeklyHours?.[dow] ?? weeklyHours?.[String(dow)] ?? '');
  if (!ranges.length) return FALLBACK_RANGE;
  return [ranges[0][0], ranges[ranges.length - 1][1]];
}

/**
 * @param {{ open: boolean, onClose: () => void, onSaved?: (block: object) => void, date?: 'YYYY-MM-DD' }} props
 */
export default function BlockForm({ open, onClose, onSaved, date }) {
  const store = usePanel();
  const toast = useToast();
  const formId = useUid('mjto-block-form');

  const initial = useMemo(() => {
    const d = isDateStr(date) ? date : store.today;
    const [from, to] = dayBounds(store.schedule.weeklyHours, d);
    return { date: d, dateTo: '', allDay: true, fromTime: from, toTime: to, reason: '' };
  }, [date, store.today, store.schedule.weeklyHours]);

  const [form, setForm] = useState(initial);
  const [errors, setErrors] = useState({});
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (open) {
      setForm(initial);
      setErrors({});
      setSaving(false);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  const set = (key, value) => setForm((f) => ({ ...f, [key]: value }));

  // Al cambiar la fecha (con todo el día apagado) proponemos las horas de ese día.
  const setDate = (value) => {
    setForm((f) => {
      const next = { ...f, date: value };
      if (isDateStr(value)) {
        const [from, to] = dayBounds(store.schedule.weeklyHours, value);
        const untouched = f.fromTime === initial.fromTime && f.toTime === initial.toTime;
        if (untouched) {
          next.fromTime = from;
          next.toTime = to;
        }
      }
      return next;
    });
  };

  const validate = () => {
    const next = {};
    if (!isDateStr(form.date)) next.date = 'Elegí una fecha.';
    if (form.dateTo && !isDateStr(form.dateTo)) next.dateTo = 'Fecha inválida.';
    if (isDateStr(form.date) && isDateStr(form.dateTo) && form.dateTo < form.date) next.dateTo = 'Tiene que ser igual o posterior a la fecha de inicio.';
    if (!form.allDay) {
      if (!isTimeStr(form.fromTime)) next.fromTime = 'Hora inválida.';
      if (!isTimeStr(form.toTime)) next.toTime = 'Hora inválida.';
      if (isTimeStr(form.fromTime) && isTimeStr(form.toTime) && timeToMinutes(form.fromTime) >= timeToMinutes(form.toTime) && (!form.dateTo || form.dateTo === form.date)) {
        next.toTime = 'Tiene que ser posterior a la hora de inicio.';
      }
    }
    setErrors(next);
    return Object.keys(next).length === 0;
  };

  const submit = async (e) => {
    e?.preventDefault?.();
    if (saving || !validate()) return;
    const payload = {
      date: form.date,
      date_to: form.dateTo || form.date,
      all_day: form.allDay,
      from_time: form.allDay ? '' : form.fromTime,
      to_time: form.allDay ? '' : form.toTime,
      reason: form.reason.trim(),
    };
    setSaving(true);
    try {
      const block = await panelApi.createBlock(payload);
      toast.success('Bloqueo guardado.');
      onSaved?.(block);
      onClose?.();
    } catch (err) {
      toast.error(err);
    } finally {
      setSaving(false);
    }
  };

  const days = isDateStr(form.date) && isDateStr(form.dateTo) ? daysBetween(form.date, form.dateTo) + 1 : 1;
  const summary = isDateStr(form.date)
    ? form.dateTo && form.dateTo !== form.date
      ? `Del ${dateLabel(form.date)} al ${dateLabel(form.dateTo, { year: true })} (${days} días)`
      : `${dateLabel(form.date, { year: true })}${form.allDay ? ', todo el día' : `, de ${form.fromTime} a ${form.toTime}`}`
    : '';

  return (
    <Modal
      open={open}
      onClose={saving ? () => {} : onClose}
      title="Nuevo bloqueo"
      description="Ese rango no se va a poder reservar desde la web ni desde el panel."
      size="sm"
      dismissible={!saving}
      footer={
        <>
          <Button variant="ghost" onClick={onClose} disabled={saving}>
            Cancelar
          </Button>
          <Button type="submit" form={formId} loading={saving}>
            Bloquear
          </Button>
        </>
      }
    >
      <form id={formId} onSubmit={submit} noValidate className="flex flex-col gap-4 pt-1">
        <FieldRow>
          <DateInput label="Desde" required value={form.date} onChange={setDate} error={errors.date} />
          <DateInput label="Hasta" help="Opcional, para varios días." value={form.dateTo} min={form.date || undefined} onChange={(v) => set('dateTo', v)} error={errors.dateTo} clearable />
        </FieldRow>
        <Toggle checked={form.allDay} onChange={(v) => set('allDay', v)} label="Todo el día" description="Apagalo para bloquear sólo una franja horaria." />
        {!form.allDay && (
          <FieldRow>
            <Input label="Desde las" type="time" step={300} required value={form.fromTime} error={errors.fromTime} onChange={(e) => set('fromTime', e.target.value)} className="mjto-tabular" />
            <Input label="Hasta las" type="time" step={300} required value={form.toTime} error={errors.toTime} onChange={(e) => set('toTime', e.target.value)} className="mjto-tabular" />
          </FieldRow>
        )}
        <Input label="Motivo" placeholder="Vacaciones, feriado, turno médico…" value={form.reason} maxLength={190} onChange={(e) => set('reason', e.target.value)} />
        {summary && <p className="rounded-tile bg-surface-2 px-3.5 py-2.5 text-sm text-ink-soft">{summary}</p>}
      </form>
    </Modal>
  );
}

export { BlockForm };
