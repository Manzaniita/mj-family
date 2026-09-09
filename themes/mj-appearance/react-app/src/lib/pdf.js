/**
 * Generación de PDFs de consulta/presupuesto (lado cliente).
 * jspdf se importa dinámicamente: no pesa en el bundle inicial.
 */
import { formatPrice } from './format';
import { effectivePrice } from './api';
import { SITE } from './config';

const MARGIN = 20;
const PAGE_WIDTH = 210; // A4 en mm
const PAGE_BOTTOM = 275;

/**
 * rows: [{ label, detail?, amount? }] — label en negrita, detail gris chico,
 * amount alineado a la derecha (string ya formateado).
 */
export async function downloadQuotePdf({ title, rows = [], total = null, info = '', filename = 'presupuesto.pdf' }) {
  const { jsPDF } = await import('jspdf');
  const doc = new jsPDF();
  let y = MARGIN;

  const ensureSpace = (needed = 10) => {
    if (y + needed > PAGE_BOTTOM) {
      doc.addPage();
      y = MARGIN;
    }
  };

  /* Encabezado */
  doc.setFont('helvetica', 'bold');
  doc.setFontSize(18);
  doc.text(SITE.name || 'Tienda', MARGIN, y);
  doc.setFontSize(10);
  doc.setFont('helvetica', 'normal');
  doc.setTextColor(120);
  doc.text(new Date().toLocaleDateString(SITE.locale || 'es-AR'), PAGE_WIDTH - MARGIN, y, { align: 'right' });
  y += 9;

  doc.setFontSize(14);
  doc.setFont('helvetica', 'bold');
  doc.setTextColor(0);
  ensureSpace();
  doc.text(title, MARGIN, y);
  y += 4;
  doc.setDrawColor(200);
  doc.line(MARGIN, y, PAGE_WIDTH - MARGIN, y);
  y += 8;

  /* Ítems */
  doc.setFontSize(11);
  rows.forEach((row) => {
    ensureSpace(14);
    doc.setFont('helvetica', 'bold');
    doc.setTextColor(0);
    const label = doc.splitTextToSize(row.label, PAGE_WIDTH - MARGIN * 2 - 40);
    doc.text(label, MARGIN, y);
    if (row.amount) {
      doc.text(String(row.amount), PAGE_WIDTH - MARGIN, y, { align: 'right' });
    }
    y += label.length * 5;
    if (row.detail) {
      doc.setFont('helvetica', 'normal');
      doc.setTextColor(110);
      doc.setFontSize(9);
      const detail = doc.splitTextToSize(row.detail, PAGE_WIDTH - MARGIN * 2 - 40);
      doc.text(detail, MARGIN, y);
      y += detail.length * 4 + 1;
      doc.setFontSize(11);
    }
    y += 3;
  });

  /* Total */
  if (total != null) {
    ensureSpace(14);
    y += 2;
    doc.setDrawColor(200);
    doc.line(MARGIN, y, PAGE_WIDTH - MARGIN, y);
    y += 8;
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(13);
    doc.setTextColor(0);
    doc.text('Total', MARGIN, y);
    doc.text(formatPrice(total), PAGE_WIDTH - MARGIN, y, { align: 'right' });
    y += 8;
  }

  /* Información del presupuesto (condiciones, validez, etc.) */
  if (info) {
    ensureSpace(20);
    y += 4;
    doc.setFont('helvetica', 'normal');
    doc.setFontSize(9);
    doc.setTextColor(110);
    const text = doc.splitTextToSize(info, PAGE_WIDTH - MARGIN * 2);
    text.forEach((line) => {
      ensureSpace(5);
      doc.text(line, MARGIN, y);
      y += 4.5;
    });
  }

  doc.save(filename);
}

/** Filas PDF desde ítems del carrito: "2 × Nombre" + unitario y subtotal. */
export const cartRows = (items) =>
  items.map((i) => {
    const unit = effectivePrice(i.product);
    return {
      label: `${i.qty} × ${i.product.name}`,
      detail: `${formatPrice(unit)} c/u`,
      amount: formatPrice(unit * i.qty),
    };
  });

/** Filas PDF desde productos sueltos (favoritos, comparador). */
export const productRows = (products) =>
  products.map((p) => ({
    label: p.name,
    detail: productLink(p),
    amount: formatPrice(effectivePrice(p)),
  }));

const productLink = (p) =>
  `${typeof location !== 'undefined' ? location.origin : ''}/producto/${p.slug}`;
