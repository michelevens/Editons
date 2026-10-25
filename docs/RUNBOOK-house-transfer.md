# Runbook — move the Écrivain Prépa books into the house account

Moves the 19 Écrivain Prépa books (production ids **199–217**, today owned by
an ordinary author account) into the platform-owned publisher account
*Écrivain Prépa* (ADR — house catalog and house publisher accounts;
console: `docs/ADMIN.md`, *House catalog*).

Everything below runs on the **app** service. Nothing here sends an email,
charges anybody or touches a sale already made: orders, royalty statements
and partner commissions keep the seller they had when the book sold.

## 0. Before you start

- This branch is deployed to app, api and worker (`railway up` on each; a
  push does not deploy), and its migration has run:

  ```sh
  railway ssh --service app -- php artisan migrate:status | grep add_house_accounts
  ```

  It must say `Ran`. If it says `Pending`:
  `railway ssh --service app -- php artisan migrate --force`.
- Check the ids are the 19 Prépa books and nothing else (titles only, no
  manuscript text): open `/admin/catalog`, search each id, or

  ```sh
  railway ssh --service app -- php artisan tinker --execute="App\Models\Book::whereBetween('id',[199,217])->orderBy('id')->get(['id','user_id','title','status'])->each(fn(\$b)=>print(\$b->id.' '.\$b->user_id.' '.\$b->title.' '.\$b->status.PHP_EOL));"
  ```

  All 19 must have the same `user_id` (the current owner's account).

## 1. Create the house account (once)

```sh
railway ssh --service app -- php artisan house:create ecrivain-prepa --name="Écrivain Prépa" --reason="Platform imprint for the Écrivain Prépa series (owner decision)"
```

Prints `Created: house account #<id> "Écrivain Prépa" (slug ecrivain-prepa)`.
Running it again prints `Already exists` and changes nothing. No password is
printed: nobody can sign in to it.

## 2. Dry run

```sh
railway ssh --service app -- php artisan house:transfer 199-217 --to=ecrivain-prepa --reason="Écrivain Prépa moves to the platform's house account (owner decision)"
```

Writes nothing. Read the table: every row must say `ok`, and the last line
`Dry run: nothing was written. 19 book(s) would move to "Écrivain Prépa".`
Read the warnings too (editions left behind, partners invited by the previous
owner). If any row is not `ok`, nothing will move — fix that book first.

The previous owner is kept as an **editor** of every book (they can still
open and edit them, and see them on their shelf). To remove their access
instead, add `--drop-previous` to both the dry run and the real run.

## 3. Apply

The same command with `--force`:

```sh
railway ssh --service app -- php artisan house:transfer 199-217 --to=ecrivain-prepa --reason="Écrivain Prépa moves to the platform's house account (owner decision)" --force
```

One transaction: all 19 move or none does. Prints
`19 book(s) moved to "Écrivain Prépa" and audited.`

## 4. Check

- `/admin/house`: 19 books, the products with their prices and on-sale
  state unchanged, exports and print readiness listed.
- `/admin/audit`, filtered on the action `house.book_transferred`: 19 rows,
  reason as given, before/after owner.
- `/admin/accounts?filter=house`: the house account, marked *House*.
- Open one book from the House catalog (*Open in the studio*): the banner
  reads *House book — edits are saved as you, {your name}*.
- The storefront pages of the books on sale still open; the seller shown is
  the book's author name (the series stays anonymous).
- `/admin/sales`: sales made before today stay under the previous owner's
  royalties; new sales of these books count as *Platform revenue*.

## Undo

There is no one-command undo, on purpose. If it must be reversed, the audit
rows hold each book's previous `user_id`; move them back with the same
service (`App\Platform\House\HouseTransfer` is one-way today — ask for a
reverse transfer to be built rather than editing rows by hand).
