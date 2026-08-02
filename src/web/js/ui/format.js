/**
 * Presentation formatters for the vanilla renderer.
 *
 * Kept apart from the DOM primitives: these turn core data (numeric prices) into
 * the display strings the step renderers write via `setText`. The currency
 * symbol comes from the core's payment context (`ctx.payment.currencySymbol`).
 */

/**
 * Format a price for display: two decimals, with the currency symbol appended
 * when one is known (matching the legacy wizard's `40.00 CHF`). An empty or
 * non-numeric value renders as an empty string so a missing price never shows
 * "NaN".
 *
 *
 * @param {number|string|null|undefined} value
 * @param {string|null} [symbol]  currency symbol, e.g. 'CHF' / '$'
 * @returns {string}
 */
export function formatPrice(value, symbol) {
  if (value == null || value === '') return '';
  const n = typeof value === 'number' ? value : parseFloat(value);
  if (Number.isNaN(n)) return '';
  return symbol ? `${n.toFixed(2)} ${symbol}` : n.toFixed(2);
}
