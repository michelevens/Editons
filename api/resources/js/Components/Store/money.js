/**
 * Minor units (cents) to a price, in the currency's own format.
 *
 * Every amount the server sends is an integer of minor units with its
 * currency beside it, as Stripe reports them; nothing is added across
 * currencies.
 */
export function money(cents, currency = 'usd') {
    const value = (Number(cents) || 0) / 100;

    try {
        return new Intl.NumberFormat(undefined, {
            style: 'currency',
            currency: String(currency).toUpperCase(),
        }).format(value);
    } catch {
        return `${value.toFixed(2)} ${String(currency).toUpperCase()}`;
    }
}
