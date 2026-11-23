# expenseTrackerAPI

## Deploying the expense attachment changes

1. Back up the existing `public/upload_receipt` and `public/upload_doc` directories before checking out the update. Some files were previously tracked and Git will remove those copies.
2. Block web access to both legacy paths at the server or proxy and clear any cached copies. The included Apache rewrite rule covers deployments that honor `public/.htaccess`. Configure equivalent deny rules on other servers.
3. Deploy with a writable, persistent `storage/app/expense-attachments` directory shared by all app instances that serve expenses. Keep this directory outside the document root and public storage links.
4. Run `php artisan expenses:move-attachments /path/to/staged-copy` using a working copy of the backup. The source must contain the two upload directories. Running the command without a source moves remaining files from the live public directory. It preserves the relative paths in existing expense records.
5. Use the returned receipt and document URLs with the client's API authentication. For bearer tokens, fetch the URL with the Authorization header and display or save the returned blob as needed.

## Environment configuration

Keep the deployed environment configuration outside Git and set `APP_DEBUG=false` in production. Rotate the previously committed database credential. Before rotating the Laravel application key, inventory encrypted data and plan its migration and any session invalidation. Update the deployment's secret store and rebuild the Laravel configuration cache after changing settings.

## Savings transfers

Run `php artisan migrate --force` against the existing application database before serving this release.

Send an authenticated `POST /api/savings/transfer` request with `saving_category`, `amount`, `transfer_key` (a UUID generated once per transfer), and optional `remarks`. For example, transfer `500.00` from `CIT` with remarks `CIT withdrawal`. The amount is drawn from that category's unfiltered savings balance. Reuse the same UUID and payload when retrying a request whose outcome is unknown. A new transfer returns 201 and a successful replay returns 200.

Use `GET /api/savings/transfer-options` for category balances. Transfer entries appear in `GET /api/savings` with `is_transfer: true`, a positive transfer amount in `add_saving`, remarks, and a reduced running savings total. The account statement lists them as credits labeled **Saving Transfer Balance**. They are internal transfers and do not increase income.
