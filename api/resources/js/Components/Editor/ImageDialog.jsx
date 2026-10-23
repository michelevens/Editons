import { useState } from 'react';
import Dialog from '../Dialog';

/**
 * Insert a picture as a figure.
 *
 * A description is required, not optional: it is what a screen reader says
 * and what EPUB accessibility checks look for, and an author asked for it
 * here writes one, where an author asked later rarely does. The caption is
 * set the academic way, "Figure 2.1" in bold, which every export then
 * typesets as a caption.
 */
export default function ImageDialog({ bookId, onInsert, onClose }) {
    const [file, setFile] = useState(null);
    const [preview, setPreview] = useState(null);
    const [alt, setAlt] = useState('');
    const [label, setLabel] = useState('');
    const [caption, setCaption] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);

    const choose = (event) => {
        const chosen = event.target.files?.[0];

        if (!chosen) return;

        setFile(chosen);
        setPreview(URL.createObjectURL(chosen));
        setError(null);
    };

    const insert = async () => {
        if (!file || !alt.trim()) return;

        setBusy(true);
        setError(null);

        try {
            const form = new FormData();
            form.append('image', file);
            const response = await window.axios.post(`/books/${bookId}/images`, form);
            const src = response.data?.src;

            // A reply without the picture's address is a failed upload,
            // whatever its status: a PHP notice printed ahead of the JSON
            // left axios a string, and the figure was saved with src null,
            // a picture that was never there after a reload.
            if (typeof src !== 'string' || src === '') {
                throw new Error('The server did not return the picture.');
            }

            onInsert({
                src,
                alt: alt.trim(),
                label: label.trim(),
                caption: caption.trim(),
            });
        } catch (failure) {
            setError(
                failure.response?.data?.errors?.image?.[0] ??
                    failure.response?.data?.message ??
                    'The picture could not be uploaded. Try again.',
            );
            setBusy(false);
        }
    };

    return (
        <Dialog
            onClose={onClose}
            aria-label="Insert picture"
            className="w-full max-w-[560px] rounded-panel border border-border-default bg-bg-surface p-5 shadow-xl"
        >
            <h3 className="text-[15px] font-semibold text-text-primary">Insert picture</h3>

            <label className="mt-3 flex min-h-[150px] cursor-pointer items-center justify-center overflow-hidden rounded-control border border-dashed border-border-input bg-bg-subtle text-[13px] text-text-secondary hover:border-brand-primary focus-within:border-brand-primary focus-within:ring-2 focus-within:ring-brand-primary/40">
                {preview ? (
                    <img src={preview} alt="" className="max-h-[220px] object-contain" />
                ) : (
                    <span>Choose a JPG, PNG, WebP or GIF (up to 15 MB)</span>
                )}
                <input
                    type="file"
                    accept="image/jpeg,image/png,image/webp,image/gif"
                    data-a="image:file"
                    onChange={choose}
                    className="sr-only"
                />
            </label>

            <label className="mt-3 block text-[12px] font-medium text-text-secondary">
                Description (for readers who cannot see it)
                <input
                    value={alt}
                    data-a="image:alt"
                    onChange={(event) => setAlt(event.target.value)}
                    placeholder="e.g. Bar chart of weekly response rates, rising from 12% to 31%"
                    className="mt-1 h-[34px] w-full rounded-control border-border-input text-[13px] focus:border-brand-primary focus:ring-0"
                />
            </label>

            <div className="mt-3 grid grid-cols-[110px_1fr] gap-2">
                <label className="text-[12px] font-medium text-text-secondary">
                    Label
                    <input
                        value={label}
                        data-a="image:label"
                        onChange={(event) => setLabel(event.target.value)}
                        placeholder="Figure 2.1"
                        className="mt-1 h-[34px] w-full rounded-control border-border-input text-[13px] focus:border-brand-primary focus:ring-0"
                    />
                </label>
                <label className="text-[12px] font-medium text-text-secondary">
                    Caption (optional)
                    <input
                        value={caption}
                        data-a="image:caption"
                        onChange={(event) => setCaption(event.target.value)}
                        placeholder="Weekly response rate, March to June 2025"
                        className="mt-1 h-[34px] w-full rounded-control border-border-input text-[13px] focus:border-brand-primary focus:ring-0"
                    />
                </label>
            </div>

            {error && <p className="mt-3 text-[12.5px] text-status-danger">{error}</p>}

            <div className="mt-4 flex justify-end gap-2">
                <button
                    type="button"
                    onClick={onClose}
                    className="h-[34px] rounded-control border border-border-input px-3.5 text-[13px] text-text-primary hover:bg-bg-subtle"
                >
                    Cancel
                </button>
                <button
                    type="button"
                    data-a="image:insert"
                    onClick={insert}
                    disabled={!file || !alt.trim() || busy}
                    className="h-[34px] rounded-control bg-brand-fill px-4 text-[13px] font-semibold text-white hover:bg-brand-primary-hover disabled:opacity-50"
                >
                    {busy ? 'Uploading…' : 'Insert'}
                </button>
            </div>
        </Dialog>
    );
}
