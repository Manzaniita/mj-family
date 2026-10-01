import { LogoBlock } from './blocks/LogoBlock';
import { NavBlock, MegaMenuBlock } from './blocks/NavBlock';
import { SearchBlock } from './blocks/SearchBlock';
import {
  CartBlock,
  WishlistBlock,
  CompareBlock,
  AccountBlock,
  ThemeToggleBlock,
  MobileTriggerBlock,
} from './blocks/ActionBlocks';
import {
  DolarBlock,
  PaymentSelectorBlock,
  CtaButtonBlock,
  HtmlBlock,
  TopBarBlock,
  ShippingBarBlock,
} from './blocks/WidgetBlocks';
import {
  FooterNewsletterBlock,
  FooterBrandBlock,
  FooterColumnsBlock,
  FooterContactBlock,
  FooterSocialsBlock,
  FooterLegalBlock,
  FooterPaymentsBlock,
} from './blocks/FooterBlocks';

/**
 * Catálogo de componentes de bloques de Cabecera (Header).
 */
export const HEADER_BLOCKS = {
  'mj-logo': LogoBlock,
  'mj-nav': NavBlock,
  'mj-megamenu': MegaMenuBlock,
  'mj-search': SearchBlock,
  'mj-cart': CartBlock,
  'mj-wishlist': WishlistBlock,
  'mj-compare': CompareBlock,
  'mj-account': AccountBlock,
  'mj-theme-toggle': ThemeToggleBlock,
  'mj-mobile-trigger': MobileTriggerBlock,
  'mj-dolar': DolarBlock,
  'mj-payment-selector': PaymentSelectorBlock,
  'mj-cta-button': CtaButtonBlock,
  'mj-html': HtmlBlock,
  'mj-topbar': TopBarBlock,
  'mj-shipping-bar': ShippingBarBlock,
};

/**
 * Catálogo de componentes de bloques de Pie de Página (Footer).
 */
export const FOOTER_BLOCKS = {
  'mj-footer-newsletter': FooterNewsletterBlock,
  'mj-footer-brand': FooterBrandBlock,
  'mj-footer-columns': FooterColumnsBlock,
  'mj-footer-contact': FooterContactBlock,
  'mj-footer-socials': FooterSocialsBlock,
  'mj-footer-legal': FooterLegalBlock,
  'mj-footer-payments': FooterPaymentsBlock,
  'mj-cta-button': CtaButtonBlock,
  'mj-html': HtmlBlock,
};
