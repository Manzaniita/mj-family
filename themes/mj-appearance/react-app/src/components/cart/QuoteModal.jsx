import { useState } from 'react';
import { FileDown, Mail, MessageCircle } from 'lucide-react';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { CONTACT, QUOTE_INFO, WHATSAPP_NUMBERS } from '@/lib/config';
import { buildMessage, cartLines, whatsappUrl, mailUrl } from '@/lib/consult';
import { downloadQuotePdf, cartRows } from '@/lib/pdf';
import { formatPrice, cn } from '@/lib/format';
import { paymentNote } from '@/lib/payments';
import { t } from '@/lib/copy';

/**
 * Presupuesto del carrito: ítems + total + la información configurada en
 * Apariencia → MJ Appearance ("Información del presupuesto"), con salida por
 * PDF, mail o WhatsApp (elegiendo vendedor si hay varios).
 */
export function QuoteModal({ open, onClose, items, total, adjustment = null }) {
  const [sellerIdx, setSellerIdx] = useState(0);
  const [pdfBusy, setPdfBusy] = useState(false);

  // Línea de ajuste por medio de pago (plugin MJ Medios de Pago): los ítems van a precio base.
  const adjText = adjustment ? `${adjustment.amount < 0 ? '- ' : '+ '}${formatPrice(Math.abs(adjustment.amount))}` : '';
  const note = [paymentNote(), QUOTE_INFO].filter(Boolean).join('\n\n');

  const message = buildMessage({
    title: t('quote_message'),
    lines: [...cartLines(items), ...(adjustment ? [`${adjustment.label}: ${adjText}`] : [])],
    total,
    footer: note || null,
  });

  const seller = WHATSAPP_NUMBERS[sellerIdx] ?? WHATSAPP_NUMBERS[0];

  const handlePdf = async () => {
    setPdfBusy(true);
    try {
      await downloadQuotePdf({
        title: t('quote_title'),
        rows: [...cartRows(items), ...(adjustment ? [{ label: adjustment.label, amount: adjText }] : [])],
        total,
        info: note,
        filename: 'presupuesto.pdf',
      });
    } finally {
      setPdfBusy(false);
    }
  };

  return (
    <Modal open={open} onClose={onClose}>
      <div className="p-7">
        <h2 className="font-display text-2xl font-semibold tracking-tight">{t('quote_title')}</h2>

        <ul className="mt-5 max-h-56 space-y-2 overflow-y-auto rounded-xl2 border border-line p-4 text-sm">
          {items.map((i) => (
            <li key={i.key ?? i.product.id} className="flex items-baseline justify-between gap-3">
              <span className="min-w-0 truncate text-ink-soft">
                {i.qty} × {i.product.name}
              </span>
              <span className="shrink-0 font-medium">
                {formatPrice((i.product.sale_price ?? i.product.price) * i.qty)}
              </span>
            </li>
          ))}
          {adjustment && (
            <li className="flex items-baseline justify-between gap-3">
              <span className="min-w-0 truncate text-ink-soft">{adjustment.label}</span>
              <span className={cn('shrink-0 font-medium', adjustment.amount < 0 && 'text-success')}>{adjText}</span>
            </li>
          )}
          <li className="flex items-baseline justify-between gap-3 border-t border-line pt-2 text-base">
            <span className="font-medium">{t('cart_total')}</span>
            <span className="font-display font-semibold">{formatPrice(total)}</span>
          </li>
        </ul>

        {note && (
          <p className="mt-4 whitespace-pre-line rounded-xl2 bg-surface-2 p-4 text-xs leading-relaxed text-ink-soft">
            {note}
          </p>
        )}

        <div className="mt-6 space-y-3">
          <Button className="w-full" variant="secondary" onClick={handlePdf} disabled={pdfBusy}>
            <FileDown size={16} /> {pdfBusy ? t('consult_pdf_busy') : t('consult_pdf')}
          </Button>

          {CONTACT.email && (
            <Button
              className="w-full"
              variant="secondary"
              onClick={() => window.open(mailUrl(CONTACT.email, t('quote_title'), message), '_self')}
            >
              <Mail size={16} /> {t('quote_send_mail')}
            </Button>
          )}

          {WHATSAPP_NUMBERS.length > 0 && (
            <div>
              {WHATSAPP_NUMBERS.length > 1 && (
                <div className="mb-3 flex flex-wrap gap-2">
                  {WHATSAPP_NUMBERS.map((s, i) => (
                    <button
                      key={s.number}
                      onClick={() => setSellerIdx(i)}
                      className={cn(
                        'mj-chip rounded-(--mj-radius-chip) border px-3.5 py-1.5 text-xs font-medium transition-colors',
                        i === sellerIdx
                          ? 'mj-chip--active border-accent bg-accent text-white'
                          : 'border-line text-ink-soft hover:text-ink'
                      )}
                    >
                      {s.name || t('consult_seller')}
                    </button>
                  ))}
                </div>
              )}
              <button
                className="mj-btn inline-flex h-11 w-full items-center justify-center gap-2 rounded-(--mj-radius-btn) bg-[#25D366] px-6 text-sm font-medium text-white transition-all duration-300 hover:bg-[#25D366]/90"
                onClick={() => window.open(whatsappUrl(seller.number, message), '_blank', 'noopener')}
              >
                <MessageCircle size={16} /> {t('quote_send_whatsapp')}{seller?.name ? ` ${t('quote_send_to')} ${seller.name}` : ''}
              </button>
            </div>
          )}
        </div>
      </div>
    </Modal>
  );
}
