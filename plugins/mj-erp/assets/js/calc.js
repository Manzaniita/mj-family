/**
 * MJ ERP — costing engine (client side mirror of MJERP_Costing, spec §7).
 *
 * Exposes MJ.calc = { calculate, roundPrice, toBase, priceFor, round, toFloat }.
 * Same algorithm, same rounding order and same output shape as the PHP engine so the UI can preview
 * numbers before saving; the server result is authoritative after a save.
 *
 * Self-contained: only depends on window.MJ existing (or creates it), so it can be loaded before core.js.
 * Accepts numeric strings in every numeric field ("9,50", "1.234,56", "1583").
 */
(function () {
	'use strict';

	window.MJ = window.MJ || {};
	const MJ = window.MJ;

	/** Decimals used for stored / returned money values. */
	const DECIMALS = 4;

	/** Valid proration methods. */
	const METHODS = ['fob', 'qty', 'none'];

	/**
	 * PHP-compatible round(): half away from zero, with the same 15-significant-digit pre-rounding
	 * PHP applies, so 1.005 → 1.01 and 5.0549999999999997 → 5.06 like on the server.
	 *
	 * @param {*} value
	 * @param {number} decimals
	 * @returns {number}
	 */
	function round(value, decimals) {
		const v = Number(value);
		if (!Number.isFinite(v)) {
			return 0;
		}
		const d = decimals | 0;
		if (v === 0) {
			return 0;
		}
		const sign = v < 0 ? -1 : 1;
		const abs = Math.abs(v);

		// Beyond double precision once shifted: rounding is pointless (same guard as PHP).
		if (abs * Math.pow(10, d) >= 1e15) {
			return v;
		}

		// Pre-round to 15 significant digits, then shift by 10^d using exponent arithmetic
		// (avoids 1.005 * 100 === 100.49999999999999).
		const pre = Number(abs.toPrecision(15));
		const parts = pre.toExponential().split('e');
		const shifted = Number(parts[0] + 'e' + (Number(parts[1]) + d));
		if (!Number.isFinite(shifted)) {
			return v;
		}
		const rounded = Math.round(shifted);
		const back = d >= 0 ? rounded / Math.pow(10, d) : rounded * Math.pow(10, -d);
		const result = sign * back;

		return result === 0 ? 0 : result;
	}

	/**
	 * Casts anything to a finite float. Understands es-AR / en-US formatted strings:
	 * "1234.56", "1234,56", "1.234,56", "1,234.56" (with both separators the last one is the decimal),
	 * ignores currency symbols and spaces. Unparseable → 0.
	 *
	 * @param {*} value
	 * @returns {number}
	 */
	function toFloat(value) {
		if (typeof value === 'number') {
			return Number.isFinite(value) ? value : 0;
		}
		if (value === null || value === undefined) {
			return 0;
		}
		if (typeof value === 'boolean') {
			return value ? 1 : 0;
		}
		if (typeof value !== 'string') {
			if (Array.isArray(value)) {
				return 0;
			}
			if (typeof value === 'object' && typeof value.toString === 'function') {
				value = String(value);
			} else {
				return 0;
			}
		}

		let s = value.trim();
		if (s === '') {
			return 0;
		}
		if (/^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?$/.test(s)) {
			const direct = Number(s);
			return Number.isFinite(direct) ? direct : 0;
		}

		s = s.replace(/[^0-9,.\-]/g, '');
		if (s === '' || s === '-') {
			return 0;
		}
		const negative = s.charAt(0) === '-';
		s = s.replace(/-/g, '');

		const lastComma = s.lastIndexOf(',');
		const lastDot = s.lastIndexOf('.');

		if (lastComma !== -1 && lastDot !== -1) {
			if (lastComma > lastDot) {
				s = s.replace(/\./g, '').replace(/,/g, '.');
			} else {
				s = s.replace(/,/g, '');
			}
		} else if (lastComma !== -1) {
			if (s.split(',').length - 1 > 1) {
				s = s.replace(/,/g, '');
			} else {
				s = s.replace(/,/g, '.');
			}
		} else if (lastDot !== -1 && s.split('.').length - 1 > 1) {
			s = s.replace(/\./g, '');
		}

		if (!/^(\d+\.?\d*|\.\d+)$/.test(s)) {
			return 0;
		}
		const f = Number(s);
		if (!Number.isFinite(f)) {
			return 0;
		}
		return negative ? -f : f;
	}

	/**
	 * Boolean cast that understands "0", "false", "no", "off", "" as false.
	 *
	 * @param {*} value
	 * @param {boolean} fallback Used when value is null/undefined.
	 * @returns {boolean}
	 */
	function toBool(value, fallback) {
		if (value === null || value === undefined) {
			return fallback;
		}
		if (typeof value === 'boolean') {
			return value;
		}
		if (typeof value === 'number') {
			return value !== 0;
		}
		if (typeof value === 'string') {
			const s = value.trim().toLowerCase();
			return ['', '0', 'false', 'no', 'off', 'null'].indexOf(s) === -1;
		}
		return Boolean(value);
	}

	/**
	 * Normalizes a currency code: trimmed, uppercased; '' when empty.
	 *
	 * @param {*} value
	 * @returns {string}
	 */
	function currencyCode(value) {
		if (typeof value !== 'string' && typeof value !== 'number') {
			return '';
		}
		return String(value).trim().toUpperCase();
	}

	/**
	 * Normalizes a proration method; '' when unknown so the caller can fall back.
	 *
	 * @param {*} value
	 * @returns {string}
	 */
	function methodCode(value) {
		if (typeof value !== 'string') {
			return '';
		}
		const m = value.trim().toLowerCase();
		return METHODS.indexOf(m) !== -1 ? m : '';
	}

	/**
	 * Reads a key from a row; null when missing.
	 *
	 * @param {Object} data
	 * @param {string} key
	 * @returns {*}
	 */
	function pick(data, key) {
		return Object.prototype.hasOwnProperty.call(data, key) && data[key] !== undefined ? data[key] : null;
	}

	/**
	 * Normalizes a row to a plain object.
	 *
	 * @param {*} value
	 * @returns {Object}
	 */
	function row(value) {
		return value !== null && typeof value === 'object' ? value : {};
	}

	/**
	 * Normalizes a collection (array or plain object) to an array of rows.
	 *
	 * @param {*} value
	 * @returns {Array}
	 */
	function toList(value) {
		if (Array.isArray(value)) {
			return value;
		}
		if (value !== null && typeof value === 'object') {
			return Object.keys(value).map(function (k) {
				return value[k];
			});
		}
		return [];
	}

	/**
	 * Reads the row id: integer when numeric, string when a non-empty temporary id, null otherwise.
	 *
	 * @param {Object} data
	 * @param {string} key
	 * @param {string} altKey
	 * @returns {number|string|null}
	 */
	function idValue(data, key, altKey) {
		let id = pick(data, key);
		if (id === null || id === '' || id === false) {
			id = pick(data, altKey);
		}
		if (id === null || id === '' || id === false) {
			return null;
		}
		if (typeof id === 'number') {
			return Number.isFinite(id) ? Math.trunc(id) : null;
		}
		if (typeof id === 'string') {
			const s = id.trim();
			if (s === '') {
				return null;
			}
			if (/^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?$/.test(s)) {
				return Math.trunc(Number(s));
			}
			return s;
		}
		return null;
	}

	/**
	 * Normalizes settings price lists to an ordered array of [key, markup] pairs.
	 * Accepts the canonical list of { key, label, markup } rows and, for robustness, a map key => markup.
	 *
	 * @param {*} value
	 * @returns {Array<Array>}
	 */
	function priceLists(value) {
		const lists = [];
		const seen = {};
		if (value === null || typeof value !== 'object') {
			return lists;
		}

		const isArray = Array.isArray(value);
		const keys = isArray ? value.map(function (_, i) { return i; }) : Object.keys(value);

		keys.forEach(function (index) {
			const entry = value[index];
			let key = null;
			let markup = null;

			if (entry !== null && typeof entry === 'object') {
				key = pick(entry, 'key');
				if ((key === null || key === '') && !isArray) {
					key = index;
				}
				markup = pick(entry, 'markup');
			} else {
				key = isArray ? null : index;
				markup = entry;
			}

			if (key === null || (typeof key !== 'string' && typeof key !== 'number')) {
				return;
			}
			key = String(key).trim();
			if (key === '') {
				return;
			}

			const m = toFloat(markup);
			if (Object.prototype.hasOwnProperty.call(seen, key)) {
				lists[seen[key]][1] = m;
			} else {
				seen[key] = lists.length;
				lists.push([key, m]);
			}
		});

		return lists;
	}

	/**
	 * Converts an amount in `currency` to the base currency.
	 * Same currency (or empty currency) → amount; otherwise rate > 0 ? amount / rate : 0.
	 *
	 * @param {*} amount
	 * @param {string} currency
	 * @param {*} rate Units of line currency per 1 unit of base currency.
	 * @param {string} base
	 * @returns {number}
	 */
	function toBase(amount, currency, rate, base) {
		const a = toFloat(amount);
		const c = currencyCode(currency);
		const b = currencyCode(base);
		if (c === '' || c === b) {
			return a;
		}
		const r = toFloat(rate);
		return r > 0 ? a / r : 0;
	}

	/**
	 * Rounds a local price according to the configured rounding step.
	 * rounding <= 0 → 2 decimals; otherwise nearest multiple of `rounding`.
	 *
	 * @param {*} price
	 * @param {*} rounding
	 * @returns {number}
	 */
	function roundPrice(price, rounding) {
		const p = toFloat(price);
		const r = Math.trunc(toFloat(rounding));
		if (r <= 0) {
			return round(p, 2);
		}
		return round(p / r, 0) * r;
	}

	/**
	 * Rounds an amount in base currency to a step (mirror of MJERP_Costing::round_usd), so the
	 * published USD price is a round number too ("US$ 129", not "US$ 128,74").
	 * step <= 0 → 2 decimals; 'up' always climbs (with an epsilon so a value already on the step
	 * does not jump to the next one after a float artifact).
	 *
	 * @param {*} usd
	 * @param {*} step
	 * @param {string} mode 'nearest' | 'up'
	 * @returns {number}
	 */
	function roundUsd(usd, step, mode) {
		const v = toFloat(usd);
		const s = toFloat(step);
		if (s <= 0) {
			return round(v, 2);
		}
		const q = v / s;
		const n = mode === 'up' ? Math.ceil(q - 1e-9) : round(q, 0);
		// The step can be fractional (0.5), so the product needs its own rounding.
		return round(n * s, DECIMALS);
	}

	/**
	 * Rounds an amount in local currency the way MJ Dólar does (mirror of MJERP_Costing::round_ars
	 * and MJD_Rate::round), so the peso price previewed here is the one the storefront prints.
	 *
	 * @param {*} amount
	 * @param {*} step
	 * @param {string} mode 'nearest' | 'up'
	 * @returns {number}
	 */
	function roundArs(amount, step, mode) {
		const v = toFloat(amount);
		const s = Math.trunc(toFloat(step));
		if (s <= 0) {
			return round(v, 2);
		}
		const q = v / s;
		const n = mode === 'up' ? Math.ceil(q - 1e-9) : round(q, 0);
		return n * s;
	}

	/**
	 * Normalizes the USD publishing context (mirror of MJERP_Costing::usd_config).
	 *
	 * @param {*} value
	 * @returns {{rounding:number, mode:string, store_rate:number, store_rounding:number, store_mode:string}}
	 */
	function usdConfig(value) {
		const cfg = row(value);
		const rounding = toFloat(pick(cfg, 'rounding'));
		const rate = toFloat(pick(cfg, 'store_rate'));
		const mode = pick(cfg, 'mode');
		const storeMode = pick(cfg, 'store_mode');
		return {
			rounding: rounding > 0 ? rounding : 0,
			mode: typeof mode === 'string' && mode.trim().toLowerCase() === 'up' ? 'up' : 'nearest',
			store_rate: rate > 0 ? rate : 0,
			store_rounding: Math.max(0, Math.trunc(toFloat(pick(cfg, 'store_rounding')))),
			store_mode: typeof storeMode === 'string' && storeMode.trim().toLowerCase() === 'up' ? 'up' : 'nearest',
		};
	}

	/**
	 * Builds the USD publishing context out of a settings object (mirror of
	 * MJERP_Costing::usd_config_from_settings). The store keys come from the read-only `dolar`
	 * block that MJERP_Dolar injects into the settings, which reach the browser verbatim in
	 * `window.MJERP.settings` — that is what keeps both engines returning the same numbers.
	 *
	 * @param {*} settings
	 * @returns {Object}
	 */
	function usdConfigFromSettings(settings) {
		const s = row(settings);
		const d = row(pick(s, 'dolar'));
		return usdConfig({
			rounding: pick(s, 'usd_rounding'),
			mode: pick(s, 'usd_rounding_mode'),
			store_rate: pick(d, 'rate'),
			store_rounding: pick(d, 'rounding'),
			store_mode: pick(d, 'rounding_mode'),
		});
	}

	/**
	 * Suggested sale price for a landed unit cost.
	 * base = landed × (1 + markup/100) (4 decimals); local = roundPrice(base × saleRate, rounding).
	 *
	 * With `usdCfg`, two more fields describe the price as MJ Dólar would publish it:
	 * usd = roundUsd(base, …) and usdLocal = roundArs(usd × storeRate, …). `usd_local` derives from
	 * the *rounded* USD price because in USD publishing mode the dollar price is the source of truth
	 * and the pesos follow it, exactly like the storefront computes it.
	 *
	 * @param {*} landedUnitBase
	 * @param {*} markupPct
	 * @param {*} saleRate (<= 0 → 1, local equals base)
	 * @param {*} rounding
	 * @param {Object} [usdCfg] { rounding, mode, store_rate, store_rounding, store_mode }
	 * @returns {{base:number, local:number, usd:number, usd_local:number}}
	 */
	function priceFor(landedUnitBase, markupPct, saleRate, rounding, usdCfg) {
		const landed = toFloat(landedUnitBase);
		const markup = toFloat(markupPct);
		let rate = toFloat(saleRate);
		if (rate <= 0) {
			rate = 1;
		}
		const base = round(landed * (1 + markup / 100), DECIMALS);
		const local = roundPrice(base * rate, rounding);

		const cfg = usdConfig(usdCfg);
		const usd = roundUsd(base, cfg.rounding, cfg.mode);
		const storeRate = cfg.store_rate > 0 ? cfg.store_rate : rate;
		const usdLocal = cfg.store_rate > 0
			? roundArs(usd * storeRate, cfg.store_rounding, cfg.store_mode)
			: roundPrice(usd * storeRate, rounding);

		return { base: base, local: local, usd: usd, usd_local: usdLocal };
	}

	/**
	 * Runs the full costing of a purchase (see MJERP_Costing::calculate).
	 *
	 * @param {Object} purchase  { base_currency, proration_method, default_rate }
	 * @param {Array}  items     [{ id?, quantity, unit_cost, currency, rate }]
	 * @param {Array}  expenses  [{ id?, amount, currency, rate, is_prorated }]
	 * @param {Object} settings  { price_lists, price_rounding, sale_rate, local_currency, … }
	 * @returns {{items:Array, expenses:Array, totals:Object}}
	 */
	function calculate(purchase, items, expenses, settings) {
		const p = row(purchase);
		const s = row(settings);
		const itemList = toList(items);
		const expenseList = toList(expenses);
		const decimals = DECIMALS;

		// ---- Context -------------------------------------------------------------------------
		let baseCurrency = currencyCode(pick(p, 'base_currency'));
		if (baseCurrency === '') {
			baseCurrency = currencyCode(pick(s, 'base_currency'));
		}
		if (baseCurrency === '') {
			baseCurrency = 'USD';
		}

		let localCurrency = currencyCode(pick(s, 'local_currency'));
		if (localCurrency === '') {
			localCurrency = 'ARS';
		}

		let defaultRate = toFloat(pick(p, 'default_rate'));
		if (defaultRate <= 0) {
			defaultRate = toFloat(pick(s, 'default_rate'));
		}
		if (defaultRate <= 0) {
			defaultRate = 1;
		}

		let saleRate = toFloat(pick(s, 'sale_rate'));
		if (saleRate <= 0) {
			saleRate = defaultRate;
		}

		let method = methodCode(pick(p, 'proration_method'));
		if (method === '') {
			method = methodCode(pick(s, 'proration_method'));
		}
		if (method === '') {
			method = 'fob';
		}

		const priceRounding = Math.trunc(toFloat(pick(s, 'price_rounding')));
		const lists = priceLists(pick(s, 'price_lists'));
		const usdCfg = usdConfigFromSettings(s);

		// ---- Step 1: items (FOB) -------------------------------------------------------------
		const rows = [];
		let fobTotal = 0;
		let totalQty = 0;
		let maxIndex = null;
		let maxFob = null;

		itemList.forEach(function (rawItem, index) {
			const item = row(rawItem);

			const quantity = toFloat(pick(item, 'quantity'));
			const unitCost = toFloat(pick(item, 'unit_cost'));
			let currency = currencyCode(pick(item, 'currency'));
			if (currency === '') {
				currency = baseCurrency;
			}
			let rate = toFloat(pick(item, 'rate'));
			if (rate <= 0) {
				rate = defaultRate;
			}

			const unitCostBase = toBase(unitCost, currency, rate, baseCurrency);
			const fob = quantity * unitCostBase;

			rows.push({
				item_id: idValue(item, 'id', 'item_id'),
				quantity: quantity,
				rate: rate,
				unit_cost_base: unitCostBase,
				fob: fob,
				share: 0
			});

			fobTotal += fob;
			totalQty += quantity;

			// Largest FOB (first one on ties) receives the cents rounding adjustment.
			if (maxIndex === null || fob > maxFob) {
				maxIndex = index;
				maxFob = fob;
			}
		});

		const itemsCount = rows.length;

		// ---- Step 2: expenses ----------------------------------------------------------------
		const expenseRows = [];
		let proratedTotal = 0;
		let nonProratedTotal = 0;

		expenseList.forEach(function (rawExpense) {
			const expense = row(rawExpense);

			const amount = toFloat(pick(expense, 'amount'));
			let currency = currencyCode(pick(expense, 'currency'));
			if (currency === '') {
				currency = localCurrency;
			}
			let rate = toFloat(pick(expense, 'rate'));
			if (rate <= 0) {
				rate = defaultRate;
			}
			const isProrated = toBool(pick(expense, 'is_prorated'), true);

			const amountBase = toBase(amount, currency, rate, baseCurrency);

			if (isProrated) {
				proratedTotal += amountBase;
			} else {
				nonProratedTotal += amountBase;
			}

			expenseRows.push({
				expense_id: idValue(expense, 'id', 'expense_id'),
				amount_base: round(amountBase, decimals),
				amount_local: round(amountBase * rate, decimals),
				is_prorated: isProrated
			});
		});

		const fobTotalR = round(fobTotal, decimals);
		const proratedTotalR = round(proratedTotal, decimals);
		const nonProratedTotalR = round(nonProratedTotal, decimals);
		const expensesTotalR = round(proratedTotalR + nonProratedTotalR, decimals);

		// ---- Step 3: shares ------------------------------------------------------------------
		let methodUsed = method;
		let roundingAdjustment = 0;
		let roundingIndex = null;
		let roundingItemId = null;

		if (method !== 'none' && proratedTotalR > 0 && itemsCount > 0) {
			if (methodUsed === 'fob' && fobTotal <= 0) {
				methodUsed = 'qty';
			}
			const denominator = methodUsed === 'fob' ? fobTotal : totalQty;

			if (denominator > 0) {
				let shareSum = 0;
				rows.forEach(function (r) {
					const weight = methodUsed === 'fob' ? r.fob : r.quantity;
					const share = round(proratedTotalR * weight / denominator, decimals);
					r.share = share;
					shareSum += share;
				});

				roundingAdjustment = round(proratedTotalR - shareSum, decimals);
				roundingIndex = maxIndex;
				roundingItemId = rows[maxIndex].item_id;

				if (roundingAdjustment !== 0) {
					rows[maxIndex].share = round(rows[maxIndex].share + roundingAdjustment, decimals);
				}
			}
		}

		// ---- Steps 4 & 6: per-item landed cost and price lists ------------------------------
		const outItems = rows.map(function (r) {
			const fob = r.fob;
			const share = r.share;
			const landedTotal = fob + share;
			const landedUnit = r.quantity > 0 ? round(landedTotal / r.quantity, decimals) : 0;

			const prices = {};
			lists.forEach(function (pair) {
				prices[pair[0]] = priceFor(landedUnit, pair[1], saleRate, priceRounding, usdCfg);
			});

			return {
				item_id: r.item_id,
				fob_base: round(fob, decimals),
				fob_local: round(fob * r.rate, decimals),
				unit_cost_base: round(r.unit_cost_base, decimals),
				share_base: round(share, decimals),
				share_pct: fob > 0 ? round(share / fob * 100, decimals) : 0,
				landed_total_base: round(landedTotal, decimals),
				landed_unit_base: landedUnit,
				landed_unit_local: round(landedUnit * saleRate, decimals),
				prices: prices
			};
		});

		// ---- Steps 5 & 7: totals -------------------------------------------------------------
		const landedTotalAll = round(fobTotalR + proratedTotalR, decimals);
		const total = round(fobTotalR + expensesTotalR, decimals);
		const prorationPct = fobTotalR > 0 ? round(proratedTotalR / fobTotalR * 100, decimals) : 0;

		const totals = {
			base_currency: baseCurrency,
			local_currency: localCurrency,
			sale_rate: saleRate,
			default_rate: defaultRate,
			fob_total: fobTotalR,
			prorated_total: proratedTotalR,
			non_prorated_total: nonProratedTotalR,
			expenses_total: expensesTotalR,
			landed_total: landedTotalAll,
			total: total,
			total_local: round(total * defaultRate, decimals),
			total_qty: round(totalQty, decimals),
			items_count: itemsCount,
			proration_pct: prorationPct,
			rounding_adjustment: roundingAdjustment,
			rounding_item_id: roundingItemId,
			rounding_item_index: roundingIndex,
			proration_method: method,
			proration_method_effective: roundingIndex !== null ? methodUsed : method,
			usd_rounding: usdCfg.rounding,
			usd_rounding_mode: usdCfg.mode,
			store_rate: usdCfg.store_rate
		};

		return {
			items: outItems,
			expenses: expenseRows,
			totals: totals
		};
	}

	MJ.calc = {
		DECIMALS: DECIMALS,
		METHODS: METHODS.slice(),
		calculate: calculate,
		roundPrice: roundPrice,
		roundUsd: roundUsd,
		roundArs: roundArs,
		usdConfig: usdConfig,
		usdConfigFromSettings: usdConfigFromSettings,
		toBase: toBase,
		priceFor: priceFor,
		round: round,
		toFloat: toFloat
	};
})();
