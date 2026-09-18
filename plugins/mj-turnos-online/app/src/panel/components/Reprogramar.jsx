/**
 * Reprogramar un turno: calendario + horarios libres (GET /panel/availability
 * con exclude_id e ignore_notice) + "Otro horario" (hora libre → force) +
 * aviso por email. PATCH /panel/appointments/{id} { date, time, force, notify }.
 *
 * Exporta también DateTimePicker (calendario + horarios + otro horario), que
 * reutiliza NuevoTurno.
 */
import { useEffect, useState } from 'react';
import { CalendarClock } from 'lucide-react';
import { panelApi } from '@/lib/api';
import { cn, dateLabel, formatTime, fullName, timeRange } from '@/lib/format';
import { isDateStr, isoDow, isTimeStr, parseRanges } from '@/lib/dates';
import { Button, Calendar, Checkbox, Field, Input, Modal, SlotPicker, Toggle, useToast } from '@/ui';
import { usePanel } from '../store';

/** ¿El negocio abre ese día según weekly_hours? (sin datos → todos abiertos). */
export function isOpenDay(weeklyHours, date) {
  if (!weeklyHours || typeof weeklyHours !== 'object' || !Object.keys(weeklyHours).length) return true;
  const dow = isoDow(date);
  if (!dow) return false;
  const ranges = weeklyHours[dow] ?? weeklyHours[String(dow)] ?? '';
  return parseRanges(ranges).length > 0;
}

/**
 * Calendario + horarios libres + "Otro horario".
 * @param {{ serviceId?: number, durationMin?: number, excludeId?: number, date: string, time: string,
 *   onDateChange: (date) => void, onTimeChange: (time) => void, free: boolean,
 *   onFreeChange: (free: boolean) => void, reloadKey?: number, min?: string, className?,
 *   preferredTime?: string (si está libre y no hay horario elegido, se elige solo: el hueco tocado en Hoy) }} props
 */
export function DateTimePicker({ serviceId = 0, durationMin = 0, excludeId = 0, date, time, onDateChange, onTimeChange, free, onFreeChange, reloadKey = 0, min, className, preferredTime = '' }) {
  const { schedule, weekStart, today } = usePanel();
  const [slots, setSlots] = useState([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!isDateStr(date) || free) return undefined;
    const ctrl = new AbortController();
    setLoading(true);
    setError('');
    panelApi
      .getSlots(
        { serviceId: Number(serviceId) || 0, durationMin: Number(durationMin) || 0, date, excludeId: Number(excludeId) || 0, ignoreNotice: true },
        { signal: ctrl.signal }
      )
      .then((res) => {
        if (ctrl.signal.aborted) return;
        const list = Array.isArray(res?.slots) ? res.slots : [];
        setSlots(list);
        if (preferredTime && !time && list.includes(preferredTime)) onTimeChange?.(preferredTime);
      })
      .catch((e) => {
        if (ctrl.signal.aborted || e?.name === 'AbortError') return;
        setSlots([]);
        setError(e?.message || 'No se pudieron cargar los horarios.');
      })
      .finally(() => {
        if (!ctrl.signal.aborted) setLoading(false);
      });
    return () => ctrl.abort();
  }, [serviceId, durationMin, excludeId, date, free, reloadKey]);

  const dayOpen = isOpenDay(schedule.weeklyHours, date);

  return (
    <div className={cn('mjto-datetime grid grid-cols-1 gap-5 sm:grid-cols-[minmax(0,17rem)_minmax(0,1fr)]', className)}>
      <Calendar
        value={date}
        onChange={(d) => {
          onDateChange?.(d);
          if (!free) onTimeChange?.('');
        }}
        weekStart={weekStart}
        today={today}
        min={min}
        isEnabled={(d) => free || isOpenDay(schedule.weeklyHours, d)}
        compact
        className="mjto-card p-3"
      />
      <div className="min-w-0">
        <div className="mb-3 flex items-center justify-between gap-3">
          <p className="text-[13px] font-semibold text-ink-soft">
            {date ? `Horarios · ${dateLabel(date)}` : 'Elegí un día'}
          </p>
          <Toggle
            size="sm"
            checked={!!free}
            onChange={(v) => {
              onFreeChange?.(v);
              onTimeChange?.('');
            }}
            label="Otro horario"
            labelPosition="left"
          />
        </div>
        {free ? (
          <Field label="Hora" help="Se guarda aunque el horario no esté libre o el día esté cerrado." required>
            <Input type="time" step={300} value={time || ''} onChange={(e) => onTimeChange?.(e.target.value)} className="max-w-[12rem]" />
          </Field>
        ) : (
          <>
            {!dayOpen && date && !loading && (
              <p className="mb-2 text-xs text-ink-faint">Ese día figura cerrado en la agenda. Activá «Otro horario» para cargar igual.</p>
            )}
            <SlotPicker slots={slots} value={time} onChange={onTimeChange} loading={loading} emptyText={error || 'No quedan horarios libres ese día. Podés usar «Otro horario».'} />
          </>
        )}
      </div>
    </div>
  );
}

