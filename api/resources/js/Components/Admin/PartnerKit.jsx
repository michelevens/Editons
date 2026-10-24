import { Field, FormDialog, inputClass, money } from '@/Components/Admin/BusinessKit';

/**
 * Pieces shared by the console's partner pages (Business → Partners). A
 * module of its own, like Kit.jsx, so neither page imports the other.
 */
export const PARTNER_TONE = { applied: 'info', approved: 'success', declined: 'neutral', suspended: 'danger', pending: 'info', paid: 'neutral', reversed: 'warning' };

export function RecordPayout({ partner, currency, balance, methods }) {
    return (
        <FormDialog
            label="Record payout"
            dataA={`admin:partners:payout:${partner.id}`}
            url={route('admin.partners.payout', partner.id)}
            detail={`Up to ${money(balance, currency)}. Record it once you have paid ${partner.name} outside Écrivain; they are emailed.`}
            initial={{ currency, amount: (balance / 100).toFixed(2), method: methods[0].key, reference: '', paid_on: new Date().toISOString().slice(0, 10), note: '' }}
            fields={(form) => (
                <>
                    <Field label={`Amount (${currency.toUpperCase()})`} id={`pp-${partner.id}-amount`}>
                        <input id={`pp-${partner.id}-amount`} data-a="admin:partners:payout:amount" type="number" min="0.01" step="0.01" max={balance / 100} value={form.data.amount} onChange={(e) => form.setData('amount', e.target.value)} className={inputClass} />
                    </Field>
                    <Field label="Method" id={`pp-${partner.id}-method`}>
                        <select id={`pp-${partner.id}-method`} value={form.data.method} onChange={(e) => form.setData('method', e.target.value)} className={inputClass}>
                            {methods.map((m) => <option key={m.key} value={m.key}>{m.label}</option>)}
                        </select>
                    </Field>
                    <Field label="Reference (optional)" id={`pp-${partner.id}-ref`}>
                        <input id={`pp-${partner.id}-ref`} value={form.data.reference} onChange={(e) => form.setData('reference', e.target.value)} className={inputClass} />
                    </Field>
                    <Field label="Paid on" id={`pp-${partner.id}-date`}>
                        <input id={`pp-${partner.id}-date`} type="date" value={form.data.paid_on} onChange={(e) => form.setData('paid_on', e.target.value)} className={inputClass} />
                    </Field>
                </>
            )}
        />
    );
}
