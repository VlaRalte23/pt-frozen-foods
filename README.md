# PT Frozen Foods POS

A barcode-first point-of-sale app for store managers and owners.

## Roles and profit

- **Store managers** sign in to Sell and Stock. At checkout they can scan a product barcode or search by product name, and see the selling price/MRP and bill total, not product costs or profit reports.
- **Owners** can do everything managers can, enter purchase costs, record store expenses, and open the Profit report.
- **Sales List** is available to managers and owners and shows customer names, products and quantities sold, totals, payment method, the selected sales manager, and which signed-in account recorded the sale.
- Owners manage sales manager names from **Sales Managers**. Select the responsible manager from the checkout dropdown; manager names do not need sign-in accounts. Existing manager accounts remain usable, but new sign-in accounts are owner-only.
- **Stock** shows an alert for out-of-stock products and products with 5 or fewer units, with quick links to update stock. The stock list can be searched by name or barcode and filtered by stock status.
- **Gross profit** is sales minus the purchase cost of products sold.
- **Net profit** is gross profit minus recorded store expenses. The report shows gross and net profit in separate columns, by day and as all-time totals.
- The owner report includes an interactive daily gross-vs-net chart with 7-, 30-, and 90-day views.
- Choose a 7-, 30-, or 90-day window from the period selector to update the chart, profit cards, and daily table together.
- Owners can open **Manager Performance** to compare each sales manager's transaction count, units sold, sales revenue, and average transaction value over a selected 7-, 30-, or 90-day range. Managers with no sales remain listed with zero totals; rankings are ordered by sales revenue.
- At checkout, choose Cash, GPay, Bank transfer, or Credit. The selected method is saved with each sale; GPay and bank transfers are recorded as paid, but are not connected to a payment provider.
- Customer details are optional for regular sales. Credit sales must be assigned to a regular customer and appear in Customer Debts.
- A custom selling price can be set for an item on the current bill without changing the product's saved selling price.
- Completing a sale opens a printable receipt with the receipt number, items, totals, payment method, and cash change when applicable. Use **Print receipt** to print it again.
- Purchase cost is saved with each sale item so changing a product's cost later does not change past profit. Products without an owner-entered purchase cost cannot be sold.

Older sales made before purchase-cost tracking have no reliable cost history. Their profit is marked unavailable instead of being overstated.

## Run locally

Requirements: PHP 8.3+, Composer, and Node.js with npm.

```sh
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
```

In one terminal, build or watch the frontend:

```sh
npm run dev
```

In another terminal, start Laravel:

```sh
php artisan serve
```

Open the address printed by `php artisan serve`. SQLite is the default database.

## Create the first owner account

When there are no accounts yet, the login page offers **Create the owner account**. This one-time setup always creates an owner, signs them in, and closes after an account is created. For terminal setup instead, run:

```sh
php artisan app:user:create
```

Use a unique password of at least 12 characters. Once signed in, the owner can create further owner accounts from **Users** and manage sales manager names from **Sales Managers**.

## Barcode scanner

Most USB scanners work as a keyboard: scan a barcode and the scanner sends Enter. Keep the cursor in the barcode field. Product barcodes must be unique; a repeated scan on the Stock screen loads that product so its details or stock can be updated.
