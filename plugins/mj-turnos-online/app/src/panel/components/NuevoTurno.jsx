/**
 * "Nuevo turno" (modal / hoja): clienta (buscar o nueva), servicio (o "Otro"
 * con nombre y duración libres), fecha + horario (GET /panel/availability con
 * ignore_notice) o "Otro horario" (force), precio, estado inicial (según
 * confirm_mode, editable), notas internas y aviso por email.
 * POST /panel/appointments → onCreated(turno).
 *
 * @param {{ open: boolean, onClose: () => void, client?: object (clienta fija: no se muestra el buscador),
 *   date?: string ('YYYY-MM-DD' inicial), onCreated?: (appointment) => void }} props
 */
import { useEffect, useMemo, useRef, useState } from 'react';
import { Plus } from 'lucide-react';
import { panelApi } from '@/lib/api';
import { dateLabel, formatTime, fullName, initials, prettyPhone } from '@/lib/format';
import { isDateStr, isTimeStr } from '@/lib/dates';
import { Button, Checkbox, FieldRow, Input, Modal, MoneyInput, SectionTitle, Select, Textarea, useToast } from '@/ui';
import { usePanel } from '../store';
import { ClientPicker, clientPayload, validateClientDraft } from './ClientPicker';
import { DateTimePicker } from './Reprogramar';

const OTHER = 'other';
const num = (v) => (Number.isFinite(Number(v)) ? Number(v) : 0);

