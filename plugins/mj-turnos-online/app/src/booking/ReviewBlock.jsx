/**
 * Opinión de un turno atendido (review de GET /bookings/{token}).
 *
 *  - review.can: formulario con estrellas 1–5, texto y nombre (precargado con
 *    review.name) → POST /bookings/{token}/review; se muestra el `message` que
 *    devuelve el servidor (publicada o a revisar).
 *  - review.done: lo que dejó (estrellas y texto) con el agradecimiento.
 *
 * Con ?opinar=1 en la URL (link del mail) la vista de estado trae este bloque
 * a la pantalla y le da el foco (id="opinion", el ancla del mail).
 */
import { forwardRef, useState } from 'react';
import { Send } from 'lucide-react';
import { publicApi } from '@/lib/api';
import { cn } from '@/lib/format';
import { Button, Input, Textarea } from '@/ui';
import { StarRating } from '@/ui/StarRating';
import { Alert, useBooking } from './shared';

/**
 * @param {{ token: string, review: { can, done, status, rating, body, name }, onSubmitted?: (res) => void, className? }} props
 */
const ReviewBlock = forwardRef(function ReviewBlock({ token, review, onSubmitted, className }, ref) {
  const { t } = useBooking();
  const [rating, setRating] = useState(0);
  const [body, setBody] = useState('');
  const [name, setName] = useState(() => String(review?.name || ''));
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [ratingError, setRatingError] = useState('');
  const [message, setMessage] = useState('');

  if (!review || (!review.can && !review.done)) return null;

  const submit = async (e) => {
    e.preventDefault();
    if (busy) return;
    if (rating < 1 || rating > 5) {
      setRatingError(t('review_invalid'));
      return;
    }
    setBusy(true);
    setError('');
    try {
      const res = await publicApi.submitReview(token, { rating, body: body.trim(), name: name.trim() });
      setMessage(res?.message || '');
      onSubmitted?.(res || {});
    } catch (err) {
      setError(err?.message || '');
    } finally {
      setBusy(false);
    }
  };

  const doneText = message || (review.status === 'pending' ? t('review_thanks_pending') : t('review_thanks'));

  return (
    <section
      ref={ref}
      id="opinion"
      tabIndex={-1}
      className={cn('mjto-booking__review mjto-card scroll-mt-6 p-5 outline-none', className)}
      aria-label={t('review_title')}
    >
      {review.done ? (
        <>
          <Alert tone="success">{doneText}</Alert>
          {review.rating > 0 && (
            <div className="mt-4">
              <StarRating value={review.rating} size={20} />
            </div>
          )}
          {review.body && <p className="mt-2 whitespace-pre-wrap text-sm leading-relaxed text-ink-soft">{review.body}</p>}
        </>
      ) : (
        <form onSubmit={submit} noValidate aria-busy={busy || undefined}>
          <p className="font-display text-lg font-semibold tracking-tight text-ink">{t('review_title')}</p>
          {t('review_text') && <p className="mt-1 text-sm leading-relaxed text-ink-soft">{t('review_text')}</p>}
          <div className="mt-4 flex flex-col gap-4">
            <StarRating
              value={rating}
              onChange={(n) => {
                setRating(n);
                setRatingError('');
              }}
              label={t('review_rating_label')}
              required
              error={ratingError}
              disabled={busy}
            />
            <Textarea label={t('review_body_label')} rows={4} value={body} onChange={(e) => setBody(e.target.value)} disabled={busy} />
            <Input label={t('review_name_label')} value={name} onChange={(e) => setName(e.target.value)} autoComplete="name" disabled={busy} />
          </div>
          {error && <Alert tone="danger" className="mt-4">{error}</Alert>}
          <div className="mt-5 flex justify-end">
            <Button type="submit" icon={Send} loading={busy} className="w-full sm:w-auto">
              {t('review_submit')}
            </Button>
          </div>
        </form>
      )}
    </section>
  );
});

export default ReviewBlock;
