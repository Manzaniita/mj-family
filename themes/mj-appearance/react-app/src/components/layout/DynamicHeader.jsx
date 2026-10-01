import { HEADER_BLOCKS } from './BlockRegistry';
import { WP } from '@/lib/config';

/**
 * Renderizador de bloque individual.
 */
function renderBlock(block) {
  if (!block || !block.type) return null;
  const Component = HEADER_BLOCKS[block.type];
  if (!Component) return null;

  const style = {};
  if (block.dimensions) {
    if (block.dimensions.width) style.width = block.dimensions.width;
    if (block.dimensions.marginLeft) style.marginLeft = block.dimensions.marginLeft;
    if (block.dimensions.marginRight) style.marginRight = block.dimensions.marginRight;
  }

  return (
    <div key={block.id} className={`mj-block mj-block--${block.type}`} style={Object.keys(style).length ? style : undefined}>
      <Component {...(block.settings || {})} />
    </div>
  );
}

/**
 * Renderizador de una fila individual de cabecera.
 */
function HeaderRow({ row, isMobileView }) {
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

  const stickyClass = row.sticky ? 'sticky top-0 z-80' : 'relative';
  const glassClass = rowStyle.headerStyle === 'glass' ? 'backdrop-blur-2xl bg-bg/85' : '';

  return (
    <div
      className={`mj-header-row ${stickyClass} ${glassClass} w-full transition-colors`}
      style={Object.keys(inlineStyles).length ? inlineStyles : undefined}
    >
      <div className="mx-auto flex w-full max-w-7xl items-center justify-between gap-3 px-4 md:px-8">
        {hasLeft && (
          <div className="mj-zone mj-zone--left flex shrink-0 items-center gap-2.5 sm:gap-3">
            {zones.left.map(renderBlock)}
          </div>
        )}

        {hasCenter && (
          <div className="mj-zone mj-zone--center flex flex-1 items-center justify-center gap-2.5 sm:gap-3">
            {zones.center.map(renderBlock)}
          </div>
        )}

        {hasRight && (
          <div className="mj-zone mj-zone--right flex shrink-0 items-center justify-end gap-1.5 sm:gap-2 ml-auto">
            {zones.right.map(renderBlock)}
          </div>
        )}
      </div>
    </div>
  );
}

/**
 * Motor Dinámico de Cabecera (Dynamic Header).
 *
 * Lee la composición de filas y zonas declaradas en el esquema JSON sin
 * ninguna estructura ni posiciones cableadas.
 */
export function DynamicHeader({ layout: propLayout }) {
  const layout = propLayout || WP?.headerLayout;

  if (!layout) return null;

  const desktopRows = layout.desktop?.rows || [];
  const mobileRows = layout.mobile?.rows || [];

  return (
    <header className="mj-dynamic-header w-full">
      {/* Vista de Computadora (Desktop / Tablet grande) */}
      {desktopRows.length > 0 && (
        <div className="hidden lg:block w-full">
          {desktopRows.map((row) => (
            <HeaderRow key={row.id} row={row} isMobileView={false} />
          ))}
        </div>
      )}

      {/* Vista Móvil (Smartphones / Tablets) */}
      {mobileRows.length > 0 && (
        <div className="lg:hidden w-full">
          {mobileRows.map((row) => (
            <HeaderRow key={row.id} row={row} isMobileView={true} />
          ))}
        </div>
      )}
    </header>
  );
}