export function NuevoTurno({ open, onClose, client: fixedClient = null, date: initialDate = '', onCreated }) {
  const store = usePanel();
  const toast = useToast();

  const [client, setClient] = useState(null);
  const [svcKey, setSvcKey] = useState('');
  const [otherName, setOtherName] = useState('');
  const [duration, setDuration] = useState(store.schedule.defaultDurationMin);
  const [date, setDate] = useState('');
  const [time, setTime] = useState('');
  const [free, setFree] = useState(false);
  const [price, setPrice] = useState('');
  const [status, setStatus] = useState('pending');
  const [notes, setNotes] = useState('');
  const [notify, setNotify] = useState(true);
  const [saving, setSaving] = useState(false);
  const [reloadKey, setReloadKey] = useState(0);
  const [errors, setErrors] = useState({});
  const statusTouched = useRef(false);

  const services = store.activeServices;
  const service = svcKey && svcKey !== OTHER ? store.serviceById(svcKey) : null;
  const isOther = svcKey === OTHER;

  // Reset al abrir.
  useEffect(() => {
    if (!open) return;
    setClient(fixedClient || null);
    const only = services.length === 1 ? String(services[0].id) : '';
    setSvcKey(only);
    setOtherName('');
    setDuration(store.schedule.defaultDurationMin);
    setDate(isDateStr(initialDate) ? initialDate : store.today);
    setTime('');
    setFree(false);
    const svc = only ? store.serviceById(only) : null;
    setPrice(svc ? num(svc.price) : '');
    setStatus(store.initialStatus(svc ? store.depositFor(svc) : 0));
    statusTouched.current = false;
    setNotes('');
    setNotify(true);
    setSaving(false);
    setReloadKey(0);
    setErrors({});
  }, [open]); // eslint-disable-line react-hooks/exhaustive-deps

  const serviceOptions = useMemo(
    () => [
      ...services.map((s) => ({
        value: String(s.id),
        label: `${s.name} · ${store.durationLabel(s.duration)}${num(s.price) > 0 ? ` · ${store.formatPrice(s.price)}` : ''}${s.bookable === false ? ' · sólo panel' : ''}`,
      })),
      { value: OTHER, label: 'Otro (turno libre)' },
    ],
    [services, store]
  );

  const statusOptions = useMemo(
    () => store.statuses.filter((s) => ['pending', 'awaiting_deposit', 'confirmed'].includes(s.key)).map((s) => ({ value: s.key, label: s.label })),
    [store.statuses]
  );

  const onServiceChange = (e) => {
    const key = e.target.value;
    setSvcKey(key);
    setTime('');
    const svc = key && key !== OTHER ? store.serviceById(key) : null;
    if (svc) {
      setPrice(num(svc.price));
      setDuration(num(svc.duration) || store.schedule.defaultDurationMin);
    } else if (key === OTHER) {
      setPrice('');
      setDuration(store.schedule.defaultDurationMin);
    }
    if (!statusTouched.current) setStatus(store.initialStatus(svc ? store.depositFor(svc) : 0));
    setErrors((er) => ({ ...er, service: '' }));
  };

  const depositHint = useMemo(() => {
    if (store.confirmMode !== 'deposit') return '';
    const d = service ? store.depositFor(service) : isOther ? store.depositFor(num(price)) : 0;
    return d > 0 ? `Seña: ${store.formatPrice(d)}` : 'Sin seña';
  }, [store, service, isOther, price]);

  const clientEmail = String(client?.email || '').trim();

  const validate = () => {
    const er = {};
    const cErr = validateClientDraft(client);
    if (cErr) er.client = cErr;
    if (!svcKey) er.service = 'Elegí un servicio.';
    if (isOther && !(num(duration) > 0)) er.duration = 'Cargá la duración en minutos.';
    if (!isDateStr(date)) er.date = 'Elegí un día.';
    if (!isTimeStr(time)) er.time = free ? 'Cargá una hora válida.' : 'Elegí un horario.';
    setErrors(er);
    return !Object.keys(er).length;
  };

  const submit = async () => {
    if (saving) return;
    if (!validate()) return;
    setSaving(true);
    try {
      const payload = {
        ...clientPayload(client),
        date,
        time,
        price: num(price),
        status,
        internal_notes: notes.trim(),
        notify: !!notify && !!clientEmail,
        force: !!free,
      };
      if (isOther) {
        payload.service_id = 0;
        payload.service_name = otherName.trim();
        payload.duration_min = Math.max(5, Math.round(num(duration)));
      } else {
        payload.service_id = Number(svcKey);
      }
      const created = await panelApi.createAppointment(payload);
      toast.success(`Turno creado: ${dateLabel(date)} a las ${formatTime(time)}.`);
      store.refresh().catch(() => {});
      onCreated?.(created);
      onClose?.();
    } catch (e) {
      toast.error(e);
      if (e?.status === 409) {
        setTime('');
        setReloadKey((k) => k + 1);
        setErrors((er) => ({ ...er, time: e.message }));
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
      title="Nuevo turno"
      description={fixedClient ? `Para ${fullName(fixedClient)}` : undefined}
      dismissible={!saving}
      footer={
        <>
          <Button variant="ghost" onClick={onClose} disabled={saving}>
            Cancelar
          </Button>
          <Button icon={Plus} onClick={submit} loading={saving}>
            Crear turno
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-6">
        {/* Clienta */}
        <section className="mjto-newappt__client">
          <SectionTitle title="Clienta" />
          {fixedClient ? (
            <div className="flex items-center gap-3 rounded-tile border border-line bg-surface-2 p-3">
              <span className="grid size-10 shrink-0 place-items-center rounded-full bg-accent-soft text-sm font-bold text-accent" aria-hidden="true">
                {initials(fullName(fixedClient))}
              </span>
              <div className="min-w-0 flex-1">
                <p className="truncate font-semibold text-ink">{fullName(fixedClient)}</p>
                <p className="truncate text-sm text-ink-soft">
                  {prettyPhone(fixedClient.phoneRaw || fixedClient.phone)}
                  {fixedClient.locality ? ` · ${fixedClient.locality}` : ''}
                </p>
              </div>
            </div>
          ) : (
            <ClientPicker
              value={client}
              onChange={(v) => {
                setClient(v);
                setErrors((er) => ({ ...er, client: '' }));
              }}
              allowNew
              label=""
              autoFocus
              error={errors.client}
            />
          )}
        </section>

        {/* Servicio */}
        <section className="mjto-newappt__service flex flex-col gap-3">
          <SectionTitle title="Servicio" />
          <Select
            label="Servicio"
            required
            value={svcKey}
            onChange={onServiceChange}
            placeholder="Elegí un servicio"
            options={serviceOptions}
            error={errors.service}
            help={service ? `${store.durationLabel(service.duration)}${depositHint ? ` · ${depositHint}` : ''}` : depositHint}
          />
          {isOther && (
            <FieldRow>
              <Input label="Nombre del servicio" value={otherName} onChange={(e) => setOtherName(e.target.value)} placeholder="Ej: Retoque, consulta…" autoComplete="off" />
              <Input
                label="Duración (minutos)"
                required
                type="number"
                inputMode="numeric"
                min={5}
                step={5}
                value={duration}
                onChange={(e) => {
                  setDuration(e.target.value);
                  setErrors((er) => ({ ...er, duration: '' }));
                }}
                error={errors.duration}
              />
            </FieldRow>
          )}
        </section>

        {/* Fecha y hora */}
        <section className="mjto-newappt__when">
          <SectionTitle title="Día y horario" />
          <DateTimePicker
            serviceId={service ? service.id : 0}
            durationMin={service ? 0 : Number(duration) || 0}
            date={date}
            time={time}
            onDateChange={(d) => {
              setDate(d);
              setErrors((er) => ({ ...er, date: '', time: '' }));
            }}
            onTimeChange={(t) => {
              setTime(t);
              setErrors((er) => ({ ...er, time: '' }));
            }}
            free={free}
            onFreeChange={setFree}
            reloadKey={reloadKey}
          />
          {isOther && !free && <p className="mt-2 text-xs text-ink-faint">Para un turno libre los horarios se calculan con la duración por defecto; si necesitás otra, activá «Otro horario».</p>}
          {(errors.date || errors.time) && (
            <p className="mjto-field__error mt-2 text-xs font-medium text-danger" role="alert">
              {errors.date || errors.time}
            </p>
          )}
        </section>

        {/* Detalles */}
        <section className="mjto-newappt__details flex flex-col gap-3">
          <SectionTitle title="Detalles" />
          <FieldRow>
            <MoneyInput label="Precio" value={price} onChange={setPrice} min={0} currency={store.currency} />
            <Select
              label="Estado inicial"
              value={status}
              onChange={(e) => {
                statusTouched.current = true;
                setStatus(e.target.value);
              }}
              options={statusOptions}
              help={status === 'confirmed' ? 'Se crea la ficha de la visita al confirmar.' : status === 'awaiting_deposit' ? 'Queda a la espera de la seña.' : 'Queda pendiente hasta que lo confirmes.'}
            />
          </FieldRow>
          <Textarea label="Notas internas" help="Sólo las ves vos." value={notes} onChange={(e) => setNotes(e.target.value)} rows={2} placeholder="Ej: viene con el pelo lavado" />
          <Checkbox
            checked={notify && !!clientEmail}
            onChange={setNotify}
            label="Avisar a la clienta por email"
            description={clientEmail ? `Se manda a ${clientEmail} según el estado del turno.` : 'Sin email cargado no se manda nada; avisale por WhatsApp.'}
            disabled={!clientEmail}
          />
        </section>
      </div>
    </Modal>
  );
}

export default NuevoTurno;