/**
 * @param {{ appointment: object, open: boolean, onClose: () => void, onChanged?: (appointment) => void }} props
 */
export function Reprogramar({ appointment, open, onClose, onChanged }) {
  const store = usePanel();
  const toast = useToast();
  const [date, setDate] = useState('');
  const [time, setTime] = useState('');
  const [free, setFree] = useState(false);
  const [notify, setNotify] = useState(true);
  const [saving, setSaving] = useState(false);
  const [reloadKey, setReloadKey] = useState(0);

  useEffect(() => {
    if (!open || !appointment) return;
    setDate(appointment.date || store.today);
    setTime('');
    setFree(false);
    setNotify(!!appointment.client?.email);
    setSaving(false);
  }, [open, appointment?.id]); // eslint-disable-line react-hooks/exhaustive-deps

  if (!appointment) return null;

  const same = date === appointment.date && time === appointment.time;
  const valid = isDateStr(date) && isTimeStr(time) && !same;

  const submit = async () => {
    if (!valid || saving) return;
    setSaving(true);
    try {
      const next = await panelApi.rescheduleAppointment(appointment.id, date, time, { force: !!free, notify: !!notify });
      toast.success(`Turno reprogramado para el ${dateLabel(date)} a las ${formatTime(time)}.`);
      store.refresh().catch(() => {});
      onChanged?.(next, { action: 'reschedule', id: appointment.id });
      onClose?.();
    } catch (e) {
      toast.error(e);
      if (e?.status === 409) {
        setTime('');
        setReloadKey((k) => k + 1);
      }
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal
      open={open}
      onClose={onClose}
      size="lg"
      title="Reprogramar turno"
      description={`${fullName(appointment.client)} · ahora ${dateLabel(appointment.date)}, ${timeRange(appointment.time, appointment.endTime)}${appointment.serviceName ? ` · ${appointment.serviceName}` : ''}`}
      dismissible={!saving}
      footer={
        <>
          <Button variant="ghost" onClick={onClose} disabled={saving}>
            Volver
          </Button>
          <Button icon={CalendarClock} onClick={submit} loading={saving} disabled={!valid}>
            {valid ? `Mover al ${dateLabel(date)} ${formatTime(time)}` : 'Reprogramar'}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-5">
        <DateTimePicker
          serviceId={appointment.serviceId}
          durationMin={appointment.durationMin}
          excludeId={appointment.id}
          date={date}
          time={time}
          onDateChange={setDate}
          onTimeChange={setTime}
          free={free}
          onFreeChange={setFree}
          reloadKey={reloadKey}
        />
        <Checkbox
          checked={notify}
          onChange={setNotify}
          label="Avisar a la clienta por email"
          description={appointment.client?.email ? `Se manda a ${appointment.client.email}.` : 'La clienta no tiene email cargado: avisale por WhatsApp.'}
          disabled={!appointment.client?.email}
        />
      </div>
    </Modal>
  );
}

export default Reprogramar;
