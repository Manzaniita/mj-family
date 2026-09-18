/**
 * Reprogramar un turno desde su vista de estado (o elegir otro día cuando la
 * dueña canceló y ofrece opciones).
 *
 * Reusa el paso de día y hora del wizard: la disponibilidad sale de
 * GET /availability?token= (duración del turno, sin contar su propio horario) y
 * confirma con POST /bookings/{token}/reschedule. Antes de confirmar se ve
 * rescheduleTerms.text (qué pasa con la seña). Con mode 'new' el servidor
 * devuelve otro token: lo resuelve quien llama (onDone).
 */
import { useState } from 'react';
import { CalendarClock } from 'lucide-react';
import { publicApi } from '@/lib/api';
import { monthKey } from '@/lib/dates';
import { Alert, useBooking } from './shared';
import DateStep, { useAvailability } from './steps/DateStep';

/**
 * @param {{ token: string, terms?: { text?: string, late?: boolean }, onCancel: () => void,
 *   onDone: (view: object, picked: { date: string, time: string }) => void }} props
 */
export default function RescheduleView({ token, terms, onCancel, onDone }) {
  const { t, cfg } = useBooking();
  const [date, setDate] = useState('');
  const [time, setTime] = useState('');
  const [notice, setNotice] = useState('');
  const [error, setError] = useState('');
  const [saving, setSaving] = useState(false);
  const availability = useAvailability(0, cfg.schedule, token);
  const text = String(terms?.text || '');

  const confirm = async () => {
    if (!date || !time || saving) return;
    setSaving(true);
    setError('');
    try {
      const res = await publicApi.rescheduleBooking(token, date, time);
      onDone(res || {}, { date, time });
    } catch (e) {
      if (e && (e.code === 'mjto_slot_taken' || e.status === 409)) {
        availability.invalidate(monthKey(date));
        setTime('');
        setNotice(e.message || '');
      } else {
        setError(e?.message || '');
      }
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="mjto-booking__reschedule animate-fade">
      <h4 className="mb-3 font-display text-lg font-semibold tracking-tight text-ink">{t('booking_reschedule_title')}</h4>
      {text && (
        <Alert tone={terms?.late ? 'warning' : 'info'} className="mb-4">
          {text}
        </Alert>
      )}
      <DateStep
        date={date}
        time={time}
        onDateChange={(d) => {
          setDate(d);
          setNotice('');
        }}
        onTimeChange={setTime}
        onBack={onCancel}
        onNext={confirm}
        notice={notice}
        availability={availability}
        nextLabel={t('booking_reschedule_confirm')}
        nextIcon={CalendarClock}
        nextLoading={saving}
      />
      {error && <Alert tone="danger" className="mt-4">{error}</Alert>}
    </div>
  );
}
