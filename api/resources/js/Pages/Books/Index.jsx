import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';

/**
 * The author's shelf.
 *
 * Deliberately plain. The screenshot's richness lives in the editor; a list
 * of books needs to be legible and fast, and inventing a dashboard full of
 * statistics we do not compute yet would be the mock-screen trap CLAUDE.md
 * warns about.
 */
export default function Index({ books }) {
    const [creating, setCreating] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({
        title: '',
        subtitle: '',
        genre: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('books.store'), {
            onSuccess: () => {
                reset();
                setCreating(false);
            },
        });
    };

    return (
        <AuthenticatedLayout
            header={
                <div className="flex items-center justify-between">
                    <h2 className="text-xl font-semibold text-slate-800">
                        My Books
                    </h2>
                    <button
                        onClick={() => setCreating((v) => !v)}
                        className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700"
                    >
                        {creating ? 'Cancel' : 'New book'}
                    </button>
                </div>
            }
        >
            <Head title="My Books" />

            <div className="mx-auto max-w-5xl p-6">
                {creating && (
                    <form
                        onSubmit={submit}
                        className="mb-6 rounded-xl border border-slate-200 bg-white p-5 shadow-sm"
                    >
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="sm:col-span-2">
                                <label className="text-sm font-semibold text-slate-700">
                                    Title
                                </label>
                                <input
                                    autoFocus
                                    value={data.title}
                                    onChange={(e) => setData('title', e.target.value)}
                                    className="mt-1 w-full rounded-lg border-slate-300 focus:border-indigo-500 focus:ring-indigo-500"
                                    placeholder="The Independent Practice Handbook"
                                />
                                {errors.title && (
                                    <p className="mt-1 text-sm text-red-600">{errors.title}</p>
                                )}
                            </div>
                            <div>
                                <label className="text-sm font-semibold text-slate-700">
                                    Subtitle
                                </label>
                                <input
                                    value={data.subtitle}
                                    onChange={(e) => setData('subtitle', e.target.value)}
                                    className="mt-1 w-full rounded-lg border-slate-300 focus:border-indigo-500 focus:ring-indigo-500"
                                />
                            </div>
                            <div>
                                <label className="text-sm font-semibold text-slate-700">
                                    Genre
                                </label>
                                <input
                                    value={data.genre}
                                    onChange={(e) => setData('genre', e.target.value)}
                                    className="mt-1 w-full rounded-lg border-slate-300 focus:border-indigo-500 focus:ring-indigo-500"
                                    placeholder="Nonfiction"
                                />
                            </div>
                        </div>
                        <button
                            disabled={processing}
                            className="mt-4 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-60"
                        >
                            Create book
                        </button>
                    </form>
                )}

                {books.length === 0 ? (
                    <div className="rounded-xl border border-dashed border-slate-300 bg-white p-12 text-center">
                        <p className="text-slate-600">No books yet.</p>
                        <p className="mt-1 text-sm text-slate-500">
                            Create one to open the writing studio.
                        </p>
                    </div>
                ) : (
                    <ul className="grid gap-3">
                        {books.map((book) => (
                            <li key={book.id}>
                                <Link
                                    href={route('books.edit', book.id)}
                                    className="block rounded-xl border border-slate-200 bg-white p-5 shadow-sm transition hover:border-indigo-300 hover:shadow"
                                >
                                    <div className="flex items-baseline justify-between gap-4">
                                        <h3 className="font-serif text-lg font-bold text-slate-900">
                                            {book.title}
                                        </h3>
                                        <span className="shrink-0 rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-semibold uppercase tracking-wide text-slate-600">
                                            {book.status.replace('_', ' ')}
                                        </span>
                                    </div>
                                    {book.subtitle && (
                                        <p className="mt-0.5 text-sm text-slate-600">
                                            {book.subtitle}
                                        </p>
                                    )}
                                    <p className="mt-3 text-xs text-slate-500">
                                        {book.word_count.toLocaleString()} words ·{' '}
                                        {book.sections_count} section
                                        {book.sections_count === 1 ? '' : 's'} · edited{' '}
                                        {book.updated_at}
                                    </p>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
