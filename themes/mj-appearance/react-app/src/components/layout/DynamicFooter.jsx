import { FOOTER_BLOCKS } from './BlockRegistry';
import { WP } from '@/lib/config';

/**
 * Renderizador de bloque individual en pie de página.
 */
function renderFooterBlock(block) {
  if (!block || !block.type) return null;
  const Component = FOOTER_BLOCKS[block.type];
  if (!Component) return null;

  const style = {};
  if (block.dimensions) {
    if (block.dimensions.width) style.width = block.dimensions.width;
    if (block.dimensions.marginLeft) style.marginLeft = block.dimensions.marginLeft;
    if (block.dimensions.marginRight) style.marginRight = block.dimensions.marginRight;
  }

  return (
    <div key={block.id} className={`mj-footer-block mj-footer-block--${block.type} w-full`} style={Object.keys(style).length ? style : undefined}>
      <Component {...(block.settings || {})} />
    </div>
  );
}

/**
 * Renderizador de una fila individual de pie de página.
 */
function FooterRow({ row }) {
  if (!row || row.enabled === false) return null;

  const zones = row.zones || {};
  const hasLeft = Array.isArray(zones.left) && zones.left.length > 0;
  const hasCenter = Array.isArray(zones.center) && zones.center.length > 0;
  const hasRight = Array.isArray(zones.right) && zones.right.length > 0;

  // Zero DOM waste: si no hay bloques en ninguna zona, no renderiza nada
  if (!hasLeft && !hasCenter && !hasRight) return null;

  const rowStyle = row.style || {};
  const inlineStyles = {};

  if (rowStyle.bgColor) inlineStyles.backgroundColor = rowStyle.bgColor;
  if (rowStyle.textColor) inlineStyles.color = rowStyle.textColor;
  if (rowStyle.minHeight) inlineStyles.minHeight = rowStyle.minHeight;
  if (rowStyle.borderBottom) inlineStyles.borderBottom = rowStyle.borderBottom;
  if (rowStyle.borderTop) inlineStyles.borderTop = rowStyle.borderTop;
  if (rowStyle.paddingX) {
    inlineStyles.paddingLeft = rowStyle.paddingX;
    inlineStyles.paddingRight = rowStyle.paddingX;
  }
  if (rowStyle.paddingY) {
    inlineStyles.paddingTop = rowStyle.paddingY;
    inlineStyles.paddingBottom = rowStyle.paddingY;
  }

  // Si solo hay zona centro (ej. newsletter o barra completa)
  const isSingleCenter = hasCenter && !hasLeft && !hasRight;

  return (
    <div
      className="mj-footer-row w-full transition-colors"
      style={Object.keys(inlineStyles).length ? inlineStyles : undefined}
    >
      <div className="mx-auto w-full max-w-7xl px-4 md:px-8">
        {isSingleCenter ? (
          <div className="mj-zone mj-zone--center flex w-full justify-center">
            {zones.center.map(renderFooterBlock)}
          </div>
        ) : (
          <div className="flex flex-col md:flex-row items-start justify-between gap-8 md:gap-12 w-full">
            {hasLeft && (
              <div className="mj-zone mj-zone--left flex flex-col gap-4 max-w-md w-full md:w-auto shrink-0">
                {zones.left.map(renderFooterBlock)}
              </div>
            )}

            {hasCenter && (
              <div className="mj-zone mj-zone--center flex-1 w-full">
                {zones.center.map(renderFooterBlock)}
              </div>
            )}

            {hasRight && (
              <div className="mj-zone mj-zone--right flex flex-col gap-4 w-full md:w-auto shrink-0 items-start md:items-end">
                {zones.right.map(renderFooterBlock)}
              </div>
            )}
          </div>
        )}
      </div>
    </div>
  );
}

/**
 * Motor Dinámico de Pie de Página (Dynamic Footer).
 */
export function DynamicFooter({ layout: propLayout }) {
  const layout = propLayout || WP?.footerLayout;

  if (!layout) return null;

  const rows = layout.desktop?.rows || [];
  if (!rows.length) return null;

  return (
    <footer className="mj-dynamic-footer w-full bg-bg-soft/50 border-t border-line-subtle mt-28">
      {rows.map((row) => (
        <FooterRow key={row.id} row={row} />
      ))}
    </footer>
  );
}
